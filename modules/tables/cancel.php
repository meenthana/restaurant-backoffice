<?php
require_once __DIR__ . '/../../config/features.php';
requireFeature(FEATURE_TABLES);
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/TableSession.php';
Auth::requireLogin();

$sessionId = (int) ($_POST['session_id'] ?? 0);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sessionId > 0) {
        TableSession::cancel($sessionId);
    }
    header('Location: index.php');
} catch (Throwable $e) {
    header('Location: index.php?error=' . urlencode($e->getMessage()));
}
exit;
