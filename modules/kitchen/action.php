<?php
// ครัวกด เริ่มทำ / พร้อม / ส่งมอบ
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/KitchenTicket.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireAnyRole(['owner','manager','kitchen','staff']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}
Security::verifyCsrf();
try {
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    $status   = (string) ($_POST['status'] ?? '');

    $stmt = getDB()->prepare('SELECT order_id FROM kitchen_tickets WHERE ticket_id = :id');
    $stmt->execute(['id' => $ticketId]);
    $orderId = (int) $stmt->fetchColumn();

    KitchenTicket::changeStatus($ticketId, $status, Auth::id());

    // ออเดอร์ที่จ่ายเงินล่วงหน้าแล้ว (ตู้สั่งเอง/ออนไลน์) พอส่งมอบครบ = ปิดออเดอร์และตัดสต๊อกทันที
    if ($status === 'served' && $orderId > 0) {
        KioskOrder::closeIfPrepaidAndServed($orderId);
    }
    header('Location: index.php');
} catch (Throwable $e) {
    header('Location: index.php?error=' . urlencode($e->getMessage()));
}
exit;
