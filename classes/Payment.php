<?php
// บันทึกการชำระเงิน/คืนเงิน และยอดที่ต้องจ่าย
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/KitchenTicket.php';
require_once __DIR__ . '/ServiceFlow.php';

class Payment
{
    public static function orderTotal(int $orderId): float
    {
        $stmt = getDB()->prepare(
            "SELECT COALESCE(SUM(oi.quantity * oi.unit_price), 0)
                    + COALESCE(SUM((SELECT COALESCE(SUM(oia.addon_price),0)
                                    FROM order_item_addons oia
                                    WHERE oia.order_item_id = oi.order_item_id) * oi.quantity), 0)
             FROM order_items oi
             WHERE oi.order_id = :id AND oi.voided_at IS NULL"
        );
        $stmt->execute(['id' => $orderId]);
        $subtotal = (float) $stmt->fetchColumn();
        $discount = getDB()->prepare('SELECT discount_amount FROM orders WHERE order_id = :id');
        $discount->execute(['id' => $orderId]);
        return max(0, $subtotal - (float) $discount->fetchColumn());
    }

    public static function paidAmount(int $orderId): float
    {
        $stmt = getDB()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0)
             FROM payments WHERE order_id = :id AND status = 'completed'"
        );
        $stmt->execute(['id' => $orderId]);
        return (float) $stmt->fetchColumn();
    }

    public static function record(int $orderId, string $method, float $amount, ?int $userId, ?string $reference = null, string $type = 'payment'): int
    {
        $db = getDB();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            $s = $db->prepare('SELECT order_id FROM orders WHERE order_id=? FOR UPDATE');
            $s->execute([$orderId]);
            if (!$s->fetchColumn()) throw new RuntimeException('ไม่พบออเดอร์');
            require_once __DIR__ . '/../config/omise.php';
            if (restaurant_env('RESTAURANT_ENV') === 'production' && $method === 'mock_online') throw new RuntimeException('ปิดการชำระจำลองบนเว็บออนไลน์');
            if (OMISE_ENABLED && $type === 'payment') {
                $s = $db->prepare('SELECT charge_id,status FROM gateway_payments WHERE order_id=?');
                $s->execute([$orderId]); $gateway = $s->fetch();
                if ($gateway && !in_array($gateway['status'], ['failed','expired'], true)) {
                    $gatewayReference = $gateway['charge_id'] ? 'omise:' . $gateway['charge_id'] : null;
                    if (!$gatewayReference || $reference !== $gatewayReference)
                        throw new RuntimeException('มีรายการ Omise อยู่ กรุณาตรวจผลชำระก่อนรับเงินช่องทางอื่น');
                }
            }
            $result = self::recordWithinTransaction($orderId,$method,$amount,$userId,$reference,$type);
            if ($ownsTransaction) $db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function recordWithinTransaction(int $orderId, string $method, float $amount, ?int $userId, ?string $reference, string $type): int
    {
        if (!in_array($method, ['cash','transfer','transfer_slip','mock_online'], true) || !in_array($type, ['payment','refund'], true) || $amount <= 0) {
            throw new Exception('ข้อมูลการชำระเงินไม่ถูกต้อง');
        }
        $db = getDB();
        if ($type === 'refund' && $amount > self::paidAmount($orderId) + 0.001) {
            throw new Exception('ยอดคืนเงินเกินยอดที่ลูกค้าชำระไว้ (คืนได้อีก ฿' . number_format(max(0, self::paidAmount($orderId)), 2) . ')');
        }
        $stmt = $db->prepare(
            "INSERT INTO payments (order_id, transaction_type, method, amount, status, reference_no, created_by)
             VALUES (:order_id, :type, :method, :amount, 'completed', :reference, :user_id)"
        );
        $stmt->execute([
            'order_id' => $orderId,
            'type' => $type,
            'method' => $method,
            'amount' => $amount,
            'reference' => trim((string) $reference) ?: null,
            'user_id' => $userId,
        ]);
        // ต้องอ่าน lastInsertId ทันทีหลัง INSERT
        $paymentId = (int) $db->lastInsertId();

        if ($type === 'payment') {
            // orders.payment_method เก็บได้แค่ cash/transfer จึงยุบวิธีที่ละเอียดกว่าลงมา
            $legacyMethod = in_array($method, ['mock_online', 'transfer_slip'], true) ? 'transfer' : $method;
            $db->prepare('UPDATE orders SET payment_method = :method WHERE order_id = :id')
               ->execute(['method' => $legacyMethod, 'id' => $orderId]);
        }
        self::syncOrder($orderId);

        // ออเดอร์จากตู้ Self-Service / ออนไลน์ จะเข้าครัวก็ต่อเมื่อชำระเงินครบแล้วเท่านั้น
        if ($type === 'payment') {
            ServiceFlow::snapshotTax($orderId);
            KitchenTicket::sendPaidOrderToKitchen($orderId);
        }
        if (ServiceFlow::details($orderId)) ServiceFlow::event($orderId, $type === 'refund' ? 'refund_recorded' : 'payment_recorded', $userId, ['method'=>$method,'amount'=>$amount,'payment_id'=>$paymentId]);
        return $paymentId;
    }

    public static function syncOrder(int $orderId): void
    {
        $db = getDB();
        $total = self::orderTotal($orderId);
        $paymentStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=:id AND transaction_type='payment' AND status='completed'");
        $refundStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=:id AND transaction_type='refund' AND status='completed'");
        $paymentStmt->execute(['id'=>$orderId]); $payments=(float)$paymentStmt->fetchColumn();
        $refundStmt->execute(['id'=>$orderId]); $refunds=(float)$refundStmt->fetchColumn();
        $paid = $payments - $refunds;
        if ($refunds > 0 && $paid <= 0.001) {
            $status = 'refunded';
        } elseif ($refunds > 0) {
            $status = 'partially_refunded';
        } else {
            $status = $paid <= 0 ? 'unpaid' : ($paid + 0.001 >= $total ? 'paid' : 'partial');
        }
        $stmt = $db->prepare(
            'UPDATE orders SET payment_status = :status, is_paid = :is_paid,
             paid_at = CASE WHEN :mark_paid = 1 THEN COALESCE(paid_at, NOW()) ELSE paid_at END
             WHERE order_id = :id'
        );
        $isPaid = in_array($status, ['paid','partially_refunded'], true) ? 1 : 0;
        $stmt->execute(['status' => $status, 'is_paid' => $isPaid, 'mark_paid' => $isPaid, 'id' => $orderId]);
    }
}
