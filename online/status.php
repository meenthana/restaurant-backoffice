<?php
// หน้าติดตามสถานะออเดอร์ของลูกค้า
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/OrderItem.php';
require_once __DIR__ . '/../classes/Payment.php';
require_once __DIR__ . '/../classes/KioskOrder.php';
require_once __DIR__ . '/../classes/Member.php';
require_once __DIR__ . '/_layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['member_id'])) { header('Location: login.php'); exit; }

// เช็คออเดอร์ที่เลยเวลาไม่มารับก่อนแสดงผลทุกครั้ง (Lazy Expiration)
Order::expireOverdueOnlineOrders();

$orderId = (int) ($_GET['order_id'] ?? 0);
$order = Order::find($orderId);
if (!$order || (int) $order['member_id'] !== (int) $_SESSION['member_id']) {
    die('ไม่พบออเดอร์นี้ หรือไม่ใช่ออเดอร์ของคุณ');
}
$member = Member::find((int) $_SESSION['member_id']);

$items = OrderItem::getByOrder($orderId);
$addonsByItem = [];
foreach ($items as $i) {
    $addonsByItem[$i['order_item_id']] = OrderItem::getAddons((int) $i['order_item_id']);
}
$total  = Payment::orderTotal($orderId);
$isPaid = (int) $order['is_paid'] === 1;
$queue  = KioskOrder::queueLabel($order['queue_no'] ? (int) $order['queue_no'] : null, $order['channel']);

// ขั้นตอนที่แสดงเป็นแถบความคืบหน้า
$steps = ['จ่ายเงิน', 'กำลังทำ', 'พร้อมรับ', 'รับแล้ว'];
$current = match (true) {
    $order['status'] === 'closed' => 3,
    $order['status'] === 'ready' => 2,
    $order['status'] === 'active' || ($order['status'] === 'pending' && $isPaid) => 1,
    default => 0,
};
$live = in_array($order['status'], ['pending', 'active', 'ready'], true);

onlineHead('ติดตามออเดอร์ ' . $queue, $live);
onlineTopbar($member);
?>
<div class="container py-4" style="max-width:560px">

    <div class="cust-hero text-center mb-3">
        <div class="eyebrow-c">เลขคิวของคุณ</div>
        <div class="display-4 fw-bold" style="color:var(--c-brand)"><?= htmlspecialchars($queue) ?></div>
        <?php if ($order['scheduled_at']): ?>
            <div class="text-muted"><i class="ti ti-calendar-time"></i> นัดรับ <?= date('d/m/Y H:i', strtotime($order['scheduled_at'])) ?> น.</div>
        <?php endif; ?>
    </div>

    <?php if (in_array($order['status'], ['cancelled', 'no_show'], true)): ?>
        <div class="alert alert-<?= $order['status'] === 'no_show' ? 'danger' : 'secondary' ?>">
            <?= $order['status'] === 'no_show' ? 'คุณพลาดการรับสินค้าตามเวลาที่กำหนด ครั้งนี้ถูกนับเป็นประวัติไม่มารับ' : 'ออเดอร์นี้ถูกยกเลิกแล้ว' ?>
        </div>
    <?php else: ?>
    <div class="cust-card p-3 mb-3">
        <div class="d-flex justify-content-between text-center">
            <?php foreach ($steps as $i => $label): $done = $i <= $current; ?>
            <div class="flex-fill">
                <div class="mx-auto mb-1 d-flex align-items-center justify-content-center rounded-circle fw-bold"
                     style="width:2.4rem;height:2.4rem;background:<?= $done ? 'var(--c-brand-2)' : '#eee' ?>;color:<?= $done ? '#fff' : '#999' ?>">
                    <?= $i < $current || $order['status'] === 'closed' ? '<i class="ti ti-check"></i>' : $i + 1 ?>
                </div>
                <div class="small <?= $i === $current ? 'fw-bold' : 'text-muted' ?>"><?= $label ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($order['status'] === 'ready'): ?>
        <div class="alert alert-success fs-5"><i class="ti ti-bell-ringing"></i> อาหารพร้อมแล้ว! <?php if ($order['pickup_deadline']): ?>กรุณามารับภายใน <strong><?= date('H:i', strtotime($order['pickup_deadline'])) ?> น.</strong><?php endif; ?></div>
    <?php elseif ($order['status'] === 'closed'): ?>
        <div class="alert alert-success"><i class="ti ti-circle-check"></i> รับสินค้าสำเร็จแล้ว ขอบคุณที่ใช้บริการ</div>
    <?php elseif (!$isPaid): ?>
        <a href="pay.php?order_id=<?= $orderId ?>" class="btn-touch btn-accent w-100 mb-3"><i class="ti ti-credit-card"></i> ชำระเงิน</a>
    <?php endif; ?>
    <?php endif; ?>

    <div class="cust-card p-3">
        <?php foreach ($items as $i): ?>
        <div class="border-bottom py-2">
            <div class="d-flex justify-content-between">
                <span><span class="text-muted me-2">×<?= (int) $i['quantity'] ?></span><?= htmlspecialchars($i['menu_name']) ?></span>
                <span>฿<?= number_format((float) $i['subtotal'], 2) ?></span>
            </div>
            <?php foreach ($addonsByItem[$i['order_item_id']] as $a): ?>
            <div class="d-flex justify-content-between small text-muted ps-4">
                <span>+ <?= htmlspecialchars($a['addon_name']) ?> ×<?= (int) $i['quantity'] ?></span>
                <span>฿<?= number_format((float) $a['addon_price'] * (int) $i['quantity'], 2) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <div class="d-flex justify-content-between fw-bold fs-5 pt-2">
            <span>รวม</span><span>฿<?= number_format($total, 2) ?></span>
        </div>
    </div>

    <a href="index.php" class="btn-touch btn-ghost w-100 mt-3">สั่งเมนูอื่นต่อ</a>
    <?php if ($live): ?><p class="text-muted small text-center mt-2">หน้านี้รีเฟรชเองทุก 20 วินาที</p><?php endif; ?>
</div>
<?php onlineFoot(); ?>
