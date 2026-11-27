<?php
// แคชเชียร์รับออเดอร์อาหารตามสั่ง (DIY): เลือกเมนูฐาน + ตัวเลือกตามกลุ่ม + หมายเหตุ แล้วรับเงินก่อนเข้าครัว
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Menu.php';
require_once __DIR__ . '/../../classes/AddonGroup.php';
require_once __DIR__ . '/../../classes/QueueCard.php';
require_once __DIR__ . '/../../classes/ItemNote.php';
Auth::requireAnyRole(['owner','manager','cashier','staff']);

$bases = [];
foreach (Menu::diyReady() ? Menu::diyBases() : [] as $m) {
    $groups = [];
    foreach (AddonGroup::forMenu((int) $m['menu_id']) as $g) {
        $groups[] = [
            'id' => (int) $g['group_id'], 'name' => $g['name'], 'rule' => AddonGroup::label($g),
            'min' => (int) $g['min_select'], 'max' => (int) $g['max_select'],
            'addons' => array_map(fn($a) => ['id' => (int) $a['addon_id'], 'name' => $a['name'], 'price' => (float) $a['extra_price']], $g['addons']),
        ];
    }
    $bases[] = ['id' => (int) $m['menu_id'], 'name' => $m['name'], 'price' => (float) $m['price'],
                'out' => Menu::unavailableReason($m), 'groups' => $groups];
}
$cardRequired = QueueCard::typeReady() && QueueCard::required('counter');

require_once __DIR__ . '/../../includes/header.php';
pageHeader('สั่งอาหารตามสั่ง', 'ประกอบจานตามที่ลูกค้าบอก รับบัตรอาหารตามสั่ง แล้วรับเงินก่อน ออเดอร์จึงเข้าครัว',
    '<a href="index.php" class="btn btn-outline-dark"><i class="ti ti-arrow-left"></i> กลับหน้าคิดเงิน</a>');
?>
<?php if (!$bases): ?>
<div class="alert alert-warning">ยังไม่มีเมนูฐานตามสั่ง (DIY) · ไปที่ <a href="../menu/index.php">จัดการเมนู</a> แล้วเปิด "เมนูฐานตามสั่ง" ให้เมนูที่ต้องการ<?= Menu::diyReady() ? '' : ' (ต้องรัน sql/migration_v3_0_counter_diy.sql ก่อน)' ?></div>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
      <h2 class="h6 fw-bold mb-2">1. เมนูฐาน</h2>
      <div class="d-flex flex-wrap gap-2" id="diy-bases" role="group" aria-label="เมนูฐาน">
        <?php foreach ($bases as $b): ?>
        <button type="button" class="btn btn-outline-dark btn-lg diy-base" data-id="<?= $b['id'] ?>" <?= $b['out'] ? 'disabled' : '' ?>>
          <?= htmlspecialchars($b['name']) ?> <span class="small">฿<?= number_format($b['price']) ?></span><?= $b['out'] ? ' <span class="badge text-bg-secondary">' . htmlspecialchars($b['out']) . '</span>' : '' ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div></div>

    <div class="card mb-3"><div class="card-body">
      <h2 class="h6 fw-bold mb-2">2. ตัวเลือก</h2>
      <div id="diy-groups"><p class="text-muted small mb-0">เลือกเมนูฐานก่อน</p></div>
      <h2 class="h6 fw-bold mt-3 mb-2">3. หมายเหตุ <span class="text-muted fw-normal small">(ไม่บังคับ)</span></h2>
      <div class="d-flex flex-wrap gap-2" id="diy-note-chips">
        <?php foreach (ItemNote::keywords() as $i => $w): ?>
        <input type="checkbox" class="btn-check" id="note-<?= $i ?>" value="<?= htmlspecialchars($w) ?>" autocomplete="off">
        <label class="btn btn-sm btn-outline-secondary" for="note-<?= $i ?>"><?= htmlspecialchars($w) ?></label>
        <?php endforeach; ?>
      </div>
      <input id="diy-note" class="form-control mt-2" maxlength="120" placeholder="พิมพ์เพิ่มเอง เช่น ไม่ใส่หอม" aria-label="หมายเหตุเพิ่มเติม">
      <div class="d-flex align-items-center gap-3 mt-3 flex-wrap">
        <div class="input-group" style="width:150px">
          <button type="button" class="btn btn-outline-secondary" id="diy-dec" aria-label="ลดจำนวน">−</button>
          <input id="diy-qty" class="form-control text-center" value="1" inputmode="numeric" aria-label="จำนวนจาน">
          <button type="button" class="btn btn-outline-secondary" id="diy-inc" aria-label="เพิ่มจำนวน">+</button>
        </div>
        <button type="button" class="btn btn-dark btn-lg flex-grow-1" id="diy-add" disabled><i class="ti ti-plus"></i> เพิ่มจานนี้ <span id="diy-unit"></span></button>
      </div>
      <div id="diy-error" class="text-danger fw-bold small mt-2" role="alert"></div>
    </div></div>
  </div>

  <div class="col-lg-5">
    <div class="card position-sticky" style="top:1rem"><div class="card-body">
      <h2 class="h6 fw-bold mb-2">รายการในออเดอร์</h2>
      <div id="diy-lines" class="mb-3"><p class="text-muted small mb-0">ยังไม่มีรายการ</p></div>
      <div class="btn-group w-100 mb-3" role="group" aria-label="รับประทาน">
        <input type="radio" class="btn-check" name="diy-service" id="svc-dine" value="dine_in" checked>
        <label class="btn btn-outline-dark" for="svc-dine">ทานที่ร้าน</label>
        <input type="radio" class="btn-check" name="diy-service" id="svc-take" value="takeaway">
        <label class="btn btn-outline-dark" for="svc-take">กลับบ้าน</label>
      </div>
      <?php if ($cardRequired): ?>
      <div class="fw-bold mb-2">รหัสบัตรอาหารตามสั่งที่ให้ลูกค้า</div>
      <div class="pin-boxes" id="diy-card-boxes" aria-live="polite"><span></span><span></span><span></span><span></span></div>
      <input type="hidden" id="diy-card" value="">
      <div class="pin-pad mb-3" id="diy-card-pad" aria-label="แป้นกรอกรหัสบัตร">
        <button type="button" data-k="1">1</button><button type="button" data-k="2">2</button><button type="button" data-k="3">3</button>
        <button type="button" data-k="4">4</button><button type="button" data-k="5">5</button><button type="button" data-k="6">6</button>
        <button type="button" data-k="7">7</button><button type="button" data-k="8">8</button><button type="button" data-k="9">9</button>
        <button type="button" data-k="clear" class="pin-fn">ล้าง</button><button type="button" data-k="0">0</button><button type="button" data-k="back" class="pin-fn" aria-label="ลบตัวสุดท้าย"><i class="ti ti-backspace"></i></button>
      </div>
      <style>
        .pin-boxes { display: flex; gap: .6rem; justify-content: center; margin-bottom: .9rem; }
        .pin-boxes span { width: 3.4rem; height: 4rem; border: 3px solid #ddd6c8; border-radius: .9rem; background: #fff;
                          display: flex; align-items: center; justify-content: center; font-size: 2.2rem; font-weight: 800; }
        .pin-boxes span.on { border-color: #7c3aed; }
        .pin-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; }
        .pin-pad button { font-size: 1.5rem; font-weight: 800; padding: .55rem 0; border-radius: .8rem; border: 2px solid #ddd6c8; background: #fff; }
        .pin-pad button:active { transform: scale(.97); }
        .pin-pad .pin-fn { font-size: 1.05rem; color: #6c757d; }
      </style>
      <?php endif; ?>
      <div class="d-flex justify-content-between fs-4 fw-bold mb-3"><span>รวม</span><span id="diy-total">฿0</span></div>
      <button type="button" class="btn btn-success btn-lg w-100" id="diy-submit" disabled><i class="ti ti-cash-register"></i> สร้างออเดอร์ แล้วรับเงิน</button>
      <div id="diy-submit-error" class="text-danger fw-bold small mt-2" role="alert"></div>
    </div></div>
  </div>
</div>

<script>
(function () {
    var BASES = <?= json_encode($bases, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var CARD_REQUIRED = <?= $cardRequired ? 'true' : 'false' ?>;
    var CSRF = document.querySelector('meta[name=csrf-token]').content;
    var $ = function (id) { return document.getElementById(id); };
    var baht = function (n) { return '฿' + Number(n).toLocaleString('th-TH'); };
    var current = null, lines = [];

    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }

    function picked() {
        return Array.prototype.map.call(document.querySelectorAll('#diy-groups input:checked'), function (i) { return +i.value; });
    }
    function unitPrice() {
        if (!current) return 0;
        var p = current.price, ids = picked();
        current.groups.forEach(function (g) { g.addons.forEach(function (a) { if (ids.indexOf(a.id) !== -1) p += a.price; }); });
        return p;
    }
    function groupError() {
        if (!current) return 'เลือกเมนูฐานก่อน';
        var ids = picked();
        for (var i = 0; i < current.groups.length; i++) {
            var g = current.groups[i], n = g.addons.filter(function (a) { return ids.indexOf(a.id) !== -1; }).length;
            if (g.id && n < g.min) return 'ต้องเลือก' + g.name + 'อย่างน้อย ' + g.min + ' อย่าง';
        }
        return '';
    }
    function refresh() {
        var qty = Math.max(1, Math.min(20, parseInt($('diy-qty').value, 10) || 1));
        $('diy-unit').textContent = current ? baht(unitPrice() * qty) : '';
        $('diy-add').disabled = !current;
    }

    function selectBase(id) {
        current = BASES.filter(function (b) { return b.id === id; })[0] || null;
        document.querySelectorAll('.diy-base').forEach(function (b) {
            var on = +b.dataset.id === id;
            b.classList.toggle('btn-dark', on); b.classList.toggle('btn-outline-dark', !on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        var box = $('diy-groups'); box.innerHTML = '';
        if (!current.groups.length) box.appendChild(el('p', 'text-muted small mb-0', 'เมนูนี้ไม่มีตัวเลือก'));
        current.groups.forEach(function (g) {
            var wrap = el('div', 'mb-3');
            var head = el('div', 'fw-bold'); head.textContent = g.name + ' '; head.appendChild(el('span', 'text-muted fw-normal small', '(' + g.rule + ')'));
            wrap.appendChild(head);
            var row = el('div', 'd-flex flex-wrap gap-2 mt-1');
            g.addons.forEach(function (a) {
                var id = 'opt-' + current.id + '-' + a.id;
                var input = el('input', 'btn-check'); input.type = 'checkbox'; input.id = id; input.value = a.id; input.autocomplete = 'off';
                input.dataset.group = g.id; input.dataset.max = g.max;
                var label = el('label', 'btn btn-outline-primary', a.name + (a.price > 0 ? ' +' + a.price : ''));
                label.htmlFor = id;
                input.addEventListener('change', function () {
                    // กลุ่มที่เลือกได้ 1 อย่าง: เลือกใหม่แทนตัวเดิม · กลุ่มที่จำกัดจำนวน: เกินแล้วไม่ให้ติ๊ก
                    if (input.checked && g.max > 0) {
                        var same = Array.prototype.filter.call(row.querySelectorAll('input:checked'), function (x) { return x !== input; });
                        if (g.max === 1) same.forEach(function (x) { x.checked = false; });
                        else if (same.length >= g.max) { input.checked = false; $('diy-error').textContent = g.name + 'เลือกได้ไม่เกิน ' + g.max + ' อย่าง'; return; }
                    }
                    $('diy-error').textContent = '';
                    refresh();
                });
                row.appendChild(input); row.appendChild(label);
            });
            wrap.appendChild(row);
            box.appendChild(wrap);
        });
        $('diy-error').textContent = '';
        refresh();
    }

    function note() {
        var parts = Array.prototype.map.call(document.querySelectorAll('#diy-note-chips input:checked'), function (i) { return i.value; });
        var extra = $('diy-note').value.trim(); if (extra) parts.push(extra);
        return parts.join(', ').slice(0, 120);
    }
    function resetDish() {
        document.querySelectorAll('#diy-groups input, #diy-note-chips input').forEach(function (i) { i.checked = false; });
        $('diy-note').value = ''; $('diy-qty').value = 1;
        refresh();
    }

    function renderLines() {
        var box = $('diy-lines'); box.innerHTML = '';
        if (!lines.length) box.appendChild(el('p', 'text-muted small mb-0', 'ยังไม่มีรายการ'));
        var total = 0;
        lines.forEach(function (l, i) {
            total += l.unit * l.qty;
            var row = el('div', 'd-flex justify-content-between align-items-start gap-2 border-bottom py-2');
            var left = el('div');
            left.appendChild(el('div', 'fw-bold', l.name + ' × ' + l.qty));
            if (l.addonNames.length) left.appendChild(el('div', 'small text-muted', '+ ' + l.addonNames.join(' · ')));
            if (l.note) left.appendChild(el('div', 'small text-danger fw-bold', 'หมายเหตุ: ' + l.note));
            var right = el('div', 'text-end text-nowrap');
            right.appendChild(el('div', 'fw-bold', baht(l.unit * l.qty)));
            var del = el('button', 'btn btn-sm btn-outline-danger mt-1', 'ลบ'); del.type = 'button'; del.setAttribute('aria-label', 'ลบรายการ ' + l.name);
            del.addEventListener('click', function () { lines.splice(i, 1); renderLines(); });
            right.appendChild(del);
            row.appendChild(left); row.appendChild(right); box.appendChild(row);
        });
        $('diy-total').textContent = baht(total);
        $('diy-submit').disabled = !lines.length;
    }

    document.querySelectorAll('.diy-base').forEach(function (b) { b.addEventListener('click', function () { selectBase(+b.dataset.id); }); });
    $('diy-inc').addEventListener('click', function () { $('diy-qty').value = Math.min(20, (parseInt($('diy-qty').value, 10) || 1) + 1); refresh(); });
    $('diy-dec').addEventListener('click', function () { $('diy-qty').value = Math.max(1, (parseInt($('diy-qty').value, 10) || 1) - 1); refresh(); });
    if (CARD_REQUIRED) {
        var renderPin = function () {
            var v = $('diy-card').value;
            $('diy-card-boxes').querySelectorAll('span').forEach(function (b, i) { b.textContent = v[i] || ''; b.classList.toggle('on', i === v.length); });
        };
        var pinKey = function (k) {
            var v = $('diy-card').value;
            if (k === 'back') v = v.slice(0, -1);
            else if (k === 'clear') v = '';
            else if (/^\d$/.test(k) && v.length < 4) v += k;
            $('diy-card').value = v; $('diy-submit-error').textContent = '';
            renderPin();
        };
        $('diy-card-pad').addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) pinKey(b.dataset.k); });
        // พิมพ์รหัสจากคีย์บอร์ดได้ด้วย ยกเว้นตอนพิมพ์ในช่องหมายเหตุ/จำนวน
        document.addEventListener('keydown', function (e) {
            if (e.target.matches && e.target.matches('textarea, select, input:not([type=radio]):not([type=checkbox])')) return;
            if (/^\d$/.test(e.key)) { pinKey(e.key); e.preventDefault(); }
            else if (e.key === 'Backspace') { pinKey('back'); e.preventDefault(); }
        });
        renderPin();
    }

    $('diy-qty').addEventListener('input', refresh);
    $('diy-add').addEventListener('click', function () {
        var err = groupError();
        if (err) { $('diy-error').textContent = err; return; }
        var ids = picked(), names = [];
        current.groups.forEach(function (g) { g.addons.forEach(function (a) { if (ids.indexOf(a.id) !== -1) names.push(a.name); }); });
        lines.push({ menu_id: current.id, name: current.name, qty: Math.max(1, Math.min(20, parseInt($('diy-qty').value, 10) || 1)),
                     addons: ids, addonNames: names, note: note(), unit: unitPrice() });
        renderLines(); resetDish();
    });

    $('diy-submit').addEventListener('click', function () {
        var btn = this, card = $('diy-card') ? $('diy-card').value.trim() : '';
        $('diy-submit-error').textContent = '';
        if (CARD_REQUIRED && !/^\d{4}$/.test(card)) { $('diy-submit-error').textContent = 'กรุณากรอกรหัส 4 หลักบนบัตรอาหารตามสั่ง'; return; }
        btn.disabled = true;
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('payload', JSON.stringify({
            service: document.querySelector('input[name=diy-service]:checked').value,
            card_code: card,
            cart: lines.map(function (l) { return { menu_id: l.menu_id, qty: l.qty, addons: l.addons, note: l.note }; })
        }));
        fetch('diy_save.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) throw new Error(d.error || 'สร้างออเดอร์ไม่สำเร็จ');
                location.href = 'checkout.php?order_id=' + d.order_id;
            })
            .catch(function (e) { $('diy-submit-error').textContent = e.message; btn.disabled = false; });
    });
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
