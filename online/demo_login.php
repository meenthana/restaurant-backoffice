<?php
// ล็อกอินลูกค้าแบบสาธิต (ไม่ต้องใช้ LINE)
require_once __DIR__ . '/../config/line_loader.php';
require_once __DIR__ . '/../classes/Member.php';
if (LINE_API_ENABLED) { header('Location: login.php'); exit; }
if (session_status() === PHP_SESSION_NONE) session_start();
$member = Member::findOrCreateByLineId('demo-line-user', 'ลูกค้าสาธิต');
$_SESSION['member_id'] = (int) $member['member_id'];
header('Location: index.php');
exit;

