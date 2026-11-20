<?php
// หน้าคิดเงิน: รอชำระ / พร้อมรับ
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireLogin();

// งานที่ต้องเช็กเวลาแบบ Lazy (ไม่ใช้ cron): ออนไลน์ไม่มารับ + ตู้สั่งเองค้างจ่ายนานเกินกำหนด
Order::expireOverdueOnlineOrders();
$expired = KioskOrder::expireUnpaid();
KioskOrder::cancelRefunded();

$awaiting = KioskOrder::awaitingPayment();
$pickup   = KioskOrder::readyForPickup();

$db = getDB();
$orders = $db->query(
    "SELECT o.*, COUNT(oi.order_item_id) AS item_count, COALESCE(SUM(oi.subtotal),0) AS total
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.order_id
     WHERE o.status IN ('active','ready') AND o.is_paid = 0 AND o.payment_status <> 'refunded'
     GROUP BY o.order_id
     ORDER BY o.opened_at ASC"
)->fetchAll();

$channelLabel = ['kiosk' => 'ตู้สั่งเอง', 'online' => 'ออนไลน์', 'walkin' => 'หน้าร้าน'];

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('คิดเงิน', 'รับเงินและส่งมอบอาหารตามคิว',
    '<a href="/restaurant-backoffice/kiosk/index.php" target="_blank" class="btn btn-outline-dark"><i class="ti ti-device-tablet"></i> เปิดหน้าตู้สั่งอาหาร</a>'); ?>

<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success"><?= htmlspecialchars($_GET['msg']) ?></div><?php endif; ?>
<?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>
<?php if ($expired): ?><div class="alert alert-secondary small"><i class="ti ti-clock-x"></i> ยกเลิกออเดอร์ที่ค้างจ่ายเกินเวลาให้อัตโนมัติ <?= $expired ?> รายการ</div><?php endif; ?>

<!-- 1. รอชำระที่เคาน์เตอร์ -->
<h2 class="h5 mb-2"><i class="ti ti-cash text-warning"></i> รอชำระเงิน <span class="badge text-bg-warning"><?= count($awaiting) ?></span></h2>
<p class="text-muted small mb-3">ออเดอร์จะเข้าครัวเมื่อรับเงินแล้ว</p>
<div class="row g-3 mb-4">
    <?php if (!$awaiting): ?>
    <div class="col-12"><div class="card"><div class="card-body text-muted text-center py-4">ไม่มีออเดอร์รอชำระ</div></div></div>
    <?php endif; ?>
    <?php foreach ($awaiting as $o): $due = Payment::orderTotal((int) $o['order_id']); ?>
    <div class="col-sm-6 col-lg-4 col-xl-3">
        <div class="card h-100 border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="queue-number"><?= !empty($o['queue_no']) ? KioskOrder::queueLabel((int) $o['queue_no'], $o['channel'], (int)$o['order_id']) : '#' . $o['order_id'] ?></div>
                    <span class="badge <?= (int) $o['waiting_minutes'] >= 10 ? 'text-bg-danger' : 'text-bg-light border' ?>"><?= (int) $o['waiting_minutes'] ?> นาที</span>
                </div>
                <div class="mt-2 small">
                    <span class="badge text-bg-light border"><?= $channelLabel[$o['channel']] ?? $o['channel'] ?></span>
                    <span class="badge text-bg-light border"><?= $o['service_type'] === 'takeaway' ? 'กลับบ้าน' : 'ทานที่ร้าน' ?></span>
                    <?= KioskOrder::cardBadge($o) ?>
                    <?php if (KioskOrder::wantsCash($o)): ?><span class="badge text-bg-warning"><i class="ti ti-cash"></i> เปลี่ยนเป็นเงินสด</span><?php endif; ?>
                </div>
                <div class="fs-3 fw-bold mt-2">฿<?= number_format($due, 2) ?></div>
                <div class="d-flex gap-2 mt-2">
                    <a href="checkout.php?order_id=<?= $o['order_id'] ?>" class="btn btn-dark flex-grow-1"><i class="ti ti-cash-register"></i> รับเงิน</a>
                    <form method="post" action="kiosk_action.php" onsubmit="return confirm('ยกเลิกออเดอร์คิวนี้? (ลูกค้าไม่มาจ่าย)');">
                        <?= Security::csrfInput() ?>
                        <input type="hidden" name="order_id" value="<?= $o['order_id'] ?>">
                        <button name="do" value="cancel" class="btn btn-outline-danger" title="ยกเลิก"><i class="ti ti-x"></i></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- 2. พร้อมรับ: กรอกรหัสบัตรที่ลูกค้ายื่นคืน แล้วตรวจรายการก่อนส่งมอบ -->
<h2 class="h5 mb-2"><i class="ti ti-bell-ringing text-success"></i> อาหารพร้อม รอลูกค้ามารับ <span class="badge text-bg-success"><?= count($pickup) ?></span></h2>
<p class="text-muted small mb-2">รับบัตรคืนจากลูกค้า กรอกรหัส 4 หลักบนบัตร แล้วตรวจรายการก่อนกดส่งมอบ · <a href="../../kiosk/board.php" target="_blank">เปิดจอคิวลูกค้า</a></p>
<form id="card-lookup" class="d-flex gap-2 mb-1" style="max-width:420px" autocomplete="off">
    <input id="card-lookup-code" class="form-control form-control-lg mono" inputmode="numeric" maxlength="4" placeholder="รหัสบัตร 4 หลัก" aria-label="รหัสบัตร 4 หลัก">
    <button class="btn btn-success btn-lg text-nowrap"><i class="ti ti-search"></i> ตรวจบัตร</button>
</form>
<div id="card-lookup-error" class="text-danger small fw-bold mb-2" role="alert"></div>
<div class="d-flex flex-wrap gap-2 mb-4">
    <?php if (!$pickup): ?><div class="text-muted small">ยังไม่มีคิวที่พร้อมรับ</div><?php endif; ?>
    <?php foreach ($pickup as $o): ?>
    <div class="card border-success">
        <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
            <div class="queue-number" style="background:#2f8f5b"><?= !empty($o['queue_no']) ? KioskOrder::queueLabel((int) $o['queue_no'], $o['channel'], (int)$o['order_id']) : '#' . $o['order_id'] ?></div>
            <?= KioskOrder::cardBadge($o, 'fs-6') ?>
            <button type="button" class="btn btn-success js-handover" data-order="<?= (int) $o['order_id'] ?>"><i class="ti ti-list-check"></i> ตรวจและส่งมอบ</button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="modal fade" id="handover-modal" tabindex="-1" aria-labelledby="handover-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form method="post" action="kiosk_action.php" id="handover-form" autocomplete="off">
        <?= Security::csrfInput() ?>
        <input type="hidden" name="order_id" id="ho-order" value="">
        <input type="hidden" name="do" value="handover">
        <div class="modal-header">
          <h2 class="modal-title h5" id="handover-title">ตรวจรายการก่อนส่งมอบ</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="queue-number" id="ho-queue" style="background:#2f8f5b"></div>
            <div><div id="ho-meta" class="small text-muted"></div><div id="ho-member" class="fw-bold"></div></div>
          </div>
          <table class="table align-middle mb-3">
            <thead class="table-light"><tr><th>รายการ</th><th class="text-end" style="width:90px">จำนวน</th></tr></thead>
            <tbody id="ho-items"></tbody>
            <tfoot><tr><th>ยอดที่ชำระ</th><th class="text-end" id="ho-total"></th></tr></tfoot>
          </table>
          <div id="ho-code-wrap" class="mb-2">
            <label for="ho-code" class="form-label fw-bold">รหัส 4 หลักบนบัตรที่ลูกค้ายื่นคืน</label>
            <input id="ho-code" name="card_code" class="form-control form-control-lg mono" inputmode="numeric" maxlength="4">
            <div id="ho-code-error" class="text-danger small fw-bold mt-1" role="alert"></div>
          </div>
          <div id="ho-verified" class="alert alert-success py-2 small mb-2" hidden><i class="ti ti-circle-check"></i> รหัสบัตรตรงกับออเดอร์นี้</div>
          <div id="ho-notready" class="alert alert-warning py-2 small mb-0" hidden></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn btn-success btn-lg" id="ho-submit"><i class="ti ti-hand-finger"></i> ยืนยัน ลูกค้ารับแล้ว</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($orders): ?>
<!-- 3. ออเดอร์เก่าที่เข้าครัวไปก่อนแล้วแต่ยังไม่จ่าย (ก่อนเปลี่ยนเป็นจ่ายก่อน) -->
<h2 class="h5 mb-2"><i class="ti ti-receipt"></i> ออเดอร์ค้างชำระ</h2>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>ช่องทาง</th><th>สถานะ</th><th>รายการ</th><th>ยอดรวม</th><th>จัดการ</th></tr>
            </thead>
            <tbody>
                <?php if (!$orders): ?>
                <tr><td colspan="6" class="text-muted text-center py-4">ไม่มีออเดอร์ที่รอชำระเงิน</td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td class="mono"><?= !empty($o['queue_no']) ? KioskOrder::queueLabel((int) $o['queue_no'], $o['channel'], (int)$o['order_id']) : '#' . $o['order_id'] ?></td>
                    <td><span class="badge text-bg-light border"><?= $o['service_type'] === 'dine_in' ? 'ทานที่ร้าน' : 'รับกลับบ้าน' ?></span></td>
                    <td><span class="badge <?= $o['status'] === 'ready' ? 'text-bg-info' : 'text-bg-warning' ?>"><?= $o['status'] === 'ready' ? 'พร้อมรับ' : 'กำลังเตรียม' ?></span></td>
                    <td><?= $o['item_count'] ?></td>
                    <td>฿<?= number_format($o['total'], 2) ?></td>
                    <td><a href="checkout.php?order_id=<?= $o['order_id'] ?>" class="btn btn-sm btn-dark"><i class="ti ti-cash-register"></i> คิดเงิน</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<iframe id="auto-print" title="พิมพ์ใบเสร็จอัตโนมัติ" tabindex="-1" aria-hidden="true" style="position:fixed;left:-10000px;top:0;width:420px;height:800px;border:0"></iframe>
<script>
// ใบเสร็จกระดาษที่ลูกค้าเลือกไว้ พิมพ์อัตโนมัติเมื่อชำระแล้ว (เปิด Chrome ด้วย --kiosk-printing จะไม่มีหน้าต่างยืนยัน)
(function () {
    var frame = document.getElementById('auto-print'), busy = false;
    async function check() {
        if (busy) return;
        try {
            var d = await fetch('print_queue.php', { cache: 'no-store' }).then(function (r) { return r.json(); });
            if (!d.order_id) return;
            busy = true;
            frame.src = 'receipt.php?order_id=' + d.order_id + '&autoprint=1';
            setTimeout(function () { busy = false; }, 10000);
        } catch (e) {}
    }
    check();
    setInterval(check, 3000);
    setTimeout(function reload() {
        var typing = document.getElementById('card-lookup-code').value !== '';
        if (busy || window.handoverOpen || typing) setTimeout(reload, 3000); else location.reload();
    }, 20000);
})();

// ส่งมอบ: เปิดรายการจากรหัสบัตร หรือจากปุ่มของแต่ละคิว แล้วให้แคชเชียร์ตรวจด้วยตาก่อนยืนยัน
(function () {
    var modalEl = document.getElementById('handover-modal');
    var $ = function (id) { return document.getElementById(id); };
    var needCode = false;
    modalEl.addEventListener('shown.bs.modal', function () {
        window.handoverOpen = true;
        if (needCode) $('ho-code').focus(); else $('ho-submit').focus();
    });
    modalEl.addEventListener('hidden.bs.modal', function () { window.handoverOpen = false; });

    function row(item) {
        var tr = document.createElement('tr'), td = document.createElement('td'), q = document.createElement('td');
        var name = document.createElement('div');
        name.className = 'fw-bold';
        name.textContent = item.name;
        td.appendChild(name);
        if (item.addons.length) {
            var a = document.createElement('div');
            a.className = 'small text-muted';
            a.textContent = '+ ' + item.addons.join(' · ');
            td.appendChild(a);
        }
        if (item.note) {
            var n = document.createElement('div');
            n.className = 'small text-danger fw-bold';
            n.textContent = 'หมายเหตุ: ' + item.note;
            td.appendChild(n);
        }
        q.className = 'text-end fs-5 fw-bold';
        q.textContent = '× ' + item.qty;
        tr.appendChild(td); tr.appendChild(q);
        return tr;
    }

    function open(query, code) {
        $('card-lookup-error').textContent = '';
        return fetch('handover.php?' + query, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { $('card-lookup-error').textContent = d.error || 'ไม่พบออเดอร์'; return; }
                $('ho-order').value = d.order_id;
                $('ho-queue').textContent = d.queue_label;
                $('ho-meta').textContent = [d.card_label, d.channel, d.service].filter(Boolean).join(' · ');
                $('ho-member').textContent = d.member ? 'สมาชิก: ' + d.member : '';
                var body = $('ho-items');
                body.innerHTML = '';
                d.items.forEach(function (it) { body.appendChild(row(it)); });
                $('ho-total').textContent = '฿' + Number(d.total).toLocaleString('th-TH', { minimumFractionDigits: 2 });
                needCode = d.needs_code;
                $('ho-code').value = d.verified ? code : '';
                $('ho-code-error').textContent = '';
                $('ho-code-wrap').hidden = !d.needs_code;
                $('ho-verified').hidden = !d.verified;
                $('ho-notready').hidden = d.ready;
                $('ho-notready').textContent = d.ready ? '' : 'ยังส่งมอบไม่ได้ สถานะตอนนี้: ' + d.status_label;
                $('ho-submit').disabled = !d.ready;
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            })
            .catch(function () { $('card-lookup-error').textContent = 'เชื่อมต่อไม่ได้ กรุณาลองใหม่'; });
    }

    $('card-lookup').addEventListener('submit', function (e) {
        e.preventDefault();
        var code = $('card-lookup-code').value.trim();
        if (!/^\d{4}$/.test(code)) { $('card-lookup-error').textContent = 'กรุณากรอกรหัสบัตร 4 หลัก'; return; }
        open('card=' + code, code).then(function () { $('card-lookup-code').value = ''; });
    });
    document.querySelectorAll('.js-handover').forEach(function (b) {
        b.addEventListener('click', function () { open('order_id=' + b.dataset.order, ''); });
    });
    $('ho-code').addEventListener('input', function () { $('ho-code-error').textContent = ''; });
    $('handover-form').addEventListener('submit', function (e) {
        if (needCode && !/^\d{4}$/.test($('ho-code').value.trim())) {
            e.preventDefault();
            $('ho-code-error').textContent = 'กรุณากรอกรหัส 4 หลักบนบัตร';
            $('ho-code').focus();
        }
    });
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
