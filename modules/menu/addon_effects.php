<?php
// ตั้งค่าว่าตัวเลือกเสริมใช้วัตถุดิบเพิ่ม หรือตัดวัตถุดิบออกจากสูตร
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Addon.php';
require_once __DIR__ . '/../../classes/Ingredient.php';
require_once __DIR__ . '/../../classes/OrderFulfillment.php';
Auth::requireLogin();

$addonId = (int) ($_GET['addon_id'] ?? $_POST['addon_id'] ?? 0);
$addon = Addon::find($addonId);
if (!$addon) die('ไม่พบตัวเลือกเสริมนี้');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'delete') {
            Addon::deleteEffect((int) $_POST['effect_id']);
        } else {
            $qty = trim($_POST['quantity'] ?? '') !== '' ? (float) $_POST['quantity'] : null;
            Addon::saveEffect($addonId, (int) $_POST['ingredient_id'], $_POST['effect'] ?? 'add', $qty);
        }
        header('Location: addon_effects.php?addon_id=' . $addonId . '&saved=1');
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$effects = Addon::effects($addonId);
$ingredients = Ingredient::all();

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('ผลต่อสต๊อก: ' . htmlspecialchars($addon['name']), 'เลือกว่าตัวเลือกนี้ใช้วัตถุดิบเพิ่ม หรือไม่ใส่วัตถุดิบไหน'); ?>

<?php if (isset($_GET['added'])): ?><div class="alert alert-success">เพิ่มตัวเลือกเสริมแล้ว ตั้งค่าผลต่อสต๊อกด้านล่าง (ถ้าไม่กระทบสต๊อก ข้ามได้)</div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">บันทึกแล้ว</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">รายการที่ตั้งไว้</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>วัตถุดิบ</th><th>ผล</th><th>ปริมาณต่อจาน</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$effects): ?>
                        <tr><td colspan="4" class="text-muted text-center py-4">ไม่กระทบสต๊อก (ใช้เป็นข้อความแจ้งครัวอย่างเดียว)</td></tr>
                    <?php endif; ?>
                    <?php foreach ($effects as $e): ?>
                        <tr>
                            <td><?= htmlspecialchars($e['ingredient_name']) ?>
                                <span class="badge text-bg-light border"><?= OrderFulfillment::TYPE_LABELS[$e['ingredient_type']] ?? '' ?></span></td>
                            <td><?= $e['effect'] === 'remove'
                                    ? '<span class="badge text-bg-warning">ไม่ใส่ (ตัดออกจากสูตร)</span>'
                                    : '<span class="badge text-bg-success">ใช้เพิ่ม</span>' ?></td>
                            <td><?= $e['effect'] === 'remove' ? '—' : rtrim(rtrim(number_format((float) $e['quantity'], 2, '.', ''), '0'), '.') . ' ' . htmlspecialchars($e['unit']) ?></td>
                            <td class="text-end">
                                <form method="post" onsubmit="return confirm('ลบรายการนี้?');">
                                    <input type="hidden" name="addon_id" value="<?= $addonId ?>">
                                    <input type="hidden" name="effect_id" value="<?= $e['effect_id'] ?>">
                                    <button name="action" value="delete" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card">
            <div class="card-header"><i class="ti ti-plus"></i> เพิ่ม / แก้รายการ</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="addon_id" value="<?= $addonId ?>">
                    <div class="mb-3">
                        <label class="form-label">วัตถุดิบ</label>
                        <select name="ingredient_id" class="form-select" required>
                            <?php foreach ($ingredients as $ing): ?>
                                <option value="<?= $ing['ingredient_id'] ?>"><?= htmlspecialchars($ing['name']) ?> (<?= htmlspecialchars($ing['unit']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ผล</label>
                        <select name="effect" class="form-select" id="effectSel">
                            <option value="add">ใช้เพิ่ม (เช่น ไข่ดาว ใช้ไข่ +1)</option>
                            <option value="remove">ไม่ใส่ (เช่น ไม่ใส่หอมใหญ่)</option>
                        </select>
                    </div>
                    <div class="mb-3" id="qtyBox">
                        <label class="form-label">ปริมาณที่ใช้เพิ่มต่อ 1 จาน</label>
                        <input type="number" step="0.01" min="0" name="quantity" class="form-control" placeholder="เช่น 1">
                    </div>
                    <button class="btn btn-dark w-100"><i class="ti ti-device-floppy"></i> บันทึก</button>
                    <div class="form-text">วัตถุดิบเดิมที่ตั้งไว้แล้ว จะถูกแทนที่ด้วยค่าใหม่</div>
                </form>
            </div>
        </div>
    </div>
</div>

<p class="mt-3"><a href="addons.php"><i class="ti ti-arrow-left"></i> กลับไปหน้าตัวเลือกเสริม</a></p>

<script>
document.getElementById('effectSel').addEventListener('change', function () {
    document.getElementById('qtyBox').style.display = this.value === 'remove' ? 'none' : '';
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
