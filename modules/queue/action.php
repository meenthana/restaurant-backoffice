<?php
require_once __DIR__ . '/../../config/features.php';
requireFeature(FEATURE_QUEUE);
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/QueueEntry.php';
require_once __DIR__ . '/../../classes/TableSession.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
Security::verifyCsrf();
$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);
try {
    if ($action === 'create') {
        QueueEntry::create($_POST, Auth::id());
    } elseif ($action === 'call') {
        QueueEntry::call($id);
    } elseif ($action === 'no_show') {
        QueueEntry::cancel($id, 'no_show');
    } elseif ($action === 'seat') {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM queue_entries WHERE queue_entry_id = :id AND status IN ('waiting','called')");
        $stmt->execute(['id'=>$id]); $q=$stmt->fetch();
        if(!$q) throw new Exception('คิวนี้ถูกดำเนินการไปแล้ว');
        $sessionId = TableSession::openMultiple($_POST['table_ids'] ?? [], (int)$q['guest_count'], (int)Auth::id(), $id, $q['reservation_id'] ? (int)$q['reservation_id'] : null, 'ลูกค้า '.$q['guest_name']);
        header('Location: ../orders/create.php?table_session_id='.$sessionId); exit;
    }
    header('Location: index.php');
} catch(Throwable $e) { header('Location: index.php?error='.urlencode($e->getMessage())); }
exit;

