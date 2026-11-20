<?php
// พนักงาน อนุมัติ / ปฏิเสธ สลิป
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/SlipVerifier.php';
require_once __DIR__ . '/../../classes/AuditLog.php';

Auth::requireLogin();
Auth::requireAnyRole(['owner', 'manager', 'cashier', 'staff']);
Security::verifyCsrf();

$slipId = (int) ($_POST['slip_id'] ?? 0);
$action = $_POST['do'] ?? '';
$userId = (int) Auth::id();

try {
    if ($action === 'approve') {
        $amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? (float) $_POST['amount'] : null;
        SlipVerifier::approveManually($slipId, $userId, $amount);
        AuditLog::write($userId, 'slip_approve', 'payment_slips', $slipId,
            'พนักงานยืนยันรับเงินจากสลิป');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'บันทึกการรับเงินเรียบร้อย'];
    } elseif ($action === 'reject') {
        SlipVerifier::reject($slipId, $userId, 'พนักงานตรวจสอบแล้วไม่พบเงินเข้า');
        AuditLog::write($userId, 'slip_reject', 'payment_slips', $slipId,
            'พนักงานปฏิเสธสลิป');
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'ปฏิเสธสลิปเรียบร้อย'];
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'คำสั่งไม่ถูกต้อง'];
    }
} catch (Exception $e) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
}

header('Location: index.php');
exit;
