// kiosk.js — ตู้สั่งอาหารด้วยตนเอง
(function () {
    'use strict';

    var MENU = window.KIOSK_MENU || [];
    var CFG = window.KIOSK_CONFIG || {};
    var byId = {};
    MENU.forEach(function (m) { byId[m.id] = m; });

    // จำนวนที่ยังเพิ่มได้ของเมนูนี้ (วัตถุดิบหลักจำกัด) ไม่เกิน 20 ต่อรายการ
    function qtyOf(menuId) {
        return state.cart.reduce(function (s, l) { return s + (l.menuId === menuId ? l.qty : 0); }, 0);
    }
    function roomFor(menuId) {
        var m = byId[menuId];
        if (!m || m.soldOut) return 0;
        return m.maxQty === null || m.maxQty === undefined ? 99 : Math.max(0, m.maxQty - qtyOf(menuId));
    }

    function toast(msg) {
        var t = document.getElementById('k-toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'k-toast';
            t.style.cssText = 'position:fixed;left:50%;top:84px;transform:translateX(-50%);z-index:3000;background:#c1443c;color:#fff;padding:14px 22px;border-radius:14px;font-size:1.1rem;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.2);max-width:90vw;text-align:center;display:none';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.style.display = 'block';
        clearTimeout(t._h);
        t._h = setTimeout(function () { t.style.display = 'none'; }, 3500);
    }

    // อัปเดตสถานะเมนูจากเซิร์ฟเวอร์ (ของหมด / เหลือกี่จาน)
    function applyAvailability(map) {
        if (!map) return;
        MENU.forEach(function (m) {
            var a = map[m.id];
            if (!a) return;
            m.soldOut = a.out !== null;
            m.outLabel = a.out;
            m.maxQty = a.max;
            m.addons.forEach(function (x) { x.out = a.addonsOut.indexOf(x.id) !== -1; });
        });
    }
    function refreshAvailability() {
        fetch(CFG.apiUrl + '?action=menu').then(function (r) { return r.json(); })
            .then(function (d) { if (d.ok) { applyAvailability(d.menu); renderMenu(); } })
            .catch(function () {});
    }

    var state = {
        service: 'dine_in',
        category: null,
        cart: [],          // [{key, menuId, qty, addons:[addonId], note}]
        order: null,       // ผลตอบกลับจากเซิร์ฟเวอร์หลังสั่ง
        card: null,        // {code, label} บัตรคิวที่กรอกแล้ว
        placing: false
    };

    var $ = function (id) { return document.getElementById(id); };
    var baht = function (n) {
        return '฿' + Number(n).toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    };
    var esc = function (s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    // ================================================================
    // แถบขั้นตอน: บัตร → สมาชิก → เลือกอาหาร → ชำระเงิน (ซ่อนขั้นที่ร้านไม่ได้เปิดใช้)
    var STEP_OF = { 'scr-card': 'card', 'scr-member': 'member', 'scr-menu': 'menu', 'scr-pay': 'pay' };
    function renderSteps(screenId) {
        var cur = STEP_OF[screenId];
        if (!cur) return;
        var steps = [];
        if (CFG.cardRequired) steps.push(['card', 'บัตร']);
        if (CFG.serviceReady) steps.push(['member', 'สมาชิก']);
        steps.push(['menu', 'เลือกอาหาร'], ['pay', 'ชำระเงิน']);
        var idx = steps.map(function (x) { return x[0]; }).indexOf(cur);
        var html = steps.map(function (x, i) {
            var cls = i < idx ? 'done' : (i === idx ? 'now' : '');
            return '<li class="' + cls + '"' + (i === idx ? ' aria-current="step"' : '') + '><span class="n">' +
                (i < idx ? '<i class="ti ti-check"></i>' : (i + 1)) + '</span>' + x[1] + '</li>';
        }).join('');
        $(screenId).querySelectorAll('.k-steps').forEach(function (ol) { ol.innerHTML = html; });
    }

    function show(screenId) {
        document.querySelectorAll('.screen').forEach(function (s) { s.classList.remove('active'); });
        $(screenId).classList.add('active');
        renderSteps(screenId);
        closeSheets();
        window.scrollTo(0, 0);
        idle.reset();
    }

    function openSheet(id) { $(id).classList.add('open'); }
    function closeSheets() {
        document.querySelectorAll('.sheet-backdrop').forEach(function (s) {
            if (s.id !== 'sheet-idle') s.classList.remove('open');
        });
    }
    document.querySelectorAll('.sheet-backdrop').forEach(function (bd) {
        bd.addEventListener('click', function (e) {
            if (e.target === bd && bd.id !== 'sheet-idle') bd.classList.remove('open');
        });
        bd.querySelectorAll('[data-close]').forEach(function (b) {
            b.addEventListener('click', function () { bd.classList.remove('open'); });
        });
    });

    // ================================================================
    document.querySelectorAll('.svc-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            setService(b.dataset.service);
            renderMenu();
            if (CFG.cardRequired) { cardReset(); show('scr-card'); return; }
            afterCard();
        });
    });
    var afterCardTarget = null;
    function afterCard() {
        if (afterCardTarget) { var t = afterCardTarget; afterCardTarget = null; show(t); return; }
        if (CFG.serviceReady) memberReset();
        show(CFG.serviceReady ? 'scr-member' : 'scr-menu');
    }

    // ---------- บัตรคิว: กรอกรหัส 4 หลักบนบัตร ----------
    function renderCard() {
        var v = $('card-code').value;
        $('card-boxes').querySelectorAll('span').forEach(function (b, i) {
            b.textContent = v[i] || ''; b.classList.toggle('on', i === v.length);
        });
        $('btn-card-next').disabled = v.length !== 4 || state.cardChecking;
    }
    function cardReset() {
        state.card = null;
        $('card-code').value = ''; $('card-error').textContent = '';
        $('pill-card').hidden = true;
        renderCard();
    }
    $('card-keypad').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b || state.cardChecking) return;
        var v = $('card-code').value, k = b.dataset.k;
        if (k === 'back') v = v.slice(0, -1);
        else if (k === 'clear') v = '';
        else if (v.length < 4) v += k;
        $('card-code').value = v; $('card-error').textContent = '';
        renderCard();
        if (v.length === 4) checkCard();
    });
    function checkCard() {
        var code = $('card-code').value;
        if (code.length !== 4 || state.cardChecking) return;
        state.cardChecking = true; renderCard();
        $('card-error').textContent = 'กำลังตรวจบัตร…';
        fetch(CFG.apiUrl + '?action=card', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code: code })
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) throw new Error(d.error || 'ตรวจบัตรไม่สำเร็จ');
            state.card = { code: code, label: d.card_label };
            $('pill-card').textContent = d.card_label; $('pill-card').hidden = false;
            $('card-error').textContent = '';
            afterCard();
        }).catch(function (err) {
            $('card-error').textContent = err.message;
            $('card-code').value = '';
        }).finally(function () { state.cardChecking = false; renderCard(); });
    }
    $('btn-card-next').addEventListener('click', checkCard);
    $('btn-card-back').addEventListener('click', function () { cardReset(); show('scr-welcome'); });

    $('btn-member-edit').addEventListener('click', function(){memberReset(); show('scr-member');});
    // ป้ายสถานะบนแถบบน: ใครเป็นคนสั่ง (กดเพื่อเปลี่ยนได้)
    var memberName = '';
    function memberLabel() {
        var mode = $('member-mode').value;
        if (mode === 'existing') return 'สมาชิก: คุณ' + memberName;
        if (mode === 'register') return 'สมัครใหม่: คุณ' + memberName;
        return 'ไม่ใช่สมาชิก';
    }
    function renderMemberPill() {
        var b = $('btn-member-edit'), mode = $('member-mode').value;
        b.innerHTML = '';
        var i = document.createElement('i');
        i.className = 'ti ' + (mode === 'existing' ? 'ti-star' : mode === 'register' ? 'ti-user-plus' : 'ti-user');
        var t = document.createElement('span');
        t.className = 'mp-name';
        t.textContent = memberLabel();
        b.appendChild(i); b.appendChild(document.createTextNode(' ')); b.appendChild(t);
        b.classList.toggle('is-member', mode !== 'guest');
        b.title = memberLabel() + ' · แตะเพื่อเปลี่ยน';
        b.setAttribute('aria-label', memberLabel() + ' แตะเพื่อเปลี่ยน');
    }
    // ---------- เลือกสมาชิกแบบปุ่ม + แป้นตัวเลข ----------
    function renderPhone() {
        var d = $('member-phone').value, mask = '___-___-____', out = '', i = 0;
        for (var k = 0; k < mask.length; k++) out += mask[k] === '_' ? (d[i++] || '_') : mask[k];
        $('phone-display').textContent = out;
    }
    function memberChoose(mode) {
        $('member-mode').value = mode;
        $('member-error').textContent = '';
        if (mode === 'guest') {
            $('member-phone').value = ''; $('member-name').value = ''; $('member-consent').checked = false;
            memberName = '';
            renderMemberPill();
            show('scr-menu');
            return;
        }
        $('member-step-title').textContent = mode === 'register' ? 'สมัครสมาชิก · กรอกเบอร์มือถือ' : 'สมาชิกเดิม · กรอกเบอร์มือถือ';
        $('member-register-fields').hidden = mode !== 'register';
        $('member-step-choose').hidden = true;
        $('member-step-phone').hidden = false;
        renderPhone();
    }
    function memberReset() {
        $('member-mode').value = 'guest';
        $('member-phone').value = ''; $('member-name').value = ''; $('member-consent').checked = false;
        $('member-error').textContent = '';
        $('member-step-choose').hidden = false;
        $('member-step-phone').hidden = true;
        $('member-register-fields').hidden = true;
        memberName = '';
        renderMemberPill();
        renderPhone();
    }
    document.querySelectorAll('.mem-btn').forEach(function (b) {
        b.addEventListener('click', function () { memberChoose(b.dataset.mode); });
    });
    $('keypad').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        var v = $('member-phone').value, k = b.dataset.k;
        if (k === 'back') v = v.slice(0, -1);
        else if (k === 'clear') v = '';
        else if (v.length < 10) v += k;
        $('member-phone').value = v;
        $('member-error').textContent = '';
        renderPhone();
    });
    $('btn-member-back').addEventListener('click', memberReset);
    $('btn-member-next').addEventListener('click', function(){
        var mode=$('member-mode').value, phone=$('member-phone').value.trim();
        $('member-error').textContent='';
        if(mode!=='guest' && !/^0[689]\d{8}$/.test(phone)) { $('member-error').textContent='กรุณากรอกเบอร์มือถือ 10 หลัก (ขึ้นต้น 06 08 หรือ 09)'; return; }
        if(mode==='register' && !$('member-name').value.trim()) { $('member-error').textContent='กรุณากรอกชื่อที่ใช้เรียก'; return; }
        if(mode==='register' && !$('member-consent').checked) { $('member-error').textContent='กรุณาติ๊กยินยอมก่อนสมัครสมาชิก'; return; }
        var btn=$('btn-member-next');
        if(btn.disabled) return;
        btn.disabled=true;
        fetch(CFG.apiUrl + '?action=member', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode: mode, phone: phone })
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.ok) {
                memberName = mode === 'register' ? $('member-name').value.trim().split(/\s+/)[0].slice(0, 30) : (d.name || 'สมาชิก');
                renderMemberPill();
                show('scr-menu');
            }
            else $('member-error').textContent = d.error || 'ตรวจเบอร์ไม่สำเร็จ';
        }).catch(function () {
            $('member-error').textContent = 'เชื่อมต่อไม่ได้ กรุณาลองใหม่';
        }).finally(function () { btn.disabled = false; });
    });
    function setService(svc) {
        state.service = svc;
        var btn = $('btn-service');
        btn.innerHTML = svc === 'takeaway'
            ? '<i class="ti ti-paper-bag"></i> <span>สั่งกลับบ้าน</span>'
            : '<i class="ti ti-armchair"></i> <span>ทานที่ร้าน</span>';
    }
    $('btn-service').addEventListener('click', function () {
        setService(state.service === 'dine_in' ? 'takeaway' : 'dine_in');
    });
    $('btn-restart').addEventListener('click', function () {
        if (!state.cart.length || confirm('ล้างรายการทั้งหมดแล้วเริ่มใหม่?')) resetAll();
    });

    // ================================================================
    function categories() {
        var seen = {}, list = [];
        MENU.forEach(function (m) { if (!seen[m.category]) { seen[m.category] = 1; list.push(m.category); } });
        return list;
    }

    function renderMenu() {
        var cats = categories();
        if (state.category !== null && cats.indexOf(state.category) === -1) state.category = null;

        var tabs = '<button class="cat-tab' + (state.category === null ? ' active' : '') + '" data-cat="">ทั้งหมด</button>';
        cats.forEach(function (c) {
            tabs += '<button class="cat-tab' + (state.category === c ? ' active' : '') + '" data-cat="' + esc(c) + '">' + esc(c) + '</button>';
        });
        $('cat-tabs').innerHTML = tabs;
        $('cat-tabs').querySelectorAll('.cat-tab').forEach(function (t) {
            t.addEventListener('click', function () {
                state.category = t.dataset.cat || null;
                renderMenu();
            });
        });

        var qtyInCart = {};
        state.cart.forEach(function (l) { qtyInCart[l.menuId] = (qtyInCart[l.menuId] || 0) + l.qty; });

        var html = '';
        MENU.forEach(function (m) {
            if (state.category !== null && m.category !== state.category) return;
            var img = m.image ? '<img class="m-img" src="' + esc(m.image) + '" alt="" loading="lazy">' : '';
            html += '<button type="button" class="m-card' + (m.image ? '' : ' m-noimg') + (m.soldOut ? ' m-soldout' : '') + '" data-id="' + m.id + '"' + (m.soldOut ? ' disabled' : '') + '>' +
                img +
                (m.soldOut ? '<span class="m-ribbon">' + esc(m.outLabel || 'หมดวันนี้') + '</span>' : '') +
                (qtyInCart[m.id] ? '<span class="m-badge-qty">' + qtyInCart[m.id] + '</span>' : '') +
                '<div class="m-body"><div class="m-name">' + esc(m.name) + '</div>' +
                '<div class="m-price">' + baht(m.price) + '</div></div></button>';
        });
        $('menu-grid').innerHTML = html || '<div class="text-muted p-4">ยังไม่มีเมนูในหมวดนี้</div>';
        $('menu-grid').querySelectorAll('.m-card:not(.m-soldout)').forEach(function (c) {
            c.addEventListener('click', function () { openItem(+c.dataset.id); });
        });
    }

    // ---------- แผ่นเลือกจำนวนและ Add-on ----------
    var editing = null; // {menuId, qty, key} key = แก้รายการเดิมในตะกร้า

    // ที่ว่างของเมนูนี้ ถ้ากำลังแก้รายการเดิม นับจำนวนของรายการนั้นคืนให้ด้วย
    function roomForEdit() {
        if (!editing) return 0;
        var old = editing.key ? state.cart.filter(function (l) { return l.key === editing.key; })[0] : null;
        return roomFor(editing.menuId) + (old ? old.qty : 0);
    }

    function openItem(menuId, line) {
        var m = byId[menuId];
        if (!m || m.soldOut) return;
        if (!line && roomFor(menuId) < 1) { toast('"' + m.name + '" ใส่ตะกร้าครบจำนวนที่ทำได้แล้ว'); return; }
        editing = { menuId: menuId, qty: line ? line.qty : 1, key: line ? line.key : null };

        $('item-media').innerHTML = m.image ? '<img class="item-thumb" src="' + esc(m.image) + '" alt="">' : '';
        $('item-media').hidden = !m.image;
        $('item-add-label').textContent = line ? 'บันทึกการแก้ไข' : 'เพิ่มลงตะกร้า';
        $('item-name').textContent = m.name;
        $('item-price').textContent = baht(m.price);

        if (m.addons.length) {
            $('item-addons-wrap').style.display = '';
            var picked = line ? line.addons : [];
            $('item-addons').innerHTML = m.addons.map(function (a) {
                var on = picked.indexOf(a.id) !== -1 && !a.out;
                return '<label class="addon-chip"' + (a.out ? ' style="opacity:.45"' : '') + '><input type="checkbox" value="' + a.id + '"' + (a.out ? ' disabled' : '') + (on ? ' checked' : '') + '>' +
                    '<i class="ti ti-plus"></i>' + esc(a.name) +
                    (a.out ? ' <span class="price">(หมด)</span>' : (a.price > 0 ? ' <span class="price">+' + baht(a.price) + '</span>' : '')) + '</label>';
            }).join('');
            $('item-addons').querySelectorAll('input').forEach(function (i) {
                i.addEventListener('change', updateItemSum);
            });
        } else {
            $('item-addons-wrap').style.display = 'none';
            $('item-addons').innerHTML = '';
        }
        // แยกหมายเหตุเดิมเป็นปุ่มลัดที่ตรงกับคำในหลังบ้าน ที่เหลือใส่ช่องพิมพ์
        var parts = line && line.note ? line.note.split(/\s*,\s*/) : [];
        var kw = CFG.noteKeywords || [];
        renderNoteChips($('item-note-chips'), parts.filter(function (x) { return kw.indexOf(x) !== -1; }));
        $('item-note').value = parts.filter(function (x) { return x && kw.indexOf(x) === -1; }).join(', ');
        updateItemSum();
        $('sheet-cart').classList.remove('open');
        openSheet('sheet-item');
    }

    // ---------- หมายเหตุต่อจาน: ปุ่มลัดจากหลังบ้าน + พิมพ์เพิ่มเอง ----------
    function renderNoteChips(box, picked) {
        box.innerHTML = (CFG.noteKeywords || []).map(function (w) {
            var on = picked.indexOf(w) !== -1;
            return '<label class="addon-chip"><input type="checkbox" value="' + esc(w) + '"' + (on ? ' checked' : '') + '>' + esc(w) + '</label>';
        }).join('');
    }
    function composeNote(box, input) {
        var parts = Array.prototype.map.call(box.querySelectorAll('input:checked'), function (i) { return i.value; });
        var extra = input.value.trim();
        if (extra) parts.push(extra);
        return parts.join(', ').slice(0, 120);
    }

    function selectedAddons() {
        return Array.prototype.map.call($('item-addons').querySelectorAll('input:checked'), function (i) { return +i.value; });
    }

    function updateItemSum() {
        if (!editing) return;
        $('item-qty').textContent = editing.qty;
        $('item-sum').textContent = baht(lineTotal(editing.menuId, editing.qty, selectedAddons()));
    }

    $('item-inc').addEventListener('click', function () {
        if (!editing) return;
        if (editing.qty >= Math.min(20, roomForEdit())) {
            if (editing.qty < 20) toast('เมนูนี้ทำได้สูงสุด ' + roomForEdit() + ' จาน');
            return;
        }
        editing.qty++; updateItemSum();
    });
    $('item-dec').addEventListener('click', function () { if (editing && editing.qty > 1) { editing.qty--; updateItemSum(); } });
    $('item-add').addEventListener('click', function () {
        if (!editing) return;
        var at = -1;
        if (editing.key) {
            at = state.cart.map(function (l) { return l.key; }).indexOf(editing.key);
            state.cart.splice(at, 1);
        }
        var n = state.cart.length;
        addToCart(editing.menuId, editing.qty, selectedAddons(), composeNote($('item-note-chips'), $('item-note')));
        // แก้แล้วให้อยู่ตำแหน่งเดิมในตะกร้า
        if (at >= 0 && state.cart.length > n) { state.cart.splice(at, 0, state.cart.pop()); renderCart(); }
        $('sheet-item').classList.remove('open');
        editing = null;
    });

    // ================================================================
    function lineKey(menuId, addons, note) { return menuId + ':' + addons.slice().sort(function (a, b) { return a - b; }).join(',') + ':' + (note || ''); }

    function unitPrice(menuId, addons) {
        var m = byId[menuId];
        if (!m) return 0;
        var p = m.price;
        addons.forEach(function (aid) {
            var a = m.addons.filter(function (x) { return x.id === aid; })[0];
            if (a) p += a.price;
        });
        return p;
    }
    function lineTotal(menuId, qty, addons) { return unitPrice(menuId, addons) * qty; }

    function addToCart(menuId, qty, addons, note, opts) {
        note = (note || '').trim().slice(0, 120);
        opts = opts || {};
        var m = byId[menuId];
        if (!m || m.soldOut) return;
        // เก็บเฉพาะ Add-on ที่เมนูนี้เปิดให้เลือกจริง (กันผลจากการสั่งด้วยเสียงหลุดมา)
        var allowed = m.addons.filter(function (a) { return !a.out; }).map(function (a) { return a.id; });
        addons = addons.filter(function (a) { return allowed.indexOf(a) !== -1; });
        var room = roomFor(menuId);
        if (room < 1) { toast('"' + m.name + '" ' + (m.maxQty === 0 ? 'หมดชั่วคราว' : 'ใส่ตะกร้าครบจำนวนที่ทำได้แล้ว')); return; }
        if (qty > room) { toast('"' + m.name + '" เหลือทำได้อีก ' + room + ' จาน ใส่ให้ ' + room + ' จาน'); qty = room; }

        var key = lineKey(menuId, addons, note);
        var line = state.cart.filter(function (l) { return l.key === key; })[0];
        var before = line ? line.qty : 0;
        if (line) { line.qty = Math.min(20, line.qty + qty); if (opts.unsure) line.unsure = true; }
        else { line = { key: key, menuId: menuId, qty: Math.min(20, qty), addons: addons, note: note, unsure: !!opts.unsure }; state.cart.push(line); }
        renderCart();
        renderMenu();
        return { key: key, qty: line.qty - before };
    }

    function changeLine(key, delta) {
        var target = state.cart.filter(function (l) { return l.key === key; })[0];
        if (target && delta > 0 && roomFor(target.menuId) < 1) {
            toast('"' + byId[target.menuId].name + '" เหลือทำได้เท่านี้แล้ว');
            return;
        }
        state.cart = state.cart.map(function (l) {
            if (l.key === key) l.qty = Math.min(20, l.qty + delta);
            return l;
        }).filter(function (l) { return l.qty > 0; });
        renderCart();
        renderMenu();
    }

    function cartTotal() {
        return state.cart.reduce(function (s, l) { return s + lineTotal(l.menuId, l.qty, l.addons); }, 0);
    }
    function cartCount() { return state.cart.reduce(function (s, l) { return s + l.qty; }, 0); }

    function cartLinesHtml() {
        if (!state.cart.length) {
            return '<div class="cart-empty"><i class="ti ti-shopping-bag"></i>ยังไม่มีรายการ<br>แตะที่เมนูเพื่อเพิ่ม</div>';
        }
        return state.cart.map(function (l) {
            var m = byId[l.menuId];
            var names = l.addons.map(function (aid) {
                var a = m.addons.filter(function (x) { return x.id === aid; })[0];
                return a ? a.name : '';
            }).filter(Boolean);
            return '<div class="cart-line' + (l.unsure ? ' unsure' : '') + '"><div class="nm"><span>' + esc(m.name) +
                (l.unsure ? ' <span class="badge text-bg-warning">ตรวจอีกครั้ง</span>' : '') + '</span>' +
                '<button type="button" class="line-edit" data-edit="' + esc(l.key) + '"><i class="ti ti-pencil"></i> แก้ไข</button></div>' +
                (names.length ? '<div class="ad">+ ' + esc(names.join(', ')) + '</div>' : '') +
                (l.note ? '<div class="ad note"><i class="ti ti-message-2"></i> ' + esc(l.note) + '</div>' : '') +
                (l.unsure ? '<div class="ad unsure-hint">ระบบฟังไม่ชัด ตรวจว่าถูกเมนูไหม ถ้าไม่ใช่กดถังขยะหรือแก้ไข</div>' : '') +
                '<div class="row2"><div class="stepper stepper-sm">' +
                '<button type="button" data-line="' + esc(l.key) + '" data-d="-1">' + (l.qty === 1 ? '<i class="ti ti-trash"></i>' : '−') + '</button>' +
                '<output>' + l.qty + '</output>' +
                '<button type="button" data-line="' + esc(l.key) + '" data-d="1">+</button></div>' +
                '<div class="fw-bold">' + baht(lineTotal(l.menuId, l.qty, l.addons)) + '</div></div></div>';
        }).join('');
    }

    function bindLineButtons(container) {
        container.querySelectorAll('[data-line]').forEach(function (b) {
            b.addEventListener('click', function () { changeLine(b.dataset.line, +b.dataset.d); });
        });
        container.querySelectorAll('[data-edit]').forEach(function (b) {
            b.addEventListener('click', function () {
                var l = state.cart.filter(function (x) { return x.key === b.dataset.edit; })[0];
                if (l) openItem(l.menuId, l);
            });
        });
    }

    // หน้าชำระเงิน: แสดงรายการทั้งหมดให้ลูกค้าตรวจก่อนจ่าย
    function renderPayReview() {
        $('pay-lines').innerHTML = state.cart.map(function (l) {
            var m = byId[l.menuId];
            var names = l.addons.map(function (aid) {
                var a = m.addons.filter(function (x) { return x.id === aid; })[0];
                return a ? a.name : '';
            }).filter(Boolean);
            return '<div class="rv-line"><div><div class="rv-nm">' + esc(m.name) + ' × ' + l.qty + '</div>' +
                (names.length ? '<div class="rv-ad">+ ' + esc(names.join(', ')) + '</div>' : '') +
                (l.note ? '<div class="rv-note">หมายเหตุ: ' + esc(l.note) + '</div>' : '') +
                '</div><div class="fw-bold text-nowrap">' + baht(lineTotal(l.menuId, l.qty, l.addons)) + '</div></div>';
        }).join('');
        $('pay-service').textContent = (state.service === 'takeaway' ? 'สั่งกลับบ้าน' : 'ทานที่ร้าน') + ' · ' + cartCount() + ' จาน' +
            ($('member-mode').value !== 'guest' ? ' · สั่งโดย' + memberLabel() : '');
        $('pay-card').hidden = !state.card;
        $('pay-card').textContent = state.card ? state.card.label : '';
    }

    function renderCart() {
        var total = cartTotal(), count = cartCount(), html = cartLinesHtml();
        $('cart-lines').innerHTML = html;
        $('m-cart-lines').innerHTML = html +
            (state.cart.length ? '<div class="d-flex justify-content-between fs-4 fw-bold mt-3"><span>รวม</span><span>' + baht(total) + '</span></div>' : '');
        bindLineButtons($('cart-lines'));
        bindLineButtons($('m-cart-lines'));

        $('cart-total').textContent = baht(total);
        $('m-cart-total').textContent = baht(total);
        $('cart-count').textContent = count;
        $('m-cart-count').textContent = count;
        $('btn-checkout').disabled = !count;
        $('btn-m-checkout').disabled = !count;
    }

    $('btn-m-cart').addEventListener('click', function () { openSheet('sheet-cart'); });
    $('btn-checkout').addEventListener('click', goPay);
    $('btn-m-checkout').addEventListener('click', goPay);

    // ================================================================
    if ($('btn-voice-dock') && window.VoiceOrder) {
        // กดปุ่มไมค์ครั้งเดียวก็เริ่มฟังเลย (กดค้าง = ฟังเฉพาะตอนกด ตัดเสียงคนคุยกันช่วงที่ไม่ได้กด)
        var voiceClose = null;
        var closeVoice = function () { clearTimeout(voiceClose); $('sheet-voice').classList.remove('open'); };
        VoiceOrder.init({
            mode: 'auto',
            apiUrl: CFG.voiceApiUrl,
            holdButtons: [$('btn-voice-dock'), $('btn-voice')],
            panel: $('voice-panel'),
            onStart: function () {
                clearTimeout(voiceClose);
                if (!$('sheet-voice').classList.contains('open')) openSheet('sheet-voice');
            },
            onAdd: function (items) {
                return items.map(function (it) {
                    return addToCart(it.menu_id, it.quantity, (it.addons || []).map(function (a) { return a.addon_id; }),
                                     it.note || '', { unsure: !!it.needs_confirm });
                });
            },
            onRemove: function (h) { if (h) changeLine(h.key, -h.qty); },
            onDone: function (auto) {
                clearTimeout(voiceClose);
                voiceClose = setTimeout(closeVoice, auto ? 4000 : 0);
            }
        });
        $('voice-panel').addEventListener('pointerdown', function () { clearTimeout(voiceClose); });
    }

    // ================================================================
    // เช็กของกับเซิร์ฟเวอร์ก่อน ถ้าไม่พอให้แก้ที่หน้าเมนูเลย ไม่ต้องไปเจอตอนจ่ายเงิน
    // ปุ่มใช้แต้มโชว์เฉพาะสมาชิกเดิม (ยอดแต้มรู้ตอนคิดเงินจริง เพื่อไม่เปิดช่องเช็กแต้มจากเบอร์คนอื่น)
    function togglePayPoints() {
        var isMember = $('member-mode').value === 'existing';
        $('pay-points').classList.toggle('d-none', !isMember);
        if (!isMember) $('use-points').checked = false;
    }

    function goPay() {
        if (!state.cart.length || state.checking) return;
        state.checking = true;
        fetch(CFG.apiUrl + '?action=check', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cart: state.cart.map(function (l) { return { menu_id: l.menuId, qty: l.qty, addons: l.addons, note: l.note || '' }; }) })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            applyAvailability(d.menu);
            renderMenu();
            if (!d.ok) { toast(d.error || 'มีบางรายการสั่งไม่ได้'); return; }
            $('sheet-cart').classList.remove('open');
            renderPayReview();
            $('pay-total').textContent = baht(cartTotal());
            togglePayPoints();
            $('pay-error').classList.add('d-none');
            show('scr-pay');
        })
        .catch(function () {
            renderPayReview();
            $('pay-total').textContent = baht(cartTotal());
            togglePayPoints();
            $('pay-error').classList.add('d-none');
            show('scr-pay');
        })
        .finally(function () { state.checking = false; });
    }
    $('btn-pay-back').addEventListener('click', function () { show('scr-menu'); });

    document.querySelectorAll('.pay-btn').forEach(function (b) {
        b.addEventListener('click', function () { placeOrder(b.dataset.pay); });
    });

    function placeOrder(pay) {
        if (state.placing) return;
        if(!state.requestId){
            var bytes=new Uint8Array(16);
            if(!window.crypto || !crypto.getRandomValues){toast('เบราว์เซอร์ไม่รองรับการสร้างรหัสคำขอ กรุณาเปลี่ยนเบราว์เซอร์');return;}
            crypto.getRandomValues(bytes);state.requestId=Array.from(bytes,function(b){return b.toString(16).padStart(2,'0');}).join('');
        }
        state.placing = true;
        document.querySelectorAll('.pay-btn').forEach(function (b) { b.dataset.wasDisabled = b.disabled; b.disabled = true; });

        fetch(CFG.apiUrl + '?action=place', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_id: state.requestId,
                service: state.service,
                pay: pay,
                member_mode: $('member-mode').value,
                phone: $('member-phone').value,
                name: $('member-name').value,
                consent: $('member-consent').checked,
                use_points: $('use-points').checked,
                receipt: $('receipt-preference').value,
                card_code: state.card ? state.card.code : '',
                cart: state.cart.map(function (l) { return { menu_id: l.menuId, qty: l.qty, addons: l.addons, note: l.note || '' }; })
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) throw new Error(data.error || 'สั่งอาหารไม่สำเร็จ');
            state.order = data;
            if (pay === 'promptpay') showQr(); else showDone('cash');
        })
        .catch(function (err) {
            // บัตรถูกใช้ไปก่อนหน้า ให้กลับไปกรอกบัตรใหม่ (ตะกร้ายังอยู่)
            if (CFG.cardRequired && /บัตร/.test(err.message)) {
                cardReset(); $('card-error').textContent = err.message;
                show('scr-card'); afterCardTarget = 'scr-pay';
                return;
            }
            $('pay-error').textContent = err.message;
            $('pay-error').classList.remove('d-none');
        })
        .finally(function () {
            state.placing = false;
            document.querySelectorAll('.pay-btn').forEach(function (b) { b.disabled = b.dataset.wasDisabled === 'true'; });
        });
    }

    // ---------- สแกนจ่าย ----------
    var poll = null, qrDeadline = 0;

    function drawQr(el, text, size) {
        el.innerHTML = '';
        if (window.QRCode) {
            new QRCode(el, { text: text, width: size, height: size, correctLevel: QRCode.CorrectLevel.M });
        } else {
            el.innerHTML = '<div class="text-danger small p-3">โหลดตัวสร้าง QR ไม่ได้<br>กรุณาตรวจอินเทอร์เน็ต</div>';
        }
    }

    function slipUrl() {
        var base = location.href.replace(/\/kiosk\/.*$/, '/');
        return base + state.order.slip_path;
    }

    function showQrPoints(o) {
        var used = o.points_used || 0;
        $('qr-points').textContent = used > 0 ? 'ใช้แต้มสะสม ' + used + ' แต้ม ลด ' + baht(used) + ' แล้ว' : '';
        $('qr-points').classList.toggle('d-none', used <= 0);
    }

    function showQr() {
        var o = state.order;
        if (o.gateway_provider) { showOmiseQr(); return; }
        $('qr-slip').closest('.qr-card').classList.remove('d-none');
        $('btn-switch-cash').classList.remove('d-none');
        $('qr-queue').textContent = o.queue_label;
        $('qr-card').textContent = o.card_label ? '· ' + o.card_label : '';
        $('qr-amount').textContent = baht(o.total);
        showQrPoints(o);
        drawQr($('qr-pay'), o.promptpay, 230);
        drawQr($('qr-slip'), slipUrl(), 230);
        // เปิดผ่าน localhost = มือถือสแกนลิงก์นี้ไม่ได้ จึงเปิดปุ่มสำรองให้ส่งสลิปบนเครื่องเดียวกัน
        var isLocal = /^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname);
        $('qr-slip-link').href = slipUrl();
        $('qr-slip-link').classList.toggle('d-none', !isLocal);
        $('qr-status-text').textContent = 'รอรับสลิปการโอนเงิน…';
        $('qr-status-sub').textContent = 'หน้าจอนี้จะเปลี่ยนเองเมื่อตรวจสลิปผ่าน';
        show('scr-qr');

        qrDeadline = Date.now() + 5 * 60 * 1000;   // รอสลิปได้นานสุด 5 นาที
        stopPoll();
        poll = setInterval(checkPaid, 3000);
    }

    function stopPoll() { if (poll) { clearInterval(poll); poll = null; } }

    function showOmiseQr() {
        var o = state.order;
        $('qr-queue').textContent = o.queue_label;
        $('qr-card').textContent = o.card_label ? '· ' + o.card_label : '';
        $('qr-amount').textContent = baht(o.total);
        showQrPoints(o);
        $('qr-pay').textContent = 'กำลังสร้าง QR…';
        $('qr-slip').closest('.qr-card').classList.add('d-none');
        $('btn-switch-cash').classList.remove('d-none');
        $('qr-status-text').textContent = CFG.omiseTest ? 'โหมดทดสอบ — ไม่รับเงินจริง' : 'กำลังเตรียมการชำระเงิน';
        $('qr-status-sub').textContent = 'ไม่ต้องแนบสลิป ระบบจะตรวจสอบผลชำระให้อัตโนมัติ';
        show('scr-qr'); stopPoll();
        fetch(CFG.apiUrl + '?action=gateway', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body:JSON.stringify({o:o.order_id,t:o.token})
        }).then(function(r){return r.json();}).then(function(d){
            if(!d.ok) throw new Error(d.error || 'สร้าง QR ไม่สำเร็จ');
            var g=d.gateway;
            if(g.qr_raw){
                drawQr($('qr-pay'), g.qr_raw, 230);
            } else if(g.qr_url){
                var img=document.createElement('img'); img.src=g.qr_url;
                img.alt='QR PromptPay'; img.width=230; img.height=230;
                $('qr-pay').replaceChildren(img);
            } else $('qr-pay').textContent='ยังไม่มี QR กรุณาให้พนักงานตรวจออเดอร์นี้';
            $('qr-status-text').textContent=g.test ? 'โหมดทดสอบ — ไม่รับเงินจริง' : 'รอชำระเงิน';
            $('qr-status-sub').textContent=g.test ? (g.provider==='scb' ? 'สแกนด้วยแอป SCB EASY Simulator แล้วหน้าจอจะเปลี่ยนอัตโนมัติ' : 'ผู้ทดสอบยืนยันรายการใน Omise Dashboard แล้วหน้าจอจะเปลี่ยนอัตโนมัติ') : 'โอนสำเร็จแล้ว กรุณารอผลยืนยันบนหน้าจอนี้';
            qrDeadline=Date.now()+12*60*1000;
            poll=setInterval(checkPaid,3000); checkPaid();
        }).catch(function(e){
            $('qr-pay').textContent='กรุณาติดต่อพนักงาน พร้อมแจ้งเลขคิวด้านบน';
            $('qr-status-sub').textContent=e.message;
        });
    }

    function checkPaid() {
        if (!state.order) return stopPoll();
        if (Date.now() > qrDeadline) {
            stopPoll();
            showDone('timeout');
            return;
        }
        fetch(CFG.apiUrl + '?action=status&o=' + state.order.order_id + '&t=' + state.order.token)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) return;
                if (d.is_paid) { stopPoll(); showDone('paid'); return; }
                if (d.gateway && ['review','failed','expired','unknown'].indexOf(d.gateway.status) !== -1) {
                    $('qr-pay').textContent='กรุณาติดต่อพนักงาน พร้อมแจ้งเลขคิวด้านบน';
                    $('qr-status-text').textContent={review:'ได้รับผลชำระแล้ว ต้องให้พนักงานตรวจสอบ',failed:'ชำระไม่สำเร็จ',expired:'QR หมดอายุแล้ว',unknown:'กำลังตรวจสอบรายการ ห้ามชำระซ้ำ'}[d.gateway.status];
                    $('qr-status-sub').textContent='อย่าโอนซ้ำจนกว่าจะตรวจสอบรายการเรียบร้อย';
                    if(d.gateway.status !== 'unknown') stopPoll();
                }
                if (d.last_slip) {
                    var map = {
                        pending:   ['ได้รับสลิปแล้ว รอพนักงานตรวจสอบ', d.last_slip.reject_reason || ''],
                        rejected:  ['สลิปไม่ผ่านการตรวจสอบ', (d.last_slip.reject_reason || '') + ' กรุณาส่งใหม่'],
                        duplicate: ['สลิปนี้เคยใช้แล้ว', 'กรุณาส่งสลิปของออเดอร์นี้']
                    }[d.last_slip.status];
                    if (map) {
                        $('qr-status-text').textContent = map[0];
                        $('qr-status-sub').textContent = map[1];
                    }
                }
            })
            .catch(function () { /* เน็ตสะดุด ลองรอบถัดไป */ });
    }

    $('btn-switch-cash').addEventListener('click', function () {
        if (!state.order) return;
        if (!confirm('เปลี่ยนไปจ่ายเงินสดที่เคาน์เตอร์?\nถ้าสแกนจ่ายไปแล้ว ไม่ต้องกด รอหน้าจอเปลี่ยนเอง')) return;
        var btn = this; btn.disabled = true; stopPoll();
        fetch(CFG.apiUrl + '?action=to_cash', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({o: state.order.order_id, t: state.order.token})
        }).then(function (r) { return r.json(); }).then(function (d) {
            showDone(d.ok && d.is_paid ? 'paid' : 'cash');
        }).catch(function () { showDone('cash'); })
          .finally(function () { btn.disabled = false; });
    });

    // ================================================================
    var doneTimer = null;

    function showDone(kind) {
        var o = state.order;
        var cfg = {
            cash: ['<i class="ti ti-cash text-warning"></i>', 'สั่งอาหารเรียบร้อย',
                   'กรุณาชำระเงิน <strong>' + baht(o.total) + '</strong> ที่เคาน์เตอร์' + (o.points_used > 0 ? ' <span class="text-success">(ใช้แต้มลด ' + baht(o.points_used) + ' แล้ว)</span>' : '') + '<br><span class="text-muted fs-6">ครัวจะเริ่มทำเมื่อชำระเงินแล้ว</span>'],
            paid: ['<i class="ti ti-circle-check text-success"></i>', 'ชำระเงินเรียบร้อย',
                   'ชำระแล้ว รอครัวรับออเดอร์<br><span class="text-muted fs-6">กรุณารอเรียกเลขคิวที่เคาน์เตอร์</span>'],
            timeout: ['<i class="ti ti-clock text-warning"></i>', 'ยังไม่ได้รับสลิป',
                      'ถ้าโอนแล้ว กรุณาแสดงสลิปที่เคาน์เตอร์<br>หรือชำระเงินสด <strong>' + baht(o.total) + '</strong>']
        }[kind];

        if (o.gateway_provider && kind === 'timeout') {
            cfg = ['<i class="ti ti-clock text-warning"></i>', 'ยังไม่ได้รับผลยืนยัน',
                'กรุณาติดต่อพนักงานพร้อมเลขคิวนี้ และอย่าโอนซ้ำจนกว่าจะตรวจสอบรายการ'];
        }
        if (o.gateway_provider === 'omise' && CFG.omiseTest && kind === 'paid') {
            cfg[1] = 'ชำระทดสอบสำเร็จ — ไม่มีเงินจริง';
        }

        $('done-icon').innerHTML = cfg[0];
        $('done-title').textContent = cfg[1];
        $('done-queue').textContent = o.queue_label;
        $('done-card').hidden = !o.card_label;
        $('done-card').innerHTML = o.card_label ? '<i class="ti ti-id"></i> ' + esc(o.card_label) + ' · เก็บบัตรไว้ แสดงเมื่อรับอาหาร แล้วคืนบัตรที่เคาน์เตอร์' : '';
        $('done-message').innerHTML = cfg[2];
        $('done-service').hidden = !o.service;
        if(o.service) {
            var type=o.service.queue_type;
            $('done-type').className='queue-category queue-type-'+type;
            $('done-type').textContent=({single:'หนึ่งจาน',multi:'หลายจาน',other:'รายการอื่น'})[type];
            var track=new URL('../'+o.service.tracking_path,location.href).href;
            drawQr($('done-track-qr'),track,190);
            $('done-track-link').href=track;
            $('done-track-warning').hidden=!/^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname);
            $('done-receipt').textContent=$('receipt-preference').value==='paper' ? 'เลือกรับใบเสร็จกระดาษ ขอได้ที่แคชเชียร์หลังจ่ายเงิน' : 'เลือกไม่รับกระดาษ ดูใบเสร็จหลังจ่ายได้จากลิงก์ติดตาม';
        }
        show('scr-done');

        var left = o.service ? 30 : 20;
        $('done-countdown').textContent = left;
        if (doneTimer) clearInterval(doneTimer);
        doneTimer = setInterval(function () {
            left--;
            $('done-countdown').textContent = left;
            if (left <= 0) resetAll();
        }, 1000);
    }
    $('btn-done').addEventListener('click', resetAll);

    // ================================================================
    // ปุ่มเลือกรับใบเสร็จ (แทน dropdown ให้กดง่ายบนจอสัมผัส)
    function setReceipt(v) {
        $('receipt-preference').value = v;
        document.querySelectorAll('.rcpt-btn').forEach(function (b) {
            var on = b.dataset.receipt === v;
            b.classList.toggle('active', on);
            b.setAttribute('aria-checked', on ? 'true' : 'false');
        });
    }
    document.querySelectorAll('.rcpt-btn').forEach(function (b) {
        b.addEventListener('click', function () { setReceipt(b.dataset.receipt); });
    });

    function resetAll() {
        stopPoll();
        if (doneTimer) { clearInterval(doneTimer); doneTimer = null; }
        if(window.VoiceOrder) { VoiceOrder.stop(); VoiceOrder.lastItems=[]; }
        memberReset();
        cardReset(); afterCardTarget = null;
        setReceipt('digital');
        $('use-points').checked = false; $('pay-points').classList.add('d-none');
        $('done-track-qr').innerHTML='';
        $('done-track-link').removeAttribute('href');
        $('done-service').hidden=true;
        $('voice-panel').innerHTML='';
        clearInterval(idle.countdown);
        state.cart = [];
        state.order = null;
        state.requestId = null;
        state.category = null;
        editing = null;
        renderCart();
        $('sheet-idle').classList.remove('open');
        show('scr-welcome');
        refreshAvailability();
    }

    // ================================================================
    var idle = {
        timer: null, countdown: null, left: 15,
        reset: function () {
            clearTimeout(this.timer);
            var active = document.querySelector('.screen.active');
            // หน้าแรกไม่ต้องจับเวลา / หน้า QR มีตัวจับเวลา 5 นาทีของตัวเอง / หน้าเลขคิวนับถอยหลังอยู่แล้ว
            if (!active || ['scr-welcome', 'scr-qr', 'scr-done'].indexOf(active.id) !== -1) return;
            var self = this;
            this.timer = setTimeout(function () { self.ask(); }, (CFG.idleSeconds || 90) * 1000);
        },
        ask: function () {
            var self = this;
            this.left = 15;
            $('idle-countdown').textContent = this.left;
            $('sheet-idle').classList.add('open');
            clearInterval(this.countdown);
            this.countdown = setInterval(function () {
                self.left--;
                $('idle-countdown').textContent = self.left;
                if (self.left <= 0) { clearInterval(self.countdown); resetAll(); }
            }, 1000);
        },
        still: function () {
            clearInterval(this.countdown);
            $('sheet-idle').classList.remove('open');
            this.reset();
        }
    };
    $('btn-still-here').addEventListener('click', function () { idle.still(); });
    ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) {
        document.addEventListener(ev, function () {
            if (!$('sheet-idle').classList.contains('open')) idle.reset();
        }, { passive: true });
    });

    // เริ่มต้น
    renderCart();
})();
