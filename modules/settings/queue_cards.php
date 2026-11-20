<?php
// จัดการบัตรคิว: เพิ่มบัตร ปิดบัตรที่หาย เปลี่ยนรหัส ปลดบัตรที่ค้าง พิมพ์บัตร
require_once __DIR__.'/../../classes/Auth.php';
require_once __DIR__.'/../../classes/Security.php';
require_once __DIR__.'/../../classes/QueueCard.php';
require_once __DIR__.'/../../classes/KioskOrder.php';
require_once __DIR__.'/../../classes/AuditLog.php';
Auth::requireAnyRole(['owner','manager']);

$error = '';
if (!QueueCard::tableReady()) $error = 'ยังไม่มีตารางบัตรคิว กรุณารัน sql/migration_v2_12_queue_cards.sql ก่อน';
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::verifyCsrf();
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['card_id'] ?? 0);
    try {
        if ($do === 'add') QueueCard::add((int) ($_POST['count'] ?? 1), (string) ($_POST['card_type'] ?? 'kiosk'));
        elseif ($do === 'on' || $do === 'off') QueueCard::setActive($id, $do === 'on');
        elseif ($do === 'code') QueueCard::regenerate($id);
        elseif ($do === 'release') QueueCard::release($id);
        elseif ($do === 'required') AppSetting::setMany(['queue_card_required' => !empty($_POST['required']) ? '1' : '0'], Auth::id());
        else throw new Exception('ไม่รู้จักคำสั่งนี้');
        AuditLog::write(Auth::id(), 'queue_card_' . $do, 'queue_card', $id ?: null, 'จัดการบัตรคิว');
        header('Location: queue_cards.php?ok=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$cards = QueueCard::tableReady() ? QueueCard::all() : [];
$required = AppSetting::get('queue_card_required', '1') === '1';
function qcEsc($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

require_once __DIR__.'/../../includes/header.php';
pageHeader('บัตรคิว', 'บัตรตู้: ลูกค้ากรอกรหัสที่ตู้ก่อนสั่ง · บัตรอาหารตามสั่ง: ยื่นให้พนักงานตอนสั่ง · ตอนรับอาหารแคชเชียร์กรอกรหัสบัตรเพื่อยืนยัน',
    '<a class="btn btn-outline-dark" href="index.php">กลับตั้งค่าร้าน</a> <a class="btn btn-dark" href="queue_cards_print.php" target="_blank"><i class="ti ti-printer"></i> พิมพ์บัตร</a>');
?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= qcEsc($error) ?></div><?php endif; ?>
<?php if (isset($_GET['ok'])): ?><div class="alert alert-success" role="status">บันทึกแล้ว</div><?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card h-100"><div class="card-body d-flex justify-content-between align-items-center gap-3">
      <div><div class="fw-bold">บังคับกรอกบัตรคิวก่อนสั่งที่ตู้</div>
      <p class="text-muted small mb-0">ถ้าปิด หรือไม่มีบัตรที่เปิดใช้ ตู้จะข้ามขั้นตอนนี้</p></div>
      <?= statusToggle('queue_cards.php', ['do' => 'required', 'required' => $required ? '' : '1'], $required, 'เปิด', 'ปิด') ?>
    </div></div>
  </div>
  <div class="col-md-6">
    <form method="post" class="card h-100"><div class="card-body d-flex align-items-end gap-2">
      <?= Security::csrfInput() ?><input type="hidden" name="do" value="add">
      <div><label class="form-label fw-bold" for="count">เพิ่มบัตรใหม่</label>
      <input id="count" name="count" type="number" min="1" max="50" value="5" class="form-control"></div>
      <?php if (QueueCard::typeReady()): ?>
      <div><label class="form-label fw-bold" for="card_type">ประเภท</label>
      <select id="card_type" name="card_type" class="form-select">
        <?php foreach (QueueCard::TYPES as $k => $label): ?><option value="<?= qcEsc($k) ?>"><?= qcEsc($label) ?></option><?php endforeach; ?>
      </select></div>
      <?php endif; ?>
      <button class="btn btn-dark"><i class="ti ti-plus"></i> เพิ่ม</button>
      <span class="text-muted small">ระบบสุ่มรหัสให้ แล้วกดพิมพ์บัตร</span>
    </div></form>
  </div>
</div>

<div class="card"><div class="table-responsive">
<table class="table align-middle mb-0">
  <thead class="table-light"><tr><th>บัตร</th><th>ประเภท</th><th>รหัสบนบัตร</th><th>เปิดใช้</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
  <tbody>
  <?php if (!$cards): ?><tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีบัตร</td></tr><?php endif; ?>
  <?php foreach ($cards as $c): ?>
    <tr class="<?= $c['is_active'] ? '' : 'table-secondary' ?>">
      <td class="fw-bold fs-5"><?= qcEsc(QueueCard::label((int) $c['card_no'])) ?></td>
      <td><span class="badge <?= ($c['card_type'] ?? 'kiosk') === 'counter' ? 'text-bg-warning' : 'text-bg-light border' ?>"><?= qcEsc(QueueCard::typeLabel($c['card_type'] ?? null)) ?></span></td>
      <td class="mono fs-5"><?= qcEsc($c['code']) ?></td>
      <td><?= statusToggle('queue_cards.php', ['do' => $c['is_active'] ? 'off' : 'on', 'card_id' => (int) $c['card_id']], (bool) $c['is_active'], 'เปิดใช้', 'ปิดใช้', $c['is_active'] ? 'ปิดใช้บัตรนี้? (ใช้เมื่อบัตรหายหรือชำรุด)' : '') ?></td>
      <td>
        <?php if (!$c['is_active']): ?><span class="badge text-bg-secondary">บัตรหาย/ชำรุด</span>
        <?php elseif ($c['order_id']): ?><span class="badge text-bg-warning">ใช้งานอยู่ · คิว <?= qcEsc(KioskOrder::queueLabel((int) $c['queue_no'], $c['channel'], (int) $c['order_id'])) ?></span>
        <?php else: ?><span class="badge text-bg-success">ว่าง</span><?php endif; ?>
      </td>
      <td class="text-end">
        <form method="post" class="d-inline"><?= Security::csrfInput() ?><input type="hidden" name="card_id" value="<?= (int) $c['card_id'] ?>">
          <?php if ($c['order_id']): ?><button name="do" value="release" class="btn btn-sm btn-outline-warning" onclick="return confirm('ปลดบัตรออกจากออเดอร์นี้? ใช้เมื่อได้บัตรคืนแล้วแต่ออเดอร์ยังค้าง')">ปลดบัตร</button><?php endif; ?>
          <button name="do" value="code" class="btn btn-sm btn-outline-dark" onclick="return confirm('เปลี่ยนรหัสใหม่? ต้องพิมพ์บัตรใบนี้ใหม่')">เปลี่ยนรหัส</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
