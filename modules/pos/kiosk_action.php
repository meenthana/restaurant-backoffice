<?php
// แคชเชียร์ ยกเลิก / ส่งมอบ ออเดอร์
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/AuditLog.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';
require_once __DIR__ . '/../../classes/QueueCard.php';

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
        if (!KioskOrder::isReadyForPickup($orderId)) throw new Exception('ครัวยังทำออเดอร์นี้ไม่เสร็จ');
        // ออเดอร์ที่ผูกบัตรคิว ต้องกรอกรหัสบนบัตรที่ลูกค้ายื่นคืนให้ตรงก่อนส่งมอบ
        if (!empty($order['queue_card_id'])
            && !QueueCard::codeMatchesOrder($orderId, trim((string) ($_POST['card_code'] ?? '')))) {
            throw new Exception('รหัสบัตรไม่ตรงกับออเดอร์นี้ กรุณาตรวจบัตรของลูกค้าอีกครั้ง');
        }
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
