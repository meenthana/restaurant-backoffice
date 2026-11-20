<?php
// SCB QR Payment: สร้าง Thai QR (Tag 30) แล้วถามธนาคารว่าจ่ายแล้วหรือยัง (Payment Inquiry)
require_once __DIR__ . '/../config/scb.php';
require_once __DIR__ . '/Payment.php';

final class ScbGateway
{
    public const QR_MINUTES = 10;

    public static function configured(): bool
    {
        return SCB_ENABLED && SCB_API_KEY !== '' && SCB_API_SECRET !== '' && SCB_BILLER_ID !== ''
            && preg_match('/^[A-Z0-9]{2,6}$/D', SCB_REF3_PREFIX) && extension_loaded('curl');
    }

    private static function call(string $method, string $path, ?array $body, ?string $token, array $query = []): array
    {
        $url = rtrim(SCB_BASE_URL, '/') . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Content-Type: application/json', 'accept-language: EN',
            'requestUId: ' . bin2hex(random_bytes(16)), 'resourceOwnerId: ' . SCB_API_KEY];
        if ($token) $headers[] = 'authorization: Bearer ' . $token;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('ติดต่อ SCB ไม่ได้: ' . $err);
        $data = json_decode($raw, true);
        return ['http' => $code, 'body' => is_array($data) ? $data : []];
    }

    private static function token(): string
    {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['scb_token'])
            && $_SESSION['scb_token']['exp'] > time() + 60) return $_SESSION['scb_token']['v'];
        $r = self::call('POST', '/v1/oauth/token', ['applicationKey' => SCB_API_KEY, 'applicationSecret' => SCB_API_SECRET], null);
        $t = $r['body']['data']['accessToken'] ?? '';
        if ($r['http'] !== 200 || $t === '') {
            throw new RuntimeException('ขอ Token จาก SCB ไม่สำเร็จ: ' . ($r['body']['status']['description'] ?? ('HTTP ' . $r['http'])));
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['scb_token'] = ['v' => $t, 'exp' => time() + (int) ($r['body']['data']['expiresIn'] ?? 900)];
        }
        return $t;
    }

    public static function row(int $id): ?array
    {
        $s = getDB()->prepare('SELECT * FROM scb_payments WHERE order_id=?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    // สร้าง QR ครั้งเดียวต่อออเดอร์ เรียกซ้ำจะได้ QR เดิม
    public static function start(int $id): array
    {
        if (!self::configured()) throw new RuntimeException('SCB ยังตั้งค่าไม่ครบ');
        $db = getDB();
        $db->beginTransaction();
        try {
            $s = $db->prepare('SELECT * FROM orders WHERE order_id=? FOR UPDATE');
            $s->execute([$id]); $o = $s->fetch();
            if (!$o || $o['status'] !== 'pending' || (int) $o['is_paid'] || Payment::paidAmount($id) > 0)
                throw new RuntimeException('ออเดอร์นี้ไม่พร้อมรับชำระ');
            $existing = self::row($id);
            if ($existing && $existing['qr_raw']) { $db->commit(); return self::publicState($existing); }
            $amount = round(Payment::orderTotal($id), 2);
            if ($amount < 1) throw new RuntimeException('ยอดชำระไม่ถูกต้อง');
            $ref1 = 'K' . $id . strtoupper(bin2hex(random_bytes(3)));
            $ref2 = 'ORDER' . $id;
            $ref3 = SCB_REF3_PREFIX . date('ymdHis');
            if ($existing) {
                $db->prepare('UPDATE scb_payments SET ref1=?,ref2=?,ref3=?,amount=?,status=? WHERE order_id=?')
                   ->execute([$ref1, $ref2, $ref3, $amount, 'creating', $id]);
            } else {
                $db->prepare('INSERT INTO scb_payments(order_id,ref1,ref2,ref3,amount,livemode) VALUES(?,?,?,?,?,?)')
                   ->execute([$id, $ref1, $ref2, $ref3, $amount, SCB_MODE === 'live' ? 1 : 0]);
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }

        $r = self::call('POST', '/v1/payment/qrcode/create', [
            'qrType' => 'PP', 'ppType' => 'BILLERID', 'ppId' => SCB_BILLER_ID,
            'amount' => number_format($amount, 2, '.', ''), 'ref1' => $ref1, 'ref2' => $ref2, 'ref3' => $ref3,
        ], self::token());
        $qr = $r['body']['data']['qrRawData'] ?? '';
        if ($r['http'] !== 200 || $qr === '') {
            self::log($id, 'qr_failed', (string) ($r['body']['status']['description'] ?? ('HTTP ' . $r['http'])));
            throw new RuntimeException('สร้าง QR กับ SCB ไม่สำเร็จ: ' . ($r['body']['status']['description'] ?? ('HTTP ' . $r['http'])));
        }
        $db->prepare("UPDATE scb_payments SET qr_raw=?, status='pending' WHERE order_id=?")->execute([$qr, $id]);
        self::log($id, 'qr_created', $ref1);
        return self::publicState(self::row($id));
    }

    // ถามธนาคารว่ามีรายการโอนของออเดอร์นี้แล้วหรือยัง (เว้นอย่างน้อย 5 วินาทีต่อครั้ง)
    public static function refresh(int $id, bool $force = false): array
    {
        $row = self::row($id);
        if (!$row) return [];
        if ($row['status'] !== 'pending') return self::publicState($row);
        if (strtotime($row['created_at']) < time() - (self::QR_MINUTES + 5) * 60) {
            getDB()->prepare("UPDATE scb_payments SET status='expired' WHERE order_id=? AND status='pending'")->execute([$id]);
            return self::publicState(self::row($id));
        }
        $s = getDB()->prepare('UPDATE scb_payments SET checked_at=NOW() WHERE order_id=? AND (checked_at IS NULL OR checked_at < DATE_SUB(NOW(), INTERVAL 5 SECOND))');
        $s->execute([$id]);
        if (!$s->rowCount() && !$force) return self::publicState($row);

        $r = self::call('GET', '/v1/payment/billpayment/inquiry', null, self::token(), [
            'eventCode' => '00300100',
            'transactionDate' => (new DateTime('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d'),
            'billerId' => SCB_BILLER_ID,
            'reference1' => $row['ref1'],
            'reference2' => $row['ref2'],
        ]);
        $found = null;
        foreach (($r['http'] === 200 ? ($r['body']['data'] ?? []) : []) as $t) {
            if (($t['billPaymentRef1'] ?? '') === $row['ref1']) { $found = $t; break; }
        }
        if ($found) self::accept($id, $found);
        return self::publicState(self::row($id));
    }

    // ใช้เฉพาะข้อมูลที่ได้จาก Inquiry API ของ SCB เท่านั้น ไม่เชื่อข้อมูลจาก callback ตรงๆ
    private static function accept(int $id, array $t): void
    {
        $db = getDB(); $db->beginTransaction();
        try {
            $s = $db->prepare('SELECT * FROM orders WHERE order_id=? FOR UPDATE');
            $s->execute([$id]); $order = $s->fetch();
            $s = $db->prepare('SELECT * FROM scb_payments WHERE order_id=? FOR UPDATE');
            $s->execute([$id]); $row = $s->fetch();
            if (!$order || !$row || $row['status'] !== 'pending') { $db->commit(); return; }
            $txn = (string) ($t['transactionId'] ?? '');
            $paid = round((float) ($t['amount'] ?? 0), 2);
            if ($txn === '' || abs($paid - (float) $row['amount']) > 0.001) {
                $db->prepare("UPDATE scb_payments SET status='review', transaction_id=? WHERE order_id=?")->execute([$txn ?: null, $id]);
                self::log($id, 'payment_review', 'ยอดโอนไม่ตรง ' . $paid);
                $db->commit(); return;
            }
            $total = round(Payment::orderTotal($id), 2);
            if ($order['status'] !== 'pending' || (int) $order['is_paid'] || abs($total - (float) $row['amount']) > 0.001) {
                $db->prepare("UPDATE scb_payments SET status='review', transaction_id=? WHERE order_id=?")->execute([$txn, $id]);
                self::log($id, 'payment_review', 'ได้รับเงินแล้วแต่ออเดอร์เปลี่ยน/ชำระไปแล้ว ' . $txn);
                $db->commit(); return;
            }
            $paymentId = Payment::record($id, 'transfer', (float) $row['amount'], null, 'scb:' . $txn);
            $db->prepare("UPDATE scb_payments SET status='successful', transaction_id=?, payment_id=? WHERE order_id=?")->execute([$txn, $paymentId, $id]);
            self::log($id, 'payment_recorded', $txn);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    public static function findByRef1(string $ref1): ?array
    {
        $s = getDB()->prepare('SELECT * FROM scb_payments WHERE ref1=?');
        $s->execute([$ref1]);
        return $s->fetch() ?: null;
    }

    public static function publicState(array $row): array
    {
        return ['provider' => 'scb', 'status' => $row['status'], 'qr_raw' => $row['qr_raw'],
            'test' => !(bool) $row['livemode']];
    }

    private static function log(int $id, string $type, string $detail): void
    {
        try {
            getDB()->prepare('INSERT INTO gateway_events(order_id,event_type,detail) VALUES(?,?,?)')
                   ->execute([$id, 'scb_' . $type, mb_substr($detail, 0, 255)]);
        } catch (Throwable $e) { /* ไม่มีตาราง log ไม่เป็นไร */ }
    }
}
