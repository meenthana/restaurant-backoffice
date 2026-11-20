<?php
// สร้างออเดอร์อาหารตามสั่ง (DIY) จากหน้าแคชเชียร์ แล้วไปหน้ารับเงิน (จ่ายก่อนจึงเข้าครัว)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';
require_once __DIR__ . '/../../classes/AuditLog.php';
Auth::requireAnyRole(['owner','manager','cashier','staff']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
Security::verifyCsrf();

try {
    $body = json_decode((string) ($_POST['payload'] ?? ''), true) ?: [];
    $service = (string) ($body['service'] ?? 'dine_in');
    $result = KioskOrder::place((array) ($body['cart'] ?? []), $service, 'walkin', null,
        'สั่งกับพนักงาน (อาหารตามสั่ง)', null, ['card_code' => trim((string) ($body['card_code'] ?? ''))]);
    AuditLog::write(Auth::id(), 'diy_order_created', 'order', (int) $result['order_id'], 'สร้างออเดอร์อาหารตามสั่ง');
    echo json_encode(['ok' => true, 'order_id' => $result['order_id'], 'queue_label' => $result['queue_label'],
                      'card_label' => $result['card_label'], 'total' => $result['total']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
