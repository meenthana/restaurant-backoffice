<?php
// รับวัตถุดิบเข้าคลัง
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/StockBatch.php';
Auth::requireLogin();

$errors = [];
const NEW_INGREDIENT_VALUE = '__new__';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ingredientChoice = $_POST['ingredient_id'] ?? '';
    $newName = trim($_POST['new_ingredient_name'] ?? '');
    $newUnit = trim($_POST['new_ingredient_unit'] ?? '');

    $quantity     = (float) ($_POST['quantity_purchased'] ?? 0);
    $costPerUnit  = (float) ($_POST['cost_per_unit'] ?? 0);
    $purchaseDate = trim($_POST['purchase_date'] ?? '');
    $expiryDate   = trim($_POST['expiry_date'] ?? '');

    $db = getDB();
    $ingredientId = null;

    if ($ingredientChoice === NEW_INGREDIENT_VALUE) {
        if ($newName === '' || $newUnit === '') {
            $errors[] = 'กรุณากรอกชื่อวัตถุดิบใหม่และหน่วยนับให้ครบ';
        } else {
            $dup = $db->prepare('SELECT ingredient_id FROM ingredients WHERE LOWER(name) = LOWER(:n)');
            $dup->execute(['n' => $newName]);
            if ($dup->fetch()) {
                $errors[] = 'มีวัตถุดิบชื่อนี้ในระบบอยู่แล้ว กรุณาเลือกจากรายการแทนการสร้างใหม่';
            } else {
                $insIng = $db->prepare('INSERT INTO ingredients (name, unit) VALUES (:n, :u)');
                $insIng->execute(['n' => $newName, 'u' => $newUnit]);
                $ingredientId = (int) $db->lastInsertId();
            }
        }
    } else {
        $ingredientId = (int) $ingredientChoice;
        if ($ingredientId <= 0) {
            $errors[] = 'กรุณาเลือกวัตถุดิบ หรือเพิ่มวัตถุดิบใหม่';
        }
    }

    if ($quantity <= 0) {
        $errors[] = 'จำนวนที่รับเข้าต้องมากกว่า 0';
    }
    if ($costPerUnit <= 0) {
        $errors[] = 'ต้นทุนต่อหน่วยต้องมากกว่า 0';
    }
    if ($purchaseDate === '') {
        $errors[] = 'กรุณาระบุวันที่รับเข้า';
    }

    $receiptPhotoPath = null;
    if (empty($_FILES['receipt_photo']['name'])) {
        $errors[] = 'กรุณาแนบรูปหลักฐานใบส่งของ/ใบเสร็จ';
    } elseif ($_FILES['receipt_photo']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่';
    } else {
        $file = $_FILES['receipt_photo'];
        $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            $errors[] = 'รองรับเฉพาะไฟล์ JPG, PNG หรือ PDF เท่านั้น';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'ไฟล์ต้องมีขนาดไม่เกิน 5MB';
        } else {
            $uploadDir = __DIR__ . '/../../uploads/receipts/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                $receiptPhotoPath = 'uploads/receipts/' . $filename;
            } else {
                $errors[] = 'บันทึกไฟล์ไม่สำเร็จ ตรวจสอบสิทธิ์เขียนไฟล์ของโฟลเดอร์ uploads/';
            }
        }
    }

    if (empty($errors)) {
        StockBatch::receive(
            $ingredientId,
            $quantity,
            $costPerUnit,
            $purchaseDate,
            $expiryDate !== '' ? $expiryDate : null,
            null,
            Auth::id(),
            $receiptPhotoPath
        );

        header('Location: index.php?added=1');
        exit;
    }
}

$db = getDB();
$ingredients = $db->query('SELECT * FROM ingredients ORDER BY name')->fetchAll();
$selected = $_POST['ingredient_id'] ?? '';

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('รับวัตถุดิบเข้า', 'บันทึกของที่ซื้อเข้าร้าน'); ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card" style="max-width:480px;">
    <div class="card-body">
        <form method="post" action="add_batch.php" enctype="multipart/form-data">

            <div class="mb-3">
                <label class="form-label">วัตถุดิบ</label>
                <select name="ingredient_id" id="ingredient_id" class="form-select" required onchange="toggleNewIngredient()">
                    <option value="">-- เลือกวัตถุดิบ --</option>
                    <?php foreach ($ingredients as $ing): ?>
                        <option value="<?= $ing['ingredient_id'] ?>" <?= ($selected == $ing['ingredient_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ing['name']) ?> (หน่วย: <?= htmlspecialchars($ing['unit']) ?>)
                        </option>
                    <?php endforeach; ?>
                    <option value="__new__" <?= ($selected === '__new__') ? 'selected' : '' ?>>+ เพิ่มวัตถุดิบใหม่ (ยังไม่มีในระบบ)</option>
                </select>
            </div>

            <div id="new-ingredient-fields" class="border rounded p-3 mb-3 bg-light" style="display:none;">
                <div class="mb-2">
                    <label class="form-label">ชื่อวัตถุดิบใหม่</label>
                    <input type="text" name="new_ingredient_name" class="form-control"
                           value="<?= htmlspecialchars($_POST['new_ingredient_name'] ?? '') ?>" placeholder="เช่น พริกขี้หนู">
                </div>
                <div>
                    <label class="form-label">หน่วยนับ (ตั้งเองได้อิสระ)</label>
                    <input type="text" name="new_ingredient_unit" class="form-control"
                           value="<?= htmlspecialchars($_POST['new_ingredient_unit'] ?? '') ?>" placeholder="เช่น กรัม, ขีด, ถุง, มัด, ลูก">
                    
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">จำนวนที่รับเข้า</label>
                <input type="number" step="0.01" name="quantity_purchased" class="form-control"
                       value="<?= htmlspecialchars($_POST['quantity_purchased'] ?? '') ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">ต้นทุนต่อหน่วย (บาท)</label>
                <input type="number" step="0.0001" name="cost_per_unit" class="form-control"
                       value="<?= htmlspecialchars($_POST['cost_per_unit'] ?? '') ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">วันที่รับเข้า</label>
                <input type="date" name="purchase_date" class="form-control"
                       value="<?= htmlspecialchars($_POST['purchase_date'] ?? date('Y-m-d')) ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">วันหมดอายุ (ถ้ามี)</label>
                <input type="date" name="expiry_date" class="form-control" value="<?= htmlspecialchars($_POST['expiry_date'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">รูปหลักฐานใบส่งของ / ใบเสร็จ (JPG, PNG, PDF ไม่เกิน 5MB)</label>
                <input type="file" name="receipt_photo" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
            </div>

            <div class="form-text mb-3">บันทึกโดย: <strong><?= htmlspecialchars(Auth::fullName()) ?></strong> (ผูกอัตโนมัติจากบัญชีที่ login อยู่)</div>

            <button type="submit" class="btn btn-dark w-100"><i class="ti ti-device-floppy"></i> บันทึกรับวัตถุดิบเข้าคลัง</button>
        </form>
    </div>
</div>

<p class="mt-3"><a href="index.php"><i class="ti ti-arrow-left"></i> กลับไปหน้าสต๊อกวัตถุดิบ</a></p>

<script>
function toggleNewIngredient(){
    const sel = document.getElementById('ingredient_id');
    const box = document.getElementById('new-ingredient-fields');
    box.style.display = (sel.value === '__new__') ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', toggleNewIngredient);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
