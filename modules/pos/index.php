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

<!-- 2. พร้อมรับ -->
<h2 class="h5 mb-2"><i class="ti ti-bell-ringing text-success"></i> อาหารพร้อม รอลูกค้ามารับ <span class="badge text-bg-success"><?= count($pickup) ?></span></h2>
<p class="text-muted small mb-3">เรียกคิว/เลขบัตรแล้วกดส่งมอบ (รับบัตรคืนจากลูกค้า) · <a href="../../kiosk/board.php" target="_blank">เปิดจอคิวลูกค้า</a></p>
<div class="d-flex flex-wrap gap-2 mb-4">
    <?php if (!$pickup): ?><div class="text-muted small">ยังไม่มีคิวที่พร้อมรับ</div><?php endif; ?>
    <?php foreach ($pickup as $o): $service=ServiceFlow::details((int)$o['order_id']); ?>
    <form method="post" action="kiosk_action.php" class="card border-success">
        <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
            <div class="queue-number" style="background:#2f8f5b"><?= !empty($o['queue_no']) ? KioskOrder::queueLabel((int) $o['queue_no'], $o['channel'], (int)$o['order_id']) : '#' . $o['order_id'] ?></div>
            <?= KioskOrder::cardBadge($o, 'fs-6') ?>
            <?= Security::csrfInput() ?>
            <input type="hidden" name="order_id" value="<?= $o['order_id'] ?>">
            <button name="do" value="handover" class="btn btn-success"><i class="ti ti-hand-finger"></i> ส่งมอบแล้ว</button>
        </div>
    </form>
    <?php endforeach; ?>
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
    setTimeout(function reload() { if (busy) setTimeout(reload, 3000); else location.reload(); }, 20000);
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
