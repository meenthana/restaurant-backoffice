<?php
// ล็อกอินลูกค้าด้วย LINE
require_once __DIR__ . '/../config/line_loader.php';
require_once __DIR__ . '/../classes/LineApi.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!LINE_API_ENABLED) {
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>เข้าสู่ระบบลูกค้า</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.44.0/dist/tabler-icons.min.css" rel="stylesheet">
<link href="../assets/css/customer.css" rel="stylesheet"></head>
<body class="cust-body"><div class="cust-topbar"><div class="cust-brand"><i class="ti ti-chef-hat"></i> สั่งอาหารออนไลน์</div></div>
<div class="container py-5" style="max-width:520px"><div class="cust-card p-4 text-center">
<i class="ti ti-brand-line" style="font-size:3.5rem;color:#06c755"></i>
<h1 class="h3 fw-bold mt-2">เข้าสู่ระบบลูกค้า</h1>
<p class="text-muted">ขณะนี้ยังไม่ได้ตั้งค่า LINE API สามารถใช้บัญชีสาธิตเพื่อทดสอบขั้นตอนการสั่งอาหารได้</p>
<a href="demo_login.php" class="btn-touch btn-brand w-100">เข้าสู่ระบบสาธิต</a>
<div class="small text-muted mt-3">เมื่อต้องการใช้ LINE จริง ให้ใส่ค่า LINE ใน config/private.php</div>
</div></div></body></html>
<?php exit; }

$state = bin2hex(random_bytes(16));
$_SESSION['line_login_state'] = $state;
header('Location: ' . LineApi::getLoginUrl($state));
exit;

