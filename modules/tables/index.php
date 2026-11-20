<?php
require_once __DIR__ . '/../../config/features.php';
requireFeature(FEATURE_TABLES);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/RestaurantTable.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireLogin();

$tables = RestaurantTable::all();
$availableTables = array_values(array_filter($tables, fn($table) => $table['status'] === 'available'));
$canManageTables = Auth::canManage();
$zones = [];
foreach ($tables as $table) {
    $zones[$table['zone_name']][] = $table;
}

$statusLabel = [
    'available' => 'โต๊ะว่าง',
    'held' => 'กำลังเลือก',
    'reserved' => 'จองแล้ว',
    'occupied' => 'กำลังใช้งาน',
    'cleaning' => 'รอทำความสะอาด',
    'out_of_service' => 'งดใช้งาน',
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-hero d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <span class="eyebrow">หน้าร้าน</span>
        <h1 class="h2 mb-1">ผังโต๊ะ</h1>
        <p class="text-muted mb-0">เลือกโต๊ะว่างเพื่อเปิดบัญชีและรับออเดอร์ลูกค้าทานที่ร้าน</p>
    </div>
    <div class="d-flex flex-wrap gap-2 small">
        <span class="legend-dot legend-available">ว่าง</span>
        <span class="legend-dot legend-occupied">ใช้งาน</span>
        <span class="legend-dot legend-cleaning">รอทำความสะอาด</span>
        <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#mergeTables"><i class="ti ti-table-plus"></i> เปิดโต๊ะรวม</button>
        <?php if ($canManageTables): ?>
        <button class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#manageTables"><i class="ti ti-settings"></i> เพิ่ม–ลบโต๊ะ</button>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_GET['error'])): ?>
<div class="alert alert-danger"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success"><i class="ti ti-circle-check"></i> <?= htmlspecialchars($_GET['success']) ?></div>
<?php endif; ?>

<?php foreach ($zones as $zoneName => $zoneTables): ?>
<section class="mb-4">
    <div class="d-flex align-items-center gap-2 mb-2">
        <h2 class="h5 mb-0"><?= htmlspecialchars($zoneName) ?></h2>
        <span class="text-muted small"><?= count($zoneTables) ?> โต๊ะ</span>
    </div>
    <div class="table-floor-grid">
        <?php foreach ($zoneTables as $table):
            $status = $table['status'];
            $isAvailable = $status === 'available';
        ?>
        <article class="restaurant-table table-status-<?= htmlspecialchars($status) ?>">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="table-code"><?= htmlspecialchars($table['table_code']) ?></div>
                    <div class="text-muted small"><i class="ti ti-users"></i> <?= (int) $table['capacity'] ?> ที่นั่ง</div>
                </div>
                <span class="status-chip"><?= $statusLabel[$status] ?? htmlspecialchars($status) ?></span>
            </div>

            <?php if ($table['session_id']): ?>
                <div class="table-session-info mt-3">
                    <div><i class="ti ti-user-check"></i> ลูกค้า <?= (int) $table['guest_count'] ?> คน</div>
                    <div><i class="ti ti-clock"></i> เปิด <?= date('H:i', strtotime($table['opened_at'])) ?> น.</div>
                    <?php if ($table['order_id']): ?>
                    <div class="fw-semibold mt-1">ออเดอร์ #<?= (int) $table['order_id'] ?> · ฿<?= number_format((float) $table['order_total'], 2) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="mt-3">
                <?php if ($isAvailable): ?>
                    <form action="open.php" method="post" class="d-flex gap-2">
                        <input type="hidden" name="table_id" value="<?= (int) $table['table_id'] ?>">
                        <input type="number" name="guest_count" min="1" max="<?= (int) $table['capacity'] ?>"
                               value="<?= min(2, (int) $table['capacity']) ?>" class="form-control form-control-sm" aria-label="จำนวนลูกค้า">
                        <button class="btn btn-dark btn-sm text-nowrap" type="submit">เปิดโต๊ะ</button>
                    </form>
                <?php elseif ($status === 'occupied' && $table['session_id']): ?>
                    <div class="table-action-grid">
                        <button class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#mergeActive<?= (int) $table['table_id'] ?>"><i class="ti ti-table-plus"></i> รวมโต๊ะ</button>
                        <button class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#moveActive<?= (int) $table['table_id'] ?>"><i class="ti ti-arrows-exchange"></i> ย้ายโต๊ะ</button>
                        <form action="session_action.php" method="post" onsubmit="return confirm('ยืนยันคืนโต๊ะ <?= htmlspecialchars($table['table_code'], ENT_QUOTES) ?> ใช่ไหม?');">
                            <?= Security::csrfInput() ?>
                            <input type="hidden" name="action" value="release">
                            <input type="hidden" name="session_id" value="<?= (int) $table['session_id'] ?>">
                            <input type="hidden" name="table_id" value="<?= (int) $table['table_id'] ?>">
                            <button class="btn btn-outline-danger btn-sm w-100" type="submit"><i class="ti ti-door-exit"></i> คืนโต๊ะ</button>
                        </form>
                        <?php if ($table['order_id']): ?>
                            <a class="btn btn-dark btn-sm" href="../orders/edit.php?order_id=<?= (int) $table['order_id'] ?>"><i class="ti ti-bowl-spoon"></i> สั่งเพิ่ม</a>
                            <?php if (in_array($table['order_status'], ['active', 'ready'], true)): ?>
                            <a class="btn btn-success btn-sm table-action-wide" href="../pos/checkout.php?order_id=<?= (int) $table['order_id'] ?>"><i class="ti ti-cash-register"></i> คิดเงิน</a>
                            <?php else: ?>
                            <a class="btn btn-secondary btn-sm table-action-wide" href="../orders/view.php?id=<?= (int) $table['order_id'] ?>" title="กรุณาส่งใบงานเข้าครัวก่อน"><i class="ti ti-cash-register"></i> คิดเงิน</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <a class="btn btn-dark btn-sm" href="../orders/create.php?table_session_id=<?= (int) $table['session_id'] ?>"><i class="ti ti-bowl-spoon"></i> สั่งเพิ่ม</a>
                            <button class="btn btn-secondary btn-sm table-action-wide" disabled><i class="ti ti-cash-register"></i> คิดเงิน</button>
                        <?php endif; ?>
                    </div>
                <?php elseif ($status === 'cleaning'): ?>
                    <form action="clean.php" method="post">
                        <input type="hidden" name="table_id" value="<?= (int) $table['table_id'] ?>">
                        <button class="btn btn-outline-success btn-sm w-100" type="submit"><i class="ti ti-sparkles"></i> ทำความสะอาดเสร็จแล้ว</button>
                    </form>
                <?php else: ?>
                    <button class="btn btn-light btn-sm w-100" disabled>ยังไม่พร้อมใช้งาน</button>
                <?php endif; ?>
            </div>

            <?php if ($status === 'occupied' && $table['session_id']): ?>
            <div class="modal fade" id="mergeActive<?= (int) $table['table_id'] ?>" tabindex="-1"><div class="modal-dialog"><form action="session_action.php" method="post" class="modal-content">
                <?= Security::csrfInput() ?>
                <input type="hidden" name="action" value="merge">
                <input type="hidden" name="session_id" value="<?= (int) $table['session_id'] ?>">
                <div class="modal-header"><h5 class="modal-title">รวมโต๊ะกับ <?= htmlspecialchars($table['table_code']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="text-muted small">โต๊ะที่เลือกจะใช้บัญชีและออเดอร์เดียวกับโต๊ะปัจจุบัน</p>
                    <?php if ($availableTables): ?>
                    <select name="table_ids[]" class="form-select" multiple size="8" required>
                        <?php foreach ($availableTables as $available): ?><option value="<?= (int) $available['table_id'] ?>"><?= htmlspecialchars($available['table_code']) ?> — <?= (int) $available['capacity'] ?> ที่นั่ง / <?= htmlspecialchars($available['zone_name']) ?></option><?php endforeach; ?>
                    </select>
                    <?php else: ?><div class="alert alert-light border mb-0">ไม่มีโต๊ะว่างสำหรับรวมโต๊ะ</div><?php endif; ?>
                </div>
                <div class="modal-footer"><button class="btn btn-dark" <?= !$availableTables ? 'disabled' : '' ?>>ยืนยันรวมโต๊ะ</button></div>
            </form></div></div>

            <div class="modal fade" id="moveActive<?= (int) $table['table_id'] ?>" tabindex="-1"><div class="modal-dialog"><form action="session_action.php" method="post" class="modal-content">
                <?= Security::csrfInput() ?>
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="session_id" value="<?= (int) $table['session_id'] ?>">
                <div class="modal-header"><h5 class="modal-title">ย้ายจาก <?= htmlspecialchars($table['table_code']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <label class="form-label">โต๊ะปลายทาง</label>
                    <select name="target_table_id" class="form-select" required>
                        <option value="">เลือกโต๊ะว่าง</option>
                        <?php foreach ($availableTables as $available): ?><option value="<?= (int) $available['table_id'] ?>"><?= htmlspecialchars($available['table_code']) ?> — <?= (int) $available['capacity'] ?> ที่นั่ง / <?= htmlspecialchars($available['zone_name']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text">ระบบจะตรวจสอบว่าที่นั่งเพียงพอก่อนย้าย</div>
                </div>
                <div class="modal-footer"><button class="btn btn-dark" <?= !$availableTables ? 'disabled' : '' ?>>ยืนยันย้ายโต๊ะ</button></div>
            </form></div></div>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>

<div class="modal fade" id="mergeTables" tabindex="-1"><div class="modal-dialog"><form method="post" action="open.php" class="modal-content">
<div class="modal-header"><h5 class="modal-title">เปิดโต๊ะรวม</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><p class="text-muted small">เลือกอย่างน้อย 2 โต๊ะ ระบบจะเปิดใช้งานทุกโต๊ะและผูกเข้ากับบัญชีเดียวกัน</p>
<label class="form-label">โต๊ะว่าง</label><select name="table_ids[]" class="form-select" multiple size="8" required><?php foreach($tables as $t):if($t['status']!=='available')continue;?><option value="<?=$t['table_id']?>"><?=htmlspecialchars($t['table_code'])?> — <?=$t['capacity']?> ที่นั่ง / <?=htmlspecialchars($t['zone_name'])?></option><?php endforeach;?></select>
<div class="row g-3 mt-1"><div class="col-5"><label class="form-label">จำนวนลูกค้า</label><input type="number" min="1" name="guest_count" value="4" class="form-control" required></div><div class="col-7"><label class="form-label">หมายเหตุ</label><input name="session_note" class="form-control" placeholder="เช่น ต่อโต๊ะวันเกิด"></div></div>
</div><div class="modal-footer"><button class="btn btn-dark">เปิดโต๊ะรวมและรับออเดอร์</button></div></form></div></div>

<?php if ($canManageTables): ?>
<div class="modal fade" id="manageTables" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">เพิ่ม–ลบโต๊ะ</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <form action="manage.php" method="post" class="row g-2 align-items-end mb-4">
            <?= Security::csrfInput() ?>
            <input type="hidden" name="action" value="add">
            <div class="col-md-3"><label class="form-label">รหัสโต๊ะ</label><input name="table_code" class="form-control" placeholder="เช่น T11" maxlength="20" required></div>
            <div class="col-md-3"><label class="form-label">จำนวนที่นั่ง</label><input type="number" name="capacity" class="form-control" min="1" max="50" value="2" required></div>
            <div class="col-md-4"><label class="form-label">โซน</label><input name="zone_name" class="form-control" placeholder="เช่น โซน A" required></div>
            <div class="col-md-2"><button class="btn btn-dark w-100"><i class="ti ti-plus"></i> เพิ่มโต๊ะ</button></div>
        </form>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>รหัสโต๊ะ</th><th>โซน</th><th>ที่นั่ง</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                <tbody>
                <?php foreach ($tables as $managedTable): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($managedTable['table_code']) ?></td>
                        <td><?= htmlspecialchars($managedTable['zone_name']) ?></td>
                        <td><?= (int) $managedTable['capacity'] ?></td>
                        <td><?= $statusLabel[$managedTable['status']] ?? htmlspecialchars($managedTable['status']) ?></td>
                        <td class="text-end">
                            <?php if (in_array($managedTable['status'], ['available','cleaning','out_of_service'], true)): ?>
                            <form action="manage.php" method="post" class="d-inline" onsubmit="return confirm('ยืนยันลบโต๊ะ <?= htmlspecialchars($managedTable['table_code'], ENT_QUOTES) ?> ออกจากผังใช่ไหม?');">
                                <?= Security::csrfInput() ?>
                                <input type="hidden" name="action" value="delete"><input type="hidden" name="table_id" value="<?= (int) $managedTable['table_id'] ?>">
                                <button class="btn btn-outline-danger btn-sm"><i class="ti ti-trash"></i> ลบ</button>
                            </form>
                            <?php else: ?><span class="text-muted small">กำลังใช้งาน</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div></div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
