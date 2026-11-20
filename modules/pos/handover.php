<?php
// ข้อมูลออเดอร์สำหรับหน้าต่างยืนยันส่งมอบ (ค้นจากเลขออเดอร์ หรือรหัสบนบัตรคิว)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';
require_once __DIR__ . '/../../classes/QueueCard.php';
require_once __DIR__ . '/../../classes/Payment.php';
Auth::requireAnyRole(['owner','manager','cashier','staff']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$verified = false;
if (isset($_GET['card'])) {
    $orderId = QueueCard::openOrderByCode((string) $_GET['card']);
    if (!$orderId) out(['ok' => false, 'error' => 'ไม่พบออเดอร์ที่ผูกกับรหัสบัตรนี้'], 404);
    $verified = true;
} else {
    $orderId = (int) ($_GET['order_id'] ?? 0);
}
$order = $orderId ? Order::find($orderId) : null;
if (!$order) out(['ok' => false, 'error' => 'ไม่พบออเดอร์นี้'], 404);

$db = getDB();
$stmt = $db->prepare('SELECT oi.order_item_id, oi.quantity, oi.item_note, m.name
                      FROM order_items oi JOIN menu m ON m.menu_id = oi.menu_id
                      WHERE oi.order_id = ? AND oi.voided_at IS NULL ORDER BY oi.order_item_id');
$stmt->execute([$orderId]);
$addonStmt = $db->prepare('SELECT addon_name FROM order_item_addons WHERE order_item_id = ? ORDER BY order_item_addon_id');
$items = [];
foreach ($stmt->fetchAll() as $row) {
    $addonStmt->execute([(int) $row['order_item_id']]);
    $items[] = [
        'name'   => $row['name'],
        'qty'    => (int) $row['quantity'],
        'addons' => $addonStmt->fetchAll(PDO::FETCH_COLUMN),
        'note'   => (string) ($row['item_note'] ?? ''),
    ];
}

$member = null;
if (!empty($order['member_id'])) {
    $m = $db->prepare('SELECT display_name FROM members WHERE member_id = ?');
    $m->execute([(int) $order['member_id']]);
    $member = $m->fetchColumn() ?: null;
}

$statusLabel = ['pending' => 'รอเข้าครัว', 'active' => 'ครัวกำลังทำ', 'ready' => 'พร้อมรับ',
                'closed' => 'ส่งมอบแล้ว', 'cancelled' => 'ยกเลิกแล้ว', 'no_show' => 'ไม่มารับ'];
out([
    'ok'           => true,
    'order_id'     => $orderId,
    'queue_label'  => !empty($order['queue_no']) ? KioskOrder::queueLabel((int) $order['queue_no'], $order['channel']) : '#' . $orderId,
    'card_label'   => QueueCard::label(isset($order['queue_card_no']) ? (int) $order['queue_card_no'] : null),
    'needs_code'   => !empty($order['queue_card_id']) && !$verified,
    'verified'     => $verified,
    'channel'      => ['kiosk' => 'ตู้สั่งเอง', 'online' => 'ออนไลน์ (LINE)', 'walkin' => 'หน้าร้าน'][$order['channel']] ?? $order['channel'],
    'service'      => $order['service_type'] === 'takeaway' ? 'กลับบ้าน' : 'ทานที่ร้าน',
    'member'       => $member,
    'items'        => $items,
    'total'        => Payment::orderTotal($orderId),
    'ready'        => KioskOrder::isReadyForPickup($orderId),
    'status_label' => $statusLabel[$order['status']] ?? $order['status'],
]);
