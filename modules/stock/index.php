<?php
// สต๊อกวัตถุดิบ แยกตามล็อต
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
Auth::requireLogin();

$db = getDB();
$batches = $db->query(
    "SELECT sb.*, i.name AS ingredient_name, i.unit, u.full_name AS received_by_name
     FROM stock_batches sb
     JOIN ingredients i ON i.ingredient_id = sb.ingredient_id
     LEFT JOIN users u ON u.user_id = sb.received_by
     WHERE sb.quantity_remaining > 0
     ORDER BY sb.expiry_date IS NULL, sb.expiry_date ASC, sb.batch_id ASC"
)->fetchAll();

// มูลค่าสต๊อกคงเหลือทั้งหมด (คำนวณจากข้อมูลจริง)
$totalStockValue = (float) $db->query(
    "SELECT COALESCE(SUM(quantity_remaining * cost_per_unit), 0) FROM stock_batches WHERE quantity_remaining > 0"
)->fetchColumn();

// ต้นทุนของเสียเดือนนี้ (จาก stock_transactions ประเภท wastage จริง)
$wastageStmt = $db->query(
    "SELECT COALESCE(SUM(ABS(st.quantity) * sb.cost_per_unit), 0) AS wastage_cost, COUNT(*) AS wastage_count
     FROM stock_transactions st
     JOIN stock_batches sb ON sb.batch_id = st.batch_id
     WHERE st.type = 'wastage'
       AND MONTH(st.created_at) = MONTH(CURDATE())
       AND YEAR(st.created_at) = YEAR(CURDATE())"
);
$wastage = $wastageStmt->fetch();

// รับวัตถุดิบเข้าล่าสุด (รายการจริงล่าสุด)
$latestBatch = $db->query(
    "SELECT sb.*, i.name AS ingredient_name, i.unit, u.full_name AS received_by_name
     FROM stock_batches sb
     JOIN ingredients i ON i.ingredient_id = sb.ingredient_id
     LEFT JOIN users u ON u.user_id = sb.received_by
     ORDER BY sb.created_at DESC
     LIMIT 1"
)->fetch();

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('สต๊อกวัตถุดิบ', 'จัดการสต๊อกในร้าน',
    '<a href="ingredients.php" class="btn btn-outline-secondary"><i class="ti ti-list-details"></i> วัตถุดิบ</a>'
    . '<a href="convert.php" class="btn btn-outline-secondary"><i class="ti ti-transfer-in"></i> แปรรูป</a>'
    . '<a href="adjust.php" class="btn btn-outline-secondary"><i class="ti ti-scale"></i> ปรับยอด</a>'
    . '<a href="wastage.php" class="btn btn-outline-danger"><i class="ti ti-trash"></i> ของเสีย</a>'
    . '<a href="add_batch.php" class="btn btn-dark"><i class="ti ti-plus"></i> รับของเข้า</a>'); ?>

<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success">บันทึกรับวัตถุดิบเข้าคลังสำเร็จแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['wasted'])): ?>
    <div class="alert alert-success">บันทึกของเสียสำเร็จแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['converted'])): ?>
    <div class="alert alert-success">แปรรูปวัตถุดิบสำเร็จแล้ว</div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white fw-semibold">รายการล็อตวัตถุดิบ</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>วัตถุดิบ</th><th>Batch</th><th>คงเหลือ</th><th>ต้นทุน/หน่วย</th><th>วันหมดอายุ</th><th>ผู้บันทึก</th><th>หลักฐาน</th></tr>
            </thead>
            <tbody>
                <?php if (!$batches): ?>
                <tr><td colspan="7" class="text-muted text-center py-4">ยังไม่มีวัตถุดิบในคลัง — กด "+ รับวัตถุดิบเข้า" เพื่อเริ่มบันทึก</td></tr>
                <?php endif; ?>

                <?php foreach ($batches as $b):
                    $isNearExpiry = false;
                    $daysLeft = null;
                    if ($b['expiry_date']) {
                        $daysLeft = (int) ((strtotime($b['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
                        $isNearExpiry = $daysLeft <= 2;
                    }
                ?>
                <tr class="<?= $isNearExpiry ? 'table-danger' : '' ?>">
                    <td><?= htmlspecialchars($b['ingredient_name']) ?></td>
                    <td>#B<?= str_pad($b['batch_id'], 3, '0', STR_PAD_LEFT) ?></td>
                    <td><?= number_format($b['quantity_remaining'], 2) ?> <?= htmlspecialchars($b['unit']) ?></td>
                    <td>฿<?= number_format($b['cost_per_unit'], 2) ?></td>
                    <td>
                        <?php if ($b['expiry_date']): ?>
                            <?= $b['expiry_date'] ?>
                            <?php if ($isNearExpiry): ?>
                                <span class="badge text-bg-danger"><i class="ti ti-alert-triangle"></i> <?= $daysLeft >= 0 ? "ใกล้หมดอายุ ({$daysLeft} วัน)" : 'หมดอายุแล้ว' ?></span>
                                <span class="badge text-bg-dark">ใช้ก่อน</span>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($b['received_by_name'] ?? '-') ?></td>
                    <td>
                        <?php if ($b['receipt_photo']): ?>
                            <a href="/restaurant-backoffice/<?= htmlspecialchars($b['receipt_photo']) ?>" target="_blank"><i class="ti ti-paperclip"></i> ดูหลักฐาน</a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<hr class="my-4">

<div class="row g-3">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h3 class="h6 mb-3">มูลค่าสต๊อกคงเหลือทั้งหมด</h3>
                <div class="fs-3 fw-bold">฿<?= number_format($totalStockValue, 2) ?></div>
                <div class="text-muted small mt-1">มูลค่าของที่เหลือทั้งหมด</div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h3 class="h6 mb-3">ต้นทุนของเสียเดือนนี้</h3>
                <?php if ((float) $wastage['wastage_cost'] > 0): ?>
                    <div class="fs-3 fw-bold text-danger">฿<?= number_format($wastage['wastage_cost'], 2) ?></div>
                    <div class="text-muted small mt-1"><?= (int) $wastage['wastage_count'] ?> รายการที่บันทึกเป็นของเสียในเดือนนี้</div>
                <?php else: ?>
                    <div class="fs-3 fw-bold text-muted">฿0.00</div>
                    <div class="text-muted small mt-1">ยังไม่มีการบันทึกของเสียในเดือนนี้</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h3 class="h6 mb-3">รับวัตถุดิบเข้าล่าสุด</h3>
                <?php if ($latestBatch): ?>
                    <div class="fw-semibold"><?= htmlspecialchars($latestBatch['ingredient_name']) ?></div>
                    <div class="text-muted small mt-1">
                        <?= number_format($latestBatch['quantity_purchased'], 2) ?> <?= htmlspecialchars($latestBatch['unit']) ?>
                        — บันทึกโดย <?= htmlspecialchars($latestBatch['received_by_name'] ?? '-') ?><br>
                        <?= $latestBatch['created_at'] ?>
                    </div>
                    <?php if ($latestBatch['receipt_photo']): ?>
                        <a href="/restaurant-backoffice/<?= htmlspecialchars($latestBatch['receipt_photo']) ?>" target="_blank" class="small"><i class="ti ti-paperclip"></i> ดูหลักฐานภาพถ่าย</a>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-muted small">ยังไม่มีการรับวัตถุดิบเข้าคลัง</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
