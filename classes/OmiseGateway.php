<?php
require_once __DIR__ . '/../config/omise.php';
require_once __DIR__ . '/Payment.php';

final class OmiseGateway
{
    public static function configured(): bool
    {
        return OMISE_ENABLED && in_array(OMISE_MODE, ['test', 'live'], true)
            && str_starts_with(OMISE_SECRET_KEY, 'skey_' . OMISE_MODE . '_')
            && OMISE_WEBHOOK_SECRET !== '' && extension_loaded('curl');
    }

    public static function request(string $path, ?array $body = null): array
    {
        if (!self::configured()) throw new RuntimeException('Omise ยังตั้งค่าไม่ครบ');
        $ch = curl_init('https://api.omise.co' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => OMISE_SECRET_KEY . ':',
            CURLOPT_HTTPHEADER => ['Omise-Version: 2019-05-29'], CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($body)]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            // Never retry charge creation automatically: a timeout can occur after Omise accepted it.
            throw new RuntimeException('ติดต่อ Omise ไม่สำเร็จ กรุณาให้พนักงานตรวจรายการก่อนสร้างการชำระใหม่');
        }
        return $data;
    }

    public static function row(int $id): ?array
    {
        $s = getDB()->prepare('SELECT * FROM gateway_payments WHERE order_id=?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function start(int $id): array
    {
        if (!self::configured()) throw new RuntimeException('Omise ยังตั้งค่าไม่ครบ');
        $db = getDB();
        $db->beginTransaction();
        try {
            $s = $db->prepare('SELECT * FROM orders WHERE order_id=? FOR UPDATE');
            $s->execute([$id]); $o = $s->fetch();
            if (!$o || $o['status'] !== 'pending' || (int)$o['is_paid'] || Payment::paidAmount($id) > 0)
                throw new RuntimeException('ออเดอร์นี้ไม่พร้อมรับชำระ');
            $existing = self::row($id);
            if ($existing) { $db->commit(); return self::publicState($existing); }
            $amount = (int)round(Payment::orderTotal($id) * 100);
            if ($amount < 2000 || $amount > 15000000) throw new RuntimeException('PromptPay รองรับยอด 20 ถึง 150,000 บาท');
            $key = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO gateway_payments(order_id,request_key,amount_satang,livemode) VALUES(?,?,?,?)')
                ->execute([$id, $key, $amount, OMISE_MODE === 'live' ? 1 : 0]);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        try {
            $charge = self::request('/charges', ['amount' => $amount, 'currency' => 'THB',
                'source' => ['type' => 'promptpay'], 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600),
                'metadata' => ['restaurant_order_id' => (string)$id, 'request_key' => $key],
                'description' => 'Restaurant order ' . $id]);
            self::acceptCharge($id, $charge);
        } catch (Throwable $e) {
            $db->prepare("UPDATE gateway_payments SET status='unknown' WHERE order_id=? AND charge_id IS NULL AND status='creating'")->execute([$id]);
            self::log($id, 'create_uncertain', 'Check Omise dashboard; do not create another charge');
            throw $e;
        }
        return self::publicState(self::row($id));
    }

    public static function matches(array $row, array $charge): bool
    {
        return ($charge['object'] ?? '') === 'charge'
            && preg_match('/^chrg_(?:test_)?[a-zA-Z0-9]+$/D', $charge['id'] ?? '')
            && (int)($charge['amount'] ?? -1) === (int)$row['amount_satang']
            && strtolower($charge['currency'] ?? '') === 'thb'
            && isset($charge['livemode']) && (bool)$charge['livemode'] === (bool)$row['livemode']
            && ($charge['source']['type'] ?? '') === 'promptpay'
            && (string)($charge['metadata']['restaurant_order_id'] ?? '') === (string)$row['order_id']
            && hash_equals($row['request_key'], (string)($charge['metadata']['request_key'] ?? ''))
            && (!$row['charge_id'] || $row['charge_id'] === $charge['id']);
    }

    // Only call with an authenticated Omise API response, never the incoming webhook body.
    public static function acceptCharge(int $id, array $charge): void
    {
        $db = getDB(); $db->beginTransaction();
        try {
            $s = $db->prepare('SELECT * FROM orders WHERE order_id=? FOR UPDATE');
            $s->execute([$id]); $order = $s->fetch();
            $s = $db->prepare('SELECT * FROM gateway_payments WHERE order_id=? FOR UPDATE');
            $s->execute([$id]); $row = $s->fetch();
            if (!$order || !$row || !self::matches($row, $charge)) throw new RuntimeException('ข้อมูลรายการชำระไม่ตรงกัน');
            if ($row['payment_id'] || $row['status'] === 'review') { $db->commit(); return; }
            $status = $charge['status'] ?? 'unknown';
            $paymentId = null;
            if ($status === 'successful' && ($charge['paid'] ?? false) === true) {
                $total = (int)round(Payment::orderTotal($id) * 100);
                if ($order['status'] !== 'pending' || Payment::paidAmount($id) > 0 || $total !== (int)$row['amount_satang']) {
                    $status = 'review'; // Money received, but cancelled/changed/already-paid order must not go to kitchen.
                    self::log($id, 'payment_review', 'Successful charge requires staff reconciliation: ' . $charge['id']);
                } else {
                    // Save the verified provider ID under the same order lock before Payment's guard runs.
                    $db->prepare('UPDATE gateway_payments SET charge_id=? WHERE order_id=?')->execute([$charge['id'],$id]);
                    $paymentId = Payment::record($id, 'transfer', $row['amount_satang'] / 100, null, 'omise:' . $charge['id']);
                    self::log($id, 'payment_recorded', $charge['id']);
                }
            } elseif (!in_array($status, ['pending', 'failed', 'expired'], true)) $status = 'unknown';
            $qr = $charge['source']['scannable_code']['image']['download_uri'] ?? null;
            if ($qr && (parse_url($qr, PHP_URL_SCHEME) !== 'https' || parse_url($qr, PHP_URL_HOST) !== 'api.omise.co')) $qr = null;
            $db->prepare('UPDATE gateway_payments SET charge_id=?,status=?,qr_url=?,expires_at=?,payment_id=?,checked_at=NOW() WHERE order_id=?')
                ->execute([$charge['id'], $status, $qr, $charge['expires_at'] ?? null, $paymentId, $id]);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    public static function refresh(int $id): array
    {
        $row = self::row($id);
        if (!$row) return [];
        // สร้างรายการกับ Omise แล้วแต่บันทึกรหัสไม่สำเร็จ: ค้นรายการเดิมด้วย request_key แทนการสร้างใหม่
        if (!$row['charge_id'] && in_array($row['status'], ['creating', 'unknown'], true)) {
            $s = getDB()->prepare('UPDATE gateway_payments SET checked_at=NOW() WHERE order_id=? AND (checked_at IS NULL OR checked_at < DATE_SUB(NOW(), INTERVAL 15 SECOND))');
            $s->execute([$id]);
            if ($s->rowCount() && ($charge = self::findByRequestKey($row['request_key']))) {
                self::acceptCharge($id, $charge);
                $row = self::row($id);
                return self::publicState($row);
            }
        }
        if ($row['charge_id'] && !in_array($row['status'], ['successful','review','failed','expired'], true)) {
            $s = getDB()->prepare('UPDATE gateway_payments SET checked_at=NOW() WHERE order_id=? AND (checked_at IS NULL OR checked_at < DATE_SUB(NOW(), INTERVAL 15 SECOND))');
            $s->execute([$id]);
            if ($s->rowCount()) {
                self::acceptCharge($id, self::request('/charges/' . rawurlencode($row['charge_id'])));
                $row = self::row($id);
            }
        }
        return self::publicState($row);
    }

    // แคชเชียร์จะรับเงินช่องทางอื่น: เช็กผลล่าสุดกับ Omise ก่อน ถ้ายังไม่จ่ายให้ยกเลิก QR นี้ (กันรับเงินซ้ำ)
    public static function releaseForCounter(int $id, ?int $userId): string
    {
        $row = self::row($id);
        if (!$row || in_array($row['status'], ['failed', 'expired'], true)) return 'none';
        $paid = getDB()->prepare('SELECT is_paid FROM orders WHERE order_id=?');
        $paid->execute([$id]);
        if ($row['payment_id'] || $row['status'] === 'successful') return (int)$paid->fetchColumn() ? 'paid' : 'none'; // จ่ายแล้วแต่คืนเงินไปแล้ว
        if ($row['status'] === 'review') throw new RuntimeException('รายการ Omise ของออเดอร์นี้รอตรวจสอบ กรุณาดูที่หน้า "รายการ Omise" ก่อนรับเงิน');
        // QR หมดอายุที่ 10 นาที (คิดเวลาในฐานข้อมูล กันเขตเวลาไม่ตรงกับ PHP)
        $s = getDB()->prepare('SELECT created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) FROM gateway_payments WHERE order_id=?');
        $s->execute([$id]);
        $old = (bool)$s->fetchColumn();
        if (self::configured()) try {
            getDB()->prepare('UPDATE gateway_payments SET checked_at=NULL WHERE order_id=?')->execute([$id]);
            self::refresh($id);
        } catch (Throwable $e) {
            if (!$old) throw new RuntimeException('ตรวจผลกับ Omise ไม่ได้ กรุณาลองใหม่อีกครั้ง (กันรับเงินซ้ำ)');
        }
        $row = self::row($id);
        if ($row['payment_id'] || $row['status'] === 'successful') return 'paid';
        if ($row['status'] === 'review') throw new RuntimeException('รายการ Omise ของออเดอร์นี้รอตรวจสอบ กรุณาดูที่หน้า "รายการ Omise" ก่อนรับเงิน');
        getDB()->prepare("UPDATE gateway_payments SET status='expired' WHERE order_id=? AND payment_id IS NULL AND status NOT IN ('successful','review')")->execute([$id]);
        self::log($id, 'counter_override', 'ยกเลิก QR เพื่อรับเงินที่เคาน์เตอร์ ผู้ใช้ ' . (int)$userId);
        return 'released';
    }

    private static function findByRequestKey(string $key): ?array
    {
        $list = self::request('/charges?order=reverse_chronological&limit=50');
        foreach ($list['data'] ?? [] as $c) {
            if (hash_equals($key, (string) ($c['metadata']['request_key'] ?? ''))) return $c;
        }
        return null;
    }

    public static function publicState(array $row): array
    {
        return ['provider'=>'omise', 'status'=>$row['status'], 'qr_url'=>$row['qr_url'],
            'expires_at'=>$row['expires_at'], 'test'=>!(bool)$row['livemode']];
    }

    public static function signature(string $raw, string $timestamp, string $signatures, string $secret, ?int $now=null): bool
    {
        $key = base64_decode($secret, true);
        if (!$key || !ctype_digit($timestamp) || abs(($now ?? time()) - (int)$timestamp) > 300) return false;
        $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $key);
        foreach (explode(',', $signatures) as $sig) if (hash_equals($expected, trim($sig))) return true;
        return false;
    }

    private static function log(int $id, string $type, string $detail): void
    {
        getDB()->prepare('INSERT INTO gateway_events(order_id,event_type,detail) VALUES(?,?,?)')->execute([$id,$type,$detail]);
    }
}
