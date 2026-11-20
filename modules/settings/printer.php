<?php
require_once __DIR__.'/../../classes/Auth.php';
require_once __DIR__.'/../../classes/Security.php';
require_once __DIR__.'/../../classes/PrinterSettings.php';
Auth::requireAnyRole(['owner','manager']);
$selectedStation=is_string($_GET['station'] ?? null) ? $_GET['station'] : 'cashier';
if (!isset(PrinterSettings::STATIONS[$selectedStation])) { http_response_code(404); exit('ไม่พบจุดใช้งาน'); }
$selectedStations=[$selectedStation=>PrinterSettings::STATIONS[$selectedStation]];
$error=''; $submittedStation='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    Security::verifyCsrf();
    try {
        $submittedStation=is_string($_POST['station'] ?? null) ? $_POST['station'] : '';
        if ($submittedStation!==$selectedStation) throw new InvalidArgumentException('จุดใช้งานไม่ตรงกัน กรุณาเปิดหน้าใหม่');
        PrinterSettings::save($submittedStation,$_POST,Auth::id());
        header('Location: printer.php?station='.rawurlencode($submittedStation).'&saved='.rawurlencode($submittedStation)); exit;
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { error_log('Printer settings save failed'); $error='บันทึกไม่สำเร็จ กรุณาลองใหม่ ข้อมูลเดิมยังอยู่'; }
}
$profiles=[];
foreach($selectedStations as $key=>$label) {
    $profiles[$key]=PrinterSettings::get($key);
    if($error && $submittedStation===$key) foreach(array_keys($profiles[$key]) as $field) {
        if($field!=='updated_at' && is_string($_POST[$field] ?? null)) $profiles[$key][$field]=$_POST[$field];
    }
}
function printerEscape($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
require_once __DIR__.'/../../includes/header.php';
pageHeader('ตั้งค่าเครื่องพิมพ์ใบเสร็จ','เครื่องพิมพ์ที่เคาน์เตอร์แคชเชียร์','<a class="btn btn-outline-dark" href="index.php">กลับตั้งค่าร้าน</a>');
?>
<link rel="stylesheet" href="../../assets/css/printers.css?v=2">
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= printerEscape($error) ?></div><?php endif; ?>
<?php if(is_string($_GET['saved']??null) && isset(PrinterSettings::STATIONS[$_GET['saved']])): ?><div class="alert alert-success" role="status">บันทึกค่าของ <?= printerEscape(PrinterSettings::STATIONS[$_GET['saved']]) ?> แล้ว</div><?php endif; ?>
<div class="row g-4">
<?php foreach($selectedStations as $station=>$label): $p=$profiles[$station]; ?>
<section class="col-12 col-lg-10 col-xl-8 mx-auto">
<form method="post" class="card printer-card h-100 printer-<?= $station ?>">
<?= Security::csrfInput() ?><input type="hidden" name="station" value="<?= $station ?>">
<div class="card-body p-3 p-md-4">
<h2 class="h4"><i class="ti ti-cash-register" aria-hidden="true"></i> <?= printerEscape($label) ?></h2><p class="text-muted">ใบเสร็จที่เคาน์เตอร์ พิมพ์เมื่อลูกค้าเลือกรับกระดาษหรือร้องขอ</p>
<div class="printer-state mb-4" id="printer-status"><span class="badge text-bg-secondary" id="printer-badge">ยังไม่ได้เชื่อมต่อ</span><span class="small text-muted" id="printer-status-text"><?= $p['device_name']!=='' ? 'บันทึกไว้: '.printerEscape($p['device_name']) : 'ยังไม่ได้เลือกเครื่อง' ?></span></div>
<div class="mb-3"><label class="form-label" for="<?= $station ?>-name">ชื่อจุดใช้งาน</label><input class="form-control" id="<?= $station ?>-name" name="station_name" maxlength="60" required value="<?= printerEscape($p['station_name']) ?>"></div>
<fieldset class="mb-4"><legend class="fs-6 fw-semibold">1. ประเภทเครื่องพิมพ์</legend><div class="printer-options printer-options-4">
<?php foreach(['thermal'=>['ti-receipt','ใบเสร็จตั้งโต๊ะ','เครื่องพิมพ์ความร้อน 58/80 มม.'],'portable'=>['ti-device-mobile','ใบเสร็จพกพา','ขนาดเล็ก ใช้แบตเตอรี่ มักเป็น Bluetooth'],'office'=>['ti-printer','เครื่องพิมพ์สำนักงาน','อิงค์เจ็ท / เลเซอร์ กระดาษ A4'],'other'=>['ti-dots','อื่น ๆ','ประเภทอื่นที่รองรับคำสั่ง ESC/POS']] as $value=>[$icon,$title,$hint]): ?>
<label class="printer-option printer-conn printer-type"><input type="radio" name="model" value="<?= $value ?>" <?= $p['model']===$value?'checked':'' ?> required><i class="ti <?= $icon ?>" aria-hidden="true"></i><span><b><?= $title ?></b><small><?= $hint ?></small></span></label>
<?php endforeach; ?></div></fieldset>
<fieldset class="mb-3"><legend class="fs-6 fw-semibold">2. วิธีเชื่อมต่อ</legend><div class="printer-options printer-options-4">
<?php foreach(['usb'=>['ti-usb','USB','เสียบสาย USB กับเครื่องนี้'],'bluetooth'=>['ti-bluetooth','Bluetooth','เครื่องพิมพ์ Bluetooth (BLE)'],'serial'=>['ti-plug-connected','Serial / COM','USB-Serial หรือ Bluetooth ที่จับคู่ใน Windows'],'system'=>['ti-printer','เครื่องพิมพ์ของระบบ','พิมพ์ผ่านหน้าต่างพิมพ์ของเบราว์เซอร์']] as $value=>[$icon,$title,$hint]): ?>
<label class="printer-option printer-conn"><input type="radio" name="connection" value="<?= $value ?>" <?= $p['connection']===$value?'checked':'' ?> required><i class="ti <?= $icon ?>" aria-hidden="true"></i><span><b><?= $title ?></b><small class="conn-support" data-support="<?= $value ?>"><?= $hint ?></small></span></label>
<?php endforeach; ?></div></fieldset>
<div class="printer-detect mb-4" id="printer-detect">
 <div class="d-flex flex-wrap gap-2 align-items-center">
  <button type="button" class="btn btn-primary" id="btn-find"><i class="ti ti-search" aria-hidden="true"></i> ค้นหาและเชื่อมต่อเครื่องพิมพ์</button>
  <button type="button" class="btn btn-outline-dark" id="btn-test"><i class="ti ti-printer" aria-hidden="true"></i> พิมพ์ทดสอบ</button>
  <button type="button" class="btn btn-link text-danger" id="btn-forget">ล้างเครื่องที่เลือก</button>
 </div>
 <ul class="list-unstyled small mt-3 mb-0" id="known-devices"></ul>
 <p class="small text-muted mt-2 mb-0" id="detect-help"></p>
</div>
<input type="hidden" name="device_name" id="device_name" value="<?= printerEscape($p['device_name']) ?>">
<input type="hidden" name="device_id" id="device_id" value="<?= printerEscape($p['device_id']) ?>">
<fieldset class="mb-4"><legend class="fs-6 fw-semibold">3. ขนาดกระดาษ</legend><div class="printer-options printer-options-3">
<?php foreach(['58'=>['58 มม.','ใบเสร็จเล็ก · 32 ตัวอักษร/บรรทัด',28],'80'=>['80 มม.','ใบเสร็จมาตรฐาน · 48 ตัวอักษร/บรรทัด',40],'a4'=>['A4','เครื่องพิมพ์สำนักงาน',56]] as $value=>[$title,$hint,$w]): ?>
<label class="printer-option printer-paper"><input type="radio" name="paper_width" value="<?= $value ?>" <?= $p['paper_width']===(string)$value?'checked':'' ?> required><span class="paper-icon" style="width:<?= $w ?>px"></span><span><b><?= $title ?></b><small><?= $hint ?></small></span></label>
<?php endforeach; ?></div></fieldset>

<div class="mb-3"><label class="form-label" for="<?= $station ?>-document">เอกสารที่เครื่องนี้พิมพ์</label><select class="form-select" id="<?= $station ?>-document" name="document"><?php foreach(['receipt_queue'=>'ใบเสร็จและบัตรคิว','queue_only'=>'บัตรคิว','receipt_only'=>'ใบเสร็จ'] as $value=>$text): ?><option value="<?= $value ?>" <?= $p['document']===$value?'selected':'' ?>><?= $text ?></option><?php endforeach; ?></select></div>
<div class="mb-3"><label class="form-label" for="<?= $station ?>-notes">หมายเหตุ</label><textarea class="form-control" id="<?= $station ?>-notes" name="notes" rows="2" maxlength="300" placeholder="เช่น เครื่องข้างลิ้นชักเงินสด"><?= printerEscape($p['notes']) ?></textarea></div>
</div><div class="card-footer bg-white p-3 p-md-4"><button class="btn btn-dark w-100" type="submit"><i class="ti ti-device-floppy" aria-hidden="true"></i> บันทึกค่าของ <?= printerEscape($label) ?></button></div>
</form></section>
<?php endforeach; ?>
</div>
<script src="../../assets/js/printer.js?v=<?= filemtime(__DIR__ . '/../../assets/js/printer.js') ?>"></script>
<script>window.PRINTER_PAGE = <?= json_encode(['store'=>AppSetting::get('store_name','ร้านอาหาร')], JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="../../assets/js/printer-settings.js?v=<?= filemtime(__DIR__ . '/../../assets/js/printer-settings.js') ?>"></script>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>