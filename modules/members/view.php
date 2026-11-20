<?php
// ข้อมูลสมาชิก
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Member.php';
require_once __DIR__ . '/../../classes/AppSetting.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireAnyRole(['owner','manager']);
$memberId = (int)($_GET['id'] ?? 0);
$member = Member::find($memberId);
if (!$member) die('ไม่พบสมาชิก');
$history = Member::pointHistory($memberId);
$ordersStmt = getDB()->prepare(
    "SELECT order_id, order_code, status, payment_status, opened_at
     FROM orders WHERE member_id=:id ORDER BY opened_at DESC LIMIT 10"
);
$ordersStmt->execute(['id'=>$memberId]);
$orders = $ordersStmt->fetchAll();
$bahtPerPoint = max(1, (int)AppSetting::get('baht_per_point', '25'));
$typeLabels = ['earn'=>'รับแต้ม','adjustment'=>'ปรับแต้ม','reversal'=>'คืนแต้ม','redeem'=>'ใช้แต้ม'];
$orderStatusLabels = [
    'draft'=>'รอส่งเข้าครัว',
    'confirmed'=>'ยืนยันแล้ว',
    'in_kitchen'=>'กำลังทำ',
    'ready'=>'อาหารพร้อม',
    'served'=>'เสิร์ฟแล้ว',
    'closed'=>'ปิดออเดอร์',
    'cancelled'=>'ยกเลิกแล้ว',
];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php pageHeader(htmlspecialchars($member['display_name']), 'สมาชิก #' . $memberId,
    '<div class="text-end"><div class="text-muted small">แต้มคงเหลือ</div><div class="fs-2 fw-bold" style="color:var(--accent)">' . number_format((int) $member['points']) . '</div></div>'); ?>
<p class="mb-3"><a href="index.php" class="text-decoration-none small"><i class="ti ti-arrow-left"></i> กลับหน้าสมาชิก</a></p>
<?php if (isset($_GET['success'])): ?><div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div><?php endif; ?>
<?php if (isset($_GET['error'])): ?><div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>

<div class="row g-4"><div class="col-lg-5">
    <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">ข้อมูลสมาชิก</div><div class="card-body">
        <form method="post" action="action.php"><?= Security::csrfInput() ?><input type="hidden" name="action" value="profile"><input type="hidden" name="member_id" value="<?= $memberId ?>">
        <div class="mb-3"><label class="form-label">ชื่อสมาชิก</label><input name="display_name" value="<?= htmlspecialchars($member['display_name']) ?>" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">เบอร์โทร</label><input name="phone" value="<?= htmlspecialchars($member['phone'] ?? '') ?>" class="form-control"></div>
        <div class="mb-3"><label class="form-label">อีเมล</label><input type="email" name="email" value="<?= htmlspecialchars($member['email'] ?? '') ?>" class="form-control"></div>
        <button class="btn btn-dark w-100"><i class="ti ti-device-floppy"></i> บันทึกข้อมูล</button></form>
    </div></div>

    <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">ปรับคะแนน</div><div class="card-body">
        <form method="post" action="action.php"><?= Security::csrfInput() ?><input type="hidden" name="action" value="points"><input type="hidden" name="member_id" value="<?= $memberId ?>">
        <div class="mb-3"><label class="form-label">จำนวนแต้ม</label><input type="number" name="points_change" class="form-control" placeholder="เช่น 10 หรือ -5" required><div class="form-text">ใส่ค่าบวกเพื่อเพิ่ม และค่าลบเพื่อลด</div></div>
        <div class="mb-3"><label class="form-label">เหตุผล</label><input name="note" class="form-control" placeholder="เช่น ชดเชยบริการ หรือแก้ไขรายการ" required></div>
        <button class="btn btn-outline-dark w-100">บันทึกการปรับแต้ม</button></form>
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-body d-flex justify-content-between align-items-center gap-3"><div><div class="fw-semibold">สิทธิ์การสั่งออนไลน์</div><div class="small text-muted">ปิดเพื่อระงับการสั่งล่วงหน้าของสมาชิกนี้</div></div><?= statusToggle('action.php', ['action' => 'toggle', 'member_id' => $memberId], !$member['is_suspended'], 'ใช้งาน', 'ระงับสิทธิ์', $member['is_suspended'] ? '' : 'ระงับสิทธิ์การสั่งออนไลน์ของสมาชิกนี้?') ?></div></div>
</div>

<div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">ประวัติคะแนน</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>วันเวลา</th><th>รายการ</th><th>เปลี่ยนแปลง</th><th>คงเหลือ</th></tr></thead><tbody>
    <?php if (!$history): ?><tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีประวัติคะแนน</td></tr><?php endif; ?>
    <?php foreach ($history as $item): ?><tr><td class="small"><?= date('d/m/Y H:i', strtotime($item['created_at'])) ?></td><td><div><?= $typeLabels[$item['transaction_type']] ?? htmlspecialchars($item['transaction_type']) ?><?= $item['order_code'] ? ' · '.htmlspecialchars($item['order_code']) : '' ?></div><div class="small text-muted"><?= htmlspecialchars($item['note'] ?: ($item['full_name'] ?: '-')) ?></div></td><td class="fw-bold <?= $item['points_change'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= $item['points_change'] >= 0 ? '+' : '' ?><?= number_format((int)$item['points_change']) ?></td><td><?= number_format((int)$item['balance_after']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>

    <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold">ออเดอร์ล่าสุด</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>ออเดอร์</th><th>วันเวลา</th><th>สถานะ</th><th></th></tr></thead><tbody>
    <?php if (!$orders): ?><tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีประวัติออเดอร์</td></tr><?php endif; ?>
    <?php foreach ($orders as $order): ?><tr><td><?= htmlspecialchars($order['order_code'] ?: '#'.$order['order_id']) ?></td><td><?= date('d/m/Y H:i', strtotime($order['opened_at'])) ?></td><td><?= htmlspecialchars($orderStatusLabels[$order['status']] ?? $order['status']) ?></td><td class="text-end"><a href="../orders/view.php?id=<?= (int)$order['order_id'] ?>" class="btn btn-outline-secondary btn-sm">ดูออเดอร์</a></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
</div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
