<?php
require_once __DIR__ . '/../../config/features.php';
requireFeature(FEATURE_QUEUE);
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/QueueEntry.php';
require_once __DIR__ . '/../../classes/RestaurantTable.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireLogin();
$queues = QueueEntry::today();
$tables = RestaurantTable::available();
$labels = ['waiting'=>'รอคิว','called'=>'เรียกแล้ว','seated'=>'เข้าที่นั่งแล้ว','cancelled'=>'ยกเลิก','no_show'=>'ไม่อยู่เมื่อเรียก'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-hero d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
  <div><span class="eyebrow">หน้าร้าน</span><h1 class="h2 mb-1">คิวรอโต๊ะวันนี้</h1><p class="text-muted mb-0">ออกหมายเลข เรียกคิว และจัดโต๊ะโดยพนักงาน</p></div>
  <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#newQueue"><i class="ti ti-plus"></i> เพิ่มคิว</button>
</div>
<?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
<thead><tr><th>คิว</th><th>ลูกค้า</th><th>จำนวน</th><th>รอแล้ว</th><th>สถานะ</th><th class="text-end">ดำเนินการ</th></tr></thead><tbody>
<?php if (!$queues): ?><tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีคิววันนี้</td></tr><?php endif; ?>
<?php foreach ($queues as $q): ?>
<tr>
  <td><span class="queue-number"><?= htmlspecialchars($q['queue_number']) ?></span></td>
  <td><div class="fw-semibold"><?= htmlspecialchars($q['guest_name']) ?></div><div class="small text-muted"><?= htmlspecialchars($q['phone'] ?: '-') ?></div></td>
  <td><?= (int)$q['guest_count'] ?> คน</td><td><?= (int)$q['wait_minutes'] ?> นาที</td>
  <td><span class="badge text-bg-light border"><?= $labels[$q['status']] ?? $q['status'] ?></span></td>
  <td class="text-end">
    <?php if ($q['status']==='waiting'): ?>
      <form method="post" action="action.php" class="d-inline"><?= Security::csrfInput() ?><input type="hidden" name="id" value="<?=$q['queue_entry_id']?>"><button name="action" value="call" class="btn btn-outline-primary btn-sm">เรียกคิว</button></form>
    <?php endif; ?>
    <?php if (in_array($q['status'], ['waiting','called'], true)): ?>
      <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#seat<?=$q['queue_entry_id']?>">จัดโต๊ะ</button>
      <form method="post" action="action.php" class="d-inline"><?= Security::csrfInput() ?><input type="hidden" name="id" value="<?=$q['queue_entry_id']?>"><button name="action" value="no_show" class="btn btn-outline-danger btn-sm">ไม่อยู่เมื่อเรียก</button></form>
    <?php endif; ?>
  </td>
</tr>
<div class="modal fade" id="seat<?=$q['queue_entry_id']?>" tabindex="-1"><div class="modal-dialog"><form method="post" action="action.php" class="modal-content">
  <div class="modal-header"><h5 class="modal-title">จัดโต๊ะให้คิว <?=htmlspecialchars($q['queue_number'])?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><?= Security::csrfInput() ?><input type="hidden" name="id" value="<?=$q['queue_entry_id']?>"><input type="hidden" name="action" value="seat">
    <p>ลูกค้า <?=htmlspecialchars($q['guest_name'])?> จำนวน <?=(int)$q['guest_count']?> คน</p>
    <label class="form-label">เลือกโต๊ะ (เลือกหลายโต๊ะได้)</label>
    <select name="table_ids[]" class="form-select" multiple size="<?=min(8,max(3,count($tables)))?>" required><?php foreach($tables as $t): ?><option value="<?=$t['table_id']?>"><?=htmlspecialchars($t['table_code'])?> — <?=$t['capacity']?> ที่นั่ง / <?=htmlspecialchars($t['zone_name'])?></option><?php endforeach; ?></select>
  </div><div class="modal-footer"><button class="btn btn-dark">เปิดโต๊ะและรับออเดอร์</button></div>
</form></div></div>
<?php endforeach; ?>
</tbody></table></div></div>

<div class="modal fade" id="newQueue" tabindex="-1"><div class="modal-dialog"><form method="post" action="action.php" class="modal-content">
<div class="modal-header"><h5 class="modal-title">เพิ่มคิวรอโต๊ะ</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><?= Security::csrfInput() ?><input type="hidden" name="action" value="create">
<div class="mb-3"><label class="form-label">ชื่อลูกค้า</label><input name="guest_name" class="form-control" required></div>
<div class="row g-3"><div class="col-6"><label class="form-label">จำนวนคน</label><input type="number" name="guest_count" min="1" value="2" class="form-control" required></div><div class="col-6"><label class="form-label">เบอร์โทร</label><input name="phone" class="form-control"></div></div>
<div class="mt-3"><label class="form-label">หมายเหตุ</label><input name="note" class="form-control"></div>
</div><div class="modal-footer"><button class="btn btn-dark">ออกหมายเลขคิว</button></div>
</form></div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

