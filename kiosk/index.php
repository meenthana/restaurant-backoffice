<?php
// หน้าตู้สั่งอาหารสำหรับลูกค้า (แท็บเล็ต)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Menu.php';
require_once __DIR__ . '/../classes/Addon.php';
require_once __DIR__ . '/../classes/AppSetting.php';
require_once __DIR__ . '/../classes/MenuStock.php';
require_once __DIR__ . '/../classes/ServiceFlow.php';
require_once __DIR__ . '/../classes/PaymentGateway.php';
require_once __DIR__ . '/../classes/QueueCard.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['kiosk_mode'] = true;   // ให้ API สั่งด้วยเสียงรู้ว่าเรียกมาจากตู้

$shopName = AppSetting::get('store_name', 'ครัวธนุรเวท');
$avail = MenuStock::availabilityMap();
$menu = [];
foreach (Menu::all() as $m) {
    $a = $avail[(int) $m['menu_id']];
    $menu[] = [
        'id'       => (int) $m['menu_id'],
        'name'     => $m['name'],
        'category' => $m['category'],
        'price'    => (float) $m['price'],
        'image'    => !empty($m['image_path']) ? '../' . $m['image_path'] : null,
        'soldOut'  => $a['out'] !== null,
        'outLabel' => $a['out'],
        'maxQty'   => $a['max'],
        'addons'   => array_map(fn($x) => [
            'id'    => (int) $x['addon_id'],
            'name'  => $x['name'],
            'price' => (float) $x['extra_price'],
            'out'   => in_array((int) $x['addon_id'], $a['addonsOut'], true),
        ], Addon::forMenu((int) $m['menu_id'])),
    ];
}
$config = [
    'idleSeconds'   => max(30, (int) AppSetting::get('kiosk_idle_seconds', '90')),
    'hasPromptPay'  => PaymentGateway::qrAvailable(),
    'omiseTest'     => PaymentGateway::isTest(),
    'gateway'       => PaymentGateway::provider(),
    'voiceEnabled'  => AppSetting::get('voice_order_enabled', '1') === '1',
    'serviceReady'  => ServiceFlow::ready(),
    'cardRequired'  => QueueCard::required(),
    'apiUrl'        => 'api.php',
    'voiceApiUrl'   => '../api/parse_voice_order.php',
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>สั่งอาหารด้วยตนเอง — <?= htmlspecialchars($shopName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.44.0/dist/tabler-icons.min.css" rel="stylesheet">
    <link href="../assets/css/customer.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer.css') ?>" rel="stylesheet">
    <link href="../assets/css/service.css?v=<?= filemtime(__DIR__ . '/../assets/css/service.css') ?>" rel="stylesheet">
    <style>
      html, body { height: 100%; overscroll-behavior: none; user-select: none; }
      .screen { display: none; min-height: 100vh; }
      .screen.active { display: flex; flex-direction: column; }

      /* ---- 1. หน้าต้อนรับ ---- */
      #scr-welcome { align-items: center; justify-content: center; text-align: center; padding: 2rem;
                     background: radial-gradient(circle at 30% 20%, #245843, var(--c-brand) 60%); color: #fff; }
      #scr-welcome .logo { font-size: 5rem; color: var(--c-accent); line-height: 1; }
      #scr-welcome h1 { font-size: clamp(2.2rem, 5vw, 3.5rem); font-weight: 800; margin: 1rem 0 .5rem; }
      #scr-welcome p { font-size: 1.3rem; opacity: .85; margin-bottom: 2.5rem; }
      #scr-welcome p.diy-hint { font-size: 1.05rem; margin: 2rem 0 0; }
      .svc-choices { display: grid; grid-template-columns: repeat(2, minmax(220px, 300px)); gap: 1.25rem; }
      .svc-btn { background: #fff; color: var(--c-ink); border: 0; border-radius: 1.5rem; padding: 2rem 1rem;
                 display: flex; flex-direction: column; align-items: center; gap: .75rem; font-size: 1.5rem; font-weight: 800;
                 box-shadow: 0 16px 40px rgba(0,0,0,.25); transition: transform .12s ease; }
      .svc-btn:active { transform: scale(.97); }
      .svc-btn i { font-size: 4rem; color: var(--c-brand-2); }
      .svc-btn small { font-size: 1rem; font-weight: 500; color: var(--c-muted); }

      /* ---- 2. หน้าเมนู ---- */
      #scr-menu { height: 100vh; }
      .menu-layout { flex: 1; display: grid; grid-template-columns: 1fr 360px; min-height: 0; }
      .menu-main { overflow-y: auto; padding: 1rem 1.25rem 2rem; }
      .cart-side { background: var(--c-surface); border-left: 1px solid var(--c-line); display: flex; flex-direction: column; min-height: 0; }
      .cart-side header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--c-line); display: flex; align-items: center; justify-content: space-between; }
      .cart-lines { flex: 1; overflow-y: auto; padding: .5rem 1.25rem; }
      .cart-line { padding: .75rem 0; border-bottom: 1px dashed var(--c-line); }
      .cart-line .nm { font-weight: 700; }
      .cart-line .ad { font-size: .9rem; color: var(--c-muted); }
      .cart-line .row2 { display: flex; align-items: center; justify-content: space-between; margin-top: .4rem; }
      .cart-empty { text-align: center; color: var(--c-muted); padding: 3rem 1rem; }
      .cart-empty i { font-size: 3rem; display: block; margin-bottom: .5rem; }
      .cart-foot { padding: 1rem 1.25rem; border-top: 1px solid var(--c-line); }
      .cart-total { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: .75rem; }
      .cart-total .v { font-size: 1.8rem; font-weight: 800; color: var(--c-brand); }
      .mobile-cart-bar { display: none; }

      @media (max-width: 900px) {
        .menu-layout { grid-template-columns: 1fr; }
        .cart-side { display: none; }
        .mobile-cart-bar { display: flex; }
        .svc-choices { grid-template-columns: 1fr; }
      }

      /* ---- แผ่นเลื่อนขึ้น (เลือกเมนู / ตะกร้ามือถือ / เสียง / ถามว่ายังอยู่ไหม) ---- */
      .sheet-backdrop { position: fixed; inset: 0; background: rgba(15, 25, 20, .55); z-index: 50; display: none;
                        align-items: flex-end; justify-content: center; }
      .sheet-backdrop.open { display: flex; }
      .sheet { background: var(--c-surface); width: min(640px, 100%); max-height: 92vh; overflow-y: auto;
               border-radius: 1.5rem 1.5rem 0 0; padding: 1.5rem; animation: sheetUp .2s ease; }
      .sheet-center { align-items: center; }
      .sheet-center .sheet { border-radius: 1.5rem; }
      @keyframes sheetUp { from { transform: translateY(40px); opacity: .5; } to { transform: none; opacity: 1; } }
      .sheet .hero-img { width: 100%; aspect-ratio: 16/9; object-fit: cover; border-radius: 1rem; background: #f2eee2; }
      .sheet .hero-ph { display: flex; align-items: center; justify-content: center; font-size: 4rem; color: #b7ae94; }

      /* ---- สมาชิก ---- */
      .mem-choices { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem; }
      .mem-btn { background: #fff; border: 3px solid var(--c-line); border-radius: 1.5rem; padding: 2.2rem 1rem;
                 display: flex; flex-direction: column; align-items: center; gap: .6rem; font-size: 1.5rem; font-weight: 800; color: var(--c-ink); }
      .mem-btn i { font-size: 3.6rem; color: var(--c-brand-2); }
      .mem-btn.guest { border-color: var(--c-brand-2); background: var(--c-brand-soft, #eef4ff); }
      .mem-btn.existing i { color: #d39b2f; }
      .mem-btn small { font-weight: 500; font-size: 1rem; color: var(--c-muted); }
      .mem-btn:active, .keypad button:active { transform: scale(.97); }
      .phone-display { font-size: 2.6rem; font-weight: 800; letter-spacing: .08em; margin: 1rem auto 1.2rem; padding: .6rem 1rem;
                       border: 3px solid var(--c-line); border-radius: 1rem; background: #fff; width: min(420px, 100%); font-variant-numeric: tabular-nums; }
      .keypad { display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem; width: min(420px, 100%); margin: 0 auto 1rem; }
      .keypad button { font-size: 2rem; font-weight: 800; padding: 1rem 0; border-radius: 1rem; border: 2px solid var(--c-line); background: #fff; color: var(--c-ink); }
      .keypad .kp-fn { font-size: 1.3rem; color: var(--c-muted); }
      .consent-row { display: flex; gap: .7rem; align-items: flex-start; text-align: left; margin-top: 1rem; font-size: 1.05rem; }
      .consent-row input { width: 1.6rem; height: 1.6rem; flex-shrink: 0; }
      @media (max-width: 800px) { .mem-choices { grid-template-columns: 1fr; } }

      /* ---- บัตรคิว ---- */
      #scr-card { align-items: center; justify-content: center; padding: 2rem; }
      .code-boxes { display: flex; gap: .8rem; justify-content: center; margin: .5rem 0 1.2rem; }
      .code-boxes span { width: 4.2rem; height: 5rem; border: 3px solid var(--c-line); border-radius: 1rem; background: #fff;
                         display: flex; align-items: center; justify-content: center; font-size: 2.8rem; font-weight: 800; }
      .code-boxes span.on { border-color: var(--c-brand-2); }
      .card-pill { background: #fff3c4 !important; color: #6b4e00 !important; font-weight: 800; }
      .done-card { font-size: 1.5rem; font-weight: 800; background: #fff3c4; color: #6b4e00; border-radius: 1rem; padding: .6rem 1rem; }

      /* ---- 3. ชำระเงิน ---- */
      #scr-pay, #scr-qr, #scr-done { align-items: center; justify-content: center; padding: 2rem; }
      .pay-box { width: min(760px, 100%); }
      .pay-total { font-size: 3rem; font-weight: 800; color: var(--c-brand); }
      .pay-choices { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
      .pay-btn { background: #fff; border: 3px solid var(--c-line); border-radius: 1.5rem; padding: 2rem 1rem;
                 display: flex; flex-direction: column; align-items: center; gap: .6rem; font-size: 1.4rem; font-weight: 800; color: var(--c-ink); }
      .pay-btn i { font-size: 3.5rem; }
      .pay-btn small { font-weight: 500; font-size: 1rem; color: var(--c-muted); text-align: center; }
      .pay-btn:disabled { opacity: .45; }
      .pay-btn.cash i { color: var(--c-accent); }
      .pay-btn.qr i { color: var(--c-brand-2); }
      @media (max-width: 700px) { .pay-choices { grid-template-columns: 1fr; } }
      .rcpt-box { width: min(620px, 100%); margin-left: auto; margin-right: auto; }
      .rcpt-label { font-weight: 700; margin-bottom: .5rem; }
      .rcpt-choices { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-bottom: .4rem; }
      .rcpt-btn { display: flex; align-items: center; gap: .75rem; padding: 1rem 1.1rem; min-height: 4.5rem; text-align: left;
                  background: #fff; border: 3px solid var(--c-line); border-radius: 1.1rem; font-size: 1.15rem; font-weight: 800; color: var(--c-ink); }
      .rcpt-btn i { font-size: 2rem; color: var(--c-muted); flex-shrink: 0; }
      .rcpt-btn small { display: block; font-size: .9rem; font-weight: 500; color: var(--c-muted); }
      .rcpt-btn.active { border-color: var(--c-brand-2); background: var(--c-brand-soft); }
      .rcpt-btn.active i { color: var(--c-brand-2); }
      .rcpt-btn:active { transform: scale(.98); }
      @media (max-width: 560px) { .rcpt-choices { grid-template-columns: 1fr; } }

      .qr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; width: min(900px, 100%); }
      .qr-card { background: #fff; border: 1px solid var(--c-line); border-radius: 1.5rem; padding: 1.5rem; text-align: center; }
      .qr-card .qr { display: inline-block; padding: .75rem; background: #fff; border-radius: .75rem; border: 1px solid var(--c-line); }
      .qr-card .qr img, .qr-card .qr canvas { display: block; }
      .qr-step { display: inline-flex; width: 2.2rem; height: 2.2rem; border-radius: 50%; background: var(--c-brand); color: #fff;
                 align-items: center; justify-content: center; font-weight: 800; margin-bottom: .5rem; }
      .qr-grid:has(.qr-card.d-none) { grid-template-columns: 1fr; width: min(440px, 100%); }
      @media (max-width: 800px) { .qr-grid { grid-template-columns: 1fr; } }

      /* ---- 4. เลขคิว ---- */
      .queue-big { font-size: clamp(5rem, 14vw, 9rem); font-weight: 800; color: var(--c-brand); line-height: 1; letter-spacing: .05em; }
      .done-icon { font-size: 5rem; }
    </style>
</head>
<body class="cust-body service-kiosk">

<!-- ============ 1. หน้าต้อนรับ ============ -->
<section id="scr-welcome" class="screen active">
    <div class="logo"><i class="ti ti-chef-hat"></i></div>
    <h1><?= htmlspecialchars($shopName) ?></h1>
    <p>สั่งอาหารด้วยตัวเอง แตะเพื่อเริ่มได้เลย</p>
    <?php if (!$config['serviceReady']): ?><div class="alert alert-warning">ขณะนี้สั่งอาหารแบบบุคคลทั่วไปได้ สมาชิกและการติดตามออเดอร์ยังไม่เปิดให้บริการ</div><?php endif; ?>
    <div class="svc-choices">
        <button class="svc-btn" data-service="dine_in"><i class="ti ti-armchair"></i>ทานที่ร้าน<small>รับอาหารที่เคาน์เตอร์เมื่อเรียกคิว</small></button>
        <button class="svc-btn" data-service="takeaway"><i class="ti ti-paper-bag"></i>สั่งกลับบ้าน<small>ใส่กล่องพร้อมถุง</small></button>
    </div>
    <p class="diy-hint"><i class="ti ti-chef-hat"></i> อยากสั่งอาหารตามสั่งแบบเลือกเอง (เช่น กะเพรา + หมูกรอบ + หมึก) สั่งกับพนักงานที่เคาน์เตอร์</p>
</section>

<!-- ============ 1b. กรอกรหัสบัตรคิว ============ -->
<section id="scr-card" class="screen">
 <div class="pay-box text-center">
  <h2 class="fw-bold mb-1">หยิบบัตรตู้สั่งอาหาร 1 ใบ</h2>
  <p class="text-muted mb-3">จากกล่องข้างตู้ แล้วกรอก <strong>รหัส 4 หลัก</strong> ที่อยู่บนบัตร</p>
  <div class="code-boxes" id="card-boxes"><span></span><span></span><span></span><span></span></div>
  <input type="hidden" id="card-code" value="">
  <div class="keypad" id="card-keypad">
   <button type="button" data-k="1">1</button><button type="button" data-k="2">2</button><button type="button" data-k="3">3</button>
   <button type="button" data-k="4">4</button><button type="button" data-k="5">5</button><button type="button" data-k="6">6</button>
   <button type="button" data-k="7">7</button><button type="button" data-k="8">8</button><button type="button" data-k="9">9</button>
   <button type="button" data-k="clear" class="kp-fn">ล้าง</button><button type="button" data-k="0">0</button><button type="button" data-k="back" class="kp-fn"><i class="ti ti-backspace"></i></button>
  </div>
  <p id="card-error" class="text-danger fw-bold" role="alert"></p>
  <div class="d-flex gap-3 justify-content-center mt-2">
   <button type="button" id="btn-card-back" class="btn-touch btn-ghost"><i class="ti ti-arrow-left"></i> ย้อนกลับ</button>
   <button type="button" id="btn-card-next" class="btn-touch btn-brand" disabled>ยืนยันบัตร</button>
  </div>
  <p class="small text-muted mt-3">เก็บบัตรไว้กับตัว ตอนรับอาหารยื่นบัตรให้พนักงานตรวจรหัส แล้วพนักงานจะรับบัตรคืน</p>
 </div>
</section>

<section id="scr-member" class="screen" style="align-items:center;justify-content:center;padding:2rem">
 <div class="pay-box text-center">
  <input type="hidden" id="member-mode" value="guest">
  <!-- ขั้นที่ 1: เลือกแบบสั่ง -->
  <div id="member-step-choose">
   <h2 class="fw-bold mb-1">สะสมแต้มไหมครับ?</h2>
   <p class="text-muted mb-4">ไม่สมัครก็สั่งอาหารได้ทันที</p>
   <div class="mem-choices">
    <button type="button" class="mem-btn guest" data-mode="guest"><i class="ti ti-user"></i>ไม่ใช่สมาชิก<small>สั่งอาหารเลย</small></button>
    <button type="button" class="mem-btn existing" data-mode="existing"><i class="ti ti-star"></i>สมาชิกเดิม<small>กรอกเบอร์โทร สะสมแต้ม</small></button>
    <button type="button" class="mem-btn register" data-mode="register"><i class="ti ti-user-plus"></i>สมัครสมาชิก<small>ใช้เบอร์โทร ไม่ต้องมี LINE</small></button>
   </div>
  </div>
  <!-- ขั้นที่ 2: กรอกเบอร์ (และชื่อถ้าสมัครใหม่) -->
  <div id="member-step-phone" hidden>
   <h2 class="fw-bold mb-1" id="member-step-title">กรอกเบอร์มือถือ</h2>
   <div class="phone-display" id="phone-display">0__-___-____</div>
   <input id="member-phone" type="hidden" value="">
   <div class="keypad" id="keypad">
    <button type="button" data-k="1">1</button><button type="button" data-k="2">2</button><button type="button" data-k="3">3</button>
    <button type="button" data-k="4">4</button><button type="button" data-k="5">5</button><button type="button" data-k="6">6</button>
    <button type="button" data-k="7">7</button><button type="button" data-k="8">8</button><button type="button" data-k="9">9</button>
    <button type="button" data-k="clear" class="kp-fn">ล้าง</button><button type="button" data-k="0">0</button><button type="button" data-k="back" class="kp-fn"><i class="ti ti-backspace"></i></button>
   </div>
   <div id="member-register-fields" hidden class="member-fields">
    <label for="member-name" class="fw-bold">ชื่อที่ใช้เรียก</label>
    <input id="member-name" class="form-control form-control-lg" maxlength="100" autocomplete="off" placeholder="เช่น ต้น">
    <label class="consent-row"><input id="member-consent" type="checkbox"> ยินยอมบันทึกชื่อและเบอร์เพื่อสะสมแต้ม (ไม่ใช้เพื่อการตลาด)</label>
   </div>
   <p id="member-error" class="text-danger fw-bold" role="alert"></p>
   <div class="d-flex gap-3 justify-content-center mt-2">
    <button type="button" id="btn-member-back" class="btn-touch btn-ghost"><i class="ti ti-arrow-left"></i> ย้อนกลับ</button>
    <button type="button" id="btn-member-next" class="btn-touch btn-brand">ยืนยัน · เลือกอาหาร</button>
   </div>
   <p class="small text-muted mt-3">เบอร์ใช้ผูกแต้มเท่านั้น หน้าตู้จะไม่แสดงประวัติการสั่ง</p>
  </div>
 </div>
</section>
<!-- ============ 2. หน้าเมนู ============ -->
<section id="scr-menu" class="screen">
    <div class="cust-topbar">
        <div class="cust-brand"><i class="ti ti-chef-hat"></i> <span class="hide-sm"><?= htmlspecialchars($shopName) ?></span></div>
        <button class="cust-pill" id="btn-service" type="button"><i class="ti ti-armchair"></i> <span>ทานที่ร้าน</span></button>
        <span class="cust-pill card-pill" id="pill-card" hidden></span>
        <div class="spacer"></div>
        <?php if ($config['voiceEnabled']): ?>
        <button class="cust-pill cust-pill-accent" id="btn-open-voice" type="button"><i class="ti ti-microphone"></i> <span class="hide-sm">สั่งด้วยเสียง</span></button>
        <?php endif; ?>
        <button class="cust-pill" id="btn-member-edit" type="button" <?= $config['serviceReady'] ? '' : 'hidden' ?>>สมาชิก</button>
        <button class="cust-pill" id="btn-restart" type="button"><i class="ti ti-refresh"></i> <span class="hide-sm">เริ่มใหม่</span></button>
    </div>

    <div class="menu-layout">
        <main class="menu-main">
            <div class="cat-tabs" id="cat-tabs"></div>
            <div class="menu-grid" id="menu-grid"></div>
        </main>

        <aside class="cart-side">
            <header>
                <div class="fw-bold fs-5"><i class="ti ti-shopping-bag"></i> รายการที่สั่ง</div>
                <span class="badge rounded-pill text-bg-dark fs-6" id="cart-count">0</span>
            </header>
            <div class="cart-lines" id="cart-lines"></div>
            <div class="cart-foot">
                <div class="cart-total"><span class="fw-bold">ยอดรวม</span><span class="v" id="cart-total">฿0</span></div>
                <button class="btn-touch btn-brand w-100" id="btn-checkout" disabled><i class="ti ti-arrow-right"></i> ไปชำระเงิน</button>
            </div>
        </aside>
    </div>

    <div class="cart-bar mobile-cart-bar">
        <div class="sum"><div class="small text-muted"><span id="m-cart-count">0</span> รายการ</div><div class="t" id="m-cart-total">฿0</div></div>
        <button class="btn-touch btn-ghost" id="btn-m-cart"><i class="ti ti-shopping-bag"></i> ดูตะกร้า</button>
        <button class="btn-touch btn-brand" id="btn-m-checkout" disabled>ชำระเงิน</button>
    </div>
</section>

<!-- ============ 3. เลือกวิธีชำระเงิน ============ -->
<section id="scr-pay" class="screen">
    <div class="pay-box text-center">
        <div class="eyebrow-c mb-2">ขั้นตอนสุดท้าย</div>
        <h2 class="fw-bold mb-1">เลือกวิธีชำระเงิน</h2>
        <div class="text-muted mb-2">ยอดที่ต้องชำระ</div>
        <div class="pay-total mb-4" id="pay-total">฿0</div>
        <p>ราคาได้รวม VAT 7% แล้ว</p>
        <label class="addon-chip d-none mb-3" id="pay-points" style="width:fit-content;margin:0 auto .75rem"><input type="checkbox" id="use-points"> ใช้แต้มสะสมเป็นส่วนลด <span class="price">1 แต้ม = ฿1 · สูงสุด <?= min(100, max(1, (int) AppSetting::get('point_redeem_cap_percent', '50'))) ?>% ของยอดบิล</span></label>
        <div class="rcpt-box mb-4">
            <div class="rcpt-label">การรับใบเสร็จหลังชำระ</div>
            <input type="hidden" id="receipt-preference" value="digital">
            <div class="rcpt-choices" role="radiogroup" aria-label="การรับใบเสร็จ">
                <button type="button" class="rcpt-btn active" data-receipt="digital" role="radio" aria-checked="true"><i class="ti ti-device-mobile"></i><span>ไม่รับกระดาษ<small>ดูใบเสร็จผ่านมือถือ</small></span></button>
                <button type="button" class="rcpt-btn" data-receipt="paper" role="radio" aria-checked="false"><i class="ti ti-receipt"></i><span>รับใบเสร็จกระดาษ<small>รับได้ที่แคชเชียร์</small></span></button>
            </div>
            <small class="text-muted">ไม่รับใบเสร็จก็ยังได้รับเลขคิว</small>
        </div>
        <div class="pay-choices mb-4">
            <button class="pay-btn cash" data-pay="cash"><i class="ti ti-cash"></i>เงินสด<small>รับเลขคิว แล้วชำระที่เคาน์เตอร์</small></button>
            <button class="pay-btn qr" data-pay="promptpay" <?= $config['hasPromptPay'] ? '' : 'disabled' ?>>
                <i class="ti ti-qrcode"></i>สแกนจ่ายพร้อมเพย์
                <small><?= $config['hasPromptPay'] ? (PaymentGateway::provider() ? ($config['omiseTest'] ? (PaymentGateway::provider() === 'scb' ? 'โหมดทดสอบ SCB — ไม่รับเงินจริง' : 'โหมดทดสอบ Omise — ไม่รับเงินจริง') : 'สแกนจ่าย ยืนยันอัตโนมัติ ไม่ต้องส่งสลิป') : 'สแกนด้วยแอปธนาคาร แล้วส่งสลิปจากมือถือ') : 'ยังไม่เปิดสแกนจ่าย กรุณาเลือกเงินสด' ?></small>
            </button>
        </div>
        <div class="alert alert-danger d-none" id="pay-error"></div>
        <button class="btn-touch btn-ghost" id="btn-pay-back"><i class="ti ti-arrow-left"></i> กลับไปแก้รายการ</button>
    </div>
</section>

<!-- ============ 3b. สแกนจ่าย ============ -->
<section id="scr-qr" class="screen">
    <div class="text-center mb-3">
        <div class="eyebrow-c">คิวของคุณ <span id="qr-queue"></span> <span id="qr-card"></span></div>
        <h2 class="fw-bold mb-0">สแกนจ่าย <span id="qr-amount"></span></h2>
        <div class="text-success fw-bold d-none" id="qr-points"></div>
    </div>
    <div class="qr-grid mb-3">
        <div class="qr-card">
            <div class="qr-step">1</div>
            <div class="fw-bold fs-5 mb-2">สแกนจ่ายด้วยแอปธนาคาร</div>
            <div class="qr" id="qr-pay"></div>
            <div class="text-muted small mt-2">ยอดเงินถูกใส่ไว้ใน QR แล้ว ไม่ต้องพิมพ์เอง</div>
        </div>
        <div class="qr-card">
            <div class="qr-step">2</div>
            <div class="fw-bold fs-5 mb-2">สแกนเพื่อส่งสลิปจากมือถือ</div>
            <div class="qr" id="qr-slip"></div>
            <div class="text-muted small mt-2">เปิดกล้องมือถือสแกน แล้วแนบรูปสลิป</div>
            <!-- แสดงเฉพาะตอนเปิดผ่าน localhost: มือถือเปิดลิงก์ localhost ไม่ได้ จึงให้เปิดหน้าส่งสลิปบนเครื่องนี้แทนเพื่อสาธิต -->
            <a id="qr-slip-link" href="#" target="_blank" class="btn btn-sm btn-outline-secondary mt-2 d-none">
                <i class="ti ti-external-link"></i> เปิดหน้าส่งสลิปในแท็บใหม่ (โหมดสาธิตบนเครื่องนี้)
            </a>
        </div>
    </div>
    <div class="cust-card px-4 py-3 mb-3 d-flex align-items-center gap-3" style="width:min(900px,100%)">
        <div class="spinner-border text-success" role="status" id="qr-spinner"></div>
        <div class="flex-grow-1">
            <div class="fw-bold" id="qr-status-text">รอรับสลิปการโอนเงิน…</div>
            <div class="small text-muted" id="qr-status-sub">หน้าจอนี้จะเปลี่ยนเองเมื่อตรวจสลิปผ่าน</div>
        </div>
    </div>
    <button class="btn-touch btn-ghost" id="btn-switch-cash"><i class="ti ti-cash"></i> เปลี่ยนไปจ่ายเงินสดที่เคาน์เตอร์</button>
</section>

<!-- ============ 4. เลขคิว ============ -->
<section id="scr-done" class="screen">
    <div class="text-center" style="max-width:640px">
        <div class="done-icon mb-2" id="done-icon"></div>
        <div class="fs-4 fw-bold mb-1" id="done-title"></div>
        <div class="text-muted mb-1">เลขคิวของคุณ</div>
        <div class="queue-big mb-3" id="done-queue"></div>
        <div class="done-card mb-3" id="done-card" hidden></div>
        <div class="cust-card p-3 mb-4 fs-5" id="done-message"></div>
        <div id="done-service" class="cust-card p-3 mb-3" hidden><div id="done-type" class="queue-category"></div><p>แสดงเลขคิวนี้แก่แคชเชียร์เมื่อรับอาหาร · สแกน QR เพื่อติดตามสถานะบนมือถือ</p><div id="done-track-qr" style="display:inline-block"></div><p><a id="done-track-link" target="_blank" rel="noreferrer">เปิดหน้าติดตาม / ใบเสร็จ</a></p><p id="done-track-warning" class="text-danger" hidden>ลิงก์ localhost เปิดจากมือถือไม่ได้ กรุณาใช้ IP ในวงร้านหรือ HTTPS ที่เข้าถึงเครื่องนี้ได้</p><p id="done-receipt"></p></div>
        <button class="btn-touch btn-brand px-5" id="btn-done">เสร็จสิ้น · <span id="done-countdown">20</span> วินาที</button>
    </div>
</section>

<!-- ============ แผ่นเลือกเมนู ============ -->
<div class="sheet-backdrop" id="sheet-item">
    <div class="sheet">
        <div id="item-media" class="mb-3"></div>
        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div class="fs-3 fw-bold" id="item-name"></div>
            <div class="fs-3 fw-bold text-success" id="item-price"></div>
        </div>
        <div id="item-addons-wrap" class="mb-3">
            <div class="fw-bold mb-2">ตัวเลือกเสริม</div>
            <div class="addon-chips" id="item-addons"></div>
        </div>
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="stepper"><button type="button" id="item-dec">−</button><output id="item-qty">1</output><button type="button" id="item-inc">+</button></div>
            <div class="d-flex gap-2 flex-grow-1 justify-content-end">
                <button class="btn-touch btn-ghost" data-close>ยกเลิก</button>
                <button class="btn-touch btn-brand" id="item-add">เพิ่มลงตะกร้า <span id="item-sum"></span></button>
            </div>
        </div>
    </div>
</div>

<!-- ============ ตะกร้า (จอเล็ก) ============ -->
<div class="sheet-backdrop" id="sheet-cart">
    <div class="sheet">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fs-4 fw-bold">รายการที่สั่ง</div>
            <button class="btn-touch btn-ghost py-2" data-close><i class="ti ti-x"></i></button>
        </div>
        <div id="m-cart-lines"></div>
    </div>
</div>

<!-- ============ สั่งด้วยเสียง ============ -->
<div class="sheet-backdrop" id="sheet-voice">
    <div class="sheet">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="fs-4 fw-bold"><i class="ti ti-microphone text-success"></i> สั่งด้วยเสียง</div>
            <button class="btn-touch btn-ghost py-2" data-close><i class="ti ti-x"></i></button>
        </div>
        <p class="text-muted">กดปุ่มแล้วพูดได้เลย เช่น <strong>"กะเพราหมูสองจาน ไข่ดาวด้วย แล้วก็ข้าวผัดกุ้งหนึ่งที่"</strong></p>
        <button type="button" id="btn-voice" class="btn-touch btn-brand w-100 mb-3"><i class="ti ti-microphone"></i> สั่งด้วยเสียง</button>
        <div id="voice-panel" class="voice-panel"></div>
    </div>
</div>

<!-- ============ ยังอยู่ไหม ============ -->
<div class="sheet-backdrop sheet-center" id="sheet-idle">
    <div class="sheet text-center">
        <i class="ti ti-hand-finger fs-1 text-success"></i>
        <div class="fs-3 fw-bold mt-2">ยังสั่งอยู่ไหมครับ?</div>
        <p class="text-muted fs-5">ถ้าไม่มีการแตะหน้าจอ ระบบจะล้างรายการและกลับหน้าแรกใน <strong id="idle-countdown">15</strong> วินาที</p>
        <button class="btn-touch btn-brand px-5" id="btn-still-here">ยังสั่งอยู่</button>
    </div>
</div>

<script>
window.KIOSK_MENU = <?= json_encode($menu, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.KIOSK_CONFIG = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="../assets/js/voice-order.js?v=<?= filemtime(__DIR__ . '/../assets/js/voice-order.js') ?>"></script>
<script src="../assets/js/kiosk.js?v=<?= filemtime(__DIR__ . '/../assets/js/kiosk.js') ?>"></script>
</body>
</html>
