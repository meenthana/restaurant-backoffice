<?php
// เปลี่ยนสถานะออเดอร์จากปุ่มในหน้ารายละเอียด
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/Auth.php';
Auth::requireLogin();
require_once __DIR__.'/../../classes/Security.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
Security::verifyCsrf();

$orderId   = (int) ($_POST['order_id'] ?? 0);
$newStatus = $_POST['new_status'] ?? '';

try {
    Order::changeStatus($orderId, $newStatus);
    header('Location: view.php?id=' . $orderId);
} catch (Exception $e) {
    header('Location: view.php?id=' . $orderId . '&error=' . urlencode($e->getMessage()));
}
exit;
