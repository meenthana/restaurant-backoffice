<?php
// ลูกค้าออกจากระบบ
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
unset($_SESSION['member_id']);
header('Location: login.php');
exit;
