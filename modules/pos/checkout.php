<?php
// รับเงิน (เงินสด/โอน) แล้วส่งเข้าครัว
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/OrderItem.php';
require_once __DIR__ . '/../../classes/TableSession.php';
require_once __DIR__ . '/../../classes/Payment.php';
require_once __DIR__ . '/../../classes/KioskOrder.php';
Auth::requireAnyRole(['owner','manager','cashier','staff']);
require_once __DIR__.'/../../classes/Security.php';

$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$order = Order::find($orderId);

if (!$order) {
    die('ไม่พบออเดอร์นี้');
}
// ทุกช่องทาง "จ่ายก่อน" รับเงินแล้วจึงส่งเข้าครัว (active/ready ที่ยังไม่จ่าย = ออเดอร์เก่าก่อนเปลี่ยนระบบ)
$isPrepay = $order['status'] === 'pending' && (int) $order['is_paid'] === 0;
// ออเดอร์ตู้ที่ค้างจากก่อนเปลี่ยนเป็นจ่ายก่อน (ทำอาหารแล้วแต่ยังไม่จ่าย): รับเงินอย่างเดียว แล้วส่งมอบตามขั้นตอนปกติ
require_once __DIR__ . '/../../classes/ServiceFlow.php';
$isLegacyService = !$isPrepay && (int) $order['is_paid'] === 0 && ServiceFlow::details($orderId) !== null;

// ร้านเปิดสแกนจ่ายอัตโนมัติ (Omise/SCB): ช่องทางโอนเงินใช้ QR แบบเดียวกับหน้าตู้ ไม่ต้องให้แคชเชียร์กดยืนยันเอง
require_once __DIR__ . '/../../classes/PaymentGateway.php';
$gatewayReady = $isPrepay && PaymentGateway::provider() && PaymentGateway::configured();
$resumeQr = false;
// เปิดหน้าใหม่ระหว่างรอลูกค้าสแกน (ออเดอร์ที่แคชเชียร์สร้างเอง) ให้กลับมาที่ QR เดิม ส่วนลูกค้าตู้ที่เลือกจ่ายเงินสดยังเริ่มที่เงินสด
if ($gatewayReady && $order['channel'] === 'walkin' && PaymentGateway::provider() === 'omise') {
    $gw = OmiseGateway::row($orderId);
    $resumeQr = $gw && $gw['status'] === 'pending';
}

if (!$isPrepay && !in_array($order['status'], ['active', 'ready'], true)) {
    die('ออเดอร์นี้ไม่ได้อยู่ในสถานะที่คิดเงินได้ (สถานะปัจจุบัน: ' . htmlspecialchars($order['status']) . ')');
}

$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::verifyCsrf();
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $cashReceived = (float) ($_POST['cash_received'] ?? 0);

    // คำนวณยอดรวมจริงฝั่งเซิร์ฟเวอร์ (ห้ามเชื่อค่าจาก JS ฝั่ง client ล้วนๆ)
    $items = OrderItem::getByOrder($orderId);
    $total = array_sum(array_column($items, 'subtotal'));
    foreach ($items as $i) {
        foreach (OrderItem::getAddons((int) $i['order_item_id']) as $a) {
            $total += (float) $a['addon_price'] * (int) $i['quantity'];
        }
    }

    $total = Payment::orderTotal($orderId);
    if ($paymentMethod === 'cash' && $cashReceived < $total) {
        $errors[] = 'เงินที่รับมาน้อยกว่ายอดที่ต้องชำระ';
    }
    if ($gatewayReady && $paymentMethod !== 'cash') {
        $errors[] = 'โอนเงินให้ลูกค้าสแกน QR แล้วรอระบบยืนยันผลอัตโนมัติ';
    }

    if (empty($errors)) {
        try {
            // ลูกค้าเคยกด QR Omise ไว้: ตรวจผลก่อน ถ้าจ่ายแล้วไม่ต้องรับเงินซ้ำ ถ้ายังไม่จ่ายจะยกเลิก QR นั้น
            require_once __DIR__ . '/../../classes/OmiseGateway.php';
            if (OmiseGateway::releaseForCounter($orderId, Auth::id()) === 'paid') {
                header('Location: receipt.php?order_id=' . $orderId);
                exit;
            }
            if ($isPrepay) {
                // จ่ายก่อน: บันทึกรับเงิน แล้ว Payment::record จะส่งออเดอร์เข้าครัวให้เอง
                KioskOrder::acceptCounterPayment($orderId, $paymentMethod, $cashReceived, Auth::id());
                header('Location: receipt.php?order_id=' . $orderId . '&cash=' . $cashReceived);
                exit;
            }
            if ($isLegacyService) {
                Payment::record($orderId, $paymentMethod, $total, Auth::id(), trim($_POST['reference_no'] ?? '') ?: null);
                header('Location: receipt.php?order_id=' . $orderId . '&cash=' . $cashReceived);
                exit;
            }

            // ปิดออเดอร์ก่อน (ตัดสต๊อก FEFO + ให้แต้มสมาชิกถ้ามี) แล้วค่อยบันทึกว่าชำระเงินแล้ว
            Order::changeStatus($orderId, 'closed');

            Payment::record($orderId, $paymentMethod, $total, Auth::id(), trim($_POST['reference_no'] ?? '') ?: null);

            if (!empty($order['table_session_id'])) {
                TableSession::close((int) $order['table_session_id']);
            }

            header('Location: receipt.php?order_id=' . $orderId . '&cash=' . $cashReceived);
            exit;
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
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

$total = Payment::orderTotal($orderId);
$service = ServiceFlow::details($orderId);
require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('คิดเงิน — ' . (!empty($order['queue_no']) ? 'คิว ' . KioskOrder::queueLabel((int) $order['queue_no'], $order['channel'], (int)$order['order_id']) : 'ออเดอร์ #' . $order['order_id']) . (!empty($order['queue_card_no']) ? ' · ' . QueueCard::label((int) $order['queue_card_no']) : ''),
    $isPrepay ? 'รับเงินแล้วออเดอร์จะเข้าครัว' : 'รับเงินจากลูกค้า'); ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if($service): ?><div class="alert alert-info">ราคาได้รวม VAT 7% แล้ว · ลูกค้าเลือก<?= $service['receipt_preference']==='paper' ? 'รับใบเสร็จกระดาษ':'ไม่รับใบเสร็จกระดาษ' ?></div><?php endif; ?>
<div class="row g-3">
<div class="col-md-6">
    <div class="card">
        <div class="card-header bg-white fw-semibold">สรุปยอด</div>
        <div class="card-body">
            <?php foreach ($items as $i): ?>
            <div class="d-flex justify-content-between py-1">
                <span><span class="text-muted me-2">x<?= $i['quantity'] ?></span><?= htmlspecialchars($i['menu_name']) ?></span>
                <span>฿<?= number_format($i['subtotal'], 2) ?></span>
            </div>
            <?php foreach ($addonsByItem[$i['order_item_id']] as $a): ?>
                <div class="d-flex justify-content-between small text-muted ps-4">
                    <span>+ <?= htmlspecialchars($a['addon_name']) ?></span>
                    <span>฿<?= number_format((float)$a['addon_price'] * (int)$i['quantity'], 2) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (!empty($i['item_note'])): ?>
                <div class="small text-danger fw-bold ps-4">หมายเหตุ: <?= htmlspecialchars($i['item_note']) ?></div>
            <?php endif; ?>
            <?php endforeach; ?>
            <div class="d-flex justify-content-between fw-bold fs-4 pt-3 mt-2 border-top border-dark">
                <span>ยอดที่ต้องชำระ</span><span id="totalDisplay">฿<?= number_format($total, 2) ?></span>
            </div>
        </div>
    </div>
</div>

<div class="col-md-6">
    <div class="card">
        <div class="card-body">
            <form method="post" action="checkout.php"><?= Security::csrfInput() ?>
                <input type="hidden" name="order_id" value="<?= $order['order_id'] ?>">

                <div class="btn-group w-100 mb-3" role="group">
                    <input type="radio" class="btn-check" name="payment_method" id="pay-cash" value="cash" checked onchange="togglePay()">
                    <label class="btn btn-outline-dark" for="pay-cash"><i class="ti ti-cash"></i> เงินสด</label>

                    <input type="radio" class="btn-check" name="payment_method" id="pay-transfer" value="transfer" onchange="togglePay()">
                    <label class="btn btn-outline-dark" for="pay-transfer"><i class="ti ti-<?= $gatewayReady ? 'qrcode' : 'building-bank' ?>"></i> โอนเงิน<?= $gatewayReady ? ' (สแกน QR)' : '' ?></label>
                </div>

                <div id="cashPanel">
                    <label class="form-label">รับเงินมา (บาท)</label>
                    <input type="number" step="1" name="cash_received" id="cashInput" class="form-control form-control-lg mb-2"
                           value="<?= (int) ceil($total / 100) * 100 ?>" oninput="calcChange()">
                    <div id="changeArea"></div>
                </div>
                <div id="transferPanel" style="display:none;">
                    <?php if ($gatewayReady): ?>
                    <div class="text-center">
                        <div class="small text-muted mb-2">ให้ลูกค้าสแกนจ่ายพร้อมเพย์ <strong>฿<?= number_format($total, 2) ?></strong></div>
                        <div id="qrBox" class="d-inline-flex align-items-center justify-content-center border rounded-3 bg-white p-2" style="width:250px;height:250px">
                            <span class="text-muted small">กำลังสร้าง QR…</span>
                        </div>
                        <div id="qrStatus" class="fw-bold mt-2" role="status">กำลังเตรียมการชำระเงิน</div>
                        <div id="qrSub" class="small text-muted">ไม่ต้องดูสลิป ระบบตรวจผลกับผู้ให้บริการแล้วส่งเข้าครัวให้อัตโนมัติ</div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-success text-center mb-0"><i class="ti ti-circle-check"></i> ยืนยันรับโอนเงินแล้ว — ไม่ต้องคำนวณเงินทอน</div>
                    <label class="form-label mt-2">เลขอ้างอิง (ถ้ามี)</label><input name="reference_no" class="form-control">
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-dark w-100 mt-3" id="submitBtn"><i class="ti ti-device-floppy"></i> <?= $isPrepay ? 'รับเงินและส่งเข้าครัว' : ($isLegacyService ? 'รับเงิน (ส่งมอบที่หน้าคิดเงิน)' : 'ยืนยันชำระเงินและปิดบัญชี') ?></button>
            </form>
        </div>
    </div>
</div>
</div>

<p class="mt-3"><a href="/restaurant-backoffice/modules/orders/view.php?id=<?= $order['order_id'] ?>"><i class="ti ti-arrow-left"></i> กลับไปหน้าออเดอร์</a></p>

<script>
const TOTAL = <?= json_encode((float) $total) ?>;
const DENOMS = [1000, 500, 100, 50, 20, 10, 5, 1];

const GATEWAY = <?= json_encode($gatewayReady) ?>;
const ORDER_ID = <?= (int) $order['order_id'] ?>;
let qrStarted = false, qrPoll = null;

function togglePay(){
    const isCash = document.getElementById('pay-cash').checked;
    document.getElementById('cashPanel').style.display = isCash ? 'block' : 'none';
    document.getElementById('transferPanel').style.display = isCash ? 'none' : 'block';
    if (GATEWAY) {
        document.getElementById('submitBtn').style.display = isCash ? '' : 'none';
        if (!isCash) startQr();
    }
}

// สร้าง QR ครั้งเดียวต่อออเดอร์ (เปิดหน้าใหม่จะได้ QR เดิมที่ยังไม่หมดอายุ) แล้วเช็กผลทุก 3 วินาที
function startQr(){
    if (qrStarted) return;
    qrStarted = true;
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('input[name=csrf_token]').value);
    fd.append('order_id', ORDER_ID);
    fetch('gateway.php?action=start', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) throw new Error(d.error || 'สร้าง QR ไม่สำเร็จ');
            showQr(d.gateway);
            if (['pending', 'creating', 'unknown'].includes(d.gateway.status)) { qrPoll = setInterval(checkQr, 3000); checkQr(); }
            else qrState(d.gateway.status);
        })
        .catch(e => { qrStarted = false; document.getElementById('qrBox').innerHTML = '<span class="text-danger small">' + e.message + '</span>'; });
}

function showQr(g){
    const box = document.getElementById('qrBox');
    if (g.qr_raw && window.QRCode) { box.innerHTML = ''; new QRCode(box, { text: g.qr_raw, width: 230, height: 230, correctLevel: QRCode.CorrectLevel.M }); }
    else if (g.qr_url) { const img = document.createElement('img'); img.src = g.qr_url; img.alt = 'QR พร้อมเพย์'; img.width = 230; img.height = 230; box.replaceChildren(img); }
    document.getElementById('qrStatus').textContent = g.test ? 'โหมดทดสอบ — รอชำระเงิน (ไม่รับเงินจริง)' : 'รอลูกค้าสแกนจ่าย';
    document.getElementById('qrSub').textContent = g.test
        ? (g.provider === 'scb' ? 'สแกนด้วยแอป SCB EASY Simulator แล้วหน้านี้จะเปลี่ยนเอง' : 'ยืนยันรายการใน Omise Dashboard (โหมดทดสอบ) แล้วหน้านี้จะเปลี่ยนเอง')
        : 'โอนสำเร็จแล้ว หน้านี้จะพาไปใบเสร็จและส่งเข้าครัวเอง';
}

function qrState(status){
    const msg = { review: 'ได้รับเงินแล้ว แต่ต้องตรวจสอบที่หน้า "รายการ Omise"', failed: 'ชำระไม่สำเร็จ', expired: 'QR หมดอายุหรือถูกยกเลิกแล้ว — รับเป็นเงินสดแทน', unknown: 'กำลังตรวจสอบรายการ ห้ามให้ลูกค้าจ่ายซ้ำ' }[status];
    if (!msg) return;
    document.getElementById('qrStatus').textContent = msg;
    document.getElementById('qrSub').textContent = '';
    if (status !== 'unknown') { document.getElementById('qrBox').innerHTML = '<i class="ti ti-qrcode-off fs-1 text-muted"></i>'; if (qrPoll) { clearInterval(qrPoll); qrPoll = null; } }
}

function checkQr(){
    fetch('gateway.php?action=status&order_id=' + ORDER_ID, { cache: 'no-store' })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) return;
            if (d.is_paid) {
                clearInterval(qrPoll); qrPoll = null;
                document.getElementById('qrStatus').innerHTML = '<span class="text-success"><i class="ti ti-circle-check"></i> ชำระแล้ว ส่งเข้าครัวแล้ว</span>';
                setTimeout(() => { location.href = 'receipt.php?order_id=' + ORDER_ID; }, 800);
                return;
            }
            if (d.gateway) qrState(d.gateway.status);
        })
        .catch(() => { /* เน็ตสะดุด ลองรอบถัดไป */ });
}

function calcChange(){
    const cash = parseFloat(document.getElementById('cashInput').value) || 0;
    const area = document.getElementById('changeArea');

    if (cash < TOTAL){
        area.innerHTML = '<div class="text-danger small mt-1">เงินไม่พอ ขาดอีก ฿' + (TOTAL - cash).toLocaleString() + '</div>';
        return;
    }

    let remaining = Math.round(cash - TOTAL);
    const change = remaining;
    let chips = '';
    for (const d of DENOMS){
        const count = Math.floor(remaining / d);
        if (count > 0){
            chips += '<span class="badge text-bg-light border me-1 mb-1">฿' + d + ' × ' + count + '</span>';
            remaining -= d * count;
        }
    }

    area.innerHTML = `
        <div class="bg-light rounded p-3 mt-2">
            <div class="text-muted small">เงินทอน</div>
            <div class="fs-3 fw-bold text-success mb-2">฿${change.toLocaleString()}</div>
            <div>${chips || '<span class="badge text-bg-light border">พอดี ไม่ต้องทอน</span>'}</div>
        </div>`;
}

document.addEventListener('DOMContentLoaded', function () {
    calcChange();
    <?php if ($resumeQr): ?>document.getElementById('pay-transfer').checked = true; togglePay();<?php endif; ?>
});
</script>

<?php if ($gatewayReady): ?><script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script><?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
