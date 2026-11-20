<?php
// ออเดอร์จากตู้สั่งเอง/ออนไลน์: สร้างออเดอร์ เลขคิว QR พร้อมเพย์ รับเงินที่เคาน์เตอร์
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Order.php';
require_once __DIR__ . '/OrderItem.php';
require_once __DIR__ . '/Menu.php';
require_once __DIR__ . '/MenuStock.php';
require_once __DIR__ . '/Addon.php';
require_once __DIR__ . '/Payment.php';
require_once __DIR__ . '/AppSetting.php';
require_once __DIR__ . '/ServiceFlow.php';
require_once __DIR__ . '/PhoneMember.php';
require_once __DIR__ . '/AuditLog.php';
require_once __DIR__ . '/QueueCard.php';

// KioskOrder — ออเดอร์ที่ลูกค้าสั่งเองจากแท็บเล็ตหน้าร้าน (Self-Service Kiosk)
class KioskOrder
{
    public const MAX_QTY_PER_LINE = 20;
    public const MAX_LINES        = 30;

    // สร้างออเดอร์จากตะกร้า
    public static function place(array $cart, string $serviceType, string $channel = 'kiosk',
                                 ?int $memberId = null, ?string $note = null, ?string $scheduledAt = null, array $service = []): array
    {
        if (!in_array($serviceType, ['dine_in', 'takeaway'], true)) {
            throw new Exception('กรุณาเลือกทานที่ร้านหรือรับกลับบ้าน');
        }
        $lines = self::validateCart($cart);

        $db = getDB();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            if ($channel === 'kiosk' && ($service['member_mode'] ?? 'guest') !== 'guest') {
                $mode = $service['member_mode'];
                if (!in_array($mode, ['existing','register'], true)) throw new Exception('ตัวเลือกสมาชิกไม่ถูกต้อง');
                $memberId = PhoneMember::resolve((string)($service['phone'] ?? ''), $mode === 'register', (string)($service['name'] ?? ''), ($service['consent'] ?? false) === true);
            }
            $orderId = Order::create($note, $channel, $memberId, $serviceType, null, $scheduledAt, false);
            $card = null;
            if ($channel === 'kiosk' && QueueCard::required()) {
                $card = QueueCard::attach($orderId, (string) ($service['card_code'] ?? ''));
            }

            foreach ($lines as $line) {
                $itemId = OrderItem::add($orderId, $line['menu_id'], $line['qty']);
                foreach ($line['addons'] as $addonId) {
                    OrderItem::addAddon($itemId, $addonId);
                }
            }

            $queueNo = self::assignQueue($orderId);
            $token   = bin2hex(random_bytes(16));
            $db->prepare('UPDATE orders SET access_token = :t WHERE order_id = :id')
               ->execute(['t' => $token, 'id' => $orderId]);

            $experience = $channel === 'kiosk' ? ServiceFlow::create($orderId, (string)($service['receipt'] ?? 'digital')) : null;
            if ($own) $db->commit();
        } catch (Exception $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }

        return [
            'order_id'    => $orderId,
            'queue_no'    => $queueNo,
            'queue_label' => self::queueLabel($queueNo, $channel, $orderId),
            'service' => $experience,
            'card_no'     => $card ? (int) $card['card_no'] : null,
            'card_label'  => $card ? QueueCard::label((int) $card['card_no']) : '',
            'token'       => $token,
            'total'       => Payment::orderTotal($orderId),
        ];
    }

    // ตรวจตะกร้าฝั่งเซิร์ฟเวอร์ทุกครั้ง เพราะแท็บเล็ตเป็นเครื่องสาธารณะ
    public static function validateCart(array $cart): array
    {
        if (!$cart) throw new Exception('ตะกร้าว่าง กรุณาเลือกเมนูอย่างน้อย 1 รายการ');
        if (count($cart) > self::MAX_LINES) throw new Exception('รายการในตะกร้ามากเกินไป');

        $lines = [];
        $names = [];
        foreach ($cart as $row) {
            $menuId = (int) ($row['menu_id'] ?? 0);
            $qty    = (int) ($row['qty'] ?? 0);
            if ($qty < 1 || $qty > self::MAX_QTY_PER_LINE) {
                throw new Exception('จำนวนต่อรายการต้องอยู่ระหว่าง 1 - ' . self::MAX_QTY_PER_LINE);
            }
            $menu = Menu::find($menuId);
            if (!$menu || !(int) $menu['is_active']) {
                throw new Exception('มีเมนูที่ไม่ได้เปิดขายอยู่ในตะกร้า');
            }
            if ($reason = Menu::unavailableReason($menu)) {
                throw new Exception('ขออภัย "' . $menu['name'] . '" ' . $reason);
            }
            $allowed = array_map('intval', array_column(Addon::forMenu($menuId), 'addon_id'));
            $addons  = array_values(array_unique(array_map('intval', (array) ($row['addons'] ?? []))));
            foreach ($addons as $a) {
                if (!in_array($a, $allowed, true)) {
                    throw new Exception('ตัวเลือกเสริมไม่ตรงกับเมนู "' . $menu['name'] . '"');
                }
            }
            $lines[] = ['menu_id' => $menuId, 'qty' => $qty, 'addons' => $addons];
            $names[$menuId] = $menu['name'];
        }
        if ($problems = MenuStock::checkCart($lines, $names)) {
            throw new Exception(implode(' / ', $problems));
        }
        return $lines;
    }

    // เลขคิวรายวัน เริ่ม 1 ใหม่ทุกวัน
    public static function assignQueue(int $orderId): int
    {
        $db = getDB();
        // นับเลขคิวแยกตามช่องทาง เริ่ม 1 ใหม่ทุกวัน: K = ตู้, W = หน้าร้าน, O = ออนไลน์
        $ch = $db->prepare('SELECT channel FROM orders WHERE order_id = ?');
        $ch->execute([$orderId]);
        $channel = (string) $ch->fetchColumn();
        $stmt = $db->prepare(
            'SELECT COALESCE(MAX(queue_no), 0) + 1 FROM orders
             WHERE opened_at >= CURDATE() AND channel = ? FOR UPDATE'
        );
        $stmt->execute([$channel]);
        $next = (int) $stmt->fetchColumn();
        $db->prepare('UPDATE orders SET queue_no = :q WHERE order_id = :id')
           ->execute(['q' => $next, 'id' => $orderId]);
        return $next;
    }

    // K = ตู้สั่งเอง, O = สั่งออนไลน์, W = พนักงานรับออเดอร์
    public static function queueLabel(?int $queueNo, string $channel, ?int $orderId = null): string
    {
        if (!$queueNo) return '-';
        $prefix = ['kiosk' => 'K', 'online' => 'O'][$channel] ?? 'W';
        return $prefix . str_pad((string) $queueNo, 3, '0', STR_PAD_LEFT);
    }

    // ป้ายเลขบัตรคิว (HTML) สำหรับหน้าพนักงาน
    public static function cardBadge(array $row, string $extra = ''): string
    {
        $label = QueueCard::label(isset($row['queue_card_no']) ? (int) $row['queue_card_no'] : null);
        return $label ? '<span class="badge queue-card-badge ' . $extra . '"><i class="ti ti-id"></i> ' . htmlspecialchars($label) . '</span>' : '';
    }

    // ใช้ token แทนการล็อกอิน — ใครมีลิงก์ที่มี token ถูกต้องเท่านั้นถึงดูออเดอร์นี้ได้
    public static function findByToken(int $orderId, string $token): ?array
    {
        if (strlen($token) !== 32 || !ctype_xdigit($token)) return null;
        $order = Order::find($orderId);
        if (!$order || !$order['access_token'] || !hash_equals($order['access_token'], $token)) {
            return null;
        }
        return $order;
    }

    // ================================================================

    // ออเดอร์ที่ยังไม่จ่าย (ทุกช่องทาง)
    public static function awaitingPayment(): array
    {
        return getDB()->query(
            "SELECT o.*, TIMESTAMPDIFF(MINUTE, o.opened_at, NOW()) AS waiting_minutes
             FROM orders o
             WHERE o.status = 'pending' AND o.is_paid = 0
             ORDER BY o.opened_at"
        )->fetchAll();
    }

    // ออเดอร์ที่จ่ายแล้วและครัวทำเสร็จ รอลูกค้ามารับ
    public static function readyForPickup(): array
    {
        return getDB()->query(
            // รวมออเดอร์เก่าที่สถานะออเดอร์ยังเป็น active แต่ครัวทำเสร็จทุกใบงานแล้ว
            "SELECT o.* FROM orders o
             WHERE o.is_paid = 1 AND (o.status = 'ready' OR (o.status = 'active'
                   AND EXISTS (SELECT 1 FROM kitchen_tickets k WHERE k.order_id = o.order_id AND k.status = 'ready')
                   AND NOT EXISTS (SELECT 1 FROM kitchen_tickets k WHERE k.order_id = o.order_id AND k.status IN ('queued','cooking'))))
             ORDER BY o.opened_at"
        )->fetchAll();
    }

    public static function isReadyForPickup(int $orderId): bool
    {
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM orders o
             WHERE o.order_id = ? AND o.is_paid = 1 AND (o.status = 'ready' OR (o.status = 'active'
                   AND EXISTS (SELECT 1 FROM kitchen_tickets k WHERE k.order_id = o.order_id AND k.status = 'ready')
                   AND NOT EXISTS (SELECT 1 FROM kitchen_tickets k WHERE k.order_id = o.order_id AND k.status IN ('queued','cooking'))))"
        );
        $stmt->execute([$orderId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    // แคชเชียร์รับเงินสด (หรือดูสลิปบนมือถือลูกค้าแล้วกดรับเอง)
    public static function acceptCounterPayment(int $orderId, string $method, float $cashReceived, ?int $userId): array
    {
        $order = Order::find($orderId);
        if (!$order || $order['status'] !== 'pending' || (int) $order['is_paid'] === 1) {
            throw new Exception('ออเดอร์นี้ไม่ได้อยู่ในสถานะรอชำระเงิน');
        }
        $due = round(Payment::orderTotal($orderId) - Payment::paidAmount($orderId), 2);
        if (!in_array($method, ['cash', 'transfer'], true)) {
            throw new Exception('วิธีชำระเงินไม่ถูกต้อง');
        }
        if ($method === 'cash' && $cashReceived + 0.001 < $due) {
            throw new Exception('รับเงินมาน้อยกว่ายอดที่ต้องชำระ');
        }

        $db = getDB();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT status,is_paid FROM orders WHERE order_id=? FOR UPDATE');
            $lock->execute([$orderId]);
            $fresh = $lock->fetch();
            if (!$fresh || $fresh['status'] !== 'pending' || (int)$fresh['is_paid']) throw new Exception('ออเดอร์นี้ไม่ได้รอชำระแล้ว');
            $due = round(Payment::orderTotal($orderId) - Payment::paidAmount($orderId), 2);
            if ($due <= 0 || ($method === 'cash' && $cashReceived + 0.001 < $due)) throw new Exception('ยอดชำระเปลี่ยน กรุณาเปิดหน้าคิดเงินใหม่');
            Payment::record($orderId, $method, $due, $userId, $method === 'cash' ? null : 'COUNTER-CHECK');
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return [
            'due'    => $due,
            'change' => $method === 'cash' ? round($cashReceived - $due, 2) : 0.0,
        ];
    }

    // ออเดอร์ที่คืนเงินเต็มจำนวนแล้วแต่ยังค้างในคิว (รอทำ/กำลังทำ/พร้อมรับ) ให้ยกเลิกออกจากคิว
    public static function cancelRefunded(?int $orderId = null, ?int $userId = null, ?string $reason = null): int
    {
        $db = getDB();
        $sql = "SELECT order_id FROM orders WHERE payment_status = 'refunded' AND status IN ('pending','active','ready')";
        $ids = $orderId ? [$orderId] : $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        $n = 0;
        foreach ($ids as $id) {
            $s = $db->prepare("UPDATE orders SET status='cancelled', cancel_reason=COALESCE(:r, cancel_reason, 'คืนเงินเต็มจำนวน'), cancelled_by=:u, cancelled_at=NOW(), closed_at=COALESCE(closed_at,NOW())
                               WHERE order_id=:id AND payment_status='refunded' AND status IN ('pending','active','ready')");
            $s->execute(['r' => $reason, 'u' => $userId, 'id' => $id]);
            if (!$s->rowCount()) continue;
            $db->prepare("UPDATE kitchen_tickets SET status='cancelled' WHERE order_id=? AND status IN ('queued','cooking','ready')")->execute([$id]);
            AuditLog::write($userId, 'refund_cancel', 'order', (int) $id, 'คืนเงินเต็มจำนวน ยกเลิกออกจากคิว');
            $n++;
        }
        return $n;
    }

    // ยกเลิกออเดอร์เงินสดที่ลูกค้าไม่มาจ่ายเกินเวลาที่ตั้งไว้
    public static function expireUnpaid(): int
    {
        $mins = max(5, (int) AppSetting::get('kiosk_unpaid_expire_minutes', '30'));
        $stmt = getDB()->prepare(
            "SELECT order_id FROM orders
             WHERE channel = 'kiosk' AND status = 'pending' AND is_paid = 0
               AND opened_at < DATE_SUB(NOW(), INTERVAL :m MINUTE)"
        );
        $stmt->execute(['m' => $mins]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            try { Order::changeStatus((int) $id, 'cancelled'); } catch (Exception $e) { /* ข้ามตัวที่เปลี่ยนไม่ได้ */ }
        }
        return count($ids);
    }

    // ครัวกด "ส่งมอบแล้ว" สำหรับออเดอร์ที่จ่ายเงินล่วงหน้า = จบงาน
    public static function closeIfPrepaidAndServed(int $orderId): bool
    {
        $order = Order::find($orderId);
        if (!$order || (int) $order['is_paid'] !== 1) {
            return false;
        }
        $left = getDB()->prepare(
            "SELECT COUNT(*) FROM kitchen_tickets WHERE order_id = :id AND status IN ('queued','cooking','ready')"
        );
        $left->execute(['id' => $orderId]);
        if ((int) $left->fetchColumn() > 0) return false;

        if (in_array($order['status'], ['active', 'ready'], true)) {
            Order::changeStatus($orderId, 'closed');
            return true;
        }
        return false;
    }

    // ลูกค้าเปลี่ยนจากสแกนจ่ายเป็นจ่ายเงินสดที่เคาน์เตอร์ (หรือกลับไปสแกนจ่าย) — บันทึกไว้ในหมายเหตุให้แคชเชียร์เห็น
    public const CASH_MARK = 'เปลี่ยนเป็นจ่ายเงินสด';

    public static function wantsCash(array $order): bool
    {
        return str_contains((string) ($order['note'] ?? ''), self::CASH_MARK);
    }

    public static function setPayAtCounter(int $orderId, bool $cash): bool
    {
        $order = Order::find($orderId);
        if (!$order || $order['status'] !== 'pending' || (int) $order['is_paid'] === 1) return false;
        $note = trim(str_replace(' · ' . self::CASH_MARK, '', (string) ($order['note'] ?? '')));
        if ($cash) $note = mb_substr($note, 0, 220) . ' · ' . self::CASH_MARK;
        getDB()->prepare("UPDATE orders SET note = :n WHERE order_id = :id AND status = 'pending' AND is_paid = 0")
               ->execute(['n' => $note !== '' ? $note : null, 'id' => $orderId]);
        return true;
    }

    // ================================================================

    // สร้างข้อความสำหรับ QR พร้อมเพย์ที่ "ฝังยอดเงิน" มาด้วย
    public static function promptPayPayload(string $target, float $amount): string
    {
        $target = preg_replace('/\D/', '', $target);
        if ($target === '') throw new Exception('ยังไม่ได้ตั้งค่าเบอร์พร้อมเพย์ของร้าน');

        if (strlen($target) === 10 && $target[0] === '0') {
            // เบอร์มือถือ 0812345678 -> 0066812345678
            $acct = self::tlv('01', '0066' . substr($target, 1));
        } elseif (strlen($target) === 13) {
            // เลขบัตรประชาชน / เลขผู้เสียภาษี
            $acct = self::tlv('02', $target);
        } else {
            throw new Exception('เบอร์พร้อมเพย์ต้องเป็นเบอร์มือถือ 10 หลัก หรือเลขบัตร 13 หลัก');
        }

        $merchant = self::tlv('00', 'A000000677010111') . $acct;
        $payload  = self::tlv('00', '01')              // Payload Format Indicator
                  . self::tlv('01', '12')              // 12 = QR ใช้ครั้งเดียว มียอดเงิน
                  . self::tlv('29', $merchant)         // ข้อมูลพร้อมเพย์
                  . self::tlv('53', '764')             // สกุลเงินบาท
                  . self::tlv('54', number_format($amount, 2, '.', ''))
                  . self::tlv('58', 'TH')
                  . '6304';                            // CRC ต่อท้าย
        return $payload . self::crc16($payload);
    }

    private static function tlv(string $tag, string $value): string
    {
        return $tag . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }

    // CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF) ตามมาตรฐาน EMVCo
    private static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
