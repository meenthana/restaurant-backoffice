<?php
// บัตรคิว: ลูกค้าหยิบบัตรแล้วกรอกรหัส 4 หลักก่อนสั่ง บัตรว่างเองเมื่อออเดอร์จบ/ยกเลิก
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AppSetting.php';

class QueueCard
{
    public const OPEN = "('pending','active','ready')";
    // kiosk = บัตรตู้ (เมนูจานเดียว ลูกค้าสั่งเอง) · counter = บัตรตามสั่ง (สั่งกับพนักงาน)
    public const TYPES = ['kiosk' => 'บัตรตู้สั่งอาหาร', 'counter' => 'บัตรอาหารตามสั่ง'];

    public static function typeReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try { getDB()->query('SELECT card_type FROM queue_cards LIMIT 1'); $ok = true; }
            catch (Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    public static function tableReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try { getDB()->query('SELECT 1 FROM queue_cards LIMIT 1'); $ok = true; }
            catch (Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    // ต้องกรอกบัตรไหม (ตั้งค่าเปิดอยู่ และมีบัตรที่ใช้ได้อย่างน้อย 1 ใบ)
    public static function required(string $type = 'kiosk'): bool
    {
        if (!self::tableReady() || AppSetting::get('queue_card_required', '1') !== '1') return false;
        $sql = 'SELECT COUNT(*) FROM queue_cards WHERE is_active = 1' . (self::typeReady() ? ' AND card_type = ?' : '');
        $stmt = getDB()->prepare($sql);
        $stmt->execute(self::typeReady() ? [$type] : []);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function label(?int $cardNo): string
    {
        return $cardNo ? 'บัตร ' . str_pad((string) $cardNo, 2, '0', STR_PAD_LEFT) : '';
    }

    // ตรวจรหัสก่อนเข้าเมนู (ยังไม่จองบัตร)
    public static function check(string $code, string $type = 'kiosk'): array
    {
        $card = self::findByCode($code);
        if (!$card) throw new Exception('ไม่พบรหัสบัตรนี้ กรุณาตรวจเลข 4 หลักบนบัตรอีกครั้ง');
        self::assertType($card, $type);
        if (self::busyOrder((int) $card['card_id'])) throw new Exception('บัตรนี้กำลังใช้งานอยู่ กรุณาหยิบบัตรใบอื่น');
        return $card;
    }

    // ผูกบัตรกับออเดอร์ (เรียกใน transaction ตอนสร้างออเดอร์)
    public static function attach(int $orderId, string $code, string $type = 'kiosk'): array
    {
        $db = getDB();
        $card = self::findByCode($code, true);
        if (!$card) throw new Exception('ไม่พบรหัสบัตรนี้ กรุณาตรวจเลข 4 หลักบนบัตรอีกครั้ง');
        self::assertType($card, $type);
        if (self::busyOrder((int) $card['card_id'], $orderId)) throw new Exception('บัตรนี้กำลังใช้งานอยู่ กรุณาหยิบบัตรใบอื่น');
        $db->prepare('UPDATE orders SET queue_card_id = ?, queue_card_no = ? WHERE order_id = ?')
           ->execute([(int) $card['card_id'], (int) $card['card_no'], $orderId]);
        return $card;
    }

    private static function assertType(array $card, string $type): void
    {
        $actual = $card['card_type'] ?? 'kiosk';
        if ($actual === $type) return;
        throw new Exception($type === 'kiosk'
            ? 'บัตรนี้เป็นบัตรอาหารตามสั่ง กรุณาสั่งกับพนักงานที่เคาน์เตอร์ หรือหยิบบัตรตู้สั่งอาหาร'
            : 'บัตรนี้เป็นบัตรตู้สั่งอาหาร กรุณาใช้บัตรอาหารตามสั่ง');
    }

    public static function typeLabel(?string $type): string
    {
        return self::TYPES[$type ?? 'kiosk'] ?? self::TYPES['kiosk'];
    }

    // ตอนส่งมอบ: หาออเดอร์ที่ยังเปิดอยู่จากรหัสบนบัตรที่ลูกค้ายื่นคืน
    public static function openOrderByCode(string $code): ?int
    {
        if (!preg_match('/^\d{4}$/D', $code)) return null;
        $stmt = getDB()->prepare('SELECT o.order_id FROM queue_cards c JOIN orders o ON o.queue_card_id = c.card_id
                                  WHERE c.code = ? AND o.status IN ' . self::OPEN . ' ORDER BY o.order_id LIMIT 1');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    // รหัสที่แคชเชียร์กรอกตรงกับบัตรที่ผูกกับออเดอร์นี้ไหม
    public static function codeMatchesOrder(int $orderId, string $code): bool
    {
        if (!preg_match('/^\d{4}$/D', $code)) return false;
        $stmt = getDB()->prepare('SELECT COUNT(*) FROM orders o JOIN queue_cards c ON c.card_id = o.queue_card_id
                                  WHERE o.order_id = ? AND c.code = ?');
        $stmt->execute([$orderId, $code]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private static function findByCode(string $code, bool $lock = false): ?array
    {
        if (!preg_match('/^\d{4}$/D', $code)) return null;
        $stmt = getDB()->prepare('SELECT * FROM queue_cards WHERE code = ? AND is_active = 1' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    private static function busyOrder(int $cardId, int $exceptOrder = 0): ?array
    {
        $stmt = getDB()->prepare('SELECT order_id, queue_no, channel FROM orders
                                  WHERE queue_card_id = ? AND order_id <> ? AND status IN ' . self::OPEN . ' LIMIT 1');
        $stmt->execute([$cardId, $exceptOrder]);
        return $stmt->fetch() ?: null;
    }

    // รายการบัตรพร้อมออเดอร์ที่ถืออยู่ (หน้าจัดการ)
    public static function all(): array
    {
        return getDB()->query(
            'SELECT c.*, o.order_id, o.queue_no, o.channel, o.status AS order_status
             FROM queue_cards c
             LEFT JOIN orders o ON o.queue_card_id = c.card_id AND o.status IN ' . self::OPEN . '
             ORDER BY c.card_no'
        )->fetchAll();
    }

    public static function add(int $count = 1, string $type = 'kiosk'): void
    {
        if (!isset(self::TYPES[$type])) throw new Exception('ประเภทบัตรไม่ถูกต้อง');
        $db = getDB();
        $next = (int) $db->query('SELECT COALESCE(MAX(card_no),0) FROM queue_cards')->fetchColumn();
        for ($i = 0; $i < max(1, min(50, $count)); $i++) {
            if (self::typeReady()) {
                $db->prepare('INSERT INTO queue_cards (card_no, card_type, code) VALUES (?, ?, ?)')->execute([++$next, $type, self::newCode()]);
            } else {
                $db->prepare('INSERT INTO queue_cards (card_no, code) VALUES (?, ?)')->execute([++$next, self::newCode()]);
            }
        }
    }

    public static function setActive(int $cardId, bool $on): void
    {
        getDB()->prepare('UPDATE queue_cards SET is_active = ? WHERE card_id = ?')->execute([$on ? 1 : 0, $cardId]);
    }

    // บัตรหาย: เปลี่ยนรหัสใหม่แล้วพิมพ์บัตรใหม่
    public static function regenerate(int $cardId): void
    {
        getDB()->prepare('UPDATE queue_cards SET code = ? WHERE card_id = ?')->execute([self::newCode(), $cardId]);
    }

    // ลูกค้าลืมคืนบัตร/ออเดอร์ค้าง: ปลดบัตรออกจากออเดอร์ (ออเดอร์ยังอยู่)
    public static function release(int $cardId): void
    {
        getDB()->prepare('UPDATE orders SET queue_card_id = NULL WHERE queue_card_id = ? AND status IN ' . self::OPEN)->execute([$cardId]);
    }

    private static function newCode(): string
    {
        $db = getDB();
        do {
            $code = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $s = $db->prepare('SELECT COUNT(*) FROM queue_cards WHERE code = ?');
            $s->execute([$code]);
        } while ((int) $s->fetchColumn() > 0);
        return $code;
    }
}
