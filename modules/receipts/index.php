<?php
// ใบเสร็จย้อนหลัง แยกตามช่องทางและวัน/เดือน
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';
Auth::requireAnyRole(['owner', 'manager', 'cashier', 'staff']);

$channelNames = ['all' => 'ทั้งหมด', 'kiosk' => 'ตู้สั่งเอง', 'walkin' => 'หน้าร้าน', 'online' => 'ออนไลน์'];
$channel = array_key_exists($_GET['channel'] ?? '', $channelNames) ? $_GET['channel'] : 'all';
$period = ($_GET['period'] ?? 'day') === 'month' ? 'month' : 'day';
$today = date('Y-m-d');

if ($period === 'month') {
    $selected = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    $from = $selected . '-01 00:00:00';
    $to = date('Y-m-d H:i:s', strtotime($from . ' +1 month'));
} else {
    $selected = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $today;
    $from = $selected . ' 00:00:00';
    $to = date('Y-m-d H:i:s', strtotime($from . ' +1 day'));
}

$sql = "SELECT o.order_id, o.order_code, o.queue_no, o.channel, o.service_type, o.status,
               o.payment_status, o.paid_at, o.discount_amount, o.note,
               m.display_name,
               GREATEST(0,
                   COALESCE((SELECT SUM(oi.quantity * oi.unit_price
                           + oi.quantity * COALESCE((SELECT SUM(oia.addon_price)
                                                     FROM order_item_addons oia
                                                     WHERE oia.order_item_id = oi.order_item_id), 0))
                             FROM order_items oi
                             WHERE oi.order_id = o.order_id AND oi.voided_at IS NULL), 0)
                   - o.discount_amount
               ) AS order_total,
               COALESCE((SELECT SUM(p.amount) FROM payments p
                         WHERE p.order_id = o.order_id AND p.transaction_type = 'payment'
                           AND p.status = 'completed'), 0) AS paid_amount,
               COALESCE((SELECT SUM(p.amount) FROM payments p
                         WHERE p.order_id = o.order_id AND p.transaction_type = 'refund'
                           AND p.status = 'completed'), 0) AS refund_amount,
               (SELECT GROUP_CONCAT(DISTINCT p.method ORDER BY p.payment_id SEPARATOR ',')
                FROM payments p WHERE p.order_id = o.order_id
                  AND p.transaction_type = 'payment' AND p.status = 'completed') AS payment_methods
        FROM orders o
        LEFT JOIN members m ON m.member_id = o.member_id
        WHERE (:channel = 'all' OR o.channel = :channel2)
          AND o.paid_at >= :date_from AND o.paid_at < :date_to
          AND o.payment_status IN ('paid', 'partially_refunded', 'refunded')
        ORDER BY o.paid_at DESC, o.order_id DESC";
$stmt = getDB()->prepare($sql);
$stmt->execute(['channel' => $channel, 'channel2' => $channel, 'date_from' => $from, 'date_to' => $to]);
$receipts = $stmt->fetchAll();

$receiptCount = count($receipts);
$grossTotal = array_sum(array_map(fn($r) => (float)$r['paid_amount'], $receipts));
$refundTotal = array_sum(array_map(fn($r) => (float)$r['refund_amount'], $receipts));
$netTotal = $grossTotal - $refundTotal;

$thaiMonths = [1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
function thaiReceiptDate(string $date, bool $withTime = false): string
{
    global $thaiMonths;
    $timestamp = strtotime($date);
    $text = date('j', $timestamp) . ' ' . $thaiMonths[(int)date('n', $timestamp)] . ' ' . ((int)date('Y', $timestamp) + 543);
    return $withTime ? $text . ' เวลา ' . date('H:i', $timestamp) . ' น.' : $text;
}
function paymentMethodLabel(?string $methods): string
{
    if (!$methods) return '-';
    $labels = ['cash'=>'เงินสด', 'transfer'=>'เงินโอน', 'transfer_slip'=>'โอน (สลิป)', 'mock_online'=>'ออนไลน์'];
    return implode(', ', array_map(fn($method) => $labels[$method] ?? $method, explode(',', $methods)));
}

$groups = [];
if ($period === 'month') {
    foreach ($receipts as $receipt) {
        $groups[date('Y-m-d', strtotime($receipt['paid_at']))][] = $receipt;
    }
} else {
    $groups[$selected] = $receipts;
}

require_once __DIR__ . '/../../includes/header.php';
?>
<?php pageHeader('ใบเสร็จ', 'ใบเสร็จย้อนหลังทั้งหมด'); ?>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-3">
        <div class="btn-group flex-wrap" role="group" aria-label="ช่องทางการสั่ง">
            <?php foreach ($channelNames as $key => $label): ?>
            <a class="btn <?= $channel === $key ? 'btn-dark' : 'btn-outline-dark' ?>" href="?channel=<?= $key ?>&period=<?= $period ?>&<?= $period === 'month' ? 'month='.urlencode($selected) : 'date='.urlencode($selected) ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
        <div class="btn-group" role="group" aria-label="ช่วงเวลา">
            <a class="btn <?= $period === 'day' ? 'btn-dark' : 'btn-outline-dark' ?>" href="?channel=<?= $channel ?>&period=day&date=<?= $today ?>">รายวัน</a>
            <a class="btn <?= $period === 'month' ? 'btn-dark' : 'btn-outline-dark' ?>" href="?channel=<?= $channel ?>&period=month&month=<?= date('Y-m') ?>">รายเดือน</a>
        </div>
    </div>
    <form method="get" class="row g-2 align-items-end mt-2">
        <input type="hidden" name="channel" value="<?= $channel ?>"><input type="hidden" name="period" value="<?= $period ?>">
        <div class="col-sm-6 col-lg-4">
            <label class="form-label"><?= $period === 'month' ? 'เลือกเดือน' : 'เลือกวันที่' ?></label>
            <input class="form-control" type="<?= $period === 'month' ? 'month' : 'date' ?>" name="<?= $period === 'month' ? 'month' : 'date' ?>" value="<?= htmlspecialchars($selected) ?>" required>
        </div>
        <div class="col-auto"><button class="btn btn-outline-dark"><i class="ti ti-filter"></i> แสดงข้อมูล</button></div>
    </form>
</div></div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">จำนวนใบเสร็จ</div><div class="fs-3 fw-bold"><?= number_format($receiptCount) ?> ใบ</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">รับชำระทั้งหมด</div><div class="fs-3 fw-bold">฿<?= number_format($grossTotal, 2) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">คืนเงิน</div><div class="fs-3 fw-bold text-danger">฿<?= number_format($refundTotal, 2) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">ยอดสุทธิ</div><div class="fs-3 fw-bold text-success">฿<?= number_format($netTotal, 2) ?></div></div></div></div>
</div>

<?php if (!$receipts): ?>
<div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5"><i class="ti ti-receipt-off fs-1 d-block mb-2"></i>ไม่พบใบเสร็จ<?= $channel === 'all' ? '' : $channelNames[$channel] ?>ในช่วงเวลาที่เลือก</div></div>
<?php endif; ?>

<?php foreach ($groups as $groupDate => $items): if (!$items) continue; ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="fw-bold"><i class="ti ti-calendar"></i> <?= thaiReceiptDate($groupDate) ?></div>
        <div class="small text-muted"><?= count($items) ?> ใบ · ยอดสุทธิ ฿<?= number_format(array_sum(array_map(fn($r)=>(float)$r['paid_amount']-(float)$r['refund_amount'], $items)), 2) ?></div>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>เลขที่ใบเสร็จ</th><th>คิว</th><th>เวลา</th><th>ช่องทาง</th><th>ประเภทบริการ</th><th>ลูกค้า/หมายเหตุ</th><th>วิธีชำระ</th><th class="text-end">ยอดชำระ</th><th class="text-end">คืนเงิน</th><th></th></tr></thead>
        <tbody><?php foreach ($items as $receipt): ?>
        <tr>
            <td class="fw-semibold"><?= htmlspecialchars($receipt['order_code'] ?: '#'.$receipt['order_id']) ?></td>
            <td class="fw-semibold"><?= !empty($receipt['queue_no']) ? KioskOrder::queueLabel((int) $receipt['queue_no'], $receipt['channel'], (int)$receipt['order_id']) : '-' ?></td>
            <td><?= date('H:i', strtotime($receipt['paid_at'])) ?> น.</td>
            <td><span class="badge text-bg-light border"><?= $channelNames[$receipt['channel']] ?? $receipt['channel'] ?></span></td>
            <td><span class="badge text-bg-light border"><?= $receipt['service_type'] === 'dine_in' ? 'ทานที่ร้าน' : 'รับกลับบ้าน' ?></span></td>
            <td><?= htmlspecialchars($receipt['display_name'] ?: ($receipt['note'] ?: '-')) ?></td>
            <td><?= htmlspecialchars(paymentMethodLabel($receipt['payment_methods'])) ?></td>
            <td class="text-end fw-semibold">฿<?= number_format((float)$receipt['paid_amount'], 2) ?></td>
            <td class="text-end <?= (float)$receipt['refund_amount'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">฿<?= number_format((float)$receipt['refund_amount'], 2) ?></td>
            <td class="text-end"><a href="../pos/receipt.php?order_id=<?= (int)$receipt['order_id'] ?>&from=history" class="btn btn-outline-secondary btn-sm"><i class="ti ti-receipt"></i> เปิดใบเสร็จ</a></td>
        </tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
