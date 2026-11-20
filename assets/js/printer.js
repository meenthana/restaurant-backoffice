// เชื่อมเครื่องพิมพ์ใบเสร็จจากเบราว์เซอร์: USB (WebUSB), Bluetooth (BLE), Serial/COM
// ภาษาไทยพิมพ์เป็นภาพ (ESC/POS raster) จึงไม่ต้องตั้ง code page ที่เครื่องพิมพ์
(function () {
    'use strict';

    var PAPER_DOTS = { '58': 384, '80': 576 };
    // service ที่เครื่องพิมพ์ความร้อน Bluetooth ส่วนใหญ่ใช้
    var BLE_SERVICES = [
        '000018f0-0000-1000-8000-00805f9b34fb',
        'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
        '49535343-fe7d-4ae5-8fa9-9fafd205e455',
        '0000ff00-0000-1000-8000-00805f9b34fb',
        '0000fee7-0000-1000-8000-00805f9b34fb'
    ];

    var conn = null; // { type, name, id, write(bytes) }

    function support() {
        var secure = window.isSecureContext;
        return {
            secure: secure,
            usb: secure && !!navigator.usb,
            bluetooth: secure && !!navigator.bluetooth,
            serial: secure && !!navigator.serial
        };
    }

    function hex4(n) { return ('0000' + n.toString(16)).slice(-4); }

    // ---------- USB ----------
    async function openUsb(device) {
        await device.open();
        if (!device.configuration) await device.selectConfiguration(1);
        var found = null;
        device.configuration.interfaces.forEach(function (itf) {
            itf.alternates.forEach(function (alt) {
                alt.endpoints.forEach(function (ep) {
                    if (!found && ep.direction === 'out' && ep.type === 'bulk') found = { itf: itf.interfaceNumber, ep: ep.endpointNumber };
                });
            });
        });
        if (!found) throw new Error('ไม่พบช่องส่งข้อมูลของเครื่องพิมพ์ USB นี้');
        await device.claimInterface(found.itf);
        return {
            type: 'usb',
            name: device.productName || ('USB ' + hex4(device.vendorId) + ':' + hex4(device.productId)),
            id: hex4(device.vendorId) + ':' + hex4(device.productId),
            write: function (bytes) { return device.transferOut(found.ep, bytes); },
            close: function () { return device.close(); }
        };
    }

    async function requestUsb() {
        var device = await navigator.usb.requestDevice({ filters: [] });
        conn = await openUsb(device);
        return conn;
    }

    // เครื่องที่เคยอนุญาตแล้ว เชื่อมใหม่ได้โดยไม่ต้องกดเลือก
    async function knownUsb(id) {
        if (!navigator.usb) return [];
        var list = await navigator.usb.getDevices();
        return list.filter(function (d) { return !id || (hex4(d.vendorId) + ':' + hex4(d.productId)) === id; });
    }

    // ---------- Bluetooth (BLE) ----------
    async function openBle(device) {
        var server = await device.gatt.connect();
        var services = await server.getPrimaryServices();
        var ch = null;
        for (var i = 0; i < services.length && !ch; i++) {
            var chars = await services[i].getCharacteristics();
            for (var j = 0; j < chars.length; j++) {
                if (chars[j].properties.writeWithoutResponse || chars[j].properties.write) { ch = chars[j]; break; }
            }
        }
        if (!ch) throw new Error('ไม่พบช่องส่งข้อมูลของเครื่องพิมพ์ Bluetooth นี้');
        return {
            type: 'bluetooth',
            name: device.name || 'Bluetooth printer',
            id: device.id,
            write: async function (bytes) {
                // BLE ส่งได้ครั้งละน้อย แบ่งเป็นก้อน
                for (var k = 0; k < bytes.length; k += 180) {
                    var part = bytes.slice(k, k + 180);
                    if (ch.properties.writeWithoutResponse) await ch.writeValueWithoutResponse(part);
                    else await ch.writeValue(part);
                }
            },
            close: function () { device.gatt.disconnect(); }
        };
    }

    async function requestBluetooth() {
        var device = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: BLE_SERVICES });
        conn = await openBle(device);
        return conn;
    }

    async function knownBluetooth(id) {
        if (!navigator.bluetooth || !navigator.bluetooth.getDevices) return [];
        var list = await navigator.bluetooth.getDevices();
        return list.filter(function (d) { return !id || d.id === id; });
    }

    // ---------- Serial / COM (รวม Bluetooth SPP ที่จับคู่ใน Windows แล้ว) ----------
    async function openSerial(port) {
        await port.open({ baudRate: 9600 });
        var info = port.getInfo ? port.getInfo() : {};
        return {
            type: 'serial',
            name: info.usbVendorId ? ('Serial ' + hex4(info.usbVendorId) + ':' + hex4(info.usbProductId)) : 'Serial/COM',
            id: info.usbVendorId ? hex4(info.usbVendorId) + ':' + hex4(info.usbProductId) : 'serial',
            write: async function (bytes) { var w = port.writable.getWriter(); await w.write(bytes); w.releaseLock(); },
            close: function () { return port.close(); }
        };
    }

    async function requestSerial() {
        var port = await navigator.serial.requestPort();
        conn = await openSerial(port);
        return conn;
    }

    // เชื่อมกลับเครื่องเดิมตามค่าที่บันทึกไว้ (ถ้าเบราว์เซอร์เคยอนุญาต)
    async function reconnect(profile) {
        if (conn) return conn;
        try {
            if (profile.connection === 'usb') {
                var u = await knownUsb(profile.device_id);
                if (u.length) conn = await openUsb(u[0]);
            } else if (profile.connection === 'bluetooth') {
                var b = await knownBluetooth(profile.device_id);
                if (b.length) conn = await openBle(b[0]);
            } else if (profile.connection === 'serial' && navigator.serial) {
                var p = await navigator.serial.getPorts();
                if (p.length) conn = await openSerial(p[0]);
            }
        } catch (e) { conn = null; }
        return conn;
    }

    // ---------- วาดใบเสร็จเป็นภาพ แล้วแปลงเป็น ESC/POS ----------
    // rows: [{text, align:'left|center'}, {left, right}, {hr:true}, {big:'K006'}]
    function renderRows(rows, paper) {
        var width = PAPER_DOTS[paper] || PAPER_DOTS['80'];
        var font = width >= 576 ? 26 : 22;
        var line = Math.round(font * 1.45);
        var c = document.createElement('canvas');
        var ctx = c.getContext('2d');
        var h = 20;
        ctx.font = font + 'px Sarabun, sans-serif';
        rows.forEach(function (r) {
            // ซ้าย+ขวายาวเกินบรรทัด ให้ขึ้นบรรทัดใหม่
            r.wrap = r.left !== undefined && ctx.measureText(r.left + '  ' + (r.right || '')).width > width - 18;
            h += r.big ? line * 2.6 : (r.hr ? line * 0.6 : (r.wrap ? line * 2 : line));
        });
        c.width = width; c.height = Math.ceil(h + 40);
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
        ctx.fillStyle = '#000'; ctx.textBaseline = 'top';
        var y = 10, pad = 6;
        rows.forEach(function (r) {
            if (r.hr) { ctx.fillRect(pad, y + line * 0.25, width - pad * 2, 2); y += line * 0.6; return; }
            if (r.big) {
                ctx.font = 'bold ' + (font * 2.4) + 'px Sarabun, sans-serif'; ctx.textAlign = 'center';
                ctx.fillText(r.big, width / 2, y); y += line * 2.6; return;
            }
            ctx.font = (r.bold ? 'bold ' : '') + font + 'px Sarabun, sans-serif';
            if (r.left !== undefined && r.wrap) {
                ctx.textAlign = 'left'; ctx.fillText(fit(ctx, r.left, width - pad * 2), pad, y); y += line;
                ctx.textAlign = 'right'; ctx.fillText(r.right || '', width - pad, y);
            } else if (r.left !== undefined) {
                ctx.textAlign = 'right'; ctx.fillText(r.right || '', width - pad, y);
                ctx.textAlign = 'left';
                var maxLeft = width - pad * 3 - ctx.measureText(r.right || '').width;
                ctx.fillText(fit(ctx, r.left, maxLeft), pad, y);
            } else {
                ctx.textAlign = r.align === 'center' ? 'center' : 'left';
                ctx.fillText(fit(ctx, r.text || '', width - pad * 2), r.align === 'center' ? width / 2 : pad, y);
            }
            y += line;
        });
        return c;
    }

    function fit(ctx, text, max) {
        if (ctx.measureText(text).width <= max) return text;
        while (text.length > 1 && ctx.measureText(text + '…').width > max) text = text.slice(0, -1);
        return text + '…';
    }

    function canvasToEscPos(c) {
        var w = c.width, h = c.height, bytesPerRow = Math.ceil(w / 8);
        var px = c.getContext('2d').getImageData(0, 0, w, h).data;
        var head = [0x1b, 0x40, 0x1d, 0x76, 0x30, 0x00, bytesPerRow & 0xff, bytesPerRow >> 8, h & 0xff, h >> 8];
        var body = new Uint8Array(bytesPerRow * h);
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var i = (y * w + x) * 4;
                if (px[i] + px[i + 1] + px[i + 2] < 384) body[y * bytesPerRow + (x >> 3)] |= (0x80 >> (x & 7));
            }
        }
        var tail = [0x1b, 0x64, 0x04, 0x1d, 0x56, 0x42, 0x00]; // ป้อนกระดาษ 4 บรรทัด แล้วตัด
        var out = new Uint8Array(head.length + body.length + tail.length);
        out.set(head, 0); out.set(body, head.length); out.set(tail, head.length + body.length);
        return out;
    }

    async function printRows(rows, paper) {
        if (!conn) throw new Error('ยังไม่ได้เชื่อมต่อเครื่องพิมพ์');
        if (document.fonts && document.fonts.load) { try { await document.fonts.load('22px Sarabun'); } catch (e) {} }
        await conn.write(canvasToEscPos(renderRows(rows, paper)));
    }

    function testRows(storeName, paper) {
        var now = new Date();
        return [
            { text: storeName || 'ร้านอาหาร', align: 'center', bold: true },
            { text: 'ทดสอบการพิมพ์ ภาษาไทย ก ข ค ฯลฯ', align: 'center' },
            { hr: true },
            { left: 'กระดาษ', right: paper + ' มม.' },
            { left: 'เวลา', right: now.toLocaleString('th-TH') },
            { hr: true },
            { big: 'K001' },
            { text: 'ถ้าเห็นข้อความนี้ชัด แปลว่าพร้อมใช้งาน', align: 'center' }
        ];
    }

    // อ่านใบเสร็จจากหน้าเว็บ (แถวซ้าย-ขวา / ข้อความ / เส้นคั่น)
    function rowsFromElement(el) {
        var rows = [];
        Array.prototype.forEach.call(el.children, function (n) {
            if (n.tagName === 'HR') { rows.push({ hr: true }); return; }
            if (n.classList.contains('justify-content-between') && n.children.length >= 2) {
                rows.push({ left: n.children[0].innerText.trim(), right: n.children[1].innerText.trim(), bold: n.classList.contains('fw-bold') });
                return;
            }
            var t = n.innerText.trim();
            if (t) rows.push({ text: t, align: n.classList.contains('text-center') ? 'center' : 'left', bold: n.classList.contains('fw-bold') });
        });
        return rows;
    }

    window.PrinterBridge = {
        support: support,
        requestUsb: requestUsb,
        requestBluetooth: requestBluetooth,
        requestSerial: requestSerial,
        knownUsb: knownUsb,
        reconnect: reconnect,
        current: function () { return conn; },
        disconnect: async function () { if (conn && conn.close) { try { await conn.close(); } catch (e) {} } conn = null; },
        printRows: printRows,
        testRows: testRows,
        rowsFromElement: rowsFromElement,
        renderRows: renderRows
    };
})();
