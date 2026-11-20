<?php
// พนักงานรับออเดอร์หน้าร้าน (มีสั่งด้วยเสียง)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Menu.php';
require_once __DIR__ . '/../../classes/Addon.php';
require_once __DIR__ . '/../../classes/MenuStock.php';
require_once __DIR__ . '/../../classes/TableSession.php';

$tableSessionId = (int) ($_GET['table_session_id'] ?? 0);
$tableSession = $tableSessionId > 0 ? TableSession::find($tableSessionId) : null;

$menuItems = Menu::forCustomers();

// ดึง Add-on ที่เปิดใช้ของแต่ละเมนู เตรียมไว้ล่วงหน้า (กัน query วนซ้ำเยอะ)
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

<?php pageHeader('รับออเดอร์หน้าร้าน', $tableSession ? 'โต๊ะ ' . $tableSession['table_codes'] : 'เลือกเมนูให้ลูกค้า'); ?>
<?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><i class="ti ti-alert-triangle"></i> <?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>

<form action="save.php" method="post" id="orderForm">
    <input type="hidden" name="table_session_id" value="<?= $tableSessionId ?>">
    <?php if ($tableSession): ?>
    <div class="service-summary mb-3">
        <div><i class="ti ti-table"></i> <strong><?= htmlspecialchars($tableSession['table_codes']) ?></strong></div>
        <div><i class="ti ti-users"></i> ลูกค้า <?= (int) $tableSession['guest_count'] ?> คน</div>
        <div class="ms-auto text-muted small">ชำระเงินเมื่อรับประทานเสร็จ</div>
    </div>
    <?php endif; ?>
    <div class="card mb-3 border-success-subtle">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <div class="fw-semibold"><i class="ti ti-microphone text-success"></i> สั่งด้วยเสียง</div>
                    <div class="text-muted small">พูดสั่งได้ทั้งประโยค</div>
                </div>
                <button type="button" id="btn-voice" class="btn btn-dark text-nowrap"><i class="ti ti-microphone"></i> สั่งด้วยเสียง</button>
            </div>
            <div id="voice-panel" class="voice-panel mt-2"></div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <label class="form-label">หมายเหตุถึงครัว</label>
            <input type="text" name="note" class="form-control" placeholder="เช่น ไม่ใส่ผัก / แพ้อาหารทะเล">
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-semibold">เลือกเมนู</span>
                <span class="badge text-bg-dark" id="cartBadge" style="display:none;"></span>
            </div>
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
            <div class="row g-3" id="menuGrid">
                <?php foreach ($menuItems as $m):
                    $menuId = (int) $m['menu_id'];
                    $addons = $addonsByMenu[$menuId];
                    $outLabel = Menu::unavailableReason($m);
                    $soldOut = $outLabel !== null;
                    $maxQty = MenuStock::maxDishes($menuId);
                ?>
                <div class="col-6 col-md-4 col-lg-3 menu-tile-wrap" data-category="<?= htmlspecialchars($m['category']) ?>">
                    <div class="menu-tile h-100 d-flex flex-column <?= $soldOut ? 'menu-tile-soldout' : '' ?>">
                        <div class="menu-tile-img-wrap">
                            <?php if (!empty($m['image_path'])): ?>
                                <img src="/restaurant-backoffice/<?= htmlspecialchars($m['image_path']) ?>" alt="<?= htmlspecialchars($m['name']) ?>" class="menu-tile-img">
                            <?php else: ?>
                                <div class="menu-tile-img menu-tile-img-placeholder"><i class="ti ti-tools-kitchen-2"></i></div>
                            <?php endif; ?>
                            <?php if ($soldOut): ?>
                                <span class="sold-out-ribbon"><?= $outLabel ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="p-2 d-flex flex-column flex-grow-1">
                            <div class="fw-semibold small"><?= htmlspecialchars($m['name']) ?></div>
                            <div class="text-success fw-semibold small mb-2">฿<?= number_format($m['price'], 2) ?></div>

                            <?php if ($soldOut): ?>
                                <div class="text-muted small mb-2"><?= $outLabel === 'หมดชั่วคราว' ? 'วัตถุดิบหลักไม่พอ' : 'ปิดขายวันนี้ จัดการได้ที่หน้าเมนู' ?></div>
                            <?php else: ?>
                            <div class="qty-stepper mb-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="stepQty(<?= $menuId ?>, -1)">−</button>
                                <input type="number" name="qty[<?= $menuId ?>]" id="qty-<?= $menuId ?>" value="0" min="0" data-max="<?= $maxQty === null ? 99 : $maxQty ?>" class="form-control form-control-sm text-center" oninput="onQtyChange(<?= $menuId ?>)" readonly>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="stepQty(<?= $menuId ?>, 1)">+</button>
                            </div>

                            <?php if ($addons): ?>
                            <div class="addon-box mt-auto" id="addonbox-<?= $menuId ?>" style="display:none;">
                                <div class="small text-muted mb-1">ตัวเลือกเสริม</div>
                                <?php foreach ($addons as $a): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               name="addons[<?= $menuId ?>][]" value="<?= $a['addon_id'] ?>"
                                               id="addon-<?= $menuId ?>-<?= $a['addon_id'] ?>">
                                        <label class="form-check-label small" for="addon-<?= $menuId ?>-<?= $a['addon_id'] ?>">
                                            <?= htmlspecialchars($a['name']) ?><?php if ((float) $a['extra_price'] > 0): ?> <span class="text-muted">(+฿<?= number_format($a['extra_price'], 2) ?>)</span><?php endif; ?>
                                        </label>
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

            <button type="submit" class="btn btn-dark mt-4"><i class="ti ti-cash-register"></i> บันทึกและไปรับเงิน</button>
        </div>
    </div>
</form>

<?php if ($tableSession && FEATURE_TABLES): ?>
<form action="../tables/cancel.php" method="post" class="mt-3" onsubmit="return confirm('ยกเลิกการเปิดโต๊ะนี้ใช่ไหม?');">
    <input type="hidden" name="session_id" value="<?= $tableSessionId ?>">
    <button class="btn btn-link text-danger p-0" type="submit"><i class="ti ti-x"></i> ยกเลิกและคืนสถานะโต๊ะ</button>
</form>
<?php else: ?>
<p class="mt-3"><a href="index.php"><i class="ti ti-arrow-left"></i> กลับไปหน้ารายการออเดอร์</a></p>
<?php endif; ?>


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
    const max = parseInt(input.dataset.max || '99', 10);
    if (val > max) { alert('เมนูนี้เหลือทำได้อีก ' + max + ' จาน'); val = max; }
    input.value = val;
    onQtyChange(menuId);
}

function onQtyChange(menuId) {
    const input = document.getElementById('qty-' + menuId);
    const addonBox = document.getElementById('addonbox-' + menuId);
    if (addonBox) {
        addonBox.style.display = parseInt(input.value || '0', 10) > 0 ? 'block' : 'none';
    }
    updateCartBadge();
}

function updateCartBadge() {
    let total = 0;
    document.querySelectorAll('input[name^="qty["]').forEach(el => total += parseInt(el.value || '0', 10));
    const badge = document.getElementById('cartBadge');
    if (total > 0) {
        badge.style.display = 'inline-block';
        badge.textContent = total + ' รายการที่เลือก';
    } else {
        badge.style.display = 'none';
    }
}
</script>

<script src="/restaurant-backoffice/assets/js/voice-order.js?v=<?= filemtime(__DIR__ . '/../../assets/js/voice-order.js') ?>"></script>
<script>
VoiceOrder.init({
    apiUrl: '/restaurant-backoffice/api/parse_voice_order.php',
    button: document.getElementById('btn-voice'),
    panel:  document.getElementById('voice-panel'),
    // เติมจำนวนและติ๊ก Add-on ลงการ์ดเมนูด้านล่าง ให้พนักงานตรวจอีกครั้งก่อนกดบันทึก
    onConfirm: function (items) {
        var first = null;
        items.forEach(function (it) {
            var input = document.getElementById('qty-' + it.menu_id);
            if (!input) return;
            input.value = (parseInt(input.value, 10) || 0) + it.quantity;
            onQtyChange(it.menu_id);
            (it.addons || []).forEach(function (a) {
                var cb = document.getElementById('addon-' + it.menu_id + '-' + a.addon_id);
                if (cb) cb.checked = true;
            });
            var tile = input.closest('.menu-tile');
            if (tile) tile.style.outline = '3px solid #2f8f5b';
            if (!first) first = input;
        });
        filterCategory('__all__', document.querySelector('.category-tab[data-category="__all__"]') || document.createElement('button'));
        if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
