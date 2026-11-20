<?php
// บันทึกออเดอร์ออนไลน์
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/slip.php';
require_once __DIR__ . '/../classes/Member.php';
require_once __DIR__ . '/../classes/KioskOrder.php';
require_once __DIR__ . '/../classes/Payment.php';
require_once __DIR__ . '/../classes/AppSetting.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['member_id'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

$member = Member::find((int) $_SESSION['member_id']);
if (!$member || $member['is_suspended']) {
    die('ไม่สามารถสั่งอาหารออนไลน์ได้ในขณะนี้');
}
if (AppSetting::get('online_payment_mode', 'mock') === 'disabled') {
    die('ร้านปิดรับออเดอร์ออนไลน์ชั่วคราว <a href="index.php">กลับ</a>');
}

$note = mb_substr(trim($_POST['note'] ?? ''), 0, 200);
$qtyByMenu = (array) ($_POST['qty'] ?? []);
$addonsByMenu = (array) ($_POST['addons'] ?? []);

// แปลงฟอร์มเป็นรูปแบบตะกร้าเดียวกับตู้ Self-Service แล้วให้ KioskOrder ตรวจความถูกต้องทั้งหมด
$cart = [];
foreach ($qtyByMenu as $menuId => $qty) {
    if ((int) $qty > 0) {
        $cart[] = ['menu_id' => (int) $menuId, 'qty' => (int) $qty, 'addons' => (array) ($addonsByMenu[$menuId] ?? [])];
    }
}

$scheduledTimestamp = strtotime(trim($_POST['scheduled_at'] ?? ''));
$leadMinutes = max(0, (int) AppSetting::get('pickup_lead_minutes', '30'));
if ($scheduledTimestamp === false || $scheduledTimestamp < time() + max(0, $leadMinutes - 5) * 60) {
    header('Location: index.php?error=' . urlencode('กรุณาเลือกเวลารับอาหารล่วงหน้าอย่างน้อย ' . $leadMinutes . ' นาที'));
    exit;
}
$closeTime = AppSetting::get('store_close_time', '20:00');
if (!preg_match('/^\d{2}:\d{2}$/', $closeTime)) $closeTime = '20:00';
if ($scheduledTimestamp > strtotime(date('Y-m-d') . ' ' . $closeTime)) {
    header('Location: index.php?error=' . urlencode('สั่งออนไลน์ได้เฉพาะวันนี้ ก่อนร้านปิด ' . $closeTime . ' น.'));
    exit;
}

try {
    $pickupNote = 'รับที่ร้านเวลา ' . date('d/m/Y H:i', $scheduledTimestamp) . ($note !== '' ? ' — ' . $note : '');
    $result = KioskOrder::place($cart, 'takeaway', 'online', (int) $member['member_id'],
                                $pickupNote, date('Y-m-d H:i:s', $scheduledTimestamp));

    // ใช้แต้มเป็นส่วนลด แล้วคำนวณยอดใหม่ก่อนไปหน้าชำระเงิน
    if (!empty($_POST['use_points'])) {
        try { Member::redeemPointsForOrder((int) $member['member_id'], (int) $result['order_id']); }
        catch (Throwable $e) { /* ใช้แต้มไม่สำเร็จ ไม่ให้กระทบออเดอร์ */ }
        $result['total'] = Payment::orderTotal((int) $result['order_id']);
    }

    // ถ้าปิดระบบตรวจสลิป = โหมดสาธิตแบบเดิม ถือว่าจ่ายแล้วทันที (ออเดอร์จะเข้าครัวเอง)
    if (!SLIP_VERIFY_ENABLED) {
        Payment::record($result['order_id'], 'mock_online', $result['total'], null, 'MOCK-' . $result['order_id']);
        header('Location: status.php?order_id=' . $result['order_id']);
        exit;
    }
    header('Location: pay.php?order_id=' . $result['order_id']);
    exit;
} catch (Exception $e) {
    header('Location: index.php?error=' . urlencode($e->getMessage()));
    exit;
}
