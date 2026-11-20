<?php
// รายละเอียดออเดอร์ + ปุ่มตามสถานะ
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/OrderItem.php';
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';
Auth::requireLogin();
require_once __DIR__.'/../../classes/Security.php';

Order::expireOverdueOnlineOrders();

$orderId = (int) ($_GET['id'] ?? 0);
$order = Order::find($orderId);
if (!$order) {
    die('ไม่พบออเดอร์นี้');
}

$db = getDB();
$member = null;
if ($order['member_id']) {
    $stmt = $db->prepare('SELECT * FROM members WHERE member_id = :id');
    $stmt->execute(['id' => $order['member_id']]);
    $member = $stmt->fetch() ?: null;
}

$items = OrderItem::getByOrder($orderId);
$addonsByItem = [];
foreach ($items as $i) {
    $addonsByItem[$i['order_item_id']] = OrderItem::getAddons((int) $i['order_item_id']);
}
$total  = Payment::orderTotal($orderId);
$paid   = Payment::paidAmount($orderId);
$isPaid = (int) $order['is_paid'] === 1;

$payStmt = $db->prepare("SELECT method, amount, created_at, transaction_type FROM payments
                         WHERE order_id = :id AND status = 'completed'
                         ORDER BY payment_id");
$payStmt->execute(['id' => $orderId]);
$payments = $payStmt->fetchAll();

// ทุกช่องทางจ่ายก่อนแล้วครัวทำ  (ยกเว้นออเดอร์หน้าร้านเก่าที่เข้าครัวไปแล้วแต่ยังไม่จ่าย)
$prepay  = !($order['channel'] === 'walkin' && !$isPaid && in_array($order['status'], ['active', 'ready'], true));
$status  = $order['status'];
$queue   = $order['queue_no'] ? KioskOrder::queueLabel((int) $order['queue_no'], $order['channel'], (int)$order['order_id']) : null;

$channelLabel = ['kiosk' => 'ตู้สั่งเอง', 'online' => 'ออนไลน์', 'walkin' => 'หน้าร้าน'][$order['channel']] ?? $order['channel'];
$serviceLabel = $order['service_type'] === 'takeaway' ? 'กลับบ้าน' : 'ทานที่ร้าน';
$methodLabel  = ['cash' => 'เงินสด', 'transfer' => 'โอนเงิน', 'transfer_slip' => 'โอน (ตรวจสลิป)', 'mock_online' => 'ออนไลน์'];

// ป้ายสถานะ
if ($status === 'pending' && $prepay && !$isPaid) {
    $badge = ['text-bg-warning', 'รอชำระเงิน'];
} else {
    $badge = [
        'pending'   => ['text-bg-secondary', 'รอครัวรับ'],
        'active'    => ['text-bg-warning', 'กำลังทำ'],
        'ready'     => ['text-bg-info', 'อาหารพร้อม'],
        'closed'    => ['text-bg-success', 'เสร็จแล้ว'],
        'no_show'   => ['text-bg-danger', 'ไม่มารับ'],
        'cancelled' => ['text-bg-dark', 'ยกเลิกแล้ว'],
    ][$status] ?? ['text-bg-secondary', $status];
}

// ขั้นตอนของออเดอร์ แยกตามรูปแบบการจ่าย
if ($prepay) {
    $steps = ['สั่งอาหาร', 'ชำระเงิน', 'กำลังทำ', 'อาหารพร้อม', 'ส่งมอบแล้ว'];
    $at = match (true) {
        $status === 'closed' => 5,
        $status === 'ready' => 3,
        $status === 'active' => 2,
        $status === 'pending' && $isPaid => 2,
        default => 1,
    };
} else {
    $steps = ['สั่งอาหาร', 'กำลังทำ', 'อาหารพร้อม', 'ชำระเงิน'];
    $at = match (true) {
        $status === 'closed' => 4,
        $status === 'ready' => 2,
        $status === 'active' => 1,
        default => 1,
    };
}
$failed = in_array($status, ['cancelled', 'no_show'], true);

$checkoutUrl = '/restaurant-backoffice/modules/pos/checkout.php?order_id=' . $orderId;

require_once __DIR__ . '/../../includes/header.php';

$title = ($queue ? 'คิว ' . $queue : 'ออเดอร์ #' . $orderId)
       . ' <span class="badge ' . $badge[0] . ' align-middle fs-6">' . $badge[1] . '</span>';
pageHeader($title, $channelLabel . ' · ' . $serviceLabel . ' · สั่งเมื่อ ' . date('d/m/Y H:i', strtotime($order['opened_at'])) . ' น.');
?>

<?php if (isset($_GET['edited'])): ?>
    <div class="alert alert-success"><i class="ti ti-circle-check"></i> แก้ไขออเดอร์แล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger"><i class="ti ti-alert-triangle"></i> <?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">รายการอาหาร</div>
            <div class="card-body">
                <?php foreach ($items as $i): ?>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <div>
                        <span class="text-muted me-2">×<?= (int) $i['quantity'] ?></span><span class="fw-semibold"><?= htmlspecialchars($i['menu_name']) ?></span>
                        <?php foreach ($addonsByItem[$i['order_item_id']] as $a): ?>
                            <div class="small text-muted ps-4">+ <?= htmlspecialchars($a['addon_name']) ?></div>
                        <?php endforeach; ?>
                        <?php if (!empty($i['item_note'])): ?><div class="small text-danger ps-4"><?= htmlspecialchars($i['item_note']) ?></div><?php endif; ?>
                    </div>
                    <div class="text-end">
                        <div>฿<?= number_format((float) $i['subtotal'], 2) ?></div>
                        <?php foreach ($addonsByItem[$i['order_item_id']] as $a): ?>
                            <div class="small text-muted">฿<?= number_format((float) $a['addon_price'] * (int) $i['quantity'], 2) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ((float) $order['discount_amount'] > 0): ?>
                <div class="d-flex justify-content-between pt-2 text-success"><span>ส่วนลด</span><span>−฿<?= number_format((float) $order['discount_amount'], 2) ?></span></div>
                <?php endif; ?>
                <div class="d-flex justify-content-between fw-bold fs-4 pt-3">
                    <span>รวม</span><span>฿<?= number_format($total, 2) ?></span>
                </div>
                <?php if (!empty($order['note'])): ?>
                <div class="small text-muted mt-2"><i class="ti ti-note"></i> <?= htmlspecialchars($order['note']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ปุ่มทำงานหลัก ตามสถานะและรูปแบบการจ่าย -->
        <div class="card">
            <div class="card-body d-grid gap-2">
            <?php if ($status === 'pending' && $prepay && !$isPaid): ?>
                <a href="<?= $checkoutUrl ?>" class="btn btn-dark btn-lg"><i class="ti ti-cash-register"></i> รับเงิน ฿<?= number_format($total - $paid, 2) ?></a>
                <div class="small text-muted text-center">ออเดอร์จะเข้าครัวเมื่อรับเงินแล้ว</div>

            <?php elseif ($status === 'pending'): ?>
                <form action="update_status.php" method="post" class="d-grid"><?= Security::csrfInput() ?>
                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                    <button name="new_status" value="active" class="btn btn-warning btn-lg"><i class="ti ti-flame"></i> เริ่มทำอาหาร</button>
                </form>

            <?php elseif ($status === 'active'): ?>
                <form action="update_status.php" method="post" class="d-grid"><?= Security::csrfInput() ?>
                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                    <button name="new_status" value="ready" class="btn btn-success btn-lg"><i class="ti ti-bell-ringing"></i> อาหารพร้อม เรียกคิว</button>
                </form>
                <?php if (!$prepay && !$isPaid): ?>
                <a href="<?= $checkoutUrl ?>" class="btn btn-outline-dark"><i class="ti ti-cash-register"></i> คิดเงิน</a>
                <?php endif; ?>

            <?php elseif ($status === 'ready'): ?>
                <?php if ($isPaid): ?>
                <form action="update_status.php" method="post" class="d-grid"><?= Security::csrfInput() ?>
                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                    <button name="new_status" value="closed" class="btn btn-success btn-lg"><i class="ti ti-hand-finger"></i> ส่งมอบอาหารแล้ว</button>
                </form>
                <?php else: ?>
                <a href="<?= $checkoutUrl ?>" class="btn btn-dark btn-lg"><i class="ti ti-cash-register"></i> คิดเงินและปิดออเดอร์</a>
                <?php endif; ?>
                <?php if ($order['channel'] === 'online'): ?>
                <form action="update_status.php" method="post" class="d-grid" onsubmit="return confirm('ลูกค้าไม่มารับใช่ไหม?');"><?= Security::csrfInput() ?>
                    <input type="hidden" name="order_id" value="<?= $orderId ?>">
                    <button name="new_status" value="no_show" class="btn btn-outline-danger"><i class="ti ti-user-x"></i> ลูกค้าไม่มารับ</button>
                </form>
                <?php endif; ?>

            <?php elseif ($status === 'closed'): ?>
                <div class="alert alert-success text-center mb-0"><i class="ti ti-circle-check"></i> ออเดอร์นี้เสร็จแล้ว</div>
            <?php elseif ($status === 'no_show'): ?>
                <div class="alert alert-danger text-center mb-0"><i class="ti ti-user-x"></i> ลูกค้าไม่มารับอาหาร</div>
            <?php elseif ($status === 'cancelled'): ?>
                <div class="alert alert-secondary text-center mb-0"><i class="ti ti-ban"></i> ออเดอร์นี้ถูกยกเลิกแล้ว</div>
            <?php endif; ?>

            <?php if (in_array($status, ['pending', 'active'], true) && !$isPaid): ?>
                <div class="d-flex gap-2">
                    <a href="edit.php?order_id=<?= $orderId ?>" class="btn btn-outline-secondary flex-fill"><i class="ti ti-edit"></i> แก้ไข</a>
                    <form action="update_status.php" method="post" class="flex-fill d-grid"
                          onsubmit="return confirm('ยกเลิกออเดอร์นี้ใช่ไหม? ยกเลิกแล้วกู้คืนไม่ได้');"><?= Security::csrfInput() ?>
                        <input type="hidden" name="order_id" value="<?= $orderId ?>">
                        <button name="new_status" value="cancelled" class="btn btn-outline-danger"><i class="ti ti-ban"></i> ยกเลิก</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($isPaid): ?>
                <div class="d-flex gap-2">
                    <a href="/restaurant-backoffice/modules/pos/receipt.php?order_id=<?= $orderId ?>" class="btn btn-outline-secondary flex-fill"><i class="ti ti-receipt"></i> ใบเสร็จ</a>
                    <a href="../refunds/request.php?order_id=<?= $orderId ?>" class="btn btn-outline-danger flex-fill"><i class="ti ti-receipt-refund"></i> ขอคืนเงิน</a>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">ขั้นตอน</div>
            <div class="card-body">
                <ul class="steps">
                    <?php foreach ($steps as $n => $label):
                        $cls = $failed ? ($n === 0 ? 'done' : '') : ($n < $at ? 'done' : ($n === $at ? 'now' : ''));
                    ?>
                    <li class="<?= $cls ?>">
                        <span class="dot"><?= $cls === 'done' ? '<i class="ti ti-check"></i>' : $n + 1 ?></span><?= $label ?>
                    </li>
                    <?php endforeach; ?>
                    <?php if ($failed): ?>
                    <li class="bad"><span class="dot"><i class="ti ti-x"></i></span><?= $status === 'no_show' ? 'ลูกค้าไม่มารับ' : 'ยกเลิก' ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-header">การชำระเงิน</div>
            <div class="card-body">
                <dl class="info-list">
                    <dt>สถานะ</dt>
                    <dd><?= match ($order['payment_status'] ?? '') {
                        'refunded' => '<span class="text-secondary">คืนเงินแล้ว</span>',
                        'partially_refunded' => '<span class="text-warning">คืนเงินบางส่วน</span>',
                        default => $isPaid ? '<span class="text-success">ชำระแล้ว</span>' : '<span class="text-danger">ยังไม่ชำระ</span>',
                    } ?></dd>
                    <?php foreach ($payments as $p): ?>
                    <?php $isRefund = $p['transaction_type'] === 'refund'; ?>
                    <dt><?= ($isRefund ? 'คืนเงิน · ' : '') . ($methodLabel[$p['method']] ?? $p['method']) ?></dt>
                    <dd class="<?= $isRefund ? 'text-danger' : '' ?>"><?= $isRefund ? '-' : '' ?>฿<?= number_format((float) $p['amount'], 2) ?> <span class="text-muted small"><?= date('H:i', strtotime($p['created_at'])) ?></span></dd>
                    <?php endforeach; ?>
                    <?php if ($order['scheduled_at']): ?>
                    <dt>นัดรับ</dt><dd><?= date('d/m/Y H:i', strtotime($order['scheduled_at'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($status === 'ready' && $order['pickup_deadline']): ?>
                    <dt>ต้องมารับภายใน</dt><dd><?= date('H:i', strtotime($order['pickup_deadline'])) ?> น.</dd>
                    <?php endif; ?>
                    <?php if ($member): ?>
                    <dt>สมาชิก</dt><dd><?= htmlspecialchars($member['display_name']) ?> <span class="text-muted small">(<?= (int) $member['points'] ?> แต้ม)</span></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
</div>

<p class="mt-3"><a href="index.php" class="text-decoration-none"><i class="ti ti-arrow-left"></i> กลับไปรายการออเดอร์</a></p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
