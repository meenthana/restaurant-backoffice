<?php
// วันที่ยอดขายผิดปกติ
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/RevenueEngine.php';
require_once __DIR__ . '/../../classes/Auth.php';
Auth::requireRole('owner');

require_once __DIR__ . '/../../includes/header.php';

$dateTo   = date('Y-m-d');
$dateFrom = date('Y-m-d', strtotime('-30 days'));
$anomalies = RevenueEngine::zScoreAnomalies($dateFrom, $dateTo, 2.0);
?>

<?php pageHeader('วันที่ยอดขายผิดปกติ', 'วันที่ขายได้มากหรือน้อยกว่าปกติ ใน 30 วันล่าสุด'); ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>วันที่</th><th>รายได้</th><th>ห่างจากปกติ</th><th>ลักษณะ</th></tr></thead>
            <tbody>
            <?php if (!$anomalies): ?>
                <tr><td colspan="4" class="text-muted text-center py-4">ไม่พบวันที่รายได้ผิดปกติในช่วงนี้ (หรือข้อมูลยังน้อยเกินไปที่จะคำนวณ)</td></tr>
            <?php endif; ?>
            <?php foreach ($anomalies as $a): ?>
                <tr>
                    <td><?= $a['sale_date'] ?></td>
                    <td>฿<?= number_format($a['revenue'], 2) ?></td>
                    <td class="mono"><?= $a['z_score'] ?></td>
                    <td><span class="badge <?= $a['z_score'] > 0 ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $a['direction'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="mt-3"><a href="summary.php"><i class="ti ti-arrow-left"></i> กลับไปหน้าสรุปรายได้</a></p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
