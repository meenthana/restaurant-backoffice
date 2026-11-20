<?php
// สรุปรายได้
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/RevenueEngine.php';
require_once __DIR__ . '/../../classes/Auth.php';
Auth::requireRole('owner');
require_once __DIR__.'/../../config/features.php';

require_once __DIR__ . '/../../includes/header.php';

$dateTo   = date('Y-m-d');
$dateFrom = date('Y-m-d', strtotime('-30 days'));
$daily = RevenueEngine::dailyRevenue($dateFrom, $dateTo);
$profitByMenu = RevenueEngine::costAdjustedProfitByMenu($dateFrom, $dateTo);

$totalRevenue = array_sum(array_column($daily, 'revenue'));
$totalProfit = array_sum(array_column($profitByMenu, 'profit'));
$totalCost = array_sum(array_column($profitByMenu, 'cost'));
?>

<?php pageHeader('รายได้', 'ยอดขาย 30 วันล่าสุด',
    '<a href="menu_matrix.php" class="btn btn-outline-secondary"><i class="ti ti-grid-dots"></i> ความนิยมของเมนู</a>'
    . '<a href="anomaly.php" class="btn btn-outline-secondary"><i class="ti ti-alert-triangle"></i> วันที่ผิดปกติ</a>'); ?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">รายได้รวม</div>
            <div class="fs-4 fw-bold">฿<?= number_format($totalRevenue, 2) ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">ต้นทุนวัตถุดิบ</div>
            <div class="fs-4 fw-bold">฿<?= number_format($totalCost,2) ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <div class="text-muted small">ส่วนต่างรายได้กับต้นทุนวัตถุดิบ</div>
            <div class="fs-4 fw-bold text-success">฿<?= number_format($totalProfit,2) ?></div>
        </div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">รายได้รายวัน</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>วันที่</th><th>รายได้</th></tr></thead>
            <tbody>
            <?php if (!$daily): ?>
                <tr><td colspan="2" class="text-muted text-center py-4">ยังไม่มีข้อมูลรายได้ในช่วงนี้</td></tr>
            <?php endif; ?>
            <?php foreach ($daily as $d): ?>
                <tr><td><?= $d['sale_date'] ?></td><td>฿<?= number_format($d['revenue'], 2) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">ยอดขายรายเมนู <span class="text-muted small fw-normal">· <?= FEATURE_AUTO_STOCK ? 'ต้นทุนจากสต๊อกที่ตัดจริง' : 'ต้นทุนตามสูตรอาหาร x ราคาซื้อวัตถุดิบล่าสุด' ?></span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>เมนู</th><th>จำนวนที่ขาย</th><th>รายได้</th><th>ต้นทุน</th><th>กำไร</th></tr></thead>
            <tbody>
            <?php if (!$profitByMenu): ?>
                <tr><td colspan="5" class="text-muted text-center py-4">ยังไม่มีออเดอร์ที่ปิดสำเร็จในช่วงนี้</td></tr>
            <?php endif; ?>
            <?php foreach ($profitByMenu as $m): ?>
                <tr>
                    <td><?= htmlspecialchars($m['menu_name']) ?></td>
                    <td><?= $m['qty_sold'] ?></td>
                    <td>฿<?= number_format($m['revenue'], 2) ?></td>
                    <td>฿<?= number_format($m['cost'],2) ?><?= !empty($m['no_recipe']) ? ' <span class="badge text-bg-light border">ยังไม่มีสูตร</span>' : '' ?></td>
                    <td class="<?= $m['profit'] >= 0 ? 'text-success' : 'text-danger' ?> fw-semibold">฿<?= number_format($m['profit'],2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
