<?php
// รายชื่อสมาชิก
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireAnyRole(['owner','manager']);

$q = trim($_GET['q'] ?? '');
$db = getDB();
$summary = $db->query(
    "SELECT COUNT(*) AS member_count, COALESCE(SUM(points),0) AS total_points,
            SUM(is_suspended=1) AS suspended_count,
            SUM(last_visit_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS active_30_days
     FROM members"
)->fetch();
$sql = "SELECT m.*,
        (SELECT COUNT(*) FROM orders o WHERE o.member_id=m.member_id) AS order_count,
        (SELECT COALESCE(SUM(oi.quantity*oi.unit_price + oi.quantity*COALESCE(
            (SELECT SUM(oia.addon_price) FROM order_item_addons oia WHERE oia.order_item_id=oi.order_item_id),0)),0)
         FROM orders o JOIN order_items oi ON oi.order_id=o.order_id
         WHERE o.member_id=m.member_id AND o.status='closed') AS lifetime_value
        FROM members m";
$params = [];
if ($q !== '') {
    $sql .= " WHERE m.display_name LIKE :q OR m.phone LIKE :q OR m.email LIKE :q OR m.line_user_id LIKE :q";
    $params = ['q' => '%' . $q . '%'];
}
$sql .= " ORDER BY m.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();
require_once __DIR__ . '/../../includes/header.php';
?>
<?php pageHeader('สมาชิก', 'ลูกค้าสมาชิกและแต้มสะสม'); ?>

<?php if (isset($_GET['success'])): ?><div class="alert alert-success"><i class="ti ti-circle-check"></i> <?= htmlspecialchars($_GET['success']) ?></div><?php endif; ?>
<?php if (isset($_GET['error'])): ?><div class="alert alert-danger"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="text-muted small">สมาชิกทั้งหมด</div><div class="h3 mb-0"><?= number_format((int)$summary['member_count']) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="text-muted small">แต้มคงเหลือรวม</div><div class="h3 mb-0"><?= number_format((int)$summary['total_points']) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="text-muted small">ใช้งานใน 30 วัน</div><div class="h3 mb-0"><?= number_format((int)$summary['active_30_days']) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="text-muted small">ถูกระงับสิทธิ์</div><div class="h3 mb-0"><?= number_format((int)$summary['suspended_count']) ?></div></div></div>
</div>

<form class="d-flex gap-2 mb-3">
    <input name="q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="ค้นหาชื่อ เบอร์โทร อีเมล หรือ LINE User ID">
    <button class="btn btn-outline-dark"><i class="ti ti-search"></i> ค้นหา</button>
</form>

<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
<thead><tr><th>สมาชิก</th><th>ออเดอร์</th><th>ยอดซื้อสะสม</th><th>แต้ม</th><th>ไม่มารับ</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
<tbody>
<?php if (!$members): ?><tr><td colspan="7" class="text-center text-muted py-5">ไม่พบสมาชิก</td></tr><?php endif; ?>
<?php foreach ($members as $member): ?>
<tr>
    <td><div class="fw-semibold"><?= htmlspecialchars($member['display_name']) ?></div><div class="small text-muted"><?= htmlspecialchars($member['phone'] ?: ($member['email'] ?: $member['line_user_id'])) ?></div></td>
    <td><?= number_format((int)$member['order_count']) ?></td>
    <td>฿<?= number_format((float)$member['lifetime_value'], 2) ?></td>
    <td><span class="badge text-bg-warning"><i class="ti ti-star"></i> <?= number_format((int)$member['points']) ?></span></td>
    <td><?= number_format((int)$member['no_show_count']) ?></td>
    <td><?= statusToggle('action.php', ['action' => 'toggle', 'member_id' => (int) $member['member_id'], 'back' => 'index'], !$member['is_suspended'], 'ใช้งาน', 'ระงับสิทธิ์', $member['is_suspended'] ? '' : 'ระงับสิทธิ์การสั่งออนไลน์ของสมาชิกนี้?') ?></td>
    <td class="text-end"><a href="view.php?id=<?= (int)$member['member_id'] ?>" class="btn btn-outline-dark btn-sm"><i class="ti ti-eye"></i> รายละเอียด</a></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
