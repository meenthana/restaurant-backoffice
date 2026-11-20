<?php
// แคชเชียร์ ยกเลิก / ส่งมอบ ออเดอร์
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/AuditLog.php';

Auth::requireAnyRole(['owner','manager','cashier','staff']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
Security::verifyCsrf();

$orderId = (int) ($_POST['order_id'] ?? 0);
$do      = $_POST['do'] ?? '';
$order   = Order::find($orderId);

try {
    if (!$order) {
        throw new Exception('ไม่พบออเดอร์นี้');
    }
    if ($do === 'cancel') {
        if ((int) $order['is_paid'] === 1) throw new Exception('ออเดอร์นี้ชำระแล้ว ต้องทำผ่านการคืนเงิน');
        Order::changeStatus($orderId, 'cancelled');
        $msg = 'ยกเลิกออเดอร์เรียบร้อย';
    } elseif ($do === 'handover') {
        if ((int) $order['is_paid'] !== 1) throw new Exception('ออเดอร์นี้ยังไม่ได้ชำระเงิน');
        // ออเดอร์เก่าที่ครัวทำเสร็จแล้วแต่สถานะออเดอร์ยังค้างที่ active: ปรับเป็นพร้อมรับก่อน
        if ($order['status'] === 'active') Order::changeStatus($orderId, 'ready');
        Order::changeStatus($orderId, 'closed');
        $msg = 'ส่งมอบและปิดออเดอร์เรียบร้อย';
    } else {
        throw new Exception('คำสั่งไม่ถูกต้อง');
    }
    AuditLog::write(Auth::id(), 'kiosk_' . $do, 'order', $orderId, $msg);
    header('Location: index.php?msg=' . urlencode($msg));
} catch (Exception $e) {
    header('Location: index.php?error=' . urlencode($e->getMessage()));
}
exit;
