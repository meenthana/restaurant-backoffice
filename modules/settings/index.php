<?php
// ตั้งค่าร้าน (พร้อมเพย์ เวลารับ ตรวจสลิป ฯลฯ)
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/AppSetting.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/AuditLog.php';
Auth::requireAnyRole(['owner','manager']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 Security::verifyCsrf();
 $allowed=['store_name','store_phone','store_address','pickup_lead_minutes','pickup_window_minutes','store_close_time','reservation_grace_minutes','default_dining_minutes','online_payment_mode','baht_per_point','point_redeem_cap_percent','promptpay_id','kiosk_idle_seconds','kiosk_unpaid_expire_minutes','slip_verify_mode','slip_amount_tolerance','voice_order_enabled'];
 $data=[];foreach($allowed as $key){$data[$key]=$_POST[$key]??'';}
 AppSetting::setMany($data,Auth::id());AuditLog::write(Auth::id(),'update','settings',null,'แก้ไขการตั้งค่าร้าน');
 header('Location:index.php?saved=1');exit;
}
$s=AppSetting::all();
require_once __DIR__ . '/../../includes/header.php';
?>
<?php pageHeader('ตั้งค่าร้าน'); ?>
<a href="queue_cards.php" class="card text-decoration-none text-dark mb-3"><div class="card-body d-flex align-items-center gap-3"><i class="ti ti-id fs-2" aria-hidden="true"></i><div><strong>บัตรคิว</strong><div class="text-muted small">บัตรที่ลูกค้าหยิบแล้วกรอกรหัสที่ตู้ · ดูบัตรว่าง/ใช้งาน · พิมพ์บัตร</div></div><span class="ms-auto" aria-hidden="true">→</span></div></a>
<a href="printer.php?station=cashier" class="card text-decoration-none text-dark mb-4"><div class="card-body d-flex align-items-center gap-3"><i class="ti ti-printer fs-2" aria-hidden="true"></i><div><strong>เครื่องพิมพ์ใบเสร็จ (Cashier)</strong><div class="text-muted small">เชื่อม USB / Bluetooth เลือกขนาดกระดาษ และพิมพ์ทดสอบ</div></div><span class="ms-auto" aria-hidden="true">→</span></div></a>
<?php if(isset($_GET['saved'])):?><div class="alert alert-success">บันทึกการตั้งค่าแล้ว</div><?php endif;?>
<form method="post" class="card border-0 shadow-sm"><div class="card-body p-4"><?=Security::csrfInput()?>
<h2 class="h5 mb-3">ข้อมูลร้าน</h2><div class="row g-3"><div class="col-md-6"><label class="form-label">ชื่อร้าน</label><input name="store_name" value="<?=htmlspecialchars($s['store_name']??'')?>" class="form-control"></div><div class="col-md-6"><label class="form-label">เบอร์โทร</label><input name="store_phone" value="<?=htmlspecialchars($s['store_phone']??'')?>" class="form-control"></div><div class="col-12"><label class="form-label">ที่อยู่</label><textarea name="store_address" class="form-control"><?=htmlspecialchars($s['store_address']??'')?></textarea></div></div>
<hr class="my-4"><h2 class="h5 mb-3">เวลาและการให้บริการ</h2><div class="row g-3">
<div class="col-md-3"><label class="form-label">สั่งล่วงหน้าอย่างน้อย (นาที)</label><input type="number" min="0" name="pickup_lead_minutes" value="<?=htmlspecialchars($s['pickup_lead_minutes']??'30')?>" class="form-control"></div>
<div class="col-md-3"><label class="form-label">ถืออาหารหลังพร้อม (นาที)</label><input type="number" min="5" name="pickup_window_minutes" value="<?=htmlspecialchars($s['pickup_window_minutes']??'30')?>" class="form-control"></div>
<div class="col-md-3"><label class="form-label">เวลาร้านปิด</label><input type="time" name="store_close_time" value="<?=htmlspecialchars($s['store_close_time']??'20:00')?>" class="form-control"><div class="form-text">สั่งออนไลน์ได้เฉพาะวันนี้ ก่อนเวลานี้</div></div>
<input type="hidden" name="reservation_grace_minutes" value="<?=htmlspecialchars($s['reservation_grace_minutes']??'15')?>">
<input type="hidden" name="default_dining_minutes" value="<?=htmlspecialchars($s['default_dining_minutes']??'120')?>">
<div class="col-md-6"><label class="form-label">การชำระออนไลน์</label><select name="online_payment_mode" class="form-select"><option value="mock" <?=($s['online_payment_mode']??'mock')==='mock'?'selected':''?>>เปิดรับออเดอร์</option><option value="disabled" <?=($s['online_payment_mode']??'')==='disabled'?'selected':''?>>ปิดรับออเดอร์ออนไลน์</option></select></div>
<div class="col-md-6"><label class="form-label">ยอดซื้อที่ได้รับ 1 แต้ม (บาท)</label><input type="number" min="1" name="baht_per_point" value="<?=htmlspecialchars($s['baht_per_point']??'25')?>" class="form-control"><div class="form-text">ตัวอย่าง 25 หมายถึง ทุกยอดซื้อ 25 บาท ได้รับ 1 แต้ม</div></div>
<div class="col-md-6"><label class="form-label">ใช้แต้มลดได้สูงสุด (% ของยอดบิล)</label><input type="number" min="1" max="100" name="point_redeem_cap_percent" value="<?=htmlspecialchars($s['point_redeem_cap_percent']??'50')?>" class="form-control"><div class="form-text">1 แต้ม = ส่วนลด 1 บาท ใช้ได้ทั้งตู้สั่งอาหารและสั่งออนไลน์ · แต้มคืนอัตโนมัติเมื่อออเดอร์ถูกยกเลิก</div></div>
</div>
<hr class="my-4"><h2 class="h5 mb-3"><i class="ti ti-device-tablet"></i> ตู้สั่งอาหารด้วยตนเองและการชำระเงิน</h2><div class="row g-3">
<div class="col-md-6"><label class="form-label">เบอร์พร้อมเพย์ของร้าน</label><input name="promptpay_id" inputmode="numeric" placeholder="0812345678 หรือเลขบัตร 13 หลัก" value="<?=htmlspecialchars($s['promptpay_id']??'')?>" class="form-control"><div class="form-text">ใช้สร้าง QR ที่ฝังยอดเงินบนตู้ ถ้าเว้นว่าง ตู้จะรับเฉพาะเงินสด</div></div>
<div class="col-md-3"><label class="form-label">ไม่มีคนแตะจอ (วินาที)</label><input type="number" min="30" name="kiosk_idle_seconds" value="<?=htmlspecialchars($s['kiosk_idle_seconds']??'90')?>" class="form-control"><div class="form-text">แล้วล้างตะกร้ากลับหน้าแรก</div></div>
<div class="col-md-3"><label class="form-label">ยกเลิกออเดอร์ค้างจ่าย (นาที)</label><input type="number" min="5" name="kiosk_unpaid_expire_minutes" value="<?=htmlspecialchars($s['kiosk_unpaid_expire_minutes']??'30')?>" class="form-control"><div class="form-text">ออเดอร์เงินสดที่ไม่มาจ่าย</div></div>
<div class="col-md-4"><label class="form-label">การตรวจสลิป</label><select name="slip_verify_mode" class="form-select"><option value="mock" <?=($s['slip_verify_mode']??'mock')==='mock'?'selected':''?>>โหมดสาธิต</option><option value="api" <?=($s['slip_verify_mode']??'')==='api'?'selected':''?>>ตรวจกับธนาคาร</option></select></div>
<div class="col-md-4"><label class="form-label">ยอมให้ยอดโอนคลาดเคลื่อน (บาท)</label><input type="number" step="0.01" min="0" name="slip_amount_tolerance" value="<?=htmlspecialchars($s['slip_amount_tolerance']??'1.00')?>" class="form-control"></div>
<div class="col-md-4"><label class="form-label">สั่งด้วยเสียง</label><select name="voice_order_enabled" class="form-select"><option value="1" <?=($s['voice_order_enabled']??'1')==='1'?'selected':''?>>เปิด</option><option value="0" <?=($s['voice_order_enabled']??'1')==='0'?'selected':''?>>ปิด</option></select></div>
</div></div><div class="card-footer bg-white text-end"><button class="btn btn-dark"><i class="ti ti-device-floppy"></i> บันทึกการตั้งค่า</button></div></form>
<?php require_once __DIR__ . '/../../includes/footer.php';?>
