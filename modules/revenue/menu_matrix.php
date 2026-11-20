<?php
// ความนิยมและกำไรของเมนู
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/RevenueEngine.php';
require_once __DIR__ . '/../../classes/Auth.php';
Auth::requireRole('owner');
require_once __DIR__.'/../../config/features.php';

require_once __DIR__ . '/../../includes/header.php';

$dateTo   = date('Y-m-d');
$dateFrom = date('Y-m-d', strtotime('-30 days'));
$matrix = RevenueEngine::menuEngineeringMatrix($dateFrom, $dateTo);

$labels = [
    'star'      => ['ป้าย' => 'ขายดี กำไรดี', 'สี' => 'text-bg-success', 'คำอธิบาย' => 'โปรโมทต่อ'],
    'plowhorse' => ['ป้าย' => 'ขายดี กำไรน้อย', 'สี' => 'text-bg-warning', 'คำอธิบาย' => 'ลองปรับราคาหรือต้นทุน'],
    'puzzle'    => ['ป้าย' => 'ขายน้อย กำไรดี', 'สี' => 'text-bg-info', 'คำอธิบาย' => 'ควรโปรโมทเพิ่ม'],
    'dog'       => ['ป้าย' => 'ขายน้อย กำไรน้อย', 'สี' => 'text-bg-secondary', 'คำอธิบาย' => 'พิจารณาตัดออก'],
];
?>

<?php pageHeader('ความนิยมของเมนู', 'เทียบยอดขายกับกำไรของแต่ละเมนู ใน 30 วันล่าสุด'); ?>
<p class="text-muted small"><?= FEATURE_AUTO_STOCK ? 'กำไร = รายได้ - ต้นทุนวัตถุดิบที่ตัดจริง' : 'กำไร = รายได้ - ต้นทุนตามสูตรอาหาร (ปริมาณในสูตร x ราคาซื้อวัตถุดิบล่าสุด) เพราะระบบใช้ Soft Stock' ?> · แบ่งกลุ่มด้วยค่ามัธยฐานของจำนวนขายและกำไร</p>

<?php if (!$matrix): ?>
    <div class="alert alert-light border">ยังไม่มีข้อมูลออเดอร์ที่ปิดสำเร็จมากพอในช่วงนี้ ลองปิดออเดอร์สักสองสามใบก่อนดูผลตรงนี้</div>
<?php else: ?>

<div class="row g-3">
    <?php foreach ($labels as $key => $meta): ?>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><?= $meta['ป้าย'] ?></span>
                <span class="badge <?= $meta['สี'] ?>"><?= $meta['คำอธิบาย'] ?></span>
            </div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <tbody>
                        <?php $found = false; foreach ($matrix as $m): if ($m['category'] !== $key) continue; $found = true; ?>
                        <tr>
                            <td><?= htmlspecialchars($m['menu_name']) ?></td>
                            <td class="text-muted small">ขาย <?= $m['qty_sold'] ?></td>
                            <td class="text-end">฿<?= number_format($m['profit'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$found): ?>
                        <tr><td class="text-muted text-center py-3">ไม่มีเมนูในกลุ่มนี้</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<p class="mt-3"><a href="summary.php"><i class="ti ti-arrow-left"></i> กลับไปหน้าสรุปรายได้</a></p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
