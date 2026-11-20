<?php
// เปิด/ปิดบัญชีพนักงาน
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireRole('owner');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
Security::verifyCsrf();

$userId = (int) ($_POST['user_id'] ?? 0);

$db = getDB();
$stmt = $db->prepare('SELECT role, is_active FROM users WHERE user_id = :id');
$stmt->execute(['id' => $userId]);
$target = $stmt->fetch();

if (!$target) {
    die('ไม่พบผู้ใช้งานนี้');
}

if ($target['role'] === 'owner') {
    die('ไม่สามารถปิดใช้งานบัญชีเจ้าของร้านได้');
}

$newStatus = $target['is_active'] ? 0 : 1;
$update = $db->prepare('UPDATE users SET is_active = :s WHERE user_id = :id');
$update->execute(['s' => $newStatus, 'id' => $userId]);

header('Location: index.php');
exit;
