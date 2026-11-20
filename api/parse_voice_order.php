<?php
// API แปลงข้อความเสียงเป็นออเดอร์
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/VoiceOrderParser.php';
require_once __DIR__ . '/../classes/AppSetting.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$isStaff    = !empty($_SESSION['user_id']);
$isCustomer = !empty($_SESSION['member_id']);
$isKiosk    = !empty($_SESSION['kiosk_mode']);   // ตั้งค่าตอนเปิดหน้าตู้ Self-Service

if (!$isStaff && !$isCustomer && !$isKiosk) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบก่อน'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (AppSetting::get('voice_order_enabled', '1') !== '1') {
    echo json_encode(['ok' => false, 'error' => 'ระบบสั่งด้วยเสียงถูกปิดอยู่'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$transcript = trim((string) ($body['transcript'] ?? $_POST['transcript'] ?? ''));

if ($transcript === '') {
    echo json_encode(['ok' => false, 'error' => 'ไม่ได้ยินเสียงพูด กรุณาลองใหม่'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($transcript) > 500) {
    $transcript = mb_substr($transcript, 0, 500);
}

try {
    $parsed = VoiceOrderParser::parse($transcript);
    $logId = VoiceOrderParser::log(
        $isStaff ? 'staff' : ($isKiosk && !$isCustomer ? 'kiosk' : 'customer'),
        $isStaff ? (int) $_SESSION['user_id'] : null,
        $isCustomer ? (int) $_SESSION['member_id'] : null,
        $transcript,
        $parsed
    );

    echo json_encode([
        'ok'        => true,
        'log_id'    => $logId,
        'items'     => $parsed['items'],
        'unmatched' => $parsed['unmatched'],
        'unavailable' => $parsed['unavailable'],
        'normalized'=> $parsed['normalized'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'ตีความคำสั่งไม่สำเร็จ: ' . $e->getMessage()],
                     JSON_UNESCAPED_UNICODE);
}
