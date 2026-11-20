<?php
// ใบงานครัว: ส่งเข้าครัวหลังจ่ายเงิน เปลี่ยนสถานะ เริ่มทำ/พร้อม/ส่งมอบ
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Member.php';
require_once __DIR__ . '/LineApi.php';
require_once __DIR__ . '/AppSetting.php';
require_once __DIR__ . '/ServiceFlow.php';

class KitchenTicket
{
    private const TRANSITIONS = [
        'queued' => ['cooking', 'cancelled'],
        'cooking' => ['ready', 'cancelled'],
        'ready' => ['served'],
        'served' => [],
        'cancelled' => [],
    ];

    public static function createRound(int $orderId, array $orderItemIds = [], ?string $note = null): int
    {
        $db = getDB();
        if (!$orderItemIds) {
            $stmt = $db->prepare('SELECT order_item_id FROM order_items WHERE order_id = :id AND voided_at IS NULL');
            $stmt->execute(['id' => $orderId]);
            $orderItemIds = array_map('intval', array_column($stmt->fetchAll(), 'order_item_id'));
        }
        if (!$orderItemIds) {
            throw new Exception('ไม่พบรายการอาหารสำหรับส่งเข้าครัว');
        }

        $roundStmt = $db->prepare('SELECT COALESCE(MAX(round_no), 0) + 1 FROM kitchen_tickets WHERE order_id = :id');
        $roundStmt->execute(['id' => $orderId]);
        $round = (int) $roundStmt->fetchColumn();

        $ins = $db->prepare('INSERT INTO kitchen_tickets (order_id, round_no, status, note) VALUES (:order_id, :round_no, \'queued\', :note)');
        $ins->execute(['order_id' => $orderId, 'round_no' => $round, 'note' => $note]);
        $ticketId = (int) $db->lastInsertId();

        $link = $db->prepare('INSERT INTO kitchen_ticket_items (ticket_id, order_item_id) VALUES (:ticket_id, :item_id)');
        foreach (array_unique($orderItemIds) as $itemId) {
            $link->execute(['ticket_id' => $ticketId, 'item_id' => (int) $itemId]);
        }
        return $ticketId;
    }

    // ส่งออเดอร์ที่ "จ่ายแล้ว" เข้าครัว — ใช้กับทุกช่องทาง (ตู้สั่งเอง / ออนไลน์ / หน้าร้าน)
    public static function sendPaidOrderToKitchen(int $orderId): ?int
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT status, is_paid, channel, note FROM orders WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!$order || (int) $order['is_paid'] !== 1) return null;
        if (!in_array($order['status'], ['pending', 'active'], true)) return null;

        $exists = $db->prepare('SELECT COUNT(*) FROM kitchen_tickets WHERE order_id = :id');
        $exists->execute(['id' => $orderId]);
        if ((int) $exists->fetchColumn() > 0) return null;

        return self::createRound($orderId, [], $order['note'] ?: null);
    }

    public static function activeBoard(): array
    {
        return getDB()->query(
            "SELECT kt.*, o.order_code, o.order_id, o.service_type, o.channel, o.queue_no, o.queue_card_no, o.note AS order_note,
                    GROUP_CONCAT(DISTINCT t.table_code ORDER BY t.table_code SEPARATOR ' + ') AS table_codes,
                    TIMESTAMPDIFF(MINUTE, kt.sent_at, NOW()) AS waiting_minutes
             FROM kitchen_tickets kt
             JOIN orders o ON o.order_id = kt.order_id
             LEFT JOIN table_sessions ts ON ts.session_id = o.table_session_id
             LEFT JOIN table_session_tables tst ON tst.session_id = ts.session_id
             LEFT JOIN restaurant_tables t ON t.table_id = tst.table_id
             WHERE kt.status IN ('queued','cooking','ready') AND o.status NOT IN ('closed','cancelled','no_show')
             GROUP BY kt.ticket_id
             ORDER BY FIELD(kt.priority, 'urgent', 'normal'), FIELD(kt.status, 'cooking', 'queued', 'ready'), kt.sent_at"
        )->fetchAll();
    }

    public static function items(int $ticketId): array
    {
        $stmt = getDB()->prepare(
            "SELECT oi.order_item_id, oi.quantity, oi.item_note, m.name AS menu_name,
                    GROUP_CONCAT(oia.addon_name ORDER BY oia.order_item_addon_id SEPARATOR ', ') AS addons
             FROM kitchen_ticket_items kti
             JOIN order_items oi ON oi.order_item_id = kti.order_item_id
             JOIN menu m ON m.menu_id = oi.menu_id
             LEFT JOIN order_item_addons oia ON oia.order_item_id = oi.order_item_id
             WHERE kti.ticket_id = :ticket_id
             GROUP BY oi.order_item_id
             ORDER BY oi.order_item_id"
        );
        $stmt->execute(['ticket_id' => $ticketId]);
        return $stmt->fetchAll();
    }

    public static function changeStatus(int $ticketId, string $newStatus, ?int $userId): void
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM kitchen_tickets WHERE ticket_id = :id');
        $stmt->execute(['id' => $ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            throw new Exception('ไม่พบใบงานครัว');
        }
        if (!in_array($newStatus, self::TRANSITIONS[$ticket['status']] ?? [], true)) {
            throw new Exception('ไม่สามารถเปลี่ยนสถานะใบงานครัวตามลำดับนี้ได้');
        }

        $timeColumn = match ($newStatus) {
            'cooking' => 'started_at',
            'ready' => 'ready_at',
            'served' => 'served_at',
            default => null,
        };
        $sql = 'UPDATE kitchen_tickets SET status = :status, updated_by = :user_id';
        if ($timeColumn) {
            $sql .= ", {$timeColumn} = NOW()";
        }
        $sql .= ' WHERE ticket_id = :id';

        $ownTx = !$db->inTransaction();
        if ($ownTx) $db->beginTransaction();
        try {
        $lock = $db->prepare('SELECT status FROM kitchen_tickets WHERE ticket_id=? FOR UPDATE');
        $lock->execute([$ticketId]);
        if ($lock->fetchColumn() !== $ticket['status']) throw new Exception('ใบงานนี้เปลี่ยนสถานะแล้ว กรุณาโหลดใหม่');
        $orderLock = $db->prepare('SELECT status,is_paid FROM orders WHERE order_id=? FOR UPDATE');
        $orderLock->execute([$ticket['order_id']]);
        $freshOrder = $orderLock->fetch();
        if (!$freshOrder || !(int)$freshOrder['is_paid'] || in_array($freshOrder['status'],['closed','cancelled','no_show'],true)) throw new Exception('ออเดอร์นี้ไม่พร้อมให้ครัวดำเนินการ');
        if (ServiceFlow::details((int)$ticket['order_id'])) {
            if ($newStatus === 'cancelled') throw new Exception('ออเดอร์ชำระแล้ว ให้พนักงานดำเนินการผ่านคืนเงิน');
            if ($newStatus === 'served') throw new Exception('ให้แคชเชียร์เรียกคิวและส่งมอบอาหาร');
            ServiceFlow::event((int)$ticket['order_id'],'kitchen_status',$userId,['ticket_id'=>$ticketId,'from'=>$ticket['status'],'to'=>$newStatus,'source'=>(($_POST['input_source'] ?? '') === 'voice' ? 'voice' : 'button')]);
        }
        // ครัวกดเริ่มทำ = ใช้วัตถุดิบจริง ตัดสต๊อก FEFO ทันที (ของไม่พอ = เริ่มทำไม่ได้)
        if (in_array($newStatus, ['cooking', 'ready'], true)) {
            require_once __DIR__ . '/OrderFulfillment.php';
            OrderFulfillment::deductStockForOrder((int) $ticket['order_id']);
        }
        $db->prepare($sql)->execute(['status' => $newStatus, 'user_id' => $userId, 'id' => $ticketId]);

        if ($newStatus === 'cooking') {
            $db->prepare("UPDATE orders SET status = 'active' WHERE order_id = :id AND status = 'pending'")
               ->execute(['id' => $ticket['order_id']]);
        } elseif ($newStatus === 'ready') {
            $left = $db->prepare("SELECT COUNT(*) FROM kitchen_tickets WHERE order_id = :id AND status IN ('queued','cooking')");
            $left->execute(['id' => $ticket['order_id']]);
            if ((int) $left->fetchColumn() === 0) {
                $order = $db->prepare('SELECT service_type FROM orders WHERE order_id = :id');
                $order->execute(['id' => $ticket['order_id']]);
                if ($order->fetchColumn() === 'takeaway') {
                    $pickupMinutes = max(5, (int) AppSetting::get('pickup_window_minutes', '30'));
                    $db->prepare("UPDATE orders SET status = 'ready', pickup_deadline = DATE_ADD(NOW(), INTERVAL :mins MINUTE) WHERE order_id = :id")
                       ->execute(['mins'=>$pickupMinutes,'id' => $ticket['order_id']]);
                } else {
                    $db->prepare("UPDATE orders SET status = 'ready' WHERE order_id = :id")
                       ->execute(['id' => $ticket['order_id']]);
                }
            }
        }

        if ($ownTx) $db->commit();
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }

        $orderStmt = $db->prepare('SELECT status, service_type, member_id, order_code FROM orders WHERE order_id=:id');
        $orderStmt->execute(['id'=>$ticket['order_id']]);
        $orderRow = $orderStmt->fetch();
        if ($orderRow) {
            $db->prepare('INSERT INTO order_status_logs(order_id,from_status,to_status,changed_by,note) VALUES(:id,NULL,:status,:user_id,:note)')
               ->execute(['id'=>$ticket['order_id'],'status'=>$orderRow['status'],'user_id'=>$userId,'note'=>'อัปเดตจากหน้าครัว ใบงาน #'.$ticketId]);
            if ($newStatus === 'ready' && $orderRow['service_type'] === 'takeaway' && $orderRow['member_id']) {
                $member = Member::find((int)$orderRow['member_id']);
                if ($member && !empty($member['line_user_id'])) {
                    $pickupMinutes = max(5, (int) AppSetting::get('pickup_window_minutes', '30'));
                    LineApi::pushMessage($member['line_user_id'], 'ออเดอร์ '.($orderRow['order_code'] ?: '#'.$ticket['order_id']).' พร้อมรับแล้ว กรุณามารับภายใน '.$pickupMinutes.' นาที');
                }
            }
        }
    }
}
