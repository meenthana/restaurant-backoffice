<?php
// ตั้งสูตรวัตถุดิบของเมนู
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Recipe.php';
require_once __DIR__ . '/../../classes/Menu.php';
require_once __DIR__ . '/../../classes/Addon.php';
require_once __DIR__ . '/../../classes/OrderFulfillment.php';
require_once __DIR__ . '/../../includes/header.php';

$menuId = (int) ($_GET['menu_id'] ?? 0);
$menu = Menu::find($menuId);
$ingredients = Recipe::getByMenu($menuId);
$menuAddons = Addon::forMenu($menuId);
?>

<?php pageHeader('ส่วนผสม', ($menu['name'] ?? '-') . ' · วัตถุดิบที่ใช้ต่อ 1 จาน'); ?>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success"><i class="ti ti-circle-check"></i> สร้างเมนูใหม่สำเร็จแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success"><i class="ti ti-circle-check"></i> แก้ไขเมนูสำเร็จแล้ว</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>วัตถุดิบ</th><th>กลุ่ม</th><th>ปริมาณที่ใช้</th><th>หน่วย</th></tr>
            </thead>
            <tbody>
            <?php if (!$ingredients): ?>
                <tr><td colspan="4" class="text-muted text-center py-4">ยังไม่มีการกำหนดสูตรสำหรับเมนูนี้</td></tr>
            <?php endif; ?>
            <?php foreach ($ingredients as $i): ?>
                <tr>
                    <td><?= htmlspecialchars($i['ingredient_name']) ?></td>
                    <td><span class="badge text-bg-light border"><?= OrderFulfillment::TYPE_LABELS[$i['ingredient_type']] ?? '-' ?></span>
                        <?php if (!(int) $i['track_inventory']): ?><span class="small text-muted">ไม่นับสต๊อก</span><?php endif; ?></td>
                    <td><?= $i['quantity_required'] ?></td>
                    <td><?= htmlspecialchars($i['unit']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="ti ti-adjustments"></i> ตัวเลือกเสริมที่เปิดให้สั่งได้</div>
    <div class="card-body">
        <?php if (!$menuAddons): ?>
            <p class="text-muted small mb-0">เมนูนี้ยังไม่เปิดตัวเลือกเสริม</p>
        <?php else: ?>
            <?php foreach ($menuAddons as $a): ?>
                <?php $sum = Addon::effectSummary((int) $a['addon_id']); ?>
                <span class="badge text-bg-light border me-1"><?= htmlspecialchars($a['name']) ?><?= (float) $a['extra_price'] > 0 ? ' +฿' . number_format($a['extra_price'], 2) : '' ?><?= $sum !== '' ? ' · ' . htmlspecialchars($sum) : '' ?></span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<p class="mt-3">
    <a href="edit.php?menu_id=<?= $menuId ?>" class="btn btn-outline-secondary btn-sm"><i class="ti ti-edit"></i> แก้ไขเมนูนี้</a>
    <a href="index.php" class="ms-2"><i class="ti ti-arrow-left"></i> กลับไปหน้ารายการเมนู</a>
</p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
