<?php
// พิมพ์บัตรคิว (A4 ได้ 10 ใบ ขนาดนามบัตร 85×54 มม.) ตัดแล้วเคลือบใช้ซ้ำได้
require_once __DIR__.'/../../classes/Auth.php';
require_once __DIR__.'/../../classes/QueueCard.php';
require_once __DIR__.'/../../classes/AppSetting.php';
Auth::requireAnyRole(['owner','manager']);
$cards = array_filter(QueueCard::tableReady() ? QueueCard::all() : [], fn($c) => (int) $c['is_active'] === 1);
$shop = AppSetting::get('store_name', 'ร้านอาหาร');
function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>พิมพ์บัตรคิว</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700;800&display=swap" rel="stylesheet">
<style>
@page { size: A4; margin: 8mm; }
* { box-sizing: border-box; }
body { font-family: Sarabun, Tahoma, sans-serif; margin: 0; background: #eee; color: #1c2b22; }
.bar { padding: 12px 16px; background: #fff; display: flex; gap: 12px; align-items: center; }
.sheet { display: grid; grid-template-columns: repeat(2, 85mm); gap: 2.5mm 6mm; justify-content: center; padding: 10mm 0; }
.qc { width: 85mm; height: 54mm; background: #fff; border: 1.2mm solid #1f4d3a; border-radius: 4mm; padding: 3mm 4mm;
      display: grid; grid-template-columns: 30mm 1fr; gap: 3mm; break-inside: avoid; }
.qc .no { background: #1f4d3a; color: #fff; border-radius: 3mm; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.qc .no small { font-size: 9pt; letter-spacing: .1em; }
.qc .no b { font-size: 40pt; line-height: 1; }
.qc .info { display: flex; flex-direction: column; justify-content: space-between; }
.qc .shop { font-weight: 800; font-size: 10pt; }
.qc .code-l { font-size: 8.5pt; color: #555; margin-top: 1mm; }
.qc .code { font-size: 26pt; font-weight: 800; letter-spacing: .18em; background: #fff3c4; border-radius: 2mm; text-align: center; }
.qc .how { font-size: 7.5pt; line-height: 1.35; color: #333; }
.qc.counter { border-color: #9a3412; }
.qc.counter .no { background: #9a3412; }
@media print { body { background: #fff; } .bar { display: none; } .sheet { padding: 0; } }
</style></head>
<body>
<div class="bar"><button onclick="print()">พิมพ์</button> <span><?= count($cards) ?> ใบ · กระดาษ A4 หน้าละ 10 ใบ · แนะนำพิมพ์บนกระดาษการ์ดแล้วเคลือบ</span></div>
<div class="sheet">
<?php foreach ($cards as $c): $counter = ($c['card_type'] ?? 'kiosk') === 'counter'; ?>
  <div class="qc<?= $counter ? ' counter' : '' ?>">
    <div class="no"><small><?= $counter ? 'ตามสั่ง' : 'บัตรตู้' ?></small><b><?= e(str_pad((string) $c['card_no'], 2, '0', STR_PAD_LEFT)) ?></b></div>
    <div class="info">
      <div class="shop"><?= e($shop) ?></div>
      <div><div class="code-l"><?= $counter ? 'รหัสบัตร' : 'รหัสสำหรับกรอกที่ตู้' ?></div><div class="code"><?= e($c['code']) ?></div></div>
      <?php if ($counter): ?>
      <div class="how">1. ยื่นบัตรนี้ให้พนักงานตอนสั่ง<br>2. ยื่นบัตรอีกครั้งเมื่อรับอาหาร<br>3. พนักงานรับบัตรคืน</div>
      <?php else: ?>
      <div class="how">1. กรอกรหัสนี้ที่ตู้ก่อนสั่ง<br>2. ยื่นบัตรเมื่อรับอาหาร<br>3. พนักงานรับบัตรคืน</div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
</body></html>
