<?php
// API ของตู้: สร้างออเดอร์ (place) เช็กตะกร้า (check) สถานะเมนู (menu) สถานะออเดอร์ (status)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/KioskOrder.php';
require_once __DIR__ . '/../classes/AppSetting.php';
require_once __DIR__ . '/../classes/PaymentGateway.php';
require_once __DIR__ . '/../classes/Member.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'gateway' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $order = KioskOrder::findByToken((int)($body['o'] ?? 0), (string)($body['t'] ?? ''));
        if (!$order || empty($_SESSION['kiosk_mode'])) out(['ok'=>false,'error'=>'ไม่พบออเดอร์'],403);
        out(['ok'=>true,'gateway'=>PaymentGateway::start((int)$order['order_id'])]);
    }
    // ลูกค้ากดเปลี่ยนจากสแกนจ่ายไปจ่ายเงินสด: เช็กผลชำระล่าสุดก่อน ถ้าจ่ายแล้วไม่เปลี่ยน
    if ($action === 'to_cash' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $order = KioskOrder::findByToken((int)($body['o'] ?? 0), (string)($body['t'] ?? ''));
        if (!$order || empty($_SESSION['kiosk_mode'])) out(['ok'=>false,'error'=>'ไม่พบออเดอร์'],403);
        if (PaymentGateway::provider()) {
            try { PaymentGateway::refresh((int)$order['order_id']); } catch (Throwable $e) { /* เช็กไม่ได้ ให้แคชเชียร์ตรวจต่อ */ }
            $order = KioskOrder::findByToken((int)$body['o'], (string)$body['t']);
        }
        if ((int)$order['is_paid'] === 1) out(['ok'=>true,'is_paid'=>true]);
        KioskOrder::setPayAtCounter((int)$order['order_id'], true);
        out(['ok'=>true,'is_paid'=>false]);
    }
    // ตรวจรหัสบัตรคิวก่อนเข้าหน้าเมนู (จำกัดการเดารหัส)
    if ($action === 'card' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['kiosk_mode'])) out(['ok' => false, 'error' => 'กรุณาเปิดหน้าตู้สั่งอาหารใหม่อีกครั้ง'], 403);
        $tries = array_filter($_SESSION['kiosk_card_fail'] ?? [], fn($t) => $t > time() - 300);
        if (count($tries) >= 8) out(['ok' => false, 'error' => 'กรอกผิดหลายครั้ง กรุณาเรียกพนักงาน หรือรอ 5 นาที'], 429);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        try {
            $card = QueueCard::check((string) ($body['code'] ?? ''));
        } catch (Exception $e) {
            $tries[] = time();
            $_SESSION['kiosk_card_fail'] = array_values($tries);
            out(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $_SESSION['kiosk_card_fail'] = [];
        out(['ok' => true, 'card_no' => (int) $card['card_no'], 'card_label' => QueueCard::label((int) $card['card_no'])]);
    }
    // ตรวจเบอร์สมาชิกก่อนเข้าหน้าเมนู: เบอร์ที่ไม่ได้สมัครไว้ไปต่อไม่ได้ (จำกัดการเดาเบอร์)
    if ($action === 'member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['kiosk_mode'])) out(['ok' => false, 'error' => 'กรุณาเปิดหน้าตู้สั่งอาหารใหม่อีกครั้ง'], 403);
        $tries = array_filter($_SESSION['kiosk_member_fail'] ?? [], fn($t) => $t > time() - 300);
        if (count($tries) >= 8) out(['ok' => false, 'error' => 'กรอกเบอร์ไม่ตรงหลายครั้ง กรุณาเรียกพนักงาน หรือรอ 5 นาที'], 429);
        // หน้าตู้แสดงชื่อสมาชิก จึงจำกัดจำนวนครั้งที่ค้นเบอร์ได้ ไม่ให้ไล่กรอกเบอร์คนอื่นเพื่อดูชื่อ
        $looks = array_filter($_SESSION['kiosk_member_look'] ?? [], fn($t) => $t > time() - 300);
        if (count($looks) >= 15) out(['ok' => false, 'error' => 'ค้นหาสมาชิกหลายครั้งเกินไป กรุณาเรียกพนักงาน หรือรอ 5 นาที'], 429);
        $looks[] = time();
        $_SESSION['kiosk_member_look'] = array_values($looks);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $mode = (string) ($body['mode'] ?? '');
        if (!in_array($mode, ['existing', 'register'], true)) out(['ok' => false, 'error' => 'ตัวเลือกสมาชิกไม่ถูกต้อง'], 422);
        try {
            $found = PhoneMember::check((string) ($body['phone'] ?? ''));
            $status = $found['status'];
        } catch (InvalidArgumentException $e) {
            out(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $error = null;
        if ($status === 'duplicate') $error = 'เบอร์นี้มีข้อมูลซ้ำ กรุณาให้พนักงานตรวจสอบ หรือเลือกไม่ใช่สมาชิก';
        elseif ($status === 'suspended') $error = 'ไม่สามารถใช้สมาชิกนี้ได้ กรุณาติดต่อพนักงาน หรือเลือกไม่ใช่สมาชิก';
        elseif ($mode === 'existing' && $status === 'none') $error = 'ไม่พบเบอร์นี้ในระบบสมาชิก กรุณาตรวจเบอร์ หรือเลือกสมัครสมาชิก';
        elseif ($mode === 'register' && $status === 'member') $error = 'เบอร์นี้เป็นสมาชิกอยู่แล้ว กรุณาย้อนกลับแล้วเลือก "สมาชิกเดิม"';
        if ($error) {
            if ($mode === 'existing') {
                $tries[] = time();
                $_SESSION['kiosk_member_fail'] = array_values($tries);
            }
            out(['ok' => false, 'error' => $error], 422);
        }
        $_SESSION['kiosk_member_fail'] = [];
        out(['ok' => true, 'name' => $mode === 'existing' ? $found['name'] : null]);
    }
    if ($action === 'place' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['kiosk_mode'])) {
            out(['ok' => false, 'error' => 'กรุณาเปิดหน้าตู้สั่งอาหารใหม่อีกครั้ง'], 403);
        }
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $requestId=(string)($body['request_id'] ?? '');
        if(!preg_match('/^[a-f0-9]{32}$/D',$requestId)) out(['ok'=>false,'error'=>'กรุณาโหลดหน้าตู้ใหม่'],422);
        $previous=$_SESSION['kiosk_last_response'] ?? null;
        if($previous && hash_equals($previous['id'],$requestId)) out($previous['response']);
        // กันการกดยืนยันซ้ำรัว ๆ จนเกิดออเดอร์ซ้ำ
        $last = $_SESSION['kiosk_last_place'] ?? 0;
        if (time() - $last < 4) {
            out(['ok' => false, 'error' => 'กำลังส่งออเดอร์ กรุณารอสักครู่'], 429);
        }


        $pay  = $body['pay'] ?? 'cash';
        if (!in_array($pay, ['cash', 'promptpay'], true)) {
            out(['ok' => false, 'error' => 'วิธีชำระเงินไม่ถูกต้อง'], 422);
        }

        $promptPayId = trim((string) AppSetting::get('promptpay_id', ''));
        $gw = PaymentGateway::provider();
        if ($pay === 'promptpay' && $gw && !PaymentGateway::configured()) {
            out(['ok'=>false,'error'=>'ระบบสแกนจ่ายยังตั้งค่าไม่ครบ กรุณาติดต่อพนักงาน'],422);
        }
        if ($pay === 'promptpay' && !$gw && !PaymentGateway::qrAvailable()) {
            out(['ok' => false, 'error' => 'ยังไม่เปิดสแกนจ่าย กรุณาเลือกจ่ายเงินสด'], 422);
        }

        $mode = (string)($body['member_mode'] ?? 'guest');
        if (!in_array($mode,['guest','existing','register'],true)) out(['ok'=>false,'error'=>'ตัวเลือกสมาชิกไม่ถูกต้อง'],422);
        if (!in_array($body['receipt'] ?? 'digital',['paper','digital'],true)) out(['ok'=>false,'error'=>'ตัวเลือกใบเสร็จไม่ถูกต้อง'],422);
        if ($pay === 'promptpay' && !$gw) KioskOrder::promptPayPayload($promptPayId,1.00);
        $note = $pay === 'cash' ? 'ตู้สั่งเอง — ชำระเงินสดที่เคาน์เตอร์' : 'ตู้สั่งเอง — สแกนจ่ายพร้อมเพย์';
        $result = KioskOrder::place((array) ($body['cart'] ?? []), (string) ($body['service'] ?? ''), 'kiosk', null, $note, null, $body);
        $_SESSION['kiosk_last_place'] = time();

        // ใช้แต้มเป็นส่วนลด (เฉพาะสมาชิกเดิม) แล้วคำนวณยอดใหม่ก่อนสร้าง QR
        $pointsUsed = 0;
        if (($body['use_points'] ?? false) === true && $mode === 'existing') {
            $mStmt = getDB()->prepare('SELECT member_id FROM orders WHERE order_id = :id');
            $mStmt->execute(['id' => $result['order_id']]);
            $mid = (int) $mStmt->fetchColumn();
            if ($mid) {
                try { $pointsUsed = Member::redeemPointsForOrder($mid, (int) $result['order_id']); }
                catch (Throwable $e) { $pointsUsed = 0; }
                if ($pointsUsed > 0) $result['total'] = Payment::orderTotal((int) $result['order_id']);
            }
        }

        $response = [
            'ok'          => true,
            'order_id'    => $result['order_id'],
            'token'       => $result['token'],
            'queue_label' => $result['queue_label'],
            'total'       => $result['total'],
            'points_used' => $pointsUsed,
            'service'     => $result['service'],
            'card_label'  => $result['card_label'],
            'slip_path'   => 'kiosk/slip.php?o=' . $result['order_id'] . '&t=' . $result['token'],
        ];
        if ($pay === 'promptpay') {
            if ($gw) $response['gateway_provider'] = $gw;
            else $response['promptpay'] = KioskOrder::promptPayPayload($promptPayId, $result['total']);
        }
        $_SESSION['kiosk_last_response']=['id'=>$requestId,'response'=>$response];
        out($response);
    }

    // เช็กตะกร้าก่อนไปหน้าชำระเงิน (ของพอไหม) และส่งสถานะเมนูล่าสุดกลับไป
    if ($action === 'check' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        try {
            KioskOrder::validateCart((array) ($body['cart'] ?? []));
            out(['ok' => true, 'menu' => MenuStock::availabilityMap()]);
        } catch (Exception $e) {
            out(['ok' => false, 'error' => $e->getMessage(), 'menu' => MenuStock::availabilityMap()]);
        }
    }

    if ($action === 'menu') {
        out(['ok' => true, 'menu' => MenuStock::availabilityMap()]);
    }

    if ($action === 'status') {
        $order = KioskOrder::findByToken((int) ($_GET['o'] ?? 0), (string) ($_GET['t'] ?? ''));
        if (!$order) out(['ok' => false, 'error' => 'ไม่พบออเดอร์'], 404);

        $gateway = [];
        if (PaymentGateway::provider()) {
            try { $gateway = PaymentGateway::refresh((int)$order['order_id']); }
            catch (Throwable $e) { $gateway = ['status'=>'checking']; }
            $order = KioskOrder::findByToken((int)$_GET['o'], (string)$_GET['t']);
        }

        $slip = getDB()->prepare(
            'SELECT status, reject_reason FROM payment_slips WHERE order_id = :id ORDER BY slip_id DESC LIMIT 1'
        );
        $slip->execute(['id' => $order['order_id']]);
        $lastSlip = $slip->fetch() ?: null;

        out([
            'ok'          => true,
            'is_paid'     => (int) $order['is_paid'] === 1,
            'gateway'     => $gateway,
            'status'      => $order['status'],
            'queue_label' => KioskOrder::queueLabel((int) $order['queue_no'], $order['channel'], (int)$order['order_id']),
            'card_label'  => QueueCard::label(isset($order['queue_card_no']) ? (int) $order['queue_card_no'] : null),
            'last_slip'   => $lastSlip,
        ]);
    }

    out(['ok' => false, 'error' => 'ไม่รู้จักคำสั่งนี้'], 400);
} catch (Exception $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 422);
}
