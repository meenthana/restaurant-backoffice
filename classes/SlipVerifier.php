<?php
// ตรวจสลิปโอนเงิน (โหมดสาธิต/ต่อ API) และกันสลิปซ้ำ
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/slip.php';
require_once __DIR__ . '/AppSetting.php';
require_once __DIR__ . '/Payment.php';

// SlipVerifier — ตรวจสอบสลิปโอนเงินของลูกค้า
class SlipVerifier
{
    private const MAX_BYTES     = 5 * 1024 * 1024; // 5 MB
    private const ALLOWED_MIME  = ['image/jpeg', 'image/png', 'image/webp'];
    private const ALLOWED_EXT   = ['jpg', 'jpeg', 'png', 'webp'];

    // โหมดที่ใช้จริงตอนนี้
    public static function mode(): string
    {
        $mode = AppSetting::get('slip_verify_mode', 'mock');
        return $mode === 'api' && SLIPOK_API_KEY !== '' && SLIPOK_BRANCH_ID !== '' ? 'api' : 'mock';
    }

    // จุดเข้าหลัก — รับสลิปจากลูกค้าแล้วตรวจสอบ
    public static function submit(int $orderId, array $file, array $manual = []): array
    {
        if (restaurant_env('RESTAURANT_ENV') === 'production' && self::mode() === 'mock') {
            throw new RuntimeException('ปิดการตรวจสลิปจำลองบนเว็บออนไลน์ กรุณาใช้ช่องทางชำระที่ร้านกำหนด');
        }
        $expected = Payment::orderTotal($orderId) - Payment::paidAmount($orderId);
        $expected = round(max(0, $expected), 2);

        if ($expected <= 0) {
            return self::fail($orderId, null, 'ออเดอร์นี้ชำระเงินครบแล้ว', $expected);
        }

        // --- ชั้นที่ 1 : ตรวจไฟล์ ---
        try {
            $savedPath = self::storeFile($file);
        } catch (Exception $e) {
            return self::fail($orderId, null, $e->getMessage(), $expected);
        }

        // --- ชั้นที่ 2 : อ่านข้อมูลจากสลิป ---
        try {
            $read = self::mode() === 'api'
                ? self::readViaApi($savedPath, $expected)
                : self::readViaMock($savedPath, $expected, $manual);
        } catch (Exception $e) {
            return self::fail($orderId, $savedPath, $e->getMessage(), $expected);
        }

        $transRef = $read['trans_ref'] ?? null;
        $amount   = isset($read['amount']) ? round((float) $read['amount'], 2) : null;

        if (!$transRef) {
            return self::fail($orderId, $savedPath, 'อ่านเลขอ้างอิงจากสลิปไม่ได้ กรุณาส่งรูปที่ชัดกว่านี้', $expected, $read);
        }

        // --- ชั้นที่ 3 : กันสลิปซ้ำ ---
        if (self::transRefUsed($transRef)) {
            $slipId = self::persist($orderId, $savedPath, $read, $expected, 'duplicate',
                'สลิปใบนี้เคยถูกใช้ชำระเงินไปแล้ว');
            return ['status' => 'duplicate', 'slip_id' => $slipId, 'data' => $read,
                    'message' => 'สลิปใบนี้เคยถูกใช้ชำระเงินไปแล้ว กรุณาใช้สลิปใบจริงของออเดอร์นี้'];
        }

        // --- ชั้นที่ 4 : ยอดเงิน ---
        $tolerance = (float) AppSetting::get('slip_amount_tolerance', '1.00');
        if ($amount === null) {
            return self::fail($orderId, $savedPath, 'อ่านยอดเงินจากสลิปไม่ได้', $expected, $read);
        }
        if (abs($amount - $expected) > $tolerance) {
            $msg = $amount < $expected
                ? sprintf('ยอดโอนน้อยกว่ายอดที่ต้องชำระ (โอน %s บาท / ต้องชำระ %s บาท)',
                          number_format($amount, 2), number_format($expected, 2))
                : sprintf('ยอดโอนมากกว่ายอดที่ต้องชำระ (โอน %s บาท / ต้องชำระ %s บาท) รอพนักงานตรวจสอบ',
                          number_format($amount, 2), number_format($expected, 2));
            $slipId = self::persist($orderId, $savedPath, $read, $expected, 'pending', $msg);
            return ['status' => 'pending', 'slip_id' => $slipId, 'data' => $read, 'message' => $msg];
        }

        // --- ชั้นที่ 5 : เวลา และบัญชีปลายทาง ---
        $maxAge = (int) AppSetting::get('slip_max_age_hours', '24');
        if (!empty($read['slip_datetime']) && $maxAge > 0) {
            $ts = strtotime($read['slip_datetime']);
            if ($ts !== false && $ts < time() - $maxAge * 3600) {
                $msg = 'สลิปมีอายุเกิน ' . $maxAge . ' ชั่วโมง รอพนักงานตรวจสอบ';
                $slipId = self::persist($orderId, $savedPath, $read, $expected, 'pending', $msg);
                return ['status' => 'pending', 'slip_id' => $slipId, 'data' => $read, 'message' => $msg];
            }
        }
        if (SHOP_RECEIVER_NAME !== '' && !empty($read['receiver_name'])
            && !self::nameLooksLike($read['receiver_name'], SHOP_RECEIVER_NAME)) {
            $msg = 'ชื่อบัญชีผู้รับไม่ตรงกับบัญชีของร้าน รอพนักงานตรวจสอบ';
            $slipId = self::persist($orderId, $savedPath, $read, $expected, 'pending', $msg);
            return ['status' => 'pending', 'slip_id' => $slipId, 'data' => $read, 'message' => $msg];
        }

        // --- ผ่านทุกชั้น : บันทึกการชำระเงิน ---
        $db = getDB();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();
        try {
            $slipId = self::persist($orderId, $savedPath, $read, $expected, 'verified', null);
            $paymentId = Payment::record($orderId, 'transfer_slip', $amount, null, $transRef);
            $db->prepare('UPDATE payment_slips SET payment_id = :p, verified_at = NOW() WHERE slip_id = :s')
               ->execute(['p' => $paymentId, 's' => $slipId]);
            if ($ownTransaction) $db->commit();
        } catch (Exception $e) {
            if ($ownTransaction && $db->inTransaction()) $db->rollBack();
            return self::fail($orderId, $savedPath, 'บันทึกการชำระเงินไม่สำเร็จ: ' . $e->getMessage(), $expected, $read);
        }

        return ['status' => 'verified', 'slip_id' => $slipId, 'data' => $read,
                'message' => 'ตรวจสอบสลิปผ่าน ชำระเงินเรียบร้อย'];
    }

    // ================================================================

    // โหมดจริง — ส่งไฟล์ไปให้ SlipOK อ่าน QR บนสลิปแล้วยิงถามธนาคาร
    private static function readViaApi(string $relPath, float $expected): array
    {
        $absPath = dirname(__DIR__) . '/' . $relPath;
        $url = str_replace('{branch_id}', SLIPOK_BRANCH_ID, SLIPOK_ENDPOINT);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['x-authorization: ' . SLIPOK_API_KEY],
            CURLOPT_POSTFIELDS     => [
                'files'  => new CURLFile($absPath),
                'amount' => (string) $expected,
                'log'    => 'true',
            ],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new Exception('เชื่อมต่อบริการตรวจสลิปไม่ได้: ' . $err);
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new Exception('บริการตรวจสลิปตอบกลับผิดรูปแบบ');
        }
        if (empty($json['success'])) {
            $reason = $json['message'] ?? 'ตรวจสอบสลิปไม่ผ่าน';
            throw new Exception($reason);
        }
        $d = $json['data'] ?? [];
        return [
            'trans_ref'     => $d['transRef'] ?? null,
            'amount'        => isset($d['amount']) ? (float) $d['amount'] : null,
            'sending_bank'  => $d['sendingBank'] ?? null,
            'sender_name'   => $d['sender']['displayName'] ?? ($d['sender']['name'] ?? null),
            'receiver_name' => $d['receiver']['displayName'] ?? ($d['receiver']['name'] ?? null),
            'slip_datetime' => isset($d['transDate'], $d['transTime'])
                ? self::thaiSlipDate($d['transDate'], $d['transTime'])
                : ($d['transTimestamp'] ?? null),
            'raw'           => $body,
            'source'        => 'api',
        ];
    }

    // โหมดสาธิต — ไม่เรียก API ภายนอก
    private static function readViaMock(string $relPath, float $expected, array $manual): array
    {
        $absPath = dirname(__DIR__) . '/' . $relPath;
        $hash = sha1_file($absPath);
        if ($hash === false) {
            throw new Exception('อ่านไฟล์สลิปไม่ได้');
        }
        $amount = isset($manual['amount']) && $manual['amount'] !== ''
            ? (float) $manual['amount']
            : $expected;

        $dt = trim((string) ($manual['slip_datetime'] ?? ''));
        $slipDatetime = $dt !== '' && strtotime($dt) !== false
            ? date('Y-m-d H:i:s', strtotime($dt))
            : date('Y-m-d H:i:s');

        return [
            'trans_ref'     => 'MOCK' . strtoupper(substr($hash, 0, 16)),
            'amount'        => $amount,
            'sending_bank'  => $manual['sending_bank'] ?? '014',
            'sender_name'   => $manual['sender_name'] ?? 'ลูกค้าทดสอบ',
            'receiver_name' => SHOP_RECEIVER_NAME !== '' ? SHOP_RECEIVER_NAME : 'บัญชีร้าน (สาธิต)',
            'slip_datetime' => $slipDatetime,
            'raw'           => json_encode(['mode' => 'mock', 'sha1' => $hash], JSON_UNESCAPED_UNICODE),
            'source'        => 'mock',
        ];
    }

    // ================================================================

    private static function storeFile(array $file): string
    {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new Exception('ไฟล์ใหญ่เกิน 5 MB');
        }
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            throw new Exception('รองรับเฉพาะไฟล์ JPG, PNG และ WEBP');
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || !in_array($info['mime'], self::ALLOWED_MIME, true)) {
            throw new Exception('ไฟล์ที่ส่งมาไม่ใช่รูปภาพที่ถูกต้อง');
        }

        $dir = dirname(__DIR__) . '/' . SLIP_UPLOAD_DIR . '/' . date('Y-m');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception('สร้างโฟลเดอร์เก็บสลิปไม่ได้');
        }
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest) && !@rename($file['tmp_name'], $dest)) {
            throw new Exception('บันทึกไฟล์สลิปไม่สำเร็จ');
        }
        @chmod($dest, 0644);
        return SLIP_UPLOAD_DIR . '/' . date('Y-m') . '/' . $name;
    }

    private static function transRefUsed(string $transRef): bool
    {
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM payment_slips
             WHERE trans_ref = :ref AND status IN ('verified','manual')"
        );
        $stmt->execute(['ref' => $transRef]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function persist(int $orderId, ?string $path, array $read,
                                    float $expected, string $status, ?string $reason): int
    {
        $db = getDB();
        // ถ้าเลขอ้างอิงชนกับแถวเดิม (UNIQUE) ให้เก็บเป็น NULL แทน แล้วคงสถานะ duplicate ไว้
        $transRef = $read['trans_ref'] ?? null;
        if ($transRef !== null) {
            $exists = $db->prepare('SELECT COUNT(*) FROM payment_slips WHERE trans_ref = :r');
            $exists->execute(['r' => $transRef]);
            if ((int) $exists->fetchColumn() > 0) {
                $transRef = null;
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO payment_slips
                (order_id, file_path, trans_ref, sending_bank, sender_name, receiver_name,
                 amount, slip_datetime, expected_amount, status, verify_source, reject_reason, raw_response)
             VALUES
                (:order_id, :file_path, :trans_ref, :bank, :sender, :receiver,
                 :amount, :slip_dt, :expected, :status, :source, :reason, :raw)'
        );
        $stmt->execute([
            'order_id'  => $orderId,
            'file_path' => $path,
            'trans_ref' => $transRef,
            'bank'      => $read['sending_bank'] ?? null,
            'sender'    => $read['sender_name'] ?? null,
            'receiver'  => $read['receiver_name'] ?? null,
            'amount'    => $read['amount'] ?? null,
            'slip_dt'   => $read['slip_datetime'] ?? null,
            'expected'  => $expected,
            'status'    => $status,
            'source'    => $read['source'] ?? self::mode(),
            'reason'    => $reason,
            'raw'       => $read['raw'] ?? null,
        ]);
        return (int) $db->lastInsertId();
    }

    private static function fail(int $orderId, ?string $path, string $message,
                                 float $expected, array $read = []): array
    {
        $slipId = null;
        try {
            $slipId = self::persist($orderId, $path, $read, $expected, 'rejected', $message);
        } catch (Exception $e) {
            // ถ้าบันทึกไม่ได้ก็ยังต้องตอบลูกค้าให้ได้
        }
        return ['status' => 'rejected', 'slip_id' => $slipId, 'data' => $read, 'message' => $message];
    }

    // ================================================================

    // สลิปที่รอพนักงานตัดสินใจ
    public static function pending(): array
    {
        return getDB()->query(
            "SELECT s.*, o.order_id, o.service_type, o.payment_status
             FROM payment_slips s
             JOIN orders o ON o.order_id = s.order_id
             WHERE s.status IN ('pending','rejected','duplicate')
             ORDER BY s.created_at DESC
             LIMIT 100"
        )->fetchAll();
    }

    public static function recent(int $limit = 50): array
    {
        $stmt = getDB()->prepare(
            'SELECT s.*, o.service_type FROM payment_slips s
             JOIN orders o ON o.order_id = s.order_id
             ORDER BY s.created_at DESC LIMIT :lim'
        );
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function find(int $slipId): ?array
    {
        $stmt = getDB()->prepare('SELECT * FROM payment_slips WHERE slip_id = :id');
        $stmt->execute(['id' => $slipId]);
        return $stmt->fetch() ?: null;
    }

    // พนักงานกดอนุมัติเอง — บันทึกการชำระเงินตามยอดที่พนักงานยืนยัน
    public static function approveManually(int $slipId, int $userId, ?float $amount = null): void
    {
        $slip = self::find($slipId);
        if (!$slip) throw new Exception('ไม่พบสลิปนี้');
        if (in_array($slip['status'], ['verified', 'manual'], true)) {
            throw new Exception('สลิปนี้ถูกอนุมัติไปแล้ว');
        }
        $pay = $amount !== null ? round($amount, 2) : (float) ($slip['amount'] ?? 0);
        if ($pay <= 0) throw new Exception('กรุณาระบุยอดเงินที่ได้รับจริง');

        $db = getDB();
        $db->beginTransaction();
        try {
            $paymentId = Payment::record((int) $slip['order_id'], 'transfer_slip', $pay, $userId,
                                         $slip['trans_ref'] ?: ('MANUAL-' . $slipId));
            $db->prepare(
                "UPDATE payment_slips
                 SET status='manual', payment_id=:p, amount=:a, verified_by=:u,
                     verified_at=NOW(), verify_source='manual', reject_reason=NULL
                 WHERE slip_id=:s"
            )->execute(['p' => $paymentId, 'a' => $pay, 'u' => $userId, 's' => $slipId]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function reject(int $slipId, int $userId, string $reason): void
    {
        $slip = self::find($slipId);
        if (!$slip) throw new Exception('ไม่พบสลิปนี้');
        if (in_array($slip['status'], ['verified', 'manual'], true)) {
            throw new Exception('สลิปนี้ชำระเงินไปแล้ว หากต้องการยกเลิกให้ทำผ่านการคืนเงิน');
        }
        getDB()->prepare(
            "UPDATE payment_slips SET status='rejected', reject_reason=:r,
             verified_by=:u, verified_at=NOW() WHERE slip_id=:s"
        )->execute(['r' => mb_substr($reason, 0, 255), 'u' => $userId, 's' => $slipId]);
    }

    // สลิปของออเดอร์หนึ่ง ๆ (ใช้แสดงในหน้าสถานะฝั่งลูกค้า)
    public static function forOrder(int $orderId): array
    {
        $stmt = getDB()->prepare(
            'SELECT * FROM payment_slips WHERE order_id = :id ORDER BY created_at DESC'
        );
        $stmt->execute(['id' => $orderId]);
        return $stmt->fetchAll();
    }

    // ================================================================

    // SlipOK ส่งวันที่มาเป็น พ.ศ. รูปแบบ YYYYMMDD และเวลา HHMMSS
    private static function thaiSlipDate(string $date, string $time): ?string
    {
        if (strlen($date) !== 8) return null;
        $year = (int) substr($date, 0, 4);
        if ($year > 2400) $year -= 543;          // พ.ศ. -> ค.ศ.
        $m = substr($date, 4, 2);
        $d = substr($date, 6, 2);
        $time = str_pad($time, 6, '0');
        return sprintf('%04d-%s-%s %s:%s:%s', $year, $m, $d,
            substr($time, 0, 2), substr($time, 2, 2), substr($time, 4, 2));
    }

    // ธนาคารปิดบังชื่อบัญชีบางส่วน เช่น "นาย ธนา ศ." จึงเทียบแบบยืดหยุ่น:
    private static function nameLooksLike(string $a, string $b): bool
    {
        $clean = function (string $s): string {
            $s = preg_replace('/(นาย|นาง|นางสาว|น\.ส\.|MR|MRS|MISS|MS)\.?/iu', '', $s);
            return preg_replace('/[\s\.\-x\*]/u', '', $s);
        };
        $a = $clean($a); $b = $clean($b);
        if ($a === '' || $b === '') return true;
        $short = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $long  = mb_strlen($a) <= mb_strlen($b) ? $b : $a;
        return mb_strpos($long, mb_substr($short, 0, min(4, mb_strlen($short)))) === 0;
    }
}
