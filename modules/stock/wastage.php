<?php
// บันทึกของเสีย
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/StockBatch.php';
Auth::requireLogin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batchId = (int) ($_POST['batch_id'] ?? 0);
    $qty = (float) ($_POST['quantity'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($batchId <= 0) {
        $errors[] = 'กรุณาเลือกล็อตวัตถุดิบ';
    }
    if ($qty <= 0) {
        $errors[] = 'จำนวนที่ทิ้งต้องมากกว่า 0';
    }

    if (empty($errors)) {
        try {
            StockBatch::recordWastage($batchId, $qty, $note ?: null);
            header('Location: index.php?wasted=1');
            exit;
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$db = getDB();
$batches = $db->query(
    "SELECT sb.batch_id, sb.quantity_remaining, sb.expiry_date, i.name AS ingredient_name, i.unit
     FROM stock_batches sb
     JOIN ingredients i ON i.ingredient_id = sb.ingredient_id
     WHERE sb.quantity_remaining > 0
     ORDER BY sb.expiry_date IS NULL, sb.expiry_date ASC"
)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('บันทึกของเสีย', 'วัตถุดิบที่เสียหรือหมดอายุ'); ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card" style="max-width:460px;">
    <div class="card-body">
        <form method="post" action="wastage.php">
            <div class="mb-3">
                <label class="form-label">เลือกล็อตวัตถุดิบที่เสีย</label>
                <select name="batch_id" class="form-select" required>
                    <option value="">-- เลือกล็อต --</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['batch_id'] ?>">
                            #B<?= str_pad($b['batch_id'], 3, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($b['ingredient_name']) ?>
                            (เหลือ <?= number_format($b['quantity_remaining'], 2) ?> <?= htmlspecialchars($b['unit']) ?>)
                            <?= $b['expiry_date'] ? ' หมดอายุ ' . $b['expiry_date'] : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">จำนวนที่ทิ้ง</label>
                <input type="number" step="0.01" name="quantity" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">หมายเหตุ (ถ้ามี)</label>
                <input type="text" name="note" class="form-control" placeholder="เช่น หมดอายุ, ทำหล่น, กลิ่นเปลี่ยน">
            </div>
            <button type="submit" class="btn btn-danger w-100"><i class="ti ti-trash"></i> บันทึกของเสีย</button>
        </form>
    </div>
</div>

<p class="mt-3"><a href="index.php"><i class="ti ti-arrow-left"></i> กลับไปหน้าสต๊อกวัตถุดิบ</a></p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
