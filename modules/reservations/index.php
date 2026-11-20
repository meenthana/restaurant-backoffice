<?php
require_once __DIR__ . '/../../config/features.php';
requireFeature(FEATURE_RESERVATIONS);
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Reservation.php';
require_once __DIR__ . '/../../classes/RestaurantTable.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireLogin();
$date = $_GET['date'] ?? date('Y-m-d');
$reservations = Reservation::upcoming($date);
$tables = getDB()->query("SELECT * FROM restaurant_tables WHERE is_active=1 ORDER BY zone_name, table_code")->fetchAll();
$labels = ['pending'=>'รอยืนยัน','confirmed'=>'ยืนยันแล้ว','checked_in'=>'มาถึงแล้ว','seated'=>'จัดโต๊ะแล้ว','completed'=>'เสร็จสิ้น','cancelled'=>'ยกเลิก','no_show'=>'ไม่มาตามนัด'];
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-hero d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
 <div><span class="eyebrow">การจอง</span><h1 class="h2 mb-1">ตารางจองโต๊ะ</h1><p class="text-muted mb-0">ลูกค้าต้องเช็กอินก่อนจัดโต๊ะ และร้านเป็นผู้เลือกโต๊ะให้</p></div>
 <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#newReservation"><i class="ti ti-calendar-plus"></i> เพิ่มการจอง</button>
</div>
<?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?=htmlspecialchars($_GET['error'])?></div><?php endif; ?>
<form class="d-flex gap-2 mb-3"><input type="date" name="date" value="<?=htmlspecialchars($date)?>" class="form-control" style="max-width:190px"><button class="btn btn-outline-dark">ดูรายการ</button></form>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
<thead><tr><th>เวลา</th><th>รหัส</th><th>ลูกค้า</th><th>จำนวน</th><th>โต๊ะที่จัดไว้</th><th>มัดจำ</th><th>สถานะ</th><th class="text-end">ดำเนินการ</th></tr></thead><tbody>
<?php if(!$reservations):?><tr><td colspan="8" class="text-center text-muted py-5">ไม่มีรายการจองในวันที่เลือก</td></tr><?php endif;?>
<?php foreach($reservations as $r):?>
<tr>
 <td class="fw-semibold"><?=date('H:i',strtotime($r['reservation_at']))?> น.</td><td><?=htmlspecialchars($r['reservation_code'])?></td>
 <td><div><?=htmlspecialchars($r['guest_name'])?></div><div class="small text-muted"><?=htmlspecialchars($r['phone'])?></div></td>
 <td><?=(int)$r['guest_count']?> คน</td><td><?=htmlspecialchars($r['table_codes'] ?: 'จัดเมื่อมาถึง')?></td><td>฿<?=number_format($r['deposit_amount'],2)?></td>
 <td><span class="badge text-bg-light border"><?=$labels[$r['status']] ?? $r['status']?></span></td>
 <td class="text-end">
  <?php if(in_array($r['status'],['pending','confirmed'],true)):?><form method="post" action="action.php" class="d-inline"><?=Security::csrfInput()?><input type="hidden" name="id" value="<?=$r['reservation_id']?>"><button name="action" value="checkin" class="btn btn-outline-primary btn-sm">เช็กอิน</button></form><?php endif;?>
  <?php if($r['status']==='checked_in'):?><button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#seatR<?=$r['reservation_id']?>">จัดโต๊ะ</button><?php endif;?>
  <?php if(in_array($r['status'],['pending','confirmed','checked_in'],true)):?><form method="post" action="action.php" class="d-inline" onsubmit="return confirm('ยืนยันยกเลิกการจองนี้?')"><?=Security::csrfInput()?><input type="hidden" name="id" value="<?=$r['reservation_id']?>"><button name="action" value="cancel" class="btn btn-outline-danger btn-sm">ยกเลิก</button></form><?php endif;?>
 </td>
</tr>
<div class="modal fade" id="seatR<?=$r['reservation_id']?>" tabindex="-1"><div class="modal-dialog"><form method="post" action="action.php" class="modal-content"><div class="modal-header"><h5 class="modal-title">จัดโต๊ะ — <?=htmlspecialchars($r['guest_name'])?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
<?=Security::csrfInput()?><input type="hidden" name="id" value="<?=$r['reservation_id']?>"><input type="hidden" name="action" value="seat"><label class="form-label">เลือกโต๊ะ (เลือกหลายโต๊ะได้)</label><select name="table_ids[]" class="form-select" multiple required size="6"><?php foreach($tables as $t):?><option value="<?=$t['table_id']?>" <?=$t['status']!=='available'?'disabled':''?>><?=htmlspecialchars($t['table_code'])?> — <?=$t['capacity']?> ที่นั่ง (<?=$t['status']==='available'?'ว่าง':'ไม่ว่าง'?>)</option><?php endforeach;?></select>
</div><div class="modal-footer"><button class="btn btn-dark">เปิดโต๊ะและรับออเดอร์</button></div></form></div></div>
<?php endforeach;?>
</tbody></table></div></div>

<div class="modal fade" id="newReservation" tabindex="-1"><div class="modal-dialog modal-lg"><form method="post" action="action.php" class="modal-content"><div class="modal-header"><h5 class="modal-title">เพิ่มการจองโต๊ะ</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
<?=Security::csrfInput()?><input type="hidden" name="action" value="create"><div class="row g-3">
<div class="col-md-6"><label class="form-label">ชื่อลูกค้า</label><input name="guest_name" class="form-control" required></div><div class="col-md-6"><label class="form-label">เบอร์โทร</label><input name="phone" class="form-control" required></div>
<div class="col-md-4"><label class="form-label">วันและเวลา</label><input type="datetime-local" name="reservation_at" min="<?=date('Y-m-d\TH:i')?>" class="form-control" required></div><div class="col-md-4"><label class="form-label">จำนวนคน</label><input type="number" name="guest_count" min="1" value="2" class="form-control" required></div><div class="col-md-4"><label class="form-label">มัดจำ (ถ้ามี)</label><input type="number" step="0.01" min="0" name="deposit_amount" value="0" class="form-control"></div>
<div class="col-12"><label class="form-label">ระบุโต๊ะไว้ล่วงหน้า (ไม่บังคับ)</label><select name="table_ids[]" class="form-select" multiple size="5"><?php foreach($tables as $t):?><option value="<?=$t['table_id']?>"><?=htmlspecialchars($t['table_code'])?> — <?=$t['capacity']?> ที่นั่ง / <?=htmlspecialchars($t['zone_name'])?></option><?php endforeach;?></select></div>
<div class="col-12"><label class="form-label">หมายเหตุ</label><input name="note" class="form-control"></div>
</div></div><div class="modal-footer"><button class="btn btn-dark">บันทึกการจอง</button></div></form></div></div>
<?php require_once __DIR__ . '/../../includes/footer.php';?>

