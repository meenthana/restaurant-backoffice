<?php
// แก้ไขออเดอร์ที่ยังไม่จ่าย
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/OrderItem.php';
require_once __DIR__ . '/../../classes/Menu.php';
require_once __DIR__ . '/../../classes/Addon.php';
require_once __DIR__ . '/../../classes/MenuStock.php';
require_once __DIR__ . '/../../classes/KitchenTicket.php';
Auth::requireLogin();
require_once __DIR__.'/../../classes/Security.php';

$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$order = Order::find($orderId);

if (!$order) {
    die('ไม่พบออเดอร์นี้');
}
if (!in_array($order['status'], ['pending', 'active', 'ready'], true)) {
    die('ออเดอร์นี้ปิดแล้วและไม่สามารถสั่งเพิ่มได้');
}
if ((int) $order['is_paid'] === 1) {
    die('ออเดอร์นี้ชำระเงินแล้ว แก้ไขไม่ได้ <a href="view.php?id=' . $orderId . '">กลับ</a>');
}
if (ServiceFlow::details($orderId)) die('ออเดอร์จากตู้มีเลขคิวและรหัสแล้ว หากรายการผิดให้ยกเลิกออเดอร์ที่ยังไม่จ่ายแล้วสั่งใหม่ เพื่อไม่ให้เลขคิวของลูกค้าเปลี่ยน');
$additionalOnly = $order['status'] === 'ready';
// ยังไม่จ่าย = ยังไม่เข้าครัว แก้รายการได้เลยโดยไม่ต้องส่งรอบใหม่เข้าครัว
$notInKitchenYet = $order['status'] === 'pending';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::verifyCsrf();
    $existingQty = $_POST['existing_qty'] ?? [];   // [order_item_id => qty]
    $removeIds   = $_POST['remove'] ?? [];          // [order_item_id, ...]
    $newQty      = $_POST['qty'] ?? [];             // [menu_id => qty] สำหรับรายการใหม่
    $newAddons   = $_POST['addons'] ?? [];          // [menu_id => [addon_id, ...]]

    $db = getDB();
    $db->beginTransaction();
    try {
        $lock=$db->prepare('SELECT status,is_paid FROM orders WHERE order_id=? FOR UPDATE');$lock->execute([$orderId]);$fresh=$lock->fetch();
        if(!$fresh || (int)$fresh['is_paid'] || $fresh['status']!==$order['status'])throw new Exception('ออเดอร์เปลี่ยนแล้ว กรุณาโหลดใหม่');
        foreach(array_unique(array_merge(array_map('intval',array_keys($existingQty)),array_map('intval',$removeIds))) as $itemId){$check=$db->prepare('SELECT order_id FROM order_items WHERE order_item_id=?');$check->execute([$itemId]);if((int)$check->fetchColumn()!==$orderId)throw new Exception('รายการอาหารไม่ได้อยู่ในออเดอร์นี้');}
        if (!$additionalOnly) {
            // รายการเดิมแก้ไขได้เฉพาะก่อนอาหารพร้อม
            foreach ($removeIds as $itemId) {
                OrderItem::remove((int) $itemId);
            }
            foreach ($existingQty as $itemId => $qty) {
                if (in_array((string) $itemId, $removeIds, true)) {
                    continue;
                }
                $qty = (int) $qty;
                if ($qty <= 0) {
                    OrderItem::remove((int) $itemId);
                } else {
                    OrderItem::updateQuantity((int) $itemId, $qty);
                }
            }
        }

        // 3) เพิ่มรายการใหม่ (ถ้าเลือกจำนวน > 0)
        $itemsToAdd = array_filter($newQty, fn($q) => (int) $q > 0);
        $lines = [];
        $names = [];
        foreach ($itemsToAdd as $menuId => $qty) {
            $menu = Menu::find((int) $menuId);
            if ($menu && ($reason = Menu::unavailableReason($menu))) {
                throw new Exception('เมนู "' . $menu['name'] . '" ' . $reason . ' ไม่สามารถสั่งเพิ่มได้');
            }
            $lines[] = ['menu_id' => (int) $menuId, 'qty' => (int) $qty, 'addons' => array_map('intval', (array) ($newAddons[$menuId] ?? []))];
            $names[(int) $menuId] = $menu['name'] ?? '';
        }
        if ($problems = MenuStock::checkCart($lines, $names)) {
            throw new Exception(implode(' / ', $problems));
        }
        $newItemIds = [];
        foreach ($itemsToAdd as $menuId => $qty) {
            $newOrderItemId = OrderItem::add($orderId, (int) $menuId, (int) $qty);
            $newItemIds[] = $newOrderItemId;
            foreach (($newAddons[$menuId] ?? []) as $addonId) {
                OrderItem::addAddon($newOrderItemId, (int) $addonId);
            }
        }

        if ($newItemIds && $notInKitchenYet) {
            // รอจ่ายเงินอยู่ ครัวยังไม่ได้รับ — แค่เพิ่มรายการ
        } elseif ($newItemIds) {
            KitchenTicket::createRound($orderId, $newItemIds, 'รายการสั่งเพิ่ม');
            Order::reopenForAdditionalItems($orderId);
        } elseif ($additionalOnly) {
            throw new Exception('กรุณาเลือกเมนูที่ต้องการสั่งเพิ่มอย่างน้อย 1 รายการ');
        }

        $db->commit();
        header('Location: view.php?id=' . $orderId . '&edited=1');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $errors[] = $e->getMessage();
    }
}

$currentItems = OrderItem::getByOrder($orderId);
$addonsByItem = [];
foreach ($currentItems as $i) {
    $addonsByItem[$i['order_item_id']] = OrderItem::getAddons((int) $i['order_item_id']);
}

$menuItems = Menu::all();
$addonsByMenu = [];
$categories = [];
foreach ($menuItems as $m) {
    $addonsByMenu[$m['menu_id']] = Addon::forMenu((int) $m['menu_id']);
    $categories[$m['category']] = true;
}
$categories = array_keys($categories);
sort($categories);

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader(($additionalOnly ? 'สั่งเพิ่ม' : 'แก้ไขออเดอร์') . ' #' . $order['order_id'], $additionalOnly ? 'เพิ่มรายการใหม่เข้าครัว' : 'แก้หรือเพิ่มรายการอาหาร'); ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" action="edit.php"><?= Security::csrfInput() ?>
    <input type="hidden" name="order_id" value="<?= $orderId ?>">

    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">รายการปัจจุบัน</div>
        <?php if ($additionalOnly): ?><div class="alert alert-info rounded-0 border-start-0 border-end-0 mb-0 py-2 small">อาหารรอบเดิมพร้อมแล้ว จึงไม่สามารถลดหรือลบรายการเดิมได้</div><?php endif; ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>เมนู</th><th style="width:120px;">จำนวน</th><th>ราคา/หน่วย</th><th style="width:80px;">ลบ</th></tr></thead>
                <tbody>
                <?php if (!$currentItems): ?>
                    <tr><td colspan="4" class="text-muted text-center py-3">ไม่มีรายการในออเดอร์นี้แล้ว</td></tr>
                <?php endif; ?>
                <?php foreach ($currentItems as $i): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($i['menu_name']) ?>
                            <?php foreach ($addonsByItem[$i['order_item_id']] as $a): ?>
                                <div class="small text-muted">+ <?= htmlspecialchars($a['addon_name']) ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <input type="number" min="0" class="form-control form-control-sm"
                                   name="existing_qty[<?= $i['order_item_id'] ?>]" value="<?= $i['quantity'] ?>" <?= $additionalOnly ? 'disabled' : '' ?>>
                        </td>
                        <td>฿<?= number_format($i['unit_price'], 2) ?></td>
                        <td class="text-center">
                            <input type="checkbox" class="form-check-input" name="remove[]" value="<?= $i['order_item_id'] ?>" <?= $additionalOnly ? 'disabled' : '' ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white">
            <span class="fw-semibold">เพิ่มเมนูใหม่ (ถ้าต้องการ)</span>
            <?php if (count($categories) > 1): ?>
            <div class="d-flex gap-2 flex-wrap mt-3 category-tabs">
                <button type="button" class="btn btn-sm btn-dark category-tab active" data-category="__all__" onclick="filterCategory('__all__', this)">ทั้งหมด</button>
                <?php foreach ($categories as $cat): ?>
                <button type="button" class="btn btn-sm btn-outline-dark category-tab" data-category="<?= htmlspecialchars($cat) ?>" onclick="filterCategory('<?= htmlspecialchars($cat, ENT_QUOTES) ?>', this)"><?= htmlspecialchars($cat) ?></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($menuItems as $m): $menuId = (int) $m['menu_id']; $addons = $addonsByMenu[$menuId]; $outLabel = Menu::unavailableReason($m); $soldOut = $outLabel !== null; ?>
                <div class="col-6 col-md-4 col-lg-3 menu-tile-wrap" data-category="<?= htmlspecialchars($m['category']) ?>">
                    <div class="menu-tile h-100 d-flex flex-column <?= $soldOut ? 'menu-tile-soldout' : '' ?>">
                        <div class="menu-tile-img-wrap">
                            <?php if (!empty($m['image_path'])): ?>
                                <img src="/restaurant-backoffice/<?= htmlspecialchars($m['image_path']) ?>" alt="<?= htmlspecialchars($m['name']) ?>" class="menu-tile-img">
                            <?php else: ?>
                                <div class="menu-tile-img menu-tile-img-placeholder"><i class="ti ti-tools-kitchen-2"></i></div>
                            <?php endif; ?>
                            <?php if ($soldOut): ?><span class="sold-out-ribbon"><?= $outLabel ?></span><?php endif; ?>
                        </div>
                        <div class="p-2 d-flex flex-column flex-grow-1">
                            <div class="fw-semibold small"><?= htmlspecialchars($m['name']) ?></div>
                            <div class="text-success fw-semibold small mb-2">฿<?= number_format($m['price'], 2) ?></div>
                            <?php if ($soldOut): ?>
                                <div class="text-muted small mb-2">สินค้าหมดชั่วคราว</div>
                            <?php else: ?>
                            <div class="qty-stepper mb-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="stepQty(<?= $menuId ?>, -1)">−</button>
                                <input type="number" name="qty[<?= $menuId ?>]" id="qty-<?= $menuId ?>" value="0" min="0" class="form-control form-control-sm text-center" oninput="onQtyChange(<?= $menuId ?>)" readonly>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="stepQty(<?= $menuId ?>, 1)">+</button>
                            </div>
                            <?php if ($addons): ?>
                            <div class="addon-box mt-auto" id="addonbox-<?= $menuId ?>" style="display:none;">
                                <?php foreach ($addons as $a): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="addons[<?= $menuId ?>][]" value="<?= $a['addon_id'] ?>" id="eaddon-<?= $menuId ?>-<?= $a['addon_id'] ?>">
                                        <label class="form-check-label small" for="eaddon-<?= $menuId ?>-<?= $a['addon_id'] ?>"><?= htmlspecialchars($a['name']) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-dark"><i class="ti ti-device-floppy"></i> บันทึกและส่งรายการเพิ่มเข้าครัว</button>
    <a href="view.php?id=<?= $orderId ?>" class="btn btn-outline-secondary">ยกเลิก</a>
</form>

<style>
.category-tabs { overflow-x:auto; flex-wrap:nowrap !important; padding-bottom:2px; }
.category-tab { white-space:nowrap; }
.menu-tile { border:1px solid #E4DCC8; border-radius:10px; overflow:hidden; background:#fff; }
.menu-tile-img-wrap { position:relative; }
.menu-tile-img { width:100%; height:110px; object-fit:cover; display:block; }
.menu-tile-img-placeholder { display:flex; align-items:center; justify-content:center; background:#F2EEE2; color:#B7AE94; font-size:28px; }
.menu-tile-soldout { opacity:.55; filter:grayscale(60%); }
.sold-out-ribbon { position:absolute; top:8px; left:8px; background:#C1443C; color:#fff; font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; }
.qty-stepper { display:flex; align-items:stretch; gap:4px; }
.qty-stepper input { flex:1; min-width:0; }
</style>

<script>
function filterCategory(cat, btn) {
    document.querySelectorAll('.category-tab').forEach(b => {
        b.classList.remove('btn-dark', 'active');
        b.classList.add('btn-outline-dark');
    });
    btn.classList.add('btn-dark', 'active');
    btn.classList.remove('btn-outline-dark');
    document.querySelectorAll('.menu-tile-wrap').forEach(el => {
        el.style.display = (cat === '__all__' || el.dataset.category === cat) ? '' : 'none';
    });
}
function stepQty(menuId, delta) {
    const input = document.getElementById('qty-' + menuId);
    let val = Math.max(0, parseInt(input.value || '0', 10) + delta);
    input.value = val;
    onQtyChange(menuId);
}
function onQtyChange(menuId) {
    const addonBox = document.getElementById('addonbox-' + menuId);
    if (addonBox) {
        const input = document.getElementById('qty-' + menuId);
        addonBox.style.display = parseInt(input.value || '0', 10) > 0 ? 'block' : 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
