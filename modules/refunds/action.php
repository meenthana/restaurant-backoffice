<?php
// อนุมัติ/ปฏิเสธการคืนเงิน
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/Member.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';
Auth::requireAnyRole(['owner','manager']);
Security::verifyCsrf();
$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
$db = getDB();

try {
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT * FROM refund_requests WHERE refund_id=:id FOR UPDATE');
    $stmt->execute(['id'=>$id]);
    $refund = $stmt->fetch();
    if (!$refund || $refund['status'] !== 'pending') throw new Exception('คำขอนี้ถูกดำเนินการแล้ว');

    if ($action === 'reject') {
        $db->prepare("UPDATE refund_requests SET status='rejected',approved_by=:user_id,decided_at=NOW() WHERE refund_id=:id")
           ->execute(['user_id'=>Auth::id(),'id'=>$id]);
    } elseif ($action === 'approve') {
        $orderId = (int)$refund['order_id'];
        // คืนด้วยช่องทางเดียวกับที่ลูกค้าจ่าย เพื่อให้ยอดเงินสดในลิ้นชักตรง
        $m = $db->prepare("SELECT method FROM payments WHERE order_id=? AND transaction_type='payment' AND status='completed' ORDER BY payment_id DESC LIMIT 1");
        $m->execute([$orderId]);
        Payment::record($orderId, $m->fetchColumn() ?: 'transfer', (float)$refund['amount'], Auth::id(), 'REFUND-'.$id, 'refund');
        $db->prepare("UPDATE refund_requests SET status='completed',approved_by=:user_id,decided_at=NOW() WHERE refund_id=:id")
           ->execute(['user_id'=>Auth::id(),'id'=>$id]);
        $paymentStatus = $db->prepare('SELECT payment_status FROM orders WHERE order_id=:id');
        $paymentStatus->execute(['id'=>$orderId]);
        if ($paymentStatus->fetchColumn() === 'refunded') {
            // คืนเต็มจำนวนแล้ว ออเดอร์ที่ทำเสร็จรอรับก็ต้องปิดด้วย ไม่งั้นค้างอยู่ในคิว
            // คืนบางส่วน = ออเดอร์ยังทำต่อ / คืนเต็มจำนวน = ยกเลิกออกจากคิว คืนแต้มที่ใช้ และดึงแต้มที่ได้คืน
            KioskOrder::cancelRefunded($orderId, Auth::id(), $refund['reason']);
            Member::returnRedeemedPoints($orderId);
            Member::reversePointsForOrder($orderId, Auth::id());
        }
    } else {
        throw new Exception('คำสั่งไม่ถูกต้อง');
    }
    $db->commit();
    header('Location:index.php');
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    header('Location:index.php?error='.urlencode($e->getMessage()));
}
exit;
