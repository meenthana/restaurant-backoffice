<?php
// แคชเชียร์ให้ลูกค้าสแกนจ่าย: สร้าง QR พร้อมเพย์ผ่าน Omise/SCB (start) และเช็กผลชำระ (status)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/PaymentGateway.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireAnyRole(['owner','manager','cashier','staff']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$orderId = (int) ($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
$order = Order::find($orderId);
if (!$order) out(['ok' => false, 'error' => 'ไม่พบออเดอร์'], 404);
if (!PaymentGateway::provider() || !PaymentGateway::configured()) out(['ok' => false, 'error' => 'ยังไม่ได้เปิดใช้การสแกนจ่ายอัตโนมัติ'], 400);

try {
    if (($_GET['action'] ?? '') === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        Security::verifyCsrf();
        out(['ok' => true, 'gateway' => PaymentGateway::start($orderId)]);
    }
    if (($_GET['action'] ?? '') === 'status') {
        try { $gateway = PaymentGateway::refresh($orderId); }
        catch (Throwable $e) { $gateway = ['status' => 'checking']; }
        $order = Order::find($orderId);
        out(['ok' => true, 'is_paid' => (int) $order['is_paid'] === 1, 'gateway' => $gateway]);
    }
    out(['ok' => false, 'error' => 'ไม่รู้จักคำสั่งนี้'], 400);
} catch (Throwable $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 422);
}
