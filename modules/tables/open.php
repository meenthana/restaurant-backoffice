<?php
require_once __DIR__ . '/../../config/features.php';
requireFeature(FEATURE_TABLES);
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/TableSession.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$tableId = (int) ($_POST['table_id'] ?? 0);
$tableIds = array_map('intval', $_POST['table_ids'] ?? []);
if (!$tableIds && $tableId > 0) { $tableIds = [$tableId]; }
$isMergeRequest = isset($_POST['table_ids']);
$guestCount = (int) ($_POST['guest_count'] ?? 0);

try {
    if ($isMergeRequest && count(array_unique($tableIds)) < 2) {
        throw new Exception('การเปิดโต๊ะรวมต้องเลือกอย่างน้อย 2 โต๊ะ');
    }
    $sessionId = TableSession::openMultiple($tableIds, $guestCount, (int) Auth::id(), null, null, trim($_POST['session_note'] ?? ''));
    header('Location: ../orders/create.php?table_session_id=' . $sessionId);
} catch (Throwable $e) {
    header('Location: index.php?error=' . urlencode($e->getMessage()));
}
exit;
