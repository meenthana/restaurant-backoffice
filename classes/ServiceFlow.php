<?php
// Customer service metadata. Optional until migration_v2_9_service_flow.sql is installed.
require_once __DIR__ . '/../config/database.php';

class ServiceFlow
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready !== null) return self::$ready;
        $stmt = getDB()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('order_service_details','member_phone_accounts','menu_queue_rules')");
        return self::$ready = (int)$stmt->fetchColumn() === 3;
    }

    public static function vat(float $gross, float $rate = 7.0): array
    {
        if (!is_finite($gross) || $gross < 0 || !is_finite($rate) || $rate < 0) throw new InvalidArgumentException('ยอดภาษีไม่ถูกต้อง');
        $grossCents = (int)round($gross * 100);
        $vatCents = (int)round($grossCents * $rate / (100 + $rate));
        return ['gross' => $grossCents / 100, 'net' => ($grossCents - $vatCents) / 100, 'vat' => $vatCents / 100, 'rate' => $rate];
    }

    public static function phone(string $input): string
    {
        $phone = preg_replace('/[\s\-()]/u', '', trim($input));
        if (str_starts_with($phone, '+66')) $phone = '0' . substr($phone, 3);
        if (preg_match('/^66[689]\d{8}$/D', $phone)) $phone = '0' . substr($phone, 2);
        if (!preg_match('/^0[689]\d{8}$/D', $phone)) throw new InvalidArgumentException('กรุณากรอกเบอร์มือถือไทย 10 หลักให้ถูกต้อง');
        return $phone;
    }

    public static function queueType(int $dishes): string
    {
        if ($dishes < 0) throw new InvalidArgumentException('จำนวนอาหารไม่ถูกต้อง');
        return $dishes === 0 ? 'other' : ($dishes === 1 ? 'single' : 'multi');
    }

    public static function typeLabel(string $type): string
    {
        return ['single' => 'หนึ่งจาน', 'multi' => 'หลายจาน', 'other' => 'รายการอื่น'][$type] ?? 'ยังไม่ระบุ';
    }

    public static function details(int $orderId): ?array
    {
        if (!self::ready()) return null;
        $stmt = getDB()->prepare('SELECT * FROM order_service_details WHERE order_id=?');
        $stmt->execute([$orderId]);
        return $stmt->fetch() ?: null;
    }

    // Call within the transaction creating the order, after its items are added.
    public static function create(int $orderId, string $receipt = 'digital'): ?array
    {
        if (!self::ready()) return null;
        if (!in_array($receipt, ['paper', 'digital'], true)) throw new InvalidArgumentException('กรุณาเลือกการรับใบเสร็จ');
        $db = getDB();
        $stmt = $db->prepare('SELECT COALESCE(SUM(oi.quantity * COALESCE(r.counts_as_dish,1)),0) FROM order_items oi LEFT JOIN menu_queue_rules r ON r.menu_id=oi.menu_id WHERE oi.order_id=? AND oi.voided_at IS NULL');
        $stmt->execute([$orderId]);
        $dishes = (int)$stmt->fetchColumn();
        $type = self::queueType($dishes);
        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare('INSERT INTO order_service_details (order_id,queue_type,dish_count,receipt_preference,tracking_hash,pickup_hash) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$orderId,$type,$dishes,$receipt,hash('sha256',$token),'']);
        self::event($orderId, 'service_created', null, ['queue_type'=>$type,'receipt'=>$receipt]);
        return ['tracking_path'=>'kiosk/track.php?o='.$orderId.'&t='.$token, 'queue_type'=>$type, 'dish_count'=>$dishes];
    }

    public static function findTracking(int $orderId, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
        $details = self::details($orderId);
        if (!$details || !hash_equals($details['tracking_hash'], hash('sha256',$token))) return null;
        // A short post-sale window allows checking a receipt without permanent public links.
        $stmt = getDB()->prepare('SELECT * FROM orders WHERE order_id=? AND opened_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        return $order ? ['order'=>$order,'details'=>$details] : null;
    }

    public static function snapshotTax(int $orderId): void
    {
        if (!self::ready()) return;
        $paid=getDB()->prepare('SELECT is_paid FROM orders WHERE order_id=?');$paid->execute([$orderId]);
        if((int)$paid->fetchColumn()!==1)return;
        require_once __DIR__.'/Payment.php';
        $tax = self::vat(Payment::orderTotal($orderId));
        getDB()->prepare('UPDATE order_service_details SET gross_amount=?,net_amount=?,vat_amount=?,vat_rate=7.00,tax_recorded_at=NOW() WHERE order_id=? AND tax_recorded_at IS NULL')
            ->execute([$tax['gross'],$tax['net'],$tax['vat'],$orderId]);
    }

    public static function event(int $orderId, string $action, ?int $actor, array $data = []): void
    {
        // Strict for new service orders: an audit failure rolls back the associated operation.
        $stmt = getDB()->prepare("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,detail,ip_address) VALUES (?,?,'order',?,?,?)");
        $stmt->execute([$actor,$action,(string)$orderId,json_encode($data,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR'] ?? null]);
    }

    public static function assertPickup(array $order, array $details): void
    {
        require_once __DIR__.'/Auth.php';
        if (!in_array(Auth::role(), ['owner','manager','cashier','staff'], true)) throw new RuntimeException('ให้แคชเชียร์เป็นผู้ตรวจและส่งมอบอาหาร');
        if ($order['status'] !== 'ready' || !(int)$order['is_paid']) throw new RuntimeException('อาหารยังไม่พร้อมหรือยังชำระไม่ครบ');
        // ส่งมอบด้วยเลขคิว (ไม่ใช้รหัสรับอาหารแล้ว)
    }
}
