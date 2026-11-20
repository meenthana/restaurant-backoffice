<?php
// หน้าจ่ายเงินออนไลน์ (QR + อัปโหลดสลิป)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/Payment.php';
require_once __DIR__ . '/../classes/SlipVerifier.php';
require_once __DIR__ . '/../classes/KioskOrder.php';
require_once __DIR__ . '/../classes/Member.php';
require_once __DIR__ . '/../classes/PaymentGateway.php';
require_once __DIR__ . '/_layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['member_id'])) { header('Location: login.php'); exit; }

$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$order = Order::find($orderId);
if (!$order || (int) $order['member_id'] !== (int) $_SESSION['member_id']) {
    die('ไม่พบออเดอร์นี้ หรือไม่ใช่ออเดอร์ของคุณ');
}
$member = Member::find((int) $_SESSION['member_id']);

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['slip']) && $order['status'] === 'pending') {
    try {
        $result = SlipVerifier::submit($orderId, $_FILES['slip'], [
            'amount'        => $_POST['amount'] ?? '',
            'slip_datetime' => $_POST['slip_datetime'] ?? '',
            'sender_name'   => trim($_POST['sender_name'] ?? '') ?: null,
        ]);
    } catch (Throwable $e) {
        $result = ['status' => 'rejected', 'message' => $e->getMessage()];
    }
    $order = Order::find($orderId);
}

$total  = Payment::orderTotal($orderId);
$paid   = Payment::paidAmount($orderId);
$due    = round(max(0, $total - $paid), 2);
$isMock = SlipVerifier::mode() === 'mock';
$queue  = KioskOrder::queueLabel($order['queue_no'] ? (int) $order['queue_no'] : null, $order['channel']);
$slips  = SlipVerifier::forOrder($orderId);

// วิธีจ่าย: gateway = สแกนแล้วระบบตรวจยอดเอง / slip = ส่งสลิป / counter = จ่ายที่ร้านตอนรับ
$payMode = PaymentGateway::provider() && PaymentGateway::configured() ? 'gateway' : (PaymentGateway::slipAllowed() ? 'slip' : 'counter');
$gateway = null; $gatewayError = '';
if ($payMode === 'gateway' && $due > 0 && $order['status'] === 'pending') {
    try { $gateway = PaymentGateway::refresh($orderId); } catch (Throwable $e) { $gateway = null; }
    if (!$gateway || empty($gateway['qr_raw']) && empty($gateway['qr_url'])) {
        try { $gateway = PaymentGateway::start($orderId); } catch (Throwable $e) { $gatewayError = $e->getMessage(); }
    }
    $order = Order::find($orderId);
    $paid = Payment::paidAmount($orderId);
    $due = round(max(0, $total - $paid), 2);
}

$promptPayId = trim((string) AppSetting::get('promptpay_id', ''));
$qrPayload = null;
if ($payMode === 'slip' && $promptPayId !== '' && $due > 0) {
    try { $qrPayload = KioskOrder::promptPayPayload($promptPayId, $due); } catch (Exception $e) { $qrPayload = null; }
}

$badge = [
    'verified'  => ['text-bg-success', 'ตรวจผ่าน ชำระแล้ว'],
    'manual'    => ['text-bg-success', 'พนักงานยืนยันแล้ว'],
    'pending'   => ['text-bg-warning', 'รอพนักงานตรวจสอบ'],
    'rejected'  => ['text-bg-danger',  'ไม่ผ่าน'],
    'duplicate' => ['text-bg-dark',    'สลิปซ้ำ'],
];

onlineHead('ชำระเงิน คิว ' . $queue);
onlineTopbar($member);
?>
<div class="container py-4" style="max-width:560px">

    <div class="cust-hero text-center mb-3">
        <div class="eyebrow-c">เลขคิวของคุณ</div>
        <div class="display-4 fw-bold" style="color:var(--c-brand)"><?= htmlspecialchars($queue) ?></div>
        <div class="d-flex justify-content-center gap-4 mt-2">
            <div><div class="small text-muted">ยอดรวม</div><div class="fw-bold">฿<?= number_format($total, 2) ?></div><?php if ((float) ($order['discount_amount'] ?? 0) > 0): ?><div class="small text-success">ใช้แต้มลด ฿<?= number_format((float) $order['discount_amount'], 2) ?> แล้ว</div><?php endif; ?></div>
            <div><div class="small text-muted">ต้องชำระ</div><div class="fw-bold fs-4 <?= $due > 0 ? 'text-danger' : 'text-success' ?>">฿<?= number_format($due, 2) ?></div></div>
        </div>
    </div>

    <?php if ($result): ?>
        <?php $cls = ['verified' => 'success', 'pending' => 'warning', 'duplicate' => 'dark', 'rejected' => 'danger'][$result['status']] ?? 'secondary'; ?>
        <div class="alert alert-<?= $cls ?>">
            <strong><?= htmlspecialchars($result['message']) ?></strong>
            <?php if (!empty($result['data']['trans_ref'])): ?><div class="small">เลขอ้างอิง <code><?= htmlspecialchars($result['data']['trans_ref']) ?></code></div><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($due <= 0): ?>
        <div class="cust-card p-4 text-center mb-3">
            <i class="ti ti-circle-check text-success" style="font-size:3.5rem"></i>
            <div class="fs-4 fw-bold">ชำระเงินเรียบร้อย</div>
            <div class="text-muted">ร้านได้รับออเดอร์แล้ว</div>
        </div>
        <a href="status.php?order_id=<?= $orderId ?>" class="btn-touch btn-brand w-100">ดูสถานะออเดอร์</a>
    <?php elseif ($order['status'] !== 'pending'): ?>
        <div class="alert alert-secondary">ออเดอร์นี้ไม่ได้รอชำระเงินแล้ว</div>
    <?php elseif ($payMode === 'gateway'): ?>
    <div class="cust-card p-4 text-center mb-3">
        <div class="fw-bold mb-2">สแกนจ่ายด้วยแอปธนาคาร</div>
        <?php if ($gateway && in_array($gateway['status'] ?? '', ['failed','expired','review','unknown'], true)): ?>
            <div class="alert alert-warning mb-0">QR นี้ใช้ต่อไม่ได้ (<?= htmlspecialchars($gateway['status']) ?>) กรุณาอย่าโอนซ้ำ และติดต่อร้านพร้อมเลขคิว</div>
        <?php elseif ($gateway && (!empty($gateway['qr_raw']) || !empty($gateway['qr_url']))): ?>
            <div id="qr-pay" class="d-inline-block p-2 bg-white border rounded-3"><?php if (empty($gateway['qr_raw'])): ?><img src="<?= htmlspecialchars($gateway['qr_url']) ?>" width="220" height="220" alt="QR PromptPay"><?php endif; ?></div>
            <div class="small text-muted mt-2">ยอด ฿<?= number_format($due, 2) ?> · ไม่ต้องแนบสลิป ระบบตรวจยอดให้อัตโนมัติ<?= PaymentGateway::isTest() ? ' (โหมดทดสอบ ไม่รับเงินจริง)' : '' ?></div>
            <div class="mt-3 d-flex align-items-center justify-content-center gap-2"><div class="spinner-border spinner-border-sm text-success"></div><span id="gw-status">รอชำระเงิน…</span></div>
        <?php else: ?>
            <div class="alert alert-warning mb-0">สร้าง QR ไม่สำเร็จ <?= htmlspecialchars($gatewayError) ?><br>กรุณาลองใหม่ หรือชำระที่ร้านตอนมารับ (แจ้งเลขคิว)</div>
        <?php endif; ?>
    </div>
    <a href="status.php?order_id=<?= $orderId ?>" class="btn btn-link w-100">ดูสถานะออเดอร์</a>
    <?php elseif ($payMode === 'counter'): ?>
    <div class="cust-card p-4 text-center mb-3">
        <i class="ti ti-cash text-warning" style="font-size:3rem"></i>
        <div class="fs-5 fw-bold">ชำระเงินที่ร้านตอนมารับอาหาร</div>
        <div class="text-muted">แจ้งเลขคิว <?= htmlspecialchars($queue) ?> ที่เคาน์เตอร์ ครัวจะเริ่มทำเมื่อชำระแล้ว<br>(ร้านยังไม่เปิดสแกนจ่ายออนไลน์)</div>
    </div>
    <a href="status.php?order_id=<?= $orderId ?>" class="btn-touch btn-brand w-100">ดูสถานะออเดอร์</a>
    <?php else: ?>

    <div class="cust-card p-4 text-center mb-3">
        <div class="fw-bold mb-2"><span class="badge rounded-pill text-bg-dark me-1">1</span> สแกนจ่ายด้วยแอปธนาคาร</div>
        <?php if ($qrPayload): ?>
            <div id="qr-pay" class="d-inline-block p-2 bg-white border rounded-3"></div>
            <div class="small text-muted mt-2">ยอด ฿<?= number_format($due, 2) ?> ถูกใส่ไว้ใน QR แล้ว</div>
        <?php else: ?>
            <div class="text-muted small border rounded-3 p-4">ร้านยังไม่ได้ตั้งค่าเบอร์พร้อมเพย์<br>(ตั้งได้ที่หลังร้าน &gt; ตั้งค่าร้าน)</div>
        <?php endif; ?>
    </div>

    <form method="post" enctype="multipart/form-data" class="cust-card p-4">
        <input type="hidden" name="order_id" value="<?= $orderId ?>">
        <div class="fw-bold mb-2"><span class="badge rounded-pill text-bg-dark me-1">2</span> แนบรูปสลิปโอนเงิน</div>
        <input type="file" name="slip" accept="image/jpeg,image/png,image/webp" class="form-control form-control-lg mb-3" required>

        <?php if ($isMock): ?>
        <div class="alert alert-light border small">
            <i class="ti ti-flask"></i> <strong>โหมดสาธิต</strong> — กรอกยอดและเวลาบนสลิปเพื่อทดสอบการตรวจสอบ
            (กลไกกันสลิปซ้ำยังทำงานจริงจากลายนิ้วมือของไฟล์รูป)
        </div>
        <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label small">ยอดบนสลิป (บาท)</label>
                <input type="number" step="0.01" min="0" name="amount" class="form-control" value="<?= number_format($due, 2, '.', '') ?>"></div>
            <div class="col-6"><label class="form-label small">เวลาบนสลิป</label>
                <input type="datetime-local" name="slip_datetime" class="form-control" value="<?= date('Y-m-d\TH:i') ?>"></div>
        </div>
        <?php endif; ?>

        <button class="btn-touch btn-brand w-100"><i class="ti ti-upload"></i> ส่งสลิปและตรวจสอบ</button>
        <a href="status.php?order_id=<?= $orderId ?>" class="btn btn-link w-100 mt-1">ดูสถานะออเดอร์</a>
    </form>
    <?php endif; ?>

    <?php if ($slips): ?>
    <div class="cust-card mt-3">
        <div class="p-3 fw-bold border-bottom">ประวัติการส่งสลิป</div>
        <?php foreach ($slips as $s): $b = $badge[$s['status']] ?? ['text-bg-light border', $s['status']]; ?>
        <div class="p-3 border-bottom">
            <div class="d-flex justify-content-between"><span class="badge <?= $b[0] ?>"><?= $b[1] ?></span><span class="small text-muted"><?= date('d/m H:i', strtotime($s['created_at'])) ?></span></div>
            <div class="small mt-1">฿<?= number_format((float) $s['amount'], 2) ?><?php if ($s['trans_ref']): ?> · <code><?= htmlspecialchars($s['trans_ref']) ?></code><?php endif; ?></div>
            <?php if ($s['reject_reason']): ?><div class="small text-danger"><?= htmlspecialchars($s['reject_reason']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($gateway && ($gateway['status'] ?? '') === 'pending' && $due > 0 && $order['status'] === 'pending'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
<?php if (!empty($gateway['qr_raw'])): ?>if (window.QRCode) new QRCode(document.getElementById('qr-pay'), { text: <?= json_encode($gateway['qr_raw']) ?>, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });<?php endif; ?>
// ถามสถานะทุก 4 วินาที จ่ายแล้วโหลดหน้าใหม่
(function poll(){ setTimeout(function(){ fetch('../kiosk/api.php?action=status&o=<?= $orderId ?>&t=<?= htmlspecialchars((string) $order['access_token']) ?>',{cache:'no-store'})
  .then(function(r){return r.json();}).then(function(d){ if(d.ok && d.is_paid){ location.reload(); return; }
    if(d.gateway && ['failed','expired','review'].indexOf(d.gateway.status)!==-1){ document.getElementById('gw-status').textContent='ชำระไม่สำเร็จหรือ QR หมดอายุ กรุณาติดต่อร้าน'; return; } poll(); })
  .catch(poll); }, 4000); })();
</script>
<?php endif; ?>
<?php if ($qrPayload && $due > 0 && $order['status'] === 'pending'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
if (window.QRCode) new QRCode(document.getElementById('qr-pay'), { text: <?= json_encode($qrPayload) ?>, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });
</script>
<?php endif; ?>
<?php onlineFoot(); ?>
