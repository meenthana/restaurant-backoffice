<?php
// แปลงหน่วยวัตถุดิบ
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/StockBatch.php';
Auth::requireLogin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sourceBatchId = (int) ($_POST['source_batch_id'] ?? 0);
    $qty = (float) ($_POST['quantity'] ?? 0);
    $targetIngredientId = (int) ($_POST['target_ingredient_id'] ?? 0);
    $expiryDate = trim($_POST['expiry_date'] ?? '');

    if ($sourceBatchId <= 0) {
        $errors[] = 'กรุณาเลือกล็อตต้นทาง';
    }
    if ($qty <= 0) {
        $errors[] = 'จำนวนที่แปรรูปต้องมากกว่า 0';
    }
    if ($targetIngredientId <= 0) {
        $errors[] = 'กรุณาเลือกวัตถุดิบปลายทาง';
    }

    if (empty($errors)) {
        try {
            StockBatch::convert($sourceBatchId, $qty, $targetIngredientId, $expiryDate ?: null);
            header('Location: index.php?converted=1');
            exit;
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$db = getDB();
$batches = $db->query(
    "SELECT sb.batch_id, sb.quantity_remaining, i.name AS ingredient_name, i.unit
     FROM stock_batches sb
     JOIN ingredients i ON i.ingredient_id = sb.ingredient_id
     WHERE sb.quantity_remaining > 0
     ORDER BY i.name"
)->fetchAll();
$ingredients = $db->query('SELECT * FROM ingredients ORDER BY name')->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('แปรรูปวัตถุดิบ', 'เช่น หมูชิ้นเป็นหมูสับ'); ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card" style="max-width:460px;">
    <div class="card-body">
        <form method="post" action="convert.php">
            <div class="mb-3">
                <label class="form-label">ล็อตต้นทาง</label>
                <select name="source_batch_id" class="form-select" required>
                    <option value="">-- เลือกล็อตต้นทาง --</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['batch_id'] ?>">
                            #B<?= str_pad($b['batch_id'], 3, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($b['ingredient_name']) ?>
                            (เหลือ <?= number_format($b['quantity_remaining'], 2) ?> <?= htmlspecialchars($b['unit']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">จำนวนที่แปรรูป</label>
                <input type="number" step="0.01" name="quantity" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">วัตถุดิบปลายทาง</label>
                <select name="target_ingredient_id" class="form-select" required>
                    <option value="">-- เลือกวัตถุดิบปลายทาง --</option>
                    <?php foreach ($ingredients as $ing): ?>
                        <option value="<?= $ing['ingredient_id'] ?>"><?= htmlspecialchars($ing['name']) ?> (<?= htmlspecialchars($ing['unit']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">วันหมดอายุของล็อตใหม่ (ถ้ามี)</label>
                <input type="date" name="expiry_date" class="form-control">
            </div>
            <button type="submit" class="btn btn-dark w-100"><i class="ti ti-transfer-in"></i> แปรรูปวัตถุดิบ</button>
        </form>
    </div>
</div>

<p class="mt-3"><a href="index.php"><i class="ti ti-arrow-left"></i> กลับไปหน้าสต๊อกวัตถุดิบ</a></p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
