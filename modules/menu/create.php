<?php
// เพิ่มเมนู
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Menu.php';
require_once __DIR__ . '/../../classes/Recipe.php';
require_once __DIR__ . '/../../classes/Addon.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireAnyRole(['owner','manager']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::verifyCsrf();
    $name     = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $price    = (float) ($_POST['price'] ?? 0);
    $ingredientIds = $_POST['ingredient_id'] ?? [];
    $quantities    = $_POST['quantity_required'] ?? [];
    $selectedAddons = $_POST['addon_ids'] ?? [];

    if ($name === '') {
        $errors[] = 'กรุณากรอกชื่อเมนู';
    }
    if ($category === '' || !in_array($category, Menu::categories(), true)) {
        $errors[] = 'กรุณาเลือกหมวดหมู่จากรายการ';
    }
    if ($price <= 0) {
        $errors[] = 'ราคาต้องมากกว่า 0';
    }

    // รูปเมนู (ไม่บังคับ)
    $imagePath = null;
    if (!empty($_FILES['image']['name'])) {
        $file = $_FILES['image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'อัปโหลดรูปไม่สำเร็จ กรุณาลองใหม่';
        } else {
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                $errors[] = 'รองรับเฉพาะไฟล์ JPG, PNG, WEBP เท่านั้น';
            } elseif ($file['size'] > 3 * 1024 * 1024) {
                $errors[] = 'ไฟล์รูปต้องมีขนาดไม่เกิน 3MB';
            } else {
                $uploadDir = __DIR__ . '/../../uploads/menu/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $filename = 'menu_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                    $imagePath = 'uploads/menu/' . $filename;
                } else {
                    $errors[] = 'บันทึกไฟล์รูปไม่สำเร็จ ตรวจสอบสิทธิ์เขียนไฟล์ของโฟลเดอร์ uploads/';
                }
            }
        }
    }

    if (empty($errors)) {
        $menuId = Menu::create($name, $category, $price, $imagePath);
        Menu::setDiy($menuId, isset($_POST['is_diy']));

        Recipe::saveForMenu($menuId, $ingredientIds, $quantities);
        Addon::assignToMenu($menuId, array_map('intval', $selectedAddons));

        header('Location: recipe.php?menu_id=' . $menuId . '&created=1');
        exit;
    }
}

$db = getDB();
$ingredients = $db->query('SELECT * FROM ingredients ORDER BY name')->fetchAll();
$addons = Addon::allActive();
$categories = Menu::categories();

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('เพิ่มเมนูใหม่', 'ตั้งชื่อ ราคา รูป และส่วนผสม'); ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="create.php" enctype="multipart/form-data">
<?= Security::csrfInput() ?>
    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold"><i class="ti ti-tools-kitchen-2"></i> ข้อมูลเมนู</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">ชื่อเมนู</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">หมวดหมู่</label>
                    <select name="category" class="form-select" required>
                        <option value="">— เลือกหมวด —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= ($_POST['category'] ?? '') === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text"><a href="categories.php">จัดการหมวดหมู่</a></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">ราคา (บาท)</label>
                    <input type="number" step="0.01" name="price" class="form-control" value="<?= htmlspecialchars($_POST['price'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">รูปเมนู (ไม่บังคับ, JPG/PNG/WEBP ไม่เกิน 3MB)</label>
                    <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                </div>
                <?php if (Menu::diyReady()): ?>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="is_diy" id="isDiy" <?= isset($_POST['is_diy']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isDiy">เมนูฐานตามสั่ง (DIY) · แสดงเฉพาะหน้าแคชเชียร์ ลูกค้าสั่งกับพนักงานด้วยบัตรอาหารตามสั่ง</label>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="ti ti-list-details"></i> ส่วนผสม</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addBomRow()">
                <i class="ti ti-plus"></i> เพิ่มวัตถุดิบ
            </button>
        </div>
        <div class="card-body">
            <div id="bom-rows"></div>
            <div class="form-text">ปริมาณที่ใช้ต่อ 1 จาน</div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold"><i class="ti ti-adjustments"></i> ตัวเลือกเสริมที่เปิดให้สั่งได้</div>
        <div class="card-body">
            <?php if (!$addons): ?>
                <p class="text-muted small mb-0">
                    ยังไม่มีตัวเลือกเสริมในระบบ — <a href="addons.php">ไปสร้างตัวเลือกเสริมก่อน</a>
                </p>
            <?php else: ?>
                <?php foreach ($addons as $a): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="addon_ids[]" value="<?= $a['addon_id'] ?>" id="addon-<?= $a['addon_id'] ?>">
                        <label class="form-check-label" for="addon-<?= $a['addon_id'] ?>">
                            <?= htmlspecialchars($a['name']) ?> <span class="text-muted">(+฿<?= number_format($a['extra_price'], 2) ?>)</span>
                        </label>
                    </div>
                <?php endforeach; ?>
                <div class="form-text mt-2"><a href="addons.php">จัดการตัวเลือกเสริมทั้งหมด</a></div>
            <?php endif; ?>
        </div>
    </div>

    <button type="submit" class="btn btn-dark"><i class="ti ti-device-floppy"></i> บันทึกเมนูใหม่</button>
</form>

<p class="mt-3"><a href="index.php"><i class="ti ti-arrow-left"></i> กลับไปหน้ารายการเมนู</a></p>

<template id="bom-row-template">
    <div class="row g-2 mb-2 bom-row">
        <div class="col-6">
            <select name="ingredient_id[]" class="form-select">
                <option value="">-- เลือกวัตถุดิบ --</option>
                <?php foreach ($ingredients as $ing): ?>
                    <option value="<?= $ing['ingredient_id'] ?>"><?= htmlspecialchars($ing['name']) ?> (<?= htmlspecialchars($ing['unit']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-4">
            <input type="number" step="0.01" name="quantity_required[]" class="form-control" placeholder="ปริมาณต่อจาน">
        </div>
        <div class="col-2">
            <button type="button" class="btn btn-outline-danger w-100" onclick="this.closest('.bom-row').remove()">
                <i class="ti ti-trash"></i>
            </button>
        </div>
    </div>
</template>

<script>
function addBomRow(){
    const tpl = document.getElementById('bom-row-template');
    const clone = tpl.content.cloneNode(true);
    document.getElementById('bom-rows').appendChild(clone);
}
// เริ่มต้นด้วย 2 แถวว่างให้กรอกทันที
document.addEventListener('DOMContentLoaded', () => { addBomRow(); addBomRow(); });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
