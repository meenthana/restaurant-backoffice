<?php
// Read-only customer tracking: no membership required, no state-changing actions.
require_once __DIR__.'/../classes/ServiceFlow.php';
require_once __DIR__.'/../classes/KioskOrder.php';
require_once __DIR__.'/../classes/OrderItem.php';
require_once __DIR__.'/../classes/QueueCard.php';
require_once __DIR__.'/../classes/Member.php';
require_once __DIR__.'/../classes/AppSetting.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$id = (int)($_GET['o'] ?? 0);
$record = ServiceFlow::findTracking($id,(string)($_GET['t'] ?? ''));
if (!$record) { http_response_code(404); exit('ไม่พบลิงก์ติดตาม หรือหมดอายุแล้ว กรุณาติดต่อเคาน์เตอร์'); }
$o = $record['order']; $d = $record['details'];
$labels = ['pending'=>'รอครัว','active'=>'กำลังทำ','ready'=>'พร้อมรับ','closed'=>'รับอาหารแล้ว','cancelled'=>'ยกเลิก','no_show'=>'เลยเวลารับ กรุณาติดต่อพนักงาน'];
$status = $o['status']==='pending' && !(int)$o['is_paid'] ? 'รอชำระเงิน (สแกนจ่ายหรือชำระที่เคาน์เตอร์)' : ($labels[$o['status']] ?? 'ติดต่อพนักงาน');
// ขั้นปัจจุบันของแถบสถานะ: 0 ชำระเงิน, 1 รอครัว, 2 กำลังทำ, 3 พร้อมรับ, 4 รับแล้ว, -1 ยกเลิก/เลยเวลา
function trackStep(array $o): int {
    if (in_array($o['status'], ['cancelled','no_show'], true)) return -1;
    if (!(int)$o['is_paid']) return 0;
    return ['pending'=>1,'active'=>2,'ready'=>3,'closed'=>4][$o['status']] ?? 1;
}
$step = trackStep($o);
$label = KioskOrder::queueLabel((int)$o['queue_no'],$o['channel'],$id);
if (isset($_GET['poll'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>$status,'state'=>$o['status'],'paid'=>(bool)$o['is_paid'],'step'=>$step],JSON_UNESCAPED_UNICODE); exit;
}
$items = OrderItem::getByOrder($id);
$member = Member::receiptSummary($o);
$shopName = AppSetting::get('store_name', 'ครัวธนุรเวท');
function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function baht($v): string { return '฿'.number_format((float)$v,2); }
$steps = ['ชำระเงิน','รอครัว','กำลังทำ','พร้อมรับ','รับแล้ว'];
$css = __DIR__.'/../assets/css/customer.css';
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>คิว <?= h($label) ?> — <?= h($shopName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.44.0/dist/tabler-icons.min.css" rel="stylesheet">
<link href="../assets/css/customer.css?v=<?= is_file($css) ? filemtime($css) : 1 ?>" rel="stylesheet">
<style>
.rc-wrap{max-width:520px;margin:0 auto;padding:16px 14px 32px}
.rc-card{background:var(--c-surface);border:1px solid var(--c-line);border-radius:var(--radius-lg);padding:18px;margin-bottom:12px}
.rc-hero{text-align:center;padding:22px 18px}
.rc-eyebrow{color:#7b5b2d;font-size:.78rem;font-weight:800;letter-spacing:.12em}
.rc-queue{font-size:3.6rem;font-weight:800;line-height:1.1;color:var(--c-brand);margin:4px 0}
.rc-sub{color:var(--c-muted);font-size:.95rem}
.rc-status{display:inline-flex;align-items:center;gap:.4rem;margin-top:12px;padding:.45rem 1rem;border-radius:999px;font-weight:700;background:var(--c-brand-soft);color:var(--c-brand)}
.rc-status.is-wait{background:var(--c-accent-soft);color:#7b5b2d}
.rc-status.is-ready{background:var(--c-brand-2);color:#fff}
.rc-status.is-stop{background:#f6e3e1;color:var(--c-danger)}
.rc-steps{display:grid;grid-template-columns:repeat(5,1fr);margin:18px 0 4px;padding:0;list-style:none}
.rc-steps li{text-align:center;font-size:.75rem;color:var(--c-muted);position:relative}
.rc-steps li::before{content:"";display:block;width:14px;height:14px;margin:0 auto 6px;border-radius:50%;background:var(--c-line);position:relative;z-index:1}
.rc-steps li::after{content:"";position:absolute;top:6px;left:-50%;width:100%;height:2px;background:var(--c-line)}
.rc-steps li:first-child::after{display:none}
.rc-steps li.done{color:var(--c-ink)}.rc-steps li.done::before,.rc-steps li.done::after{background:var(--c-brand-2)}
.rc-steps li.now{color:var(--c-brand);font-weight:800}.rc-steps li.now::before{background:var(--c-brand-2);box-shadow:0 0 0 4px var(--c-brand-soft)}
.rc-steps li.now::after{background:var(--c-brand-2)}
.rc-note{font-size:.82rem;color:var(--c-muted);text-align:center;margin:8px 0 0}
.rc-title{display:flex;align-items:center;gap:.45rem;font-weight:800;font-size:1rem;margin:0 0 12px}
.rc-title i{color:var(--c-brand-2);font-size:1.2rem}
.rc-member{display:flex;align-items:center;gap:12px}
.rc-avatar{width:46px;height:46px;border-radius:50%;background:var(--c-brand-soft);color:var(--c-brand);display:grid;place-items:center;font-size:1.4rem;flex-shrink:0}
.rc-name{font-weight:800;font-size:1.05rem}
.rc-pts{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}
.rc-pt{background:var(--c-bg);border-radius:var(--radius-md);padding:10px 12px}
.rc-pt b{display:block;font-size:1.35rem;color:var(--c-brand)}
.rc-pt.plus b{color:var(--c-brand-2)}
.rc-pt span{font-size:.8rem;color:var(--c-muted)}
.rc-row{display:flex;justify-content:space-between;gap:12px;padding:6px 0}
.rc-row .q{color:var(--c-muted);white-space:nowrap}
.rc-addon{display:block;font-size:.82rem;color:var(--c-muted)}
.rc-line{border-top:1px dashed var(--c-line);margin:8px 0}
.rc-total{font-weight:800;font-size:1.15rem}
.rc-muted{color:var(--c-muted);font-size:.85rem}
.rc-tag{display:inline-block;font-size:.75rem;padding:.1rem .55rem;border-radius:999px;background:var(--c-accent-soft);color:#7b5b2d;font-weight:700}
.rc-btn{display:block;width:100%;padding:.85rem;border:0;border-radius:var(--radius-md);background:var(--c-brand);color:#fff;font:inherit;font-weight:700;cursor:pointer}
</style></head>
<body class="cust-body">
<div class="cust-topbar"><div class="cust-brand"><i class="ti ti-chef-hat"></i> <?= h($shopName) ?></div></div>
<main class="rc-wrap">

<section class="rc-card rc-hero">
    <div class="rc-eyebrow">เลขคิวของคุณ</div>
    <div class="rc-queue"><?= h($label) ?></div>
    <div class="rc-sub"><?= h($o['service_type']==='takeaway' ? 'กลับบ้าน':'ทานที่ร้าน') ?> · <?= (int)$d['dish_count'] ?> จาน<?php if (!empty($o['queue_card_no'])): ?> · <?= h(QueueCard::label((int)$o['queue_card_no'])) ?><?php endif; ?></div>
    <div id="track-status" class="rc-status <?= $step===3 ? 'is-ready' : ($step===-1 ? 'is-stop' : ($step===0 ? 'is-wait' : '')) ?>" role="status" aria-live="polite"><?= h($status) ?></div>
    <?php if ($step >= 0): ?>
    <ol class="rc-steps">
        <?php foreach ($steps as $i => $s): ?><li class="<?= $i < $step ? 'done' : ($i === $step ? 'now' : '') ?>"><?= h($s) ?></li><?php endforeach; ?>
    </ol>
    <?php endif; ?>
    <p class="rc-note" id="track-updated">แสดงสถานะล่าสุดจากร้าน ไม่ใช่เวลาประมาณการ</p>
    <?php if (!empty($o['queue_card_no'])): ?><p class="rc-note">แสดงบัตรเมื่อรับอาหาร แล้วคืนบัตรที่เคาน์เตอร์</p><?php endif; ?>
</section>

<section class="rc-card">
    <h2 class="rc-title"><i class="ti ti-user"></i> ผู้สั่ง</h2>
    <?php if (!$member): ?>
    <div class="rc-member">
        <div class="rc-avatar"><i class="ti ti-user"></i></div>
        <div><div class="rc-name">ลูกค้าทั่วไป</div><div class="rc-muted">ไม่ได้สมัครสมาชิก · สมัครที่ตู้ด้วยเบอร์โทรเพื่อสะสมแต้มครั้งหน้า</div></div>
    </div>
    <?php else: ?>
    <div class="rc-member">
        <div class="rc-avatar"><i class="ti ti-star"></i></div>
        <div><div class="rc-name"><?= h($member['name']) ?></div><div class="rc-muted">สมาชิก<?= $member['phone'] ? ' · '.h($member['phone']) : '' ?></div></div>
    </div>
    <div class="rc-pts">
        <div class="rc-pt"><b><?= number_format($member['balance']) ?></b><span>แต้มคงเหลือ</span></div>
        <div class="rc-pt plus"><b>+<?= number_format($member['earned']) ?></b><span>แต้มจากบิลนี้<?= $member['pending'] ? ' (ได้รับเมื่อรับอาหาร)' : '' ?></span></div>
    </div>
    <?php if ($member['used'] > 0): ?><p class="rc-muted" style="margin:10px 0 0">ใช้แต้มเป็นส่วนลด <?= number_format($member['used']) ?> แต้ม<?= $member['returned'] > 0 ? ' · คืนแต้มแล้ว '.number_format($member['returned']).' แต้ม' : '' ?></p><?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($d['tax_recorded_at']): ?>
<section class="rc-card">
    <h2 class="rc-title"><i class="ti ti-receipt"></i> ใบเสร็จรับเงิน</h2>
    <div class="rc-muted" style="margin-bottom:8px">เลขที่ <?= h($o['order_code']) ?> · ชำระเมื่อ <?= h(date('d/m/Y H:i', strtotime((string)$o['paid_at']))) ?></div>
    <?php foreach($items as $item): if($item['voided_at']) continue; $addons = OrderItem::getAddons((int)$item['order_item_id']); $line=(float)$item['subtotal']; foreach($addons as $a) $line+=(float)$a['addon_price']*(int)$item['quantity']; ?>
    <div class="rc-row"><div><?= h($item['menu_name']) ?> <span class="q">× <?= (int)$item['quantity'] ?></span><?php foreach($addons as $a): ?><span class="rc-addon">+ <?= h($a['addon_name']) ?></span><?php endforeach; ?><?php if(!empty($item['item_note'])): ?><span class="rc-addon">หมายเหตุ: <?= h($item['item_note']) ?></span><?php endif; ?></div><div><?= baht($line) ?></div></div>
    <?php endforeach; ?>
    <div class="rc-line"></div>
    <?php if ((float)$o['discount_amount'] > 0): ?><div class="rc-row"><span>ส่วนลด (ใช้แต้ม)</span><span>−<?= baht($o['discount_amount']) ?></span></div><?php endif; ?>
    <div class="rc-row rc-muted"><span>มูลค่าก่อน VAT</span><span><?= baht($d['net_amount']) ?></span></div>
    <div class="rc-row rc-muted"><span>VAT <?= h(rtrim(rtrim((string)$d['vat_rate'],'0'),'.')) ?>%</span><span><?= baht($d['vat_amount']) ?></span></div>
    <div class="rc-line"></div>
    <div class="rc-row rc-total"><span>ยอดรวม</span><span><?= baht($d['gross_amount']) ?></span></div>
    <?php $refund=getDB()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=? AND transaction_type='refund' AND status='completed'"); $refund->execute([$id]); $refundAmount=(float)$refund->fetchColumn(); if($refundAmount>0): ?>
    <div class="rc-row" style="color:var(--c-danger)"><span>คืนเงินแล้ว</span><span><?= baht($refundAmount) ?></span></div>
    <?php endif; ?>
    <p class="rc-muted" style="margin:10px 0 0">ราคารวมภาษีมูลค่าเพิ่มแล้ว · ไม่ใช่ใบกำกับภาษี · <span class="rc-tag"><?= $d['receipt_preference']==='paper' ? 'รับกระดาษได้ที่แคชเชียร์' : 'ไม่รับกระดาษ' ?></span></p>
</section>
<?php else: ?>
<section class="rc-card">
    <h2 class="rc-title"><i class="ti ti-list-details"></i> รายการที่สั่ง</h2>
    <?php foreach($items as $item): if ($item['voided_at']) continue; ?>
    <div class="rc-row"><div><?= h($item['menu_name']) ?><?php foreach(OrderItem::getAddons((int)$item['order_item_id']) as $addon): ?><span class="rc-addon">+ <?= h($addon['addon_name']) ?></span><?php endforeach; ?><?php if(!empty($item['item_note'])): ?><span class="rc-addon">หมายเหตุ: <?= h($item['item_note']) ?></span><?php endif; ?></div><span class="q">× <?= (int)$item['quantity'] ?></span></div>
    <?php endforeach; ?>
    <p class="rc-muted" style="margin:10px 0 0">ใบเสร็จจะแสดงที่นี่หลังชำระเงินสำเร็จ</p>
</section>
<?php endif; ?>

<button class="rc-btn" onclick="location.reload()"><i class="ti ti-refresh"></i> อัปเดตสถานะ</button>
<p class="rc-note">ลิงก์นี้ใช้ได้ 7 วัน กรุณาไม่ส่งต่อให้ผู้อื่น</p>
</main>
<script>
(function(){let timer;let wasPaid=<?= (int)$o['is_paid'] ?>;let lastStep=<?= (int)$step ?>;
async function refresh(){try{const u=new URL(location.href);u.searchParams.set('poll','1');const r=await fetch(u,{cache:'no-store'});if(!r.ok)throw Error();const d=await r.json();
if((d.paid&&!wasPaid)||d.step!==lastStep){location.reload();return;}
document.getElementById('track-status').textContent=d.status;document.getElementById('track-updated').textContent='อัปเดต '+new Date().toLocaleTimeString('th-TH');
if(['closed','cancelled','no_show'].includes(d.state))return;}catch(e){document.getElementById('track-updated').textContent='เชื่อมต่อไม่ได้ สถานะข้างต้นอาจเก่า ระบบจะลองใหม่';}timer=setTimeout(refresh,5000);}
timer=setTimeout(refresh,5000);addEventListener('pagehide',()=>clearTimeout(timer));})();
</script></body></html>
