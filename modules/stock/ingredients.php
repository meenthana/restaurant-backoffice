<?php
// จัดการรายชื่อวัตถุดิบ
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Ingredient.php';
require_once __DIR__ . '/../../classes/OrderFulfillment.php';
Auth::requireLogin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $ingredientId = (int) ($_POST['ingredient_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $reorderPoint = trim($_POST['reorder_point'] ?? '') !== '' ? (float) $_POST['reorder_point'] : null;

        if ($name === '' || $unit === '') {
            $errors[] = 'กรุณากรอกชื่อและหน่วยนับให้ครบ';
        } else {
            Ingredient::update($ingredientId, $name, $unit, $reorderPoint, $_POST['ingredient_type'] ?? 'main', isset($_POST['track_inventory']));
            header('Location: ingredients.php?updated=1');
            exit;
        }
    } elseif ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $reorderPoint = trim($_POST['reorder_point'] ?? '') !== '' ? (float) $_POST['reorder_point'] : null;

        if ($name === '' || $unit === '') {
            $errors[] = 'กรุณากรอกชื่อและหน่วยนับให้ครบ';
        } else {
            Ingredient::create($name, $unit, $reorderPoint, $_POST['ingredient_type'] ?? 'main', isset($_POST['track_inventory']));
            header('Location: ingredients.php?added=1');
            exit;
        }
    }
}

$ingredients = Ingredient::all();
$lowStockIds = array_column(Ingredient::belowReorderPoint(), 'ingredient_id');

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('รายการวัตถุดิบ', 'ชื่อ หน่วย กลุ่ม และจุดสั่งเพิ่ม'); ?>

<div class="alert alert-light border small">
    <b>หลัก</b> ของไม่พอครัวเริ่มทำไม่ได้ ·
    <b>รอง</b> ตัดเท่าที่มี ไม่บล็อกครัว ·
    <b>ตกแต่ง</b> ปกติไม่นับสต๊อก (ปิด "นับสต๊อก" ไว้) ใช้ตรวจนับเป็นรอบแทน
</div>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">แก้ไขข้อมูลวัตถุดิบสำเร็จแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success">เพิ่มวัตถุดิบใหม่สำเร็จแล้ว</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-3">
<div class="col-md-9">
<div class="card">
    <div class="card-header bg-white fw-semibold">วัตถุดิบทั้งหมด</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>ชื่อ</th><th>หน่วยนับ</th><th>กลุ่ม</th><th>นับสต๊อก</th><th style="width:150px;">สั่งเพิ่มเมื่อเหลือ</th><th style="width:100px;">จัดการ</th></tr></thead>
            <tbody>
            <?php if (!$ingredients): ?>
                <tr><td colspan="6" class="text-muted text-center py-4">ยังไม่มีวัตถุดิบในระบบ</td></tr>
            <?php endif; ?>
            <?php foreach ($ingredients as $ing): $isLow = in_array($ing['ingredient_id'], $lowStockIds, true); ?>
                <tr class="<?= $isLow ? 'table-danger' : '' ?>">
                    <form method="post" action="ingredients.php">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="ingredient_id" value="<?= $ing['ingredient_id'] ?>">
                        <td>
                            <input type="text" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars($ing['name']) ?>">
                            <?php if ($isLow): ?><span class="badge text-bg-danger mt-1"><i class="ti ti-alert-triangle"></i> ต่ำกว่าจุดสั่งซื้อ</span><?php endif; ?>
                        </td>
                        <td><input type="text" name="unit" class="form-control form-control-sm" value="<?= htmlspecialchars($ing['unit']) ?>"></td>
                        <td><select name="ingredient_type" class="form-select form-select-sm">
                            <?php foreach (OrderFulfillment::TYPE_LABELS as $k => $label): ?>
                                <option value="<?= $k ?>" <?= ($ing['ingredient_type'] ?? 'main') === $k ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select></td>
                        <td><div class="form-check form-switch mb-0"><input type="checkbox" role="switch" class="form-check-input" name="track_inventory" value="1" aria-label="นับสต๊อก" <?= !isset($ing['track_inventory']) || $ing['track_inventory'] ? 'checked' : '' ?>></div></td>
                        <td><input type="number" step="0.01" name="reorder_point" class="form-control form-control-sm" value="<?= htmlspecialchars($ing['reorder_point'] ?? '') ?>" placeholder="ไม่ตั้ง = ไม่เตือน"></td>
                        <td><button type="submit" class="btn btn-sm btn-outline-dark"><i class="ti ti-device-floppy"></i> บันทึก</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<div class="col-md-3">
<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="ti ti-plus"></i> เพิ่มวัตถุดิบใหม่</div>
    <div class="card-body">
        <form method="post" action="ingredients.php">
            <input type="hidden" name="action" value="create">
            <div class="mb-3">
                <label class="form-label">ชื่อวัตถุดิบ</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">หน่วยนับ</label>
                <input type="text" name="unit" class="form-control" placeholder="เช่น กรัม, ขีด, ลูก" required>
            </div>
            <div class="mb-3">
                <label class="form-label">กลุ่ม</label>
                <select name="ingredient_type" class="form-select">
                    <?php foreach (OrderFulfillment::TYPE_LABELS as $k => $label): ?>
                        <option value="<?= $k ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-check form-switch mb-3">
                <input type="checkbox" role="switch" class="form-check-input" name="track_inventory" value="1" id="trk" checked>
                <label class="form-check-label" for="trk">นับสต๊อก (ตัดอัตโนมัติตามสูตร)</label>
            </div>
            <div class="mb-3">
                <label class="form-label">สั่งเพิ่มเมื่อเหลือ (ไม่บังคับ)</label>
                <input type="number" step="0.01" name="reorder_point" class="form-control" placeholder="เช่น 500">
                <div class="form-text">เหลือน้อยกว่านี้จะแจ้งเตือน</div>
            </div>
            <button type="submit" class="btn btn-dark w-100"><i class="ti ti-device-floppy"></i> เพิ่มวัตถุดิบ</button>
        </form>
    </div>
</div>
</div>
</div>

<p class="mt-3"><a href="index.php"><i class="ti ti-arrow-left"></i> กลับไปหน้าสต๊อกวัตถุดิบ</a></p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
