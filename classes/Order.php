<?php
// ออเดอร์หลัก: สร้าง เปลี่ยนสถานะ (ตัดสต๊อกตอนเริ่มทำ)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/OrderFulfillment.php';
require_once __DIR__ . '/Member.php';
require_once __DIR__ . '/LineApi.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/AppSetting.php';
require_once __DIR__ . '/ServiceFlow.php';

class Order
{
    // ระยะเวลาที่ลูกค้าต้องมารับหลังออเดอร์ออนไลน์ (นาที)
    public const PICKUP_WINDOW_MINUTES = 30;

    // สถานะที่อนุญาตให้เปลี่ยนได้ตาม FSM
    private const ALLOWED_TRANSITIONS = [
        'pending'   => ['active', 'cancelled'],
        'active'    => ['ready', 'closed', 'cancelled'],
        'ready'     => ['closed', 'no_show'],
        'closed'    => [],
        'no_show'   => [],
        'cancelled' => [],
    ];

    public static function create(
        ?string $note = null,
        string $channel = 'walkin',
        ?int $memberId = null,
        string $serviceType = 'dine_in',
        ?int $tableSessionId = null,
        ?string $scheduledAt = null,
        bool $isPaid = false
    ): int
    {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO orders
                (order_code, status, note, channel, service_type, table_session_id, member_id, scheduled_at,
                 is_paid, payment_status, payment_method, paid_at, opened_at)
             VALUES
                (:order_code, :status, :note, :channel, :service_type, :table_session_id, :member_id, :scheduled_at,
                 :is_paid, :payment_status, :payment_method, :paid_at, NOW())'
        );
        $orderCode = 'ORD-' . date('ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $stmt->execute([
            'order_code' => $orderCode,
            'status' => 'pending',
            'note' => $note,
            'channel' => $channel,
            'service_type' => $serviceType,
            'table_session_id' => $tableSessionId,
            'member_id' => $memberId,
            'scheduled_at' => $scheduledAt,
            'is_paid' => $isPaid ? 1 : 0,
            'payment_status' => $isPaid ? 'paid' : 'unpaid',
            'payment_method' => $isPaid ? 'transfer' : null,
            'paid_at' => $isPaid ? date('Y-m-d H:i:s') : null,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function changeStatus(int $orderId, string $newStatus): bool
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM orders WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            throw new Exception("ไม่พบออเดอร์ #$orderId");
        }
        $current = $order['status'];
        $pickupWindowMinutes = max(5, (int) AppSetting::get('pickup_window_minutes', (string) self::PICKUP_WINDOW_MINUTES));

        if (!in_array($newStatus, self::ALLOWED_TRANSITIONS[$current] ?? [], true)) {
            throw new Exception("ไม่สามารถเปลี่ยนสถานะจาก '$current' ไปเป็น '$newStatus' ได้");
        }

        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT * FROM orders WHERE order_id=? FOR UPDATE');
            $lock->execute([$orderId]);
            $fresh = $lock->fetch();
            if (!$fresh || $fresh['status'] !== $current) throw new Exception('สถานะเปลี่ยนแล้ว กรุณาโหลดหน้าใหม่');
            if (ServiceFlow::details($orderId)) {
                if (in_array($newStatus,['active','ready','closed'],true) && !(int)$fresh['is_paid']) throw new Exception('ต้องชำระครบก่อนส่งครัว');
                if ($newStatus==='cancelled' && (int)$fresh['is_paid']) throw new Exception('ออเดอร์ชำระแล้ว กรุณาดำเนินการผ่านคืนเงิน');
            }
            if ($newStatus === 'closed' && ($service = ServiceFlow::details($orderId))) ServiceFlow::assertPickup($fresh,$service);
            if (ServiceFlow::details($orderId)) ServiceFlow::event($orderId,'order_status',class_exists('Auth') ? Auth::id() : null,['from'=>$current,'to'=>$newStatus]);
            // เริ่มทำอาหาร = ใช้วัตถุดิบจริง ตัดสต๊อก FEFO ตรงนี้ ถ้าของไม่พอจะ rollback ไม่เปลี่ยนสถานะ
            if (in_array($newStatus, ['active', 'ready'], true)) {
                OrderFulfillment::deductStockForOrder($orderId);
            }

            if ($newStatus === 'ready') {
                if ($order['service_type'] === 'takeaway') {
                    $stmt = $db->prepare(
                        "UPDATE orders SET status = 'ready',
                            pickup_deadline = DATE_ADD(NOW(), INTERVAL :mins MINUTE)
                         WHERE order_id = :id"
                    );
                    $stmt->execute(['mins' => $pickupWindowMinutes, 'id' => $orderId]);
                } else {
                    $stmt = $db->prepare("UPDATE orders SET status = 'ready' WHERE order_id = :id");
                    $stmt->execute(['id' => $orderId]);
                }
            } elseif ($newStatus === 'closed') {
                // ปกติตัดสต๊อกไปแล้วตอนเริ่มทำ ตรงนี้กันพลาดสำหรับรายการที่ยังไม่ถูกตัด (เช่นออเดอร์เก่า)
                OrderFulfillment::deductStockForOrder($orderId);

                $stmt = $db->prepare("UPDATE orders SET status = 'closed', closed_at = NOW() WHERE order_id = :id");
                $stmt->execute(['id' => $orderId]);

                // ให้แต้มสมาชิกถ้าออเดอร์นี้ผูกกับสมาชิก (นับเฉพาะออเดอร์ที่ปิดสำเร็จ = มารับจริง)
                if ($order['member_id']) {
                    Member::awardPointsForOrder((int) $order['member_id'], $orderId);
                }
            } elseif ($newStatus === 'no_show') {
                $stmt = $db->prepare("UPDATE orders SET status = 'no_show', closed_at = NOW() WHERE order_id = :id");
                $stmt->execute(['id' => $orderId]);

                if ($order['member_id']) {
                    Member::recordNoShow((int) $order['member_id']);
                }
            } elseif ($newStatus === 'cancelled') {
                // ยกเลิกหลังเริ่มทำ = วัตถุดิบถูกใช้ไปแล้วจริง จึงไม่คืนสต๊อก
                $stmt = $db->prepare("UPDATE orders SET status = 'cancelled', closed_at = NOW() WHERE order_id = :id");
                $stmt->execute(['id' => $orderId]);

                // คืนแต้มที่ลูกค้าใช้เป็นส่วนลดในออเดอร์นี้
                Member::returnRedeemedPoints($orderId);
            } else {
                $stmt = $db->prepare('UPDATE orders SET status = :status WHERE order_id = :id');
                $stmt->execute(['status' => $newStatus, 'id' => $orderId]);
            }

            if ($newStatus === 'active') {
                $db->prepare("UPDATE kitchen_tickets SET status='cooking', started_at=COALESCE(started_at,NOW()) WHERE order_id=:id AND status='queued'")
                   ->execute(['id'=>$orderId]);
            } elseif ($newStatus === 'ready') {
                $db->prepare("UPDATE kitchen_tickets SET status='ready', ready_at=COALESCE(ready_at,NOW()) WHERE order_id=:id AND status IN ('queued','cooking')")
                   ->execute(['id'=>$orderId]);
            } elseif ($newStatus === 'closed') {
                $db->prepare("UPDATE kitchen_tickets SET status='served', served_at=COALESCE(served_at,NOW()) WHERE order_id=:id AND status='ready'")
                   ->execute(['id'=>$orderId]);
            } elseif ($newStatus === 'cancelled') {
                $db->prepare("UPDATE kitchen_tickets SET status='cancelled' WHERE order_id=:id AND status IN ('queued','cooking')")
                   ->execute(['id'=>$orderId]);
            }

            $log = $db->prepare(
                'INSERT INTO order_status_logs (order_id, from_status, to_status, changed_by)
                 VALUES (:order_id, :from_status, :to_status, :changed_by)'
            );
            $log->execute([
                'order_id' => $orderId,
                'from_status' => $current,
                'to_status' => $newStatus,
                'changed_by' => class_exists('Auth') ? Auth::id() : null,
            ]);

            $db->commit();
            AuditLog::write(class_exists('Auth') ? Auth::id() : null, 'change_status', 'order', $orderId, "$current → $newStatus");

            // แจ้งเตือนลูกค้าผ่าน LINE หลัง commit สำเร็จแล้ว (ทำนอก transaction เพราะเป็น external API
            if ($newStatus === 'ready' && $order['service_type'] === 'takeaway' && $order['member_id']) {
                $member = Member::find((int) $order['member_id']);
                if ($member && !empty($member['line_user_id'])) {
                    LineApi::pushMessage(
                        $member['line_user_id'],
                        "ออเดอร์ #$orderId ของคุณพร้อมรับแล้ว! กรุณามารับภายใน " . $pickupWindowMinutes . " นาที ที่ร้าน"
                    );
                }
            }

            return true;
        } catch (Exception $e) {
            if($db->inTransaction()) $db->rollBack();
            if($newStatus==='closed') AuditLog::write(class_exists('Auth') ? Auth::id() : null,'pickup_failed','order',$orderId,mb_substr($e->getMessage(),0,255));
            throw $e;
        }
    }

    // สแกนออเดอร์ออนไลน์ที่ "พร้อมรับ" แล้วเลยกรอบเวลาที่กำหนด เปลี่ยนเป็น "ไม่มารับ" อัตโนมัติ
    public static function expireOverdueOnlineOrders(): int
    {
        $db = getDB();
        $stmt = $db->query(
            "SELECT order_id, member_id FROM orders
             WHERE status = 'ready' AND channel = 'online' AND pickup_deadline < NOW()"
        );
        $overdue = $stmt->fetchAll();

        foreach ($overdue as $o) {
            self::changeStatus((int) $o['order_id'], 'no_show');
        }

        return count($overdue);
    }

    public static function find(int $orderId): ?array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM orders WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        return $order ?: null;
    }

    public static function reopenForAdditionalItems(int $orderId): void
    {
        $db = getDB();
        $stmt = $db->prepare("SELECT status FROM orders WHERE order_id = :id FOR UPDATE");
        $stmt->execute(['id' => $orderId]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            throw new Exception("ไม่พบออเดอร์ #{$orderId}");
        }
        if ($status !== 'ready') {
            return;
        }
        $db->prepare("UPDATE orders SET status = 'active' WHERE order_id = :id")
           ->execute(['id' => $orderId]);
        $db->prepare(
            "INSERT INTO order_status_logs (order_id, from_status, to_status, changed_by, note)
             VALUES (:order_id, 'ready', 'active', :changed_by, 'เปิดออเดอร์อีกครั้งเนื่องจากมีรายการสั่งเพิ่ม')"
        )->execute([
            'order_id' => $orderId,
            'changed_by' => class_exists('Auth') ? Auth::id() : null,
        ]);
    }
}
