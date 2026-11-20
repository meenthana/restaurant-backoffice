<?php
require_once __DIR__ . '/../../config/features.php';
requireFeature(FEATURE_TABLES);
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/TableSession.php';
require_once __DIR__ . '/../../classes/AuditLog.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

Security::verifyCsrf();
$action = $_POST['action'] ?? '';
$sessionId = (int) ($_POST['session_id'] ?? 0);

try {
    if ($action === 'merge') {
        TableSession::mergeTables($sessionId, $_POST['table_ids'] ?? []);
        $message = 'รวมโต๊ะเข้ากับบัญชีเดิมเรียบร้อยแล้ว';
    } elseif ($action === 'move') {
        TableSession::moveTo($sessionId, (int) ($_POST['target_table_id'] ?? 0));
        $message = 'ย้ายโต๊ะเรียบร้อยแล้ว';
    } elseif ($action === 'release') {
        TableSession::releaseTable($sessionId, (int) ($_POST['table_id'] ?? 0));
        $message = 'คืนโต๊ะเรียบร้อยแล้ว';
    } else {
        throw new Exception('คำสั่งรอบโต๊ะไม่ถูกต้อง');
    }
    AuditLog::write(Auth::id(), $action, 'table_session', $sessionId, $message);
    header('Location: index.php?success=' . urlencode($message));
} catch (Throwable $e) {
    header('Location: index.php?error=' . urlencode($e->getMessage()));
}
exit;
