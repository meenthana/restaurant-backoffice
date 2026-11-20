<?php
// ปรับแต้มสมาชิก
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/Member.php';
require_once __DIR__ . '/../../classes/AuditLog.php';
Auth::requireAnyRole(['owner','manager']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location:index.php'); exit; }
Security::verifyCsrf();
$memberId = (int)($_POST['member_id'] ?? 0);
$action = $_POST['action'] ?? '';
try {
    $member = Member::find($memberId);
    if (!$member) throw new Exception('ไม่พบสมาชิก');
    if ($action === 'profile') {
        Member::updateProfile($memberId, (string)($_POST['display_name'] ?? ''), $_POST['phone'] ?? null, $_POST['email'] ?? null);
        $message = 'บันทึกข้อมูลสมาชิกแล้ว';
    } elseif ($action === 'points') {
        Member::adjustPoints($memberId, (int)($_POST['points_change'] ?? 0), (string)($_POST['note'] ?? ''), (int)Auth::id());
        $message = 'บันทึกการปรับแต้มแล้ว';
    } elseif ($action === 'toggle') {
        if ($member['is_suspended']) { Member::unsuspend($memberId); $message = 'คืนสิทธิ์สมาชิกแล้ว'; }
        else { getDB()->prepare('UPDATE members SET is_suspended=1 WHERE member_id=:id')->execute(['id'=>$memberId]); $message = 'ระงับสิทธิ์สมาชิกแล้ว'; }
    } else throw new Exception('คำสั่งไม่ถูกต้อง');
    AuditLog::write(Auth::id(), $action, 'member', $memberId, $message);
    if (($_POST['back'] ?? '') === 'index') { header('Location:index.php?success='.urlencode($message)); exit; }
    header('Location:view.php?id='.$memberId.'&success='.urlencode($message));
} catch (Throwable $e) {
    header('Location:view.php?id='.$memberId.'&error='.urlencode($e->getMessage()));
}
exit;
