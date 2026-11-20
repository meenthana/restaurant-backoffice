<?php
// ปรับยอดสต๊อกตามที่นับได้จริง (ใช้กับของตกแต่ง/วัตถุดิบรองที่ไม่ได้ตัดทุกจาน)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/StockBatch.php';
require_once __DIR__ . '/../../classes/StockTransaction.php';
require_once __DIR__ . '/../../classes/OrderFulfillment.php';
Auth::requireLogin();

$db = getDB();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ingredientId = (int) ($_POST['ingredient_id'] ?? 0);
    $counted = (float) ($_POST['counted'] ?? -1);
    $note = trim($_POST['note'] ?? '');
    try {
        if ($counted < 0) throw new Exception('กรุณาใส่จำนวนที่นับได้');
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT COALESCE(SUM(quantity_remaining),0) FROM stock_batches WHERE ingredient_id = :id');
        $stmt->execute(['id' => $ingredientId]);
        $system = (float) $stmt->fetchColumn();
        $diff = round($counted - $system, 2);
        $label = 'ปรับยอดตามนับจริง' . ($note !== '' ? ' — ' . $note : '');

        if ($diff < 0) {
            StockBatch::deductFEFO($ingredientId, -$diff, null, null, $label, false, 'adjustment');
        } elseif ($diff > 0) {
            $batch = $db->prepare('SELECT batch_id FROM stock_batches WHERE ingredient_id = :id ORDER BY batch_id DESC LIMIT 1');
            $batch->execute(['id' => $ingredientId]);
            $batchId = $batch->fetchColumn();
            if (!$batchId) throw new Exception('วัตถุดิบนี้ยังไม่เคยรับเข้า ให้ใช้เมนู "รับของเข้า" ก่อน');
            $db->prepare('UPDATE stock_batches SET quantity_remaining = quantity_remaining + :q WHERE batch_id = :b')
               ->execute(['q' => $diff, 'b' => $batchId]);
            StockTransaction::log((int) $batchId, 'adjustment', $diff, null, null, $label);
        }
        $db->commit();
        header('Location: adjust.php?done=' . urlencode((string) $diff));
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$ingredients = $db->query(
    "SELECT i.ingredient_id, i.name, i.unit, i.ingredient_type, i.track_inventory,
            COALESCE(SUM(sb.quantity_remaining), 0) AS on_hand
     FROM ingredients i LEFT JOIN stock_batches sb ON sb.ingredient_id = i.ingredient_id
     GROUP BY i.ingredient_id, i.name, i.unit, i.ingredient_type, i.track_inventory
     ORDER BY FIELD(i.ingredient_type, 'garnish', 'secondary', 'main'), i.name"
)->fetchAll();

$recent = $db->query(
    "SELECT st.created_at, st.quantity, st.note, i.name, i.unit
     FROM stock_transactions st
     JOIN stock_batches sb ON sb.batch_id = st.batch_id
     JOIN ingredients i ON i.ingredient_id = sb.ingredient_id
     WHERE st.type = 'adjustment'
     ORDER BY st.transaction_id DESC LIMIT 15"
)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('ปรับยอดสต๊อก', 'ใส่จำนวนที่นับได้จริง ระบบปรับยอดให้'); ?>

<?php if (isset($_GET['done'])): ?>
    <div class="alert alert-success">ปรับยอดแล้ว (<?= (float) $_GET['done'] > 0 ? '+' : '' ?><?= htmlspecialchars($_GET['done']) ?>)</div>
<?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-md-7">
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>วัตถุดิบ</th><th>กลุ่ม</th><th class="text-end">ในระบบ</th><th style="width:230px;">นับได้จริง</th></tr></thead>
                    <tbody>
                    <?php foreach ($ingredients as $ing): ?>
                        <tr>
                            <td><?= htmlspecialchars($ing['name']) ?>
                                <?php if (!(int) $ing['track_inventory']): ?><div class="small text-muted">ไม่ตัดอัตโนมัติ</div><?php endif; ?></td>
                            <td><span class="badge text-bg-light border"><?= OrderFulfillment::TYPE_LABELS[$ing['ingredient_type']] ?? '-' ?></span></td>
                            <td class="text-end"><?= rtrim(rtrim(number_format((float) $ing['on_hand'], 2, '.', ''), '0'), '.') ?: '0' ?> <?= htmlspecialchars($ing['unit']) ?></td>
                            <td>
                                <form method="post" class="d-flex gap-1">
                                    <input type="hidden" name="ingredient_id" value="<?= $ing['ingredient_id'] ?>">
                                    <input type="number" step="0.01" min="0" name="counted" class="form-control form-control-sm" placeholder="จำนวน" required>
                                    <button class="btn btn-sm btn-dark text-nowrap">ปรับ</button>
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
            <div class="card-header">ปรับยอดล่าสุด</div>
            <ul class="list-group list-group-flush">
                <?php if (!$recent): ?><li class="list-group-item text-muted small">ยังไม่มี</li><?php endif; ?>
                <?php foreach ($recent as $r): ?>
                    <li class="list-group-item small d-flex justify-content-between">
                        <span><?= htmlspecialchars($r['name']) ?> <span class="text-muted"><?= date('d/m H:i', strtotime($r['created_at'])) ?></span></span>
                        <span class="fw-semibold <?= (float) $r['quantity'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (float) $r['quantity'] > 0 ? '+' : '' ?><?= rtrim(rtrim(number_format((float) $r['quantity'], 2, '.', ''), '0'), '.') ?> <?= htmlspecialchars($r['unit']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<p class="mt-3"><a href="index.php"><i class="ti ti-arrow-left"></i> กลับไปหน้าสต๊อกวัตถุดิบ</a></p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
