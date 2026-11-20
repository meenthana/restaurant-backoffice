// หน้าตั้งค่าเครื่องพิมพ์: ตรวจว่าเบราว์เซอร์รองรับอะไร ค้นหา/เชื่อมต่อ และพิมพ์ทดสอบ
(function () {
    'use strict';
    var B = window.PrinterBridge;
    var form = document.querySelector('form.printer-card');
    if (!B || !form) return;
    var $ = function (id) { return document.getElementById(id); };
    var sup = B.support();

    function conn() { var r = form.querySelector('input[name=connection]:checked'); return r ? r.value : 'system'; }
    function paper() { var r = form.querySelector('input[name=paper_width]:checked'); return r ? r.value : '80'; }

    function setStatus(ok, text) {
        $('printer-badge').className = 'badge ' + (ok ? 'text-bg-success' : 'text-bg-secondary');
        $('printer-badge').textContent = ok ? 'เชื่อมต่อแล้ว' : 'ยังไม่ได้เชื่อมต่อ';
        $('printer-status-text').textContent = text;
    }

    function choose(c) {
        $('device_name').value = c.name; $('device_id').value = c.id;
        setStatus(true, c.name + ' — กดบันทึกด้านล่างเพื่อจำเครื่องนี้');
    }

    // แสดงว่าเครื่อง/เบราว์เซอร์นี้รองรับแบบไหน
    var notes = {
        usb: sup.usb ? 'พร้อมใช้ (Chrome/Edge)' : 'เบราว์เซอร์นี้ไม่รองรับ USB',
        bluetooth: sup.bluetooth ? 'พร้อมใช้ (Chrome/Edge)' : 'เบราว์เซอร์นี้ไม่รองรับ Bluetooth',
        serial: sup.serial ? 'พร้อมใช้ (Chrome/Edge บนคอม)' : 'ใช้ได้เฉพาะ Chrome/Edge บนคอม',
        system: 'ใช้ได้ทุกเบราว์เซอร์'
    };
    form.querySelectorAll('.conn-support').forEach(function (el) {
        var k = el.dataset.support;
        el.textContent = notes[k];
        el.closest('.printer-option').classList.toggle('is-unsupported', k !== 'system' && !sup[k]);
    });

    function refreshUi() {
        var c = conn();
        var direct = c !== 'system';
        $('btn-find').hidden = !direct;
        $('btn-forget').hidden = !direct || !$('device_id').value;
        $('btn-test').textContent = '';
        $('btn-test').insertAdjacentHTML('beforeend', '<i class="ti ti-printer" aria-hidden="true"></i> พิมพ์ทดสอบ');
        var help = '';
        if (!sup.secure && direct) help = 'ต้องเปิดหน้านี้ผ่าน https:// หรือ localhost จึงจะเชื่อมเครื่องพิมพ์ได้';
        else if (c === 'usb') help = 'Windows: ถ้าเชื่อมไม่ได้ ("Unable to claim interface") ให้เปลี่ยนไดรเวอร์เครื่องพิมพ์เป็น WinUSB ด้วยโปรแกรม Zadig หรือใช้แบบ "เครื่องพิมพ์ของระบบ" แทน';
        else if (c === 'bluetooth') help = 'เปิดเครื่องพิมพ์และ Bluetooth ของแท็บเล็ต แล้วกดค้นหา เลือกชื่อเครื่องพิมพ์จากรายการ (รองรับรุ่น BLE)';
        else if (c === 'serial') help = 'จับคู่ Bluetooth ใน Windows ก่อน แล้วเลือกพอร์ต COM ของเครื่องพิมพ์';
        else help = 'พิมพ์ผ่านหน้าต่างพิมพ์ของเบราว์เซอร์ เลือกเครื่องพิมพ์ที่ติดตั้งในเครื่องได้เลย ระบบจะตั้งขนาดหน้ากระดาษให้ตามที่เลือก';
        $('detect-help').textContent = help;
        listKnown();
    }

    // เครื่อง USB ที่เสียบอยู่และเคยอนุญาตแล้ว
    async function listKnown() {
        var ul = $('known-devices'); ul.innerHTML = '';
        if (conn() !== 'usb' || !sup.usb) return;
        var list = await B.knownUsb();
        list.forEach(function (d) {
            var li = document.createElement('li');
            li.innerHTML = '<i class="ti ti-usb"></i> พบ: ' + (d.productName || 'USB device') + ' <button type="button" class="btn btn-sm btn-outline-primary ms-2">ใช้เครื่องนี้</button>';
            li.querySelector('button').addEventListener('click', async function () {
                try { await B.disconnect(); choose(await B.reconnect({ connection: 'usb', device_id: hexId(d) })); } catch (e) { fail(e); }
            });
            ul.appendChild(li);
        });
    }
    function hexId(d) { var h = function (n) { return ('0000' + n.toString(16)).slice(-4); }; return h(d.vendorId) + ':' + h(d.productId); }

    function fail(e) {
        if (e && e.name === 'NotFoundError') { setStatus(false, 'ยังไม่ได้เลือกเครื่อง'); return; }
        setStatus(false, 'เชื่อมต่อไม่สำเร็จ: ' + (e && e.message ? e.message : e));
    }

    $('btn-find').addEventListener('click', async function () {
        try {
            await B.disconnect();
            var c = conn();
            if (!sup[c]) throw new Error(notes[c]);
            choose(c === 'usb' ? await B.requestUsb() : c === 'bluetooth' ? await B.requestBluetooth() : await B.requestSerial());
            refreshUi();
        } catch (e) { fail(e); }
    });

    $('btn-forget').addEventListener('click', async function () {
        await B.disconnect();
        $('device_name').value = ''; $('device_id').value = '';
        setStatus(false, 'ล้างแล้ว กดบันทึกเพื่อยืนยัน');
        refreshUi();
    });

    $('btn-test').addEventListener('click', async function () {
        var rows = B.testRows(window.PRINTER_PAGE.store, paper());
        if (conn() === 'system' || paper() === 'a4') { systemPrint(rows); return; }
        try {
            if (!B.current()) await B.reconnect({ connection: conn(), device_id: $('device_id').value });
            if (!B.current()) throw new Error('กด "ค้นหาและเชื่อมต่อ" ก่อน');
            await B.printRows(rows, paper());
            setStatus(true, 'ส่งงานพิมพ์ทดสอบแล้ว');
        } catch (e) { fail(e); }
    });

    // พิมพ์ผ่านหน้าต่างพิมพ์ โดยตั้งขนาดหน้าตามกระดาษ
    function systemPrint(rows) {
        // กระดาษม้วนไม่ระบุความสูง ให้ไดรเวอร์เครื่องพิมพ์กำหนดเอง ('80mm auto' เป็นค่าที่ Chrome ไม่รับ)
        var page = paper() === 'a4' ? 'size:A4;margin:10mm' : 'margin:0';
        var width = paper() === '58' ? '48mm' : paper() === '80' ? '72mm' : 'auto';
        var w = window.open('', '_blank', 'width=420,height=640');
        if (!w) { setStatus(false, 'เบราว์เซอร์บล็อกหน้าต่างพิมพ์'); return; }
        var html = rows.map(function (r) {
            if (r.hr) return '<hr>';
            if (r.big) return '<div class="big">' + esc(r.big) + '</div>';
            if (r.left !== undefined) return '<div class="row"><span>' + esc(r.left) + '</span><span>' + esc(r.right) + '</span></div>';
            return '<div style="text-align:' + (r.align || 'left') + '">' + esc(r.text) + '</div>';
        }).join('');
        w.document.write('<!doctype html><meta charset="utf-8"><title>ทดสอบพิมพ์</title><style>@page{' + page + '}body{font-family:Sarabun,Tahoma,sans-serif;font-size:13px;margin:0;width:' + width + '}.row{display:flex;justify-content:space-between}.big{font-size:40px;font-weight:700;text-align:center}hr{border:0;border-top:1px dashed #000}</style>' + html + '<script>onafterprint=function(){close()};onload=function(){setTimeout(print,300)}<\/script>');
        w.document.close();
    }
    function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    form.querySelectorAll('input[name=connection]').forEach(function (r) { r.addEventListener('change', refreshUi); });
    // เลือกประเภทแล้วตั้งค่าแนะนำให้ (เปลี่ยนเองได้)
    var suggest = { thermal: ['usb', '80'], portable: ['bluetooth', '58'], office: ['system', 'a4'] };
    form.querySelectorAll('input[name=model]').forEach(function (r) {
        r.addEventListener('change', function () {
            var s = suggest[r.value]; if (!s) return;
            var c = form.querySelector('input[name=connection][value="' + s[0] + '"]');
            var p = form.querySelector('input[name=paper_width][value="' + s[1] + '"]');
            if (c) c.checked = true; if (p) p.checked = true;
            refreshUi();
        });
    });
    if (navigator.usb) {
        navigator.usb.addEventListener('connect', function () { listKnown(); });
        navigator.usb.addEventListener('disconnect', function () { listKnown(); if (conn() === 'usb') setStatus(false, 'ถอดสาย USB แล้ว'); });
    }

    refreshUi();
    // เชื่อมกลับเครื่องที่บันทึกไว้อัตโนมัติ (ถ้าเบราว์เซอร์นี้เคยอนุญาต)
    if (conn() !== 'system' && $('device_id').value) {
        B.reconnect({ connection: conn(), device_id: $('device_id').value }).then(function (c) {
            if (c) setStatus(true, 'เชื่อมต่ออยู่: ' + c.name);
        });
    }
})();
