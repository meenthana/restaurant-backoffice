<?php
// บันทึกออเดอร์หน้าร้าน แล้วไปหน้าคิดเงิน
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/OrderItem.php';
require_once __DIR__ . '/../../classes/TableSession.php';
require_once __DIR__ . '/../../classes/KitchenTicket.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Menu.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';
require_once __DIR__ . '/../../classes/MenuStock.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: create.php');
    exit;
}

$note = trim($_POST['note'] ?? '');
$qtyByMenu = $_POST['qty'] ?? [];
$addonsByMenu = $_POST['addons'] ?? []; // [menu_id => [addon_id, ...]]
$tableSessionId = (int) ($_POST['table_session_id'] ?? 0);
$tableSession = $tableSessionId > 0 ? TableSession::find($tableSessionId) : null;

if ($tableSessionId > 0 && (!$tableSession || $tableSession['status'] !== 'open')) {
    die('รอบการใช้งานโต๊ะนี้ไม่พร้อมรับออเดอร์');
}

// กรองเอาเฉพาะรายการที่จำนวน > 0
$items = array_filter($qtyByMenu, fn($q) => (int) $q > 0);

if (empty($items)) {
    die('กรุณาเลือกอย่างน้อย 1 เมนู');
}

// เมนูที่ปิดขาย หรือวัตถุดิบหลักไม่พอ ให้กลับไปแก้ที่หน้าเลือกเมนู
$lines = [];
$names = [];
foreach ($items as $menuId => $qty) {
    $menu = Menu::find((int) $menuId);
    if ($menu && ($reason = Menu::unavailableReason($menu))) {
        header('Location: create.php?error=' . urlencode('"' . $menu['name'] . '" ' . $reason));
        exit;
    }
    $lines[] = ['menu_id' => (int) $menuId, 'qty' => (int) $qty, 'addons' => array_map('intval', (array) ($addonsByMenu[$menuId] ?? []))];
    $names[(int) $menuId] = $menu['name'] ?? '';
}
if ($problems = MenuStock::checkCart($lines, $names)) {
    header('Location: create.php?error=' . urlencode(implode(' / ', $problems)));
    exit;
}

$db = getDB();
$db->beginTransaction();

try {
    $orderNote = $tableSession
        ? 'โต๊ะ ' . $tableSession['table_codes'] . ($note !== '' ? ' — ' . $note : '')
        : ($note ?: null);
    $orderId = Order::create($orderNote, 'walkin', null, 'dine_in', $tableSessionId ?: null);
    KioskOrder::assignQueue($orderId);   // เลขคิวรายวัน ใช้เรียกลูกค้ารับอาหาร

    $newItemIds = [];
    foreach ($items as $menuId => $qty) {
        $orderItemId = OrderItem::add($orderId, (int) $menuId, (int) $qty);
        $newItemIds[] = $orderItemId;

        $selectedAddons = $addonsByMenu[$menuId] ?? [];
        foreach ($selectedAddons as $addonId) {
            OrderItem::addAddon($orderItemId, (int) $addonId);
        }
    }

    // จ่ายก่อน: ยังไม่ส่งเข้าครัว — รับเงินที่หน้าคิดเงินแล้ว Payment::record จะส่งเข้าครัวเอง

    $db->commit();
    header('Location: ../pos/checkout.php?order_id=' . $orderId);
    exit;
} catch (Exception $e) {
    $db->rollBack();
    die('เกิดข้อผิดพลาด: ' . $e->getMessage());
}
