<?php
// จอคิวรับอาหารสำหรับลูกค้า (เปิดบนทีวี/จอหน้าร้าน) แสดงแค่เลขคิว ไม่มีชื่อหรือเบอร์
require_once __DIR__.'/../classes/Auth.php';
require_once __DIR__.'/../classes/KioskOrder.php';
require_once __DIR__.'/../classes/AppSetting.php';
require_once __DIR__.'/../classes/QueueCard.php';
Auth::requireAnyRole(['owner','manager','cashier','kitchen','staff']);
header('Cache-Control: no-store');

$rows = getDB()->query(
    "SELECT o.order_id, o.queue_no, o.queue_card_no, o.channel, o.status,
            (SELECT MAX(kt.ready_at) FROM kitchen_tickets kt WHERE kt.order_id = o.order_id) AS ready_at
     FROM orders o
     WHERE o.is_paid = 1 AND o.status IN ('pending','active','ready')
       AND o.opened_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
     ORDER BY o.paid_at, o.order_id LIMIT 200"
)->fetchAll();

$preparing = []; $ready = [];
foreach ($rows as $r) {
    $item = ['id' => (int) $r['order_id'], 'label' => KioskOrder::queueLabel((int) $r['queue_no'], $r['channel'], (int) $r['order_id']),
             'card' => QueueCard::label($r['queue_card_no'] ? (int) $r['queue_card_no'] : null),
             'cooking' => $r['status'] === 'active', 'ready_at' => $r['ready_at']];
    if ($r['status'] === 'ready') $ready[] = $item; else $preparing[] = $item;
}
// พร้อมรับล่าสุดขึ้นก่อน
usort($ready, fn($a, $b) => strcmp((string) $b['ready_at'], (string) $a['ready_at']));

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['preparing' => $preparing, 'ready' => $ready, 'time' => date('H:i')], JSON_UNESCAPED_UNICODE);
    exit;
}
$shop = AppSetting::get('store_name', 'ร้านอาหาร');
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>จอคิวรับอาหาร</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@500;700;800&display=swap" rel="stylesheet">
<style>
  :root { --bg:#0f1f17; --panel:#16291f; --ready:#22c55e; --ready-dark:#14532d; --wait:#cbd5e1; --text:#f8fafc; --muted:#94a3b8; --accent:#facc15; }
  * { box-sizing:border-box; }
  html,body { margin:0; height:100%; }
  body { background:var(--bg); color:var(--text); font-family:'Sarabun',system-ui,sans-serif; display:flex; flex-direction:column; }
  header { display:flex; align-items:center; justify-content:space-between; padding:18px 36px; border-bottom:1px solid #24402f; }
  header h1 { margin:0; font-size:2rem; font-weight:800; }
  header .clock { font-size:2rem; font-weight:700; color:var(--muted); font-variant-numeric:tabular-nums; }
  main { flex:1; display:grid; grid-template-columns:1fr 1.6fr; gap:24px; padding:24px 36px; min-height:0; }
  section { background:var(--panel); border-radius:24px; padding:24px 28px; display:flex; flex-direction:column; min-height:0; }
  section h2 { margin:0 0 4px; font-size:2.2rem; font-weight:800; display:flex; align-items:center; gap:14px; }
  section .sub { color:var(--muted); font-size:1.25rem; margin-bottom:20px; }
  .dot { width:18px; height:18px; border-radius:50%; display:inline-block; }
  .wait .dot { background:var(--wait); }
  .ready .dot { background:var(--ready); box-shadow:0 0 0 6px rgba(34,197,94,.25); }
  .grid { display:flex; flex-wrap:wrap; gap:14px; align-content:flex-start; overflow:hidden; }
  .num { font-weight:800; border-radius:16px; text-align:center; font-variant-numeric:tabular-nums; letter-spacing:.02em; }
  .wait .num { font-size:2.6rem; padding:10px 22px; background:#22362b; color:var(--wait); }
  .wait .num.cooking { color:var(--accent); }
  .ready .num { font-size:4.2rem; padding:14px 30px; background:var(--ready); color:var(--ready-dark); }
  .num small { display:block; font-size:.42em; font-weight:700; opacity:.85; }
  .ready .num.new { animation:pop 1s ease-in-out 6; }
  @keyframes pop { 0%,100% { transform:scale(1); box-shadow:0 0 0 0 rgba(250,204,21,0); } 50% { transform:scale(1.08); box-shadow:0 0 0 10px rgba(250,204,21,.6); } }
  .empty { color:var(--muted); font-size:1.5rem; }
  .legend { color:var(--muted); font-size:1.1rem; margin-top:auto; padding-top:12px; }
  .legend b { color:var(--accent); }
  footer { text-align:center; padding:12px; font-size:1.3rem; color:var(--muted); border-top:1px solid #24402f; }
  @media (max-width:900px) { main { grid-template-columns:1fr; padding:16px; } header { padding:14px 16px; } .ready .num { font-size:3rem; } .wait .num { font-size:2rem; } }
</style>
</head>
<body>
<header>
  <h1><?= htmlspecialchars($shop) ?> · คิวรับอาหาร</h1>
  <div class="clock" id="clock"><?= date('H:i') ?></div>
</header>
<main>
  <section class="wait">
    <h2><span class="dot"></span>กำลังเตรียม</h2>
    <div class="sub">กรุณารอสักครู่</div>
    <div class="grid" id="preparing"></div>
    <div class="legend">เลขสี<b>เหลือง</b> = ครัวกำลังทำ</div>
  </section>
  <section class="ready">
    <h2><span class="dot"></span>พร้อมรับ</h2>
    <div class="sub">เชิญรับอาหารที่เคาน์เตอร์</div>
    <div class="grid" id="ready"></div>
  </section>
</main>
<footer>K = สั่งที่ตู้ · W = สั่งกับพนักงาน · O = สั่งออนไลน์</footer>
<script>
(function () {
  var data = <?= json_encode(['preparing' => $preparing, 'ready' => $ready], JSON_UNESCAPED_UNICODE) ?>;
  var seen = null;
  function esc(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function beep() {
    try {
      var ctx = new (window.AudioContext || window.webkitAudioContext)();
      [880, 1175].forEach(function (f, i) {
        var o = ctx.createOscillator(), g = ctx.createGain();
        o.frequency.value = f; o.connect(g); g.connect(ctx.destination);
        g.gain.setValueAtTime(0.25, ctx.currentTime + i * 0.25);
        g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + i * 0.25 + 0.22);
        o.start(ctx.currentTime + i * 0.25); o.stop(ctx.currentTime + i * 0.25 + 0.24);
      });
    } catch (e) {}
  }
  function render(d) {
    var ids = d.ready.map(function (r) { return r.id; });
    var fresh = seen ? ids.filter(function (id) { return seen.indexOf(id) === -1; }) : [];
    document.getElementById('preparing').innerHTML = d.preparing.length
      ? d.preparing.map(function (o) { return '<div class="num' + (o.cooking ? ' cooking' : '') + '">' + esc(o.label) + (o.card ? '<small>' + esc(o.card) + '</small>' : '') + '</div>'; }).join('')
      : '<div class="empty">ไม่มีคิวที่รอ</div>';
    document.getElementById('ready').innerHTML = d.ready.length
      ? d.ready.map(function (o) { return '<div class="num' + (fresh.indexOf(o.id) !== -1 ? ' new' : '') + '">' + esc(o.label) + (o.card ? '<small>' + esc(o.card) + '</small>' : '') + '</div>'; }).join('')
      : '<div class="empty">ยังไม่มีคิวที่พร้อมรับ</div>';
    if (fresh.length) beep();
    seen = ids;
  }
  function load() {
    fetch('board.php?json=1', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) { render(d); document.getElementById('clock').textContent = d.time; })
      .catch(function () {});
  }
  render(data);
  setInterval(load, 4000);
})();
</script>
</body>
</html>
