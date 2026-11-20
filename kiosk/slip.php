<?php
// รับไฟล์สลิปที่ลูกค้าอัปโหลดจากตู้
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/KioskOrder.php';
require_once __DIR__ . '/../classes/OrderItem.php';
require_once __DIR__ . '/../classes/SlipVerifier.php';
require_once __DIR__ . '/../classes/AppSetting.php';

$orderId = (int) ($_GET['o'] ?? 0);
$token   = (string) ($_GET['t'] ?? '');
$order   = KioskOrder::findByToken($orderId, $token);
if (!$order) {
    http_response_code(404);
    die('ลิงก์ไม่ถูกต้องหรือหมดอายุแล้ว');
}

$shopName = AppSetting::get('store_name', 'ครัวธนุรเวท');
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['slip'])
    && $order['status'] === 'pending' && (int) $order['is_paid'] === 0) {
    try {
        $result = SlipVerifier::submit($orderId, $_FILES['slip'], [
            'amount'        => $_POST['amount'] ?? '',
            'slip_datetime' => $_POST['slip_datetime'] ?? '',
        ]);
    } catch (Throwable $e) {
        $result = ['status' => 'rejected', 'message' => $e->getMessage()];
    }
    $order = Order::find($orderId);
}

$total  = Payment::orderTotal($orderId);
$due    = round(max(0, $total - Payment::paidAmount($orderId)), 2);
$paid   = (int) $order['is_paid'] === 1;
$isMock = SlipVerifier::mode() === 'mock';
$queue  = KioskOrder::queueLabel((int) $order['queue_no'], $order['channel'], (int)$order['order_id']);
$items  = OrderItem::getByOrder($orderId);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ส่งสลิป คิว <?= htmlspecialchars($queue) ?> — <?= htmlspecialchars($shopName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.44.0/dist/tabler-icons.min.css" rel="stylesheet">
    <link href="../assets/css/customer.css" rel="stylesheet">
</head>
<body class="cust-body">
<div class="cust-topbar"><div class="cust-brand"><i class="ti ti-chef-hat"></i> <?= htmlspecialchars($shopName) ?></div></div>

<div class="container py-4" style="max-width:520px">
    <div class="cust-hero text-center mb-3">
        <div class="eyebrow-c">เลขคิวของคุณ</div>
        <div class="display-3 fw-bold" style="color:var(--c-brand)"><?= htmlspecialchars($queue) ?></div>
        <div class="text-muted">ยอดที่ต้องชำระ <strong class="text-dark">฿<?= number_format($due, 2) ?></strong></div>
    </div>

    <?php if ($result): ?>
        <?php $cls = ['verified' => 'success', 'pending' => 'warning', 'duplicate' => 'dark', 'rejected' => 'danger'][$result['status']] ?? 'secondary'; ?>
        <div class="alert alert-<?= $cls ?>"><strong><?= htmlspecialchars($result['message']) ?></strong></div>
    <?php endif; ?>

    <?php if ($paid): ?>
        <div class="cust-card p-4 text-center">
            <i class="ti ti-circle-check text-success" style="font-size:4rem"></i>
            <div class="fs-4 fw-bold mt-2">ชำระเงินเรียบร้อย</div>
            <div class="text-muted">ครัวกำลังเตรียมอาหาร กรุณารอเรียกคิว <?= htmlspecialchars($queue) ?> ที่เคาน์เตอร์</div>
        </div>
    <?php elseif ($order['status'] !== 'pending'): ?>
        <div class="alert alert-secondary">ออเดอร์นี้ไม่ได้รอชำระเงินแล้ว</div>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="cust-card p-4">
            <label class="form-label fw-bold fs-5"><i class="ti ti-photo"></i> แนบรูปสลิปโอนเงิน</label>
            <input type="file" name="slip" accept="image/jpeg,image/png,image/webp" class="form-control form-control-lg mb-3" required>

            <?php if ($isMock): ?>
            <div class="alert alert-light border small">
                <i class="ti ti-flask"></i> <strong>โหมดสาธิต</strong> — กรอกยอดและเวลาบนสลิปเพื่อทดสอบการตรวจสอบ
                (ระบบกันสลิปซ้ำยังทำงานจริงจากลายนิ้วมือของไฟล์รูป)
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small">ยอดบนสลิป</label>
                    <input type="number" step="0.01" min="0" name="amount" class="form-control" value="<?= number_format($due, 2, '.', '') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small">เวลาบนสลิป</label>
                    <input type="datetime-local" name="slip_datetime" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                </div>
            </div>
            <?php endif; ?>

            <button class="btn-touch btn-brand w-100"><i class="ti ti-upload"></i> ส่งสลิป</button>
        </form>
    <?php endif; ?>

    <details class="cust-card p-3 mt-3">
        <summary class="fw-bold">รายการที่สั่ง (<?= count($items) ?>)</summary>
        <?php foreach ($items as $i): ?>
            <div class="d-flex justify-content-between border-top py-2 mt-2">
                <span>×<?= (int) $i['quantity'] ?> <?= htmlspecialchars($i['menu_name']) ?></span>
                <span>฿<?= number_format((float) $i['subtotal'], 2) ?></span>
            </div>
        <?php endforeach; ?>
        <div class="d-flex justify-content-between fw-bold border-top pt-2"><span>รวม</span><span>฿<?= number_format($total, 2) ?></span></div>
    </details>
</div>
</body>
</html>
