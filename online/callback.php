<?php
// รับค่ากลับจาก LINE Login
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineApi.php';
require_once __DIR__ . '/../classes/Member.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$code  = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

// ตรวจ state ก่อนเสมอ กัน CSRF (ต้องตรงกับที่เก็บไว้ตอนเริ่ม login.php)
if ($state === '' || !isset($_SESSION['line_login_state']) || $state !== $_SESSION['line_login_state']) {
    die('การเข้าสู่ระบบไม่ถูกต้อง (state ไม่ตรงกัน) กรุณาลองใหม่ที่ <a href="login.php">login.php</a>');
}
unset($_SESSION['line_login_state']);

if ($code === '') {
    die('ไม่ได้รับ authorization code จาก LINE กรุณาลองใหม่');
}

try {
    $tokenResponse = LineApi::exchangeCodeForToken($code);
    $profile = LineApi::getProfile($tokenResponse['access_token']);

    $member = Member::findOrCreateByLineId($profile['userId'], $profile['displayName'] ?? 'ลูกค้า LINE');

    if ($member['is_suspended']) {
        die(
            'บัญชีของคุณถูกระงับสิทธิ์การสั่งอาหารออนไลน์ชั่วคราว เนื่องจากไม่มารับสินค้าเกินกำหนด ' .
            'กรุณาติดต่อร้านโดยตรงเพื่อปลดล็อกสิทธิ์'
        );
    }

    $_SESSION['member_id'] = (int) $member['member_id'];
    header('Location: index.php');
    exit;
} catch (Exception $e) {
    die('เข้าสู่ระบบด้วย LINE ไม่สำเร็จ: ' . htmlspecialchars($e->getMessage()));
}
