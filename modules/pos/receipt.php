<?php
// แสดงใบเสร็จ
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/OrderItem.php';
require_once __DIR__ . '/../../classes/AppSetting.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';
require_once __DIR__ . '/../../classes/Member.php';
Auth::requireLogin();

$orderId = (int) ($_GET['order_id'] ?? 0);
$cashReceived = isset($_GET['cash']) ? (float) $_GET['cash'] : null;
$order = Order::find($orderId);

if (!$order || !in_array($order['payment_status'] ?? 'unpaid', ['paid', 'partially_refunded', 'refunded'], true)) {
    die('ไม่พบใบเสร็จของออเดอร์นี้');
}

$items = OrderItem::getByOrder($orderId);
$total = array_sum(array_column($items, 'subtotal'));
$addonsByItem = [];
foreach ($items as $i) {
    $itemAddons = OrderItem::getAddons((int) $i['order_item_id']);
    $addonsByItem[$i['order_item_id']] = $itemAddons;
    foreach ($itemAddons as $a) {
        $total += (float) $a['addon_price'] * (int) $i['quantity'];
    }
}

$subtotal = $total;
$discount = (float)($order['discount_amount'] ?? 0);
$total = max(0, $subtotal - $discount);
$change = ($order['payment_method'] === 'cash' && $cashReceived !== null) ? $cashReceived - $total : null;
$paymentStmt = getDB()->prepare("SELECT method, SUM(amount) AS paid_amount
                                FROM payments WHERE order_id=:id AND status='completed' AND transaction_type='payment'
                                GROUP BY method ORDER BY MIN(payment_id)");
$paymentStmt->execute(['id'=>$orderId]);
$paymentRows = $paymentStmt->fetchAll();
$refundStmt = getDB()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments
                               WHERE order_id=:id AND status='completed' AND transaction_type='refund'");
$refundStmt->execute(['id'=>$orderId]);
$refundAmount = (float)$refundStmt->fetchColumn();
$methodLabels = ['cash'=>'เงินสด','transfer'=>'เงินโอน','transfer_slip'=>'โอน (สลิป)','mock_online'=>'ออนไลน์'];
$storeName = AppSetting::get('store_name', 'ครัวธนุรเวท');
$member = Member::receiptSummary($order);
$service=ServiceFlow::details($orderId);
// ลูกค้าเลือกรับใบเสร็จกระดาษ หรือหน้าแคชเชียร์สั่งพิมพ์อัตโนมัติ: พิมพ์ทันทีครั้งเดียว
$printedStmt = getDB()->prepare("SELECT COUNT(*) FROM audit_logs WHERE entity_type='order' AND entity_id=? AND action='receipt_print_requested'");
$printedStmt->execute([(string) $orderId]);
$autoPrint = (int) $printedStmt->fetchColumn() === 0
    && (($_GET['autoprint'] ?? '') === '1' || ($service && $service['receipt_preference'] === 'paper'));
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_once __DIR__.'/../../classes/Security.php'; Security::verifyCsrf();
    if (($_POST['action'] ?? '')==='print_request') {
        ServiceFlow::event($orderId,'receipt_print_requested',Auth::id(),['channel'=>'browser','result'=>'requested_not_confirmed']);
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>true]); exit;
    }
    http_response_code(400); exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('ใบเสร็จ', (!empty($order['queue_no']) ? 'คิว ' . KioskOrder::queueLabel((int) $order['queue_no'], $order['channel'], (int)$order['order_id']) : 'ออเดอร์ #' . $order['order_id']) . (!empty($order['queue_card_no']) ? ' · ' . QueueCard::label((int) $order['queue_card_no']) : '')); ?>

<?php if($service): ?><p class="text-center no-print">ลูกค้าเลือก<?= $service['receipt_preference']==='paper' ? 'รับใบเสร็จกระดาษ':'ไม่รับกระดาษ — พิมพ์เมื่อร้องขอเพิ่มเติมเท่านั้น' ?></p><?php endif; ?>
<div class="card mx-auto" style="max-width:340px;">
    <div class="card-body font-monospace small" id="receipt-paper">
        <div class="text-center fw-bold"><?= htmlspecialchars($storeName) ?></div>
        <div class="text-center text-muted">ใบเสร็จรับเงิน (ไม่ใช่ใบกำกับภาษี)</div>
        <hr>
        <div class="d-flex justify-content-between"><span><?= htmlspecialchars($order['order_code'] ?: 'ORD-'.str_pad($order['order_id'], 4, '0', STR_PAD_LEFT)) ?></span><span><?= $order['paid_at'] ?></span></div>
        <?php if (!empty($order['queue_no'])): ?><div class="d-flex justify-content-between fw-bold"><span>คิว <?= KioskOrder::queueLabel((int) $order['queue_no'], $order['channel'], (int)$order['order_id']) ?></span><span><?= htmlspecialchars(QueueCard::label(!empty($order['queue_card_no']) ? (int) $order['queue_card_no'] : null)) ?></span></div><?php endif; ?>
        <hr>
        <?php foreach ($items as $i): ?>
            <div class="d-flex justify-content-between"><span><?= htmlspecialchars($i['menu_name']) ?> x<?= $i['quantity'] ?></span><span><?= number_format($i['subtotal'], 2) ?></span></div>
            <?php foreach ($addonsByItem[$i['order_item_id']] as $a): ?>
                <div class="d-flex justify-content-between text-muted"><span>+ <?= htmlspecialchars($a['addon_name']) ?> x<?= $i['quantity'] ?></span><span><?= number_format((float)$a['addon_price'] * (int)$i['quantity'], 2) ?></span></div>
            <?php endforeach; ?>
            <?php if (!empty($i['item_note'])): ?><div class="text-muted small">หมายเหตุ: <?= htmlspecialchars($i['item_note']) ?></div><?php endif; ?>
        <?php endforeach; ?>
        <hr>
        <div class="d-flex justify-content-between"><span>รวมรายการ</span><span><?= number_format($subtotal, 2) ?></span></div>
        <?php if ($discount > 0): ?><div class="d-flex justify-content-between text-danger"><span>ส่วนลด</span><span>-<?= number_format($discount, 2) ?></span></div><?php endif; ?>
        <?php if($service && $service['tax_recorded_at']): ?>
        <div>ราคานี้รวม VAT <?= htmlspecialchars($service['vat_rate']) ?>% แล้ว</div>
        <div class="d-flex justify-content-between"><span>มูลค่าก่อน VAT</span><span><?= number_format((float)$service['net_amount'],2) ?></span></div>
        <div class="d-flex justify-content-between"><span>VAT</span><span><?= number_format((float)$service['vat_amount'],2) ?></span></div>
        <?php else: $tax = ServiceFlow::vat((float)$total); ?>
        <div>ราคานี้รวม VAT <?= number_format($tax['rate'], 2) ?>% แล้ว</div>
        <div class="d-flex justify-content-between"><span>มูลค่าก่อน VAT</span><span><?= number_format($tax['net'],2) ?></span></div>
        <div class="d-flex justify-content-between"><span>VAT</span><span><?= number_format($tax['vat'],2) ?></span></div>
        <?php endif; ?>
        <div class="d-flex justify-content-between fw-bold"><span>ยอดสุทธิ</span><span><?= number_format($total, 2) ?></span></div>
        <?php if ($order['payment_method'] === 'cash' && $cashReceived !== null): ?>
            <div class="d-flex justify-content-between"><span>รับเงิน</span><span><?= number_format($cashReceived, 2) ?></span></div>
            <div class="d-flex justify-content-between"><span>เงินทอน</span><span><?= number_format($change, 2) ?></span></div>
        <?php endif; ?>
        <?php foreach ($paymentRows as $payment): ?>
            <div class="d-flex justify-content-between"><span>รับชำระ · <?= htmlspecialchars($methodLabels[$payment['method']] ?? $payment['method']) ?></span><span><?= number_format((float)$payment['paid_amount'], 2) ?></span></div>
        <?php endforeach; ?>
        <?php if ($refundAmount > 0): ?><div class="d-flex justify-content-between text-danger"><span>คืนเงินแล้ว</span><span>-<?= number_format($refundAmount, 2) ?></span></div><?php endif; ?>
        <hr>
        <?php if ($member): ?>
            <div class="d-flex justify-content-between fw-bold"><span>สมาชิก</span><span><?= htmlspecialchars($member['name']) ?></span></div>
            <?php if ($member['used'] > 0): ?><div class="d-flex justify-content-between"><span>ใช้แต้มเป็นส่วนลด</span><span>-<?= number_format($member['used']) ?></span></div><?php endif; ?>
            <div class="d-flex justify-content-between"><span>แต้มที่ได้รับ</span><span>+<?= number_format($member['earned']) ?></span></div>
            <div class="d-flex justify-content-between fw-bold"><span>แต้มคงเหลือ</span><span><?= number_format($member['balance'] + ($member['pending'] ? $member['earned'] : 0)) ?></span></div>
            <?php if ($member['pending']): ?><div class="text-center text-muted">แต้มเข้าบัญชีเมื่อรับอาหาร</div><?php endif; ?>
        <?php else: ?>
            <div class="d-flex justify-content-between"><span>ลูกค้า</span><span>ทั่วไป (ไม่ใช่สมาชิก)</span></div>
        <?php endif; ?>
        <hr>
        <div class="text-center">ขอบคุณที่ใช้บริการ</div>
    </div>
</div>

<div class="text-center mt-3 no-print">
    <button class="btn btn-outline-secondary" id="print-receipt"><i class="ti ti-printer"></i> พิมพ์ใบเสร็จ</button>
    <a href="/restaurant-backoffice/modules/receipts/index.php?channel=<?= $order['channel'] === 'online' ? 'online' : 'walkin' ?>" class="btn btn-outline-dark"><i class="ti ti-history"></i> ประวัติใบเสร็จ</a>
    <a href="/restaurant-backoffice/modules/pos/index.php" class="btn btn-dark"><i class="ti ti-arrow-left"></i> กลับไปหน้า POS</a>
</div>

<?php require_once __DIR__.'/../../classes/Security.php'; ?>
<?php require_once __DIR__.'/../../classes/PrinterSettings.php'; $printer=PrinterSettings::forJs('cashier'); ?>
<style>@media print{@page{<?= $printer['paper']==='a4' ? 'size:A4;margin:10mm' : 'margin:0' ?>}<?php if($printer['paper']!=='a4'): ?>#receipt-paper{width:<?= $printer['paper']==='58' ? '48mm' : '72mm' ?>;margin:0;padding:0 2mm}<?php endif; ?>}</style>
<script src="../../assets/js/printer.js?v=<?= filemtime(__DIR__ . '/../../assets/js/printer.js') ?>"></script>
<script>
(function(){
  var P=<?= json_encode($printer, JSON_UNESCAPED_UNICODE) ?>;
  var btn=document.getElementById('print-receipt');
  var AUTO=<?= $autoPrint ? 'true' : 'false' ?>;
  var direct=P.connection!=='system' && P.paper!=='a4' && P.device_id!=='';
  btn.addEventListener('click',async function(){
    btn.disabled=true;
    try{
      var data=new FormData();data.set('action','print_request');data.set('csrf_token',<?= json_encode(Security::csrfToken()) ?>);
      var r=await fetch(location.href,{method:'POST',body:data});if(!r.ok||!(await r.json()).ok)throw Error('บันทึกคำขอพิมพ์ไม่สำเร็จ');
      if(direct){
        // พิมพ์ตรงไปเครื่องพิมพ์ใบเสร็จที่ตั้งไว้ ถ้ายังไม่ได้อนุญาตในเบราว์เซอร์นี้จะถามเลือกเครื่องครั้งแรก
        if(!PrinterBridge.current()) await PrinterBridge.reconnect(P);
        if(!PrinterBridge.current()){
          if(P.connection==='usb') await PrinterBridge.requestUsb();
          else if(P.connection==='bluetooth') await PrinterBridge.requestBluetooth();
          else await PrinterBridge.requestSerial();
        }
        await PrinterBridge.printRows(PrinterBridge.rowsFromElement(document.getElementById('receipt-paper')),P.paper);
      } else window.print();
    }catch(e){
      if(e && e.name==='NotFoundError') return;
      if(confirm('พิมพ์ตรงไม่สำเร็จ ('+(e.message||e)+')\nพิมพ์ผ่านหน้าต่างพิมพ์แทนไหม?')) window.print();
    }finally{btn.disabled=false;}
  });
  if(AUTO) btn.click();
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
