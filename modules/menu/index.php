<?php
// รายการเมนู
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Menu.php';
require_once __DIR__ . '/../../classes/MenuStock.php';
require_once __DIR__ . '/../../classes/Auth.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $result = Menu::delete((int) $_POST['menu_id']);
    header('Location: index.php?' . ($result === 'deleted' ? 'deleted=1' : 'deactivated=1'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    require_once __DIR__ . '/../../classes/Security.php';
    Security::verifyCsrf();
    getDB()->prepare('UPDATE menu SET is_active = 1 - is_active WHERE menu_id = :id')->execute(['id' => (int) $_POST['menu_id']]);
    header('Location: index.php?toggled=1');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_sold_out') {
    Menu::markSoldOutToday((int) $_POST['menu_id']);
    header('Location: index.php?sold_out=1');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_sold_out') {
    Menu::clearSoldOut((int) $_POST['menu_id']);
    header('Location: index.php?restocked=1');
    exit;
}

require_once __DIR__ . '/../../includes/header.php';

$menuItems = Menu::all(false);
?>

<?php pageHeader('เมนูอาหาร', 'จัดการเมนูของร้าน',
    '<a href="categories.php" class="btn btn-outline-secondary"><i class="ti ti-category"></i> หมวดหมู่</a>'
    . '<a href="addons.php" class="btn btn-outline-secondary"><i class="ti ti-adjustments"></i> ตัวเลือกเสริม</a>'
    . '<a href="create.php" class="btn btn-dark"><i class="ti ti-plus"></i> เพิ่มเมนู</a>'); ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">ลบเมนูสำเร็จแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['deactivated'])): ?>
    <div class="alert alert-warning">เมนูนี้เคยถูกสั่งในออเดอร์แล้ว จึงลบไม่ได้ — ระบบปิดใช้งานให้แทน</div>
<?php endif; ?>
<?php if (isset($_GET['sold_out'])): ?>
    <div class="alert alert-warning">ปิดขายเมนูชั่วคราวสำหรับวันนี้แล้ว — จะกลับมาขายได้เองอัตโนมัติพรุ่งนี้</div>
<?php endif; ?>
<?php if (isset($_GET['toggled'])): ?>
    <div class="alert alert-success">เปลี่ยนสถานะเมนูแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['restocked'])): ?>
    <div class="alert alert-success">เปิดขายเมนูกลับคืนแล้ว</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>รูป</th><th>ชื่อเมนู</th><th>หมวดหมู่</th><th>ราคา</th><th>สถานะ</th><th>วันนี้</th><th>ส่วนผสม</th><th>จัดการ</th></tr>
            </thead>
            <tbody>
            <?php if (!$menuItems): ?>
                <tr><td colspan="8" class="text-muted text-center py-4">ยังไม่มีเมนูในระบบ</td></tr>
            <?php endif; ?>
            <?php foreach ($menuItems as $m): $soldOut = Menu::isSoldOutToday($m); $max = MenuStock::maxDishes((int) $m['menu_id']); ?>
                <tr class="<?= $soldOut || $max === 0 ? 'table-warning' : '' ?>">
                    <td>
                        <?php if (!empty($m['image_path'])): ?>
                            <img src="/restaurant-backoffice/<?= htmlspecialchars($m['image_path']) ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:6px;">
                        <?php else: ?>
                            <span class="d-inline-flex align-items-center justify-content-center bg-light text-muted rounded" style="width:44px;height:44px;"><i class="ti ti-photo"></i></span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($m['name']) ?>
                        <?php if ($max === 0): ?><div><span class="badge text-bg-danger">วัตถุดิบหลักไม่พอ ปิดขายอัตโนมัติ</span></div>
                        <?php elseif ($max !== null && $max <= 5): ?><div><span class="badge text-bg-warning">ทำได้อีก <?= $max ?> จาน</span></div><?php endif; ?></td>
                    <td><?= htmlspecialchars($m['category']) ?></td>
                    <td>฿<?= number_format($m['price'], 2) ?></td>
                    <td><?= statusToggle('index.php', ['action' => 'toggle_active', 'menu_id' => (int) $m['menu_id']], (bool) $m['is_active'], 'ใช้งาน', 'ปิดใช้งาน') ?></td>
                    <td><?= statusToggle('index.php', ['action' => $soldOut ? 'clear_sold_out' : 'mark_sold_out', 'menu_id' => (int) $m['menu_id']], !$soldOut, 'มีขาย', 'หมดวันนี้', '', !$m['is_active']) ?></td>
                    <td><a href="recipe.php?menu_id=<?= $m['menu_id'] ?>"><i class="ti ti-list-details"></i> ดูส่วนผสม</a></td>
                    <td class="text-nowrap">
                        <a href="edit.php?menu_id=<?= $m['menu_id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="ti ti-edit"></i></a>
                        <form method="post" action="index.php" class="d-inline" onsubmit="return confirm('ยืนยันลบเมนู &quot;<?= htmlspecialchars(addslashes($m['name'])) ?>&quot; ใช่ไหม?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="menu_id" value="<?= $m['menu_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
