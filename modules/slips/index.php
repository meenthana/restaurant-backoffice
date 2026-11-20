<?php
// สลิปที่รอพนักงานตรวจ
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/SlipVerifier.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireLogin();

$pending = SlipVerifier::pending();
$recent  = SlipVerifier::recent(30);
$mode    = SlipVerifier::mode();

$badge = [
    'verified'  => ['text-bg-success', 'ตรวจผ่านอัตโนมัติ'],
    'manual'    => ['text-bg-success', 'พนักงานยืนยัน'],
    'pending'   => ['text-bg-warning', 'รอตรวจสอบ'],
    'rejected'  => ['text-bg-danger',  'ไม่ผ่าน'],
    'duplicate' => ['text-bg-dark',    'สลิปซ้ำ'],
];

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('ตรวจสลิป', 'สลิปที่ต้องให้พนักงานตรวจ',
    '<span class="badge ' . ($mode === 'api' ? 'text-bg-success' : 'text-bg-light border') . '">' . ($mode === 'api' ? 'ตรวจกับธนาคาร' : 'โหมดสาธิต') . '</span>'); ?>

<?php if (!empty($_SESSION['flash'])): ?>
<div class="alert alert-<?= htmlspecialchars($_SESSION['flash']['type']) ?>">
    <?= htmlspecialchars($_SESSION['flash']['msg']) ?>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<h2 class="h5 mb-3">รอพนักงานตัดสินใจ (<?= count($pending) ?>)</h2>

<?php if (!$pending): ?>
    <div class="card mb-4"><div class="card-body text-muted text-center py-4">
        <i class="ti ti-circle-check fs-3 d-block mb-2"></i>ไม่มีสลิปค้างตรวจสอบ
    </div></div>
<?php else: ?>
<div class="row g-3 mb-4">
    <?php foreach ($pending as $s): $b = $badge[$s['status']] ?? ['text-bg-light border', $s['status']]; ?>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <?php if ($s['file_path']): ?>
            <a href="/restaurant-backoffice/<?= htmlspecialchars($s['file_path']) ?>" target="_blank">
                <img src="/restaurant-backoffice/<?= htmlspecialchars($s['file_path']) ?>"
                     class="card-img-top" style="max-height:220px;object-fit:contain;background:#f8f9fa;" alt="สลิป">
            </a>
            <?php endif; ?>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="fw-bold">ออเดอร์ #<?= $s['order_id'] ?></div>
                        <div class="small text-muted"><?= date('d/m/Y H:i', strtotime($s['created_at'])) ?></div>
                    </div>
                    <span class="badge <?= $b[0] ?>"><?= $b[1] ?></span>
                </div>

                <table class="table table-sm mb-2">
                    <tr><td class="text-muted">ยอดบนสลิป</td><td class="text-end fw-bold">฿<?= number_format((float) $s['amount'], 2) ?></td></tr>
                    <tr><td class="text-muted">ยอดที่ต้องชำระ</td><td class="text-end">฿<?= number_format((float) $s['expected_amount'], 2) ?></td></tr>
                    <?php if ($s['sender_name']): ?><tr><td class="text-muted">ผู้โอน</td><td class="text-end"><?= htmlspecialchars($s['sender_name']) ?></td></tr><?php endif; ?>
                    <?php if ($s['slip_datetime']): ?><tr><td class="text-muted">เวลาบนสลิป</td><td class="text-end"><?= date('d/m/Y H:i', strtotime($s['slip_datetime'])) ?></td></tr><?php endif; ?>
                    <?php if ($s['trans_ref']): ?><tr><td class="text-muted">เลขอ้างอิง</td><td class="text-end mono small"><?= htmlspecialchars($s['trans_ref']) ?></td></tr><?php endif; ?>
                </table>

                <?php if ($s['reject_reason']): ?>
                <div class="alert alert-warning py-2 small mb-2">
                    <i class="ti ti-alert-triangle"></i> <?= htmlspecialchars($s['reject_reason']) ?>
                </div>
                <?php endif; ?>

                <form method="post" action="action.php" class="d-flex gap-2">
                    <input type="hidden" name="slip_id" value="<?= $s['slip_id'] ?>">
                    <?= Security::csrfInput() ?>
                    <input type="number" step="0.01" min="0" name="amount" class="form-control form-control-sm"
                           value="<?= number_format((float) ($s['amount'] ?: $s['expected_amount']), 2, '.', '') ?>"
                           aria-label="ยอดที่ได้รับจริง">
                    <button name="do" value="approve" class="btn btn-sm btn-success text-nowrap"
                            onclick="return confirm('ยืนยันว่าได้รับเงินจริงตามยอดนี้?')">
                        <i class="ti ti-check"></i> รับเงิน
                    </button>
                    <button name="do" value="reject" class="btn btn-sm btn-outline-danger text-nowrap">
                        <i class="ti ti-x"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h2 class="h5 mb-3">ประวัติล่าสุด</h2>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>เวลา</th><th>ออเดอร์</th><th>ยอด</th><th>เลขอ้างอิง</th><th>ที่มา</th><th>สถานะ</th></tr>
            </thead>
            <tbody>
            <?php if (!$recent): ?>
                <tr><td colspan="6" class="text-muted text-center py-4">ยังไม่มีสลิปเข้ามา</td></tr>
            <?php endif; ?>
            <?php foreach ($recent as $s): $b = $badge[$s['status']] ?? ['text-bg-light border', $s['status']]; ?>
                <tr>
                    <td class="small"><?= date('d/m H:i', strtotime($s['created_at'])) ?></td>
                    <td class="mono">#<?= $s['order_id'] ?></td>
                    <td>฿<?= number_format((float) $s['amount'], 2) ?></td>
                    <td class="mono small"><?= htmlspecialchars($s['trans_ref'] ?: '—') ?></td>
                    <td class="small text-muted"><?= ['api'=>'ธนาคาร','mock'=>'สาธิต','manual'=>'พนักงาน'][$s['verify_source']] ?? $s['verify_source'] ?></td>
                    <td><span class="badge <?= $b[0] ?>"><?= $b[1] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
