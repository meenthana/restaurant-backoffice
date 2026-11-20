<?php
// หน้าสั่งออนไลน์ของลูกค้า (มือถือ: เมนูก่อน ตะกร้าเลื่อนขึ้นจากด้านล่าง · จอใหญ่: ตะกร้าอยู่ด้านขวา)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Member.php';
require_once __DIR__ . '/../classes/Menu.php';
require_once __DIR__ . '/../classes/Addon.php';
require_once __DIR__ . '/../classes/AppSetting.php';
require_once __DIR__ . '/../classes/MenuStock.php';
require_once __DIR__ . '/../classes/KioskOrder.php';
require_once __DIR__ . '/_layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['member_id'])) { header('Location: login.php'); exit; }

$member = Member::find((int) $_SESSION['member_id']);
if (!$member) { session_destroy(); header('Location: login.php'); exit; }

$menuItems   = Menu::forCustomers();
$avail       = MenuStock::availabilityMap();
$leadMinutes = max(0, (int) AppSetting::get('pickup_lead_minutes', '30'));
$closeTime   = AppSetting::get('store_close_time', '20:00');
if (!preg_match('/^\d{2}:\d{2}$/', $closeTime)) $closeTime = '20:00';
$closeAt     = strtotime(date('Y-m-d') . ' ' . $closeTime);
$voiceOn     = AppSetting::get('voice_order_enabled', '1') === '1';
$redeemCap   = min(100, max(1, (int) AppSetting::get('point_redeem_cap_percent', '50')));
$addonsByMenu = [];
$categories   = [];
foreach ($menuItems as $m) {
    $addonsByMenu[$m['menu_id']] = Addon::forMenu((int) $m['menu_id']);
    $categories[$m['category']] = true;
}
$categories = array_keys($categories);

// ตัวเลือกเวลารับ: เร็วสุด (ปัดขึ้นทีละ 5 นาที) แล้วเพิ่มทีละ 15 นาที สั่งได้เฉพาะวันนี้ก่อนร้านปิด
$earliest = (int) ceil((time() + $leadMinutes * 60) / 300) * 300;
$closedToday = $earliest > $closeAt;
$slots = [];
for ($i = 0; $i < 8 && $earliest + $i * 900 <= $closeAt; $i++) $slots[] = $earliest + $i * 900;
$slotLabel = function (int $ts): string {
    return (date('Y-m-d', $ts) === date('Y-m-d') ? '' : 'พรุ่งนี้ ') . date('H:i', $ts);
};

// ออเดอร์ที่ยังไม่จบ ให้กดกลับไปดูได้
$activeStmt = getDB()->prepare("SELECT order_id, queue_no, channel, status, is_paid FROM orders
    WHERE member_id = :m AND channel = 'online' AND status IN ('pending','active','ready') ORDER BY order_id DESC LIMIT 3");
$activeStmt->execute(['m' => (int) $member['member_id']]);
$activeOrders = $activeStmt->fetchAll();
$statusText = function (array $o): string {
    if ($o['status'] === 'ready') return 'อาหารพร้อมรับแล้ว';
    if ($o['status'] === 'active') return 'ครัวกำลังทำ';
    return (int) $o['is_paid'] === 1 ? 'รอครัวรับออเดอร์' : 'รอชำระเงิน';
};

onlineHead('สั่งอาหารออนไลน์');
onlineTopbar($member);
?>
<div class="on-wrap">
    <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><i class="ti ti-alert-triangle"></i> <?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <?php foreach ($activeOrders as $o): ?>
    <a class="on-active" href="<?= (int) $o['is_paid'] === 1 ? 'status.php' : 'pay.php' ?>?order_id=<?= (int) $o['order_id'] ?>">
        <span class="q"><?= htmlspecialchars(KioskOrder::queueLabel($o['queue_no'] ? (int) $o['queue_no'] : null, $o['channel'])) ?></span>
        <span class="flex-fill"><?= $statusText($o) ?></span>
        <span class="go"><?= (int) $o['is_paid'] === 1 ? 'ดูสถานะ' : 'ชำระเงิน' ?> <i class="ti ti-chevron-right"></i></span>
    </a>
    <?php endforeach; ?>

<?php if ($member['is_suspended']): ?>
    <div class="alert alert-danger"><i class="ti ti-ban"></i> บัญชีของคุณถูกระงับสิทธิ์การสั่งออนไลน์ชั่วคราว เนื่องจากไม่มารับสินค้าเกินกำหนด กรุณาติดต่อร้านโดยตรง</div>
<?php elseif ($closedToday): ?>
    <div class="alert alert-warning"><i class="ti ti-clock-off"></i> วันนี้ปิดรับออเดอร์ออนไลน์แล้ว (ร้านปิด <?= htmlspecialchars($closeTime) ?> น.) สั่งได้อีกครั้งวันพรุ่งนี้</div>
<?php else: ?>

<form action="order.php" method="post" id="orderForm" class="on-layout">
    <div class="on-main">
        <div class="on-intro">
            <div>
                <div class="eyebrow-c">สั่งล่วงหน้า รับที่ร้าน</div>
                <h1>สั่งก่อน ไม่ต้องรอคิว</h1>
            </div>
            <div class="on-intro-note"><i class="ti ti-clock"></i> รับได้เร็วสุด <?= $slotLabel($earliest) ?> น. · สั่งได้ถึงก่อนร้านปิด <?= htmlspecialchars($closeTime) ?> น. · ชำระด้วย QR</div>
        </div>
        <?php if ($member['no_show_count'] > 0): ?>
        <div class="on-note-warn"><i class="ti ti-alert-triangle"></i> เคยไม่มารับ <?= (int) $member['no_show_count'] ?> ครั้ง (ครบ <?= Member::NO_SHOW_THRESHOLD ?> ครั้งจะงดสั่งออนไลน์ชั่วคราว)</div>
        <?php endif; ?>

        <div class="on-toolbar">
            <div class="cat-tabs" id="cat-tabs">
                <button type="button" class="cat-tab active" data-cat="">ทั้งหมด</button>
                <?php foreach ($categories as $c): ?>
                    <button type="button" class="cat-tab" data-cat="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></button>
                <?php endforeach; ?>
            </div>
            <?php if ($voiceOn): ?>
            <button type="button" id="btn-voice" class="btn-touch btn-brand on-voice"><i class="ti ti-microphone"></i> สั่งด้วยเสียง</button>
            <?php endif; ?>
        </div>
        <?php if ($voiceOn): ?><div id="voice-panel" class="voice-panel on-voice-panel"></div><?php endif; ?>

        <div class="on-menu">
            <?php foreach ($menuItems as $m):
                $id = (int) $m['menu_id'];
                $outLabel = $avail[$id]['out'];
                $soldOut = $outLabel !== null;
                $maxQty = $avail[$id]['max'] === null ? 20 : min(20, $avail[$id]['max']);
                $addons = $addonsByMenu[$id];
            ?>
            <div class="m-card on-item <?= $soldOut ? 'm-soldout' : '' ?>" data-cat="<?= htmlspecialchars($m['category']) ?>" data-id="<?= $id ?>"
                 data-name="<?= htmlspecialchars($m['name']) ?>" data-price="<?= (float) $m['price'] ?>" data-max="<?= $maxQty ?>">
                <div class="on-thumb">
                    <?php if (!empty($m['image_path'])): ?>
                        <img class="m-img" src="../<?= htmlspecialchars($m['image_path']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="m-img m-img-ph"><i class="ti ti-tools-kitchen-2"></i></div>
                    <?php endif; ?>
                    <?php if ($soldOut): ?><span class="m-ribbon"><?= $outLabel ?></span><?php endif; ?>
                </div>
                <div class="m-body">
                    <div class="m-name"><?= htmlspecialchars($m['name']) ?></div>
                    <div class="on-row">
                        <div class="m-price">฿<?= number_format((float) $m['price'], 0) ?></div>
                        <?php if (!$soldOut): ?>
                        <button type="button" class="on-add" data-step="1" aria-label="เพิ่ม <?= htmlspecialchars($m['name']) ?>"><i class="ti ti-plus"></i> เพิ่ม</button>
                        <div class="stepper stepper-sm on-stepper" hidden>
                            <button type="button" data-step="-1" aria-label="ลด">−</button>
                            <output id="out-<?= $id ?>">0</output>
                            <button type="button" data-step="1" aria-label="เพิ่ม">+</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!$soldOut): ?>
                    <input type="hidden" name="qty[<?= $id ?>]" id="qty-<?= $id ?>" value="0">
                    <?php if ($addons): ?>
                    <div class="addon-chips on-addons" id="addons-<?= $id ?>" hidden>
                        <?php foreach ($addons as $a): $aOut = in_array((int) $a['addon_id'], $avail[$id]['addonsOut'], true); ?>
                        <label class="addon-chip"<?= $aOut ? ' style="opacity:.45"' : '' ?>>
                            <input type="checkbox" name="addons[<?= $id ?>][]" value="<?= (int) $a['addon_id'] ?>" data-name="<?= htmlspecialchars($a['name']) ?>"
                                   id="oaddon-<?= $id ?>-<?= (int) $a['addon_id'] ?>" data-price="<?= (float) $a['extra_price'] ?>"<?= $aOut ? ' disabled' : '' ?>>
                            <?= htmlspecialchars($a['name']) ?><?php if ($aOut): ?> <span class="price">(หมด)</span><?php elseif ((float) $a['extra_price'] > 0): ?> <span class="price">+<?= number_format((float) $a['extra_price'], 0) ?></span><?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <aside class="on-cart" id="cart-panel" aria-label="ตะกร้า">
        <div class="on-cart-head">
            <div class="fw-bold fs-5"><i class="ti ti-shopping-bag"></i> ตะกร้าของคุณ</div>
            <button type="button" class="on-close" data-cart-close aria-label="ปิดตะกร้า"><i class="ti ti-x"></i></button>
        </div>
        <div class="on-cart-body">
            <div id="cart-items"></div>
            <div class="on-empty" id="cart-empty"><i class="ti ti-bowl"></i><div>ยังไม่ได้เลือกเมนู</div></div>

            <div class="on-section">
                <div class="on-label"><i class="ti ti-clock"></i> เวลาที่จะมารับ</div>
                <div class="on-slots">
                    <?php foreach ($slots as $i => $ts): ?>
                    <label class="addon-chip on-slot">
                        <input type="radio" name="pickup_slot" value="<?= date('Y-m-d\TH:i', $ts) ?>"<?= $i === 0 ? ' checked' : '' ?>>
                        <?= $slotLabel($ts) ?><?php if ($i === 0): ?> <span class="price">เร็วสุด</span><?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                    <label class="addon-chip on-slot"><input type="radio" name="pickup_slot" value="custom"> เวลาอื่น</label>
                </div>
                <input type="datetime-local" id="pickup-custom" class="form-control mt-2" hidden
                       min="<?= date('Y-m-d\TH:i', $earliest) ?>" max="<?= date('Y-m-d\TH:i', $closeAt) ?>" value="<?= date('Y-m-d\TH:i', $earliest) ?>">
                <input type="hidden" name="scheduled_at" id="scheduled-at" value="<?= date('Y-m-d\TH:i', $earliest) ?>">
            </div>

            <div class="on-section">
                <label class="on-label" for="note"><i class="ti ti-message"></i> หมายเหตุถึงร้าน (ถ้ามี)</label>
                <input type="text" name="note" id="note" class="form-control" maxlength="200" placeholder="เช่น ไม่ใส่ผัก / เผ็ดน้อย">
            </div>
        </div>
        <div class="on-cart-foot">
            <?php if ((int) $member['points'] > 0): ?>
            <label class="addon-chip on-slot mb-2" id="pts-toggle">
                <input type="checkbox" name="use_points" value="1" id="use-points"
                       data-points="<?= (int) $member['points'] ?>" data-cap="<?= $redeemCap ?>">
                ใช้แต้มสะสม <span class="price">มี <?= number_format((int) $member['points']) ?> แต้ม · ลดได้สูงสุด <?= $redeemCap ?>% ของบิล</span>
            </label>
            <div class="d-flex justify-content-between text-success mb-1" id="pts-row" hidden>
                <span>ส่วนลดจากแต้ม (<span id="pts-used">0</span> แต้ม)</span><span>−฿<span id="pts-amt">0</span></span>
            </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-baseline mb-2">
                <span class="text-muted"><span id="cart-count">0</span> จาน</span>
                <span class="on-total" id="cart-total">฿0</span>
            </div>
            <button type="submit" class="btn-touch btn-brand w-100" id="btn-submit" disabled><i class="ti ti-arrow-right"></i> ยืนยันและไปชำระเงิน</button>
        </div>
    </aside>
    <div class="on-backdrop" data-cart-close></div>

    <div class="on-bottombar" id="bottombar" hidden>
        <button type="button" class="btn-touch btn-brand w-100 on-open-cart" data-cart-open>
            <span class="on-badge" id="bar-count">0</span>
            <span class="flex-fill text-start">ดูตะกร้า</span>
            <span id="bar-total">฿0</span>
        </button>
    </div>
</form>
<?php endif; ?>
</div>

<script src="../assets/js/voice-order.js?v=<?= filemtime(__DIR__ . '/../assets/js/voice-order.js') ?>"></script>
<script>
(function () {
    var form = document.getElementById('orderForm');
    if (!form) return;
    var top = document.querySelector('.cust-topbar');
    var setTop = function () { if (top) document.documentElement.style.setProperty('--on-top', top.offsetHeight + 'px'); };
    setTop(); window.addEventListener('resize', setTop);
    var cards = document.querySelectorAll('.on-item[data-id]');
    var fmt = function (n) { return '฿' + n.toLocaleString('th-TH'); };
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };

    // แท็บหมวดหมู่
    document.querySelectorAll('.cat-tab').forEach(function (t) {
        t.addEventListener('click', function () {
            document.querySelectorAll('.cat-tab').forEach(function (x) { x.classList.remove('active'); });
            t.classList.add('active');
            cards.forEach(function (c) { c.hidden = !!t.dataset.cat && c.dataset.cat !== t.dataset.cat; });
        });
    });

    function qtyOf(id) { var i = document.getElementById('qty-' + id); return i ? (parseInt(i.value, 10) || 0) : 0; }

    function setQty(id, qty) {
        var input = document.getElementById('qty-' + id);
        if (!input) return;
        var card = input.closest('.on-item');
        var max = parseInt(card.dataset.max, 10);
        if (qty > max) { if (max < 20) alert('เมนูนี้เหลือทำได้อีก ' + max + ' จาน'); qty = max; }
        qty = Math.max(0, qty);
        input.value = qty;
        document.getElementById('out-' + id).textContent = qty;
        card.querySelector('.on-add').hidden = qty > 0;
        card.querySelector('.on-stepper').hidden = qty === 0;
        var box = document.getElementById('addons-' + id);
        if (box) box.hidden = qty === 0;
        card.classList.toggle('on-picked', qty > 0);
        render();
    }

    cards.forEach(function (c) {
        c.querySelectorAll('[data-step]').forEach(function (b) {
            b.addEventListener('click', function () { setQty(c.dataset.id, qtyOf(c.dataset.id) + (+b.dataset.step)); });
        });
        c.querySelectorAll('.addon-chip input').forEach(function (i) { i.addEventListener('change', render); });
    });

    // วาดรายการในตะกร้าใหม่ทุกครั้งที่มีการเปลี่ยนแปลง
    function render() {
        var total = 0, count = 0, html = '';
        cards.forEach(function (c) {
            var q = qtyOf(c.dataset.id);
            if (!q) return;
            var unit = parseFloat(c.dataset.price), names = [];
            c.querySelectorAll('.addon-chip input:checked').forEach(function (a) { unit += parseFloat(a.dataset.price) || 0; names.push(a.dataset.name); });
            total += unit * q; count += q;
            html += '<div class="on-line"><div class="flex-fill"><div class="fw-bold">' + esc(c.dataset.name) + '</div>' +
                (names.length ? '<div class="small text-muted">+ ' + esc(names.join(', ')) + '</div>' : '') +
                '<div class="small text-muted">' + fmt(unit) + ' / จาน</div></div>' +
                '<div class="text-end"><div class="stepper stepper-sm"><button type="button" data-line="' + c.dataset.id + '" data-d="-1">−</button>' +
                '<output>' + q + '</output><button type="button" data-line="' + c.dataset.id + '" data-d="1">+</button></div>' +
                '<div class="fw-bold mt-1">' + fmt(unit * q) + '</div></div></div>';
        });
        document.getElementById('cart-items').innerHTML = html;
        document.getElementById('cart-empty').hidden = count > 0;
        // ส่วนลดจากแต้ม (เซิร์ฟเวอร์คำนวณซ้ำด้วยกติกาเดียวกันตอนบันทึก)
        var ptsBox = document.getElementById('use-points'), ptsDisc = 0;
        if (ptsBox) {
            ptsDisc = ptsBox.checked ? Math.min(parseInt(ptsBox.dataset.points, 10), Math.floor(total * parseInt(ptsBox.dataset.cap, 10) / 100)) : 0;
            document.getElementById('pts-row').hidden = ptsDisc <= 0;
            document.getElementById('pts-used').textContent = ptsDisc.toLocaleString('th-TH');
            document.getElementById('pts-amt').textContent = ptsDisc.toLocaleString('th-TH');
        }
        var net = total - ptsDisc;
        document.getElementById('cart-total').textContent = fmt(net);
        document.getElementById('cart-count').textContent = count;
        document.getElementById('bar-total').textContent = fmt(net);
        document.getElementById('bar-count').textContent = count;
        document.getElementById('bottombar').hidden = count === 0;
        document.getElementById('btn-submit').disabled = count === 0;
        if (count === 0) openCart(false);
    }
    document.getElementById('cart-items').addEventListener('click', function (e) {
        var b = e.target.closest('[data-line]');
        if (b) setQty(b.dataset.line, qtyOf(b.dataset.line) + (+b.dataset.d));
    });

    // ตะกร้าแบบเลื่อนขึ้น (มือถือ)
    function openCart(on) { document.body.classList.toggle('on-cart-open', on); }
    document.querySelectorAll('[data-cart-open]').forEach(function (b) { b.addEventListener('click', function () { openCart(true); }); });
    document.querySelectorAll('[data-cart-close]').forEach(function (b) { b.addEventListener('click', function () { openCart(false); }); });

    // เวลารับ
    var hidden = document.getElementById('scheduled-at'), custom = document.getElementById('pickup-custom');
    form.querySelectorAll('input[name="pickup_slot"]').forEach(function (r) {
        r.addEventListener('change', function () {
            custom.hidden = r.value !== 'custom';
            hidden.value = r.value === 'custom' ? custom.value : r.value;
        });
    });
    custom.addEventListener('change', function () { hidden.value = custom.value; });

    var ptsToggle = document.getElementById('use-points');
    if (ptsToggle) ptsToggle.addEventListener('change', render);

    form.addEventListener('submit', function () {
        var b = document.getElementById('btn-submit');
        setTimeout(function () { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังส่งออเดอร์…'; }, 0);
    });

    if (document.getElementById('btn-voice') && window.VoiceOrder) {
        VoiceOrder.init({
            apiUrl: '../api/parse_voice_order.php',
            button: document.getElementById('btn-voice'),
            panel: document.getElementById('voice-panel'),
            onConfirm: function (items) {
                items.forEach(function (it) {
                    if (!document.getElementById('qty-' + it.menu_id)) return;
                    (it.addons || []).forEach(function (a) {
                        var cb = document.getElementById('oaddon-' + it.menu_id + '-' + a.addon_id);
                        if (cb && !cb.disabled) cb.checked = true;
                    });
                    setQty(it.menu_id, qtyOf(it.menu_id) + it.quantity);
                });
            }
        });
    }
    render();
})();
</script>
<?php onlineFoot(); ?>
