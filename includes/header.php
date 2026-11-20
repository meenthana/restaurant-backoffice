<?php
// ส่วนหัวทุกหน้า: เมนูด้านบน และฟังก์ชัน pageHeader()
if (!function_exists('getDB')) {
    require_once __DIR__ . '/../config/database.php';
}
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../config/features.php';
Auth::requireLogin();

$currentRole = Auth::role();
$canManage = Auth::canManage();
$currentDir  = basename(dirname($_SERVER['SCRIPT_NAME']));

function navActive(string $dir, string $activeDir): string {
    return $dir === $activeDir ? ' active' : '';
}

// หัวของทุกหน้า: ชื่อหน้า + คำอธิบายสั้นๆ 1 บรรทัด + ปุ่มด้านขวา (ถ้ามี)
function pageHeader(string $title, string $subtitle = '', string $actions = ''): void
{
    echo '<div class="pg-head"><div><h1>' . $title . '</h1>';
    if ($subtitle !== '') echo '<div class="sub">' . htmlspecialchars($subtitle) . '</div>';
    echo '</div>';
    if ($actions !== '') echo '<div class="actions">' . $actions . '</div>';
    echo '</div>';
}

// สวิตช์เปิด/ปิดสถานะ ใช้แบบเดียวกันทุกหน้า (ส่ง POST ไปที่ $action พร้อม CSRF)
function statusToggle(string $action, array $fields, bool $on, string $onText, string $offText, string $confirm = '', bool $disabled = false): string
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    return '<div class="form-check form-switch status-toggle' . ($on ? ' is-on' : '') . '">'
        . '<input class="form-check-input" type="checkbox" role="switch"' . ($on ? ' checked' : '') . ($disabled ? ' disabled' : '')
        . ' data-action="' . $e($action) . '" data-fields="' . $e(json_encode($fields, JSON_UNESCAPED_UNICODE)) . '"'
        . ($confirm !== '' ? ' data-confirm="' . $e($confirm) . '"' : '')
        . ' onchange="statusToggleSubmit(this)" aria-label="' . $e($on ? $onText : $offText) . '">'
        . '<span class="form-check-label">' . $e($on ? $onText : $offText) . '</span></div>';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หลังร้าน — ครัวธนุรเวท</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.44.0/dist/tabler-icons.min.css" rel="stylesheet">
    <link href="/restaurant-backoffice/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" rel="stylesheet">
    <link href="/restaurant-backoffice/assets/css/service.css?v=<?= filemtime(__DIR__ . '/../assets/css/service.css') ?>" rel="stylesheet">
    <meta name="csrf-token" content="<?= htmlspecialchars(Security::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <script>
    function statusToggleSubmit(el) {
        if (el.dataset.confirm && !confirm(el.dataset.confirm)) { el.checked = !el.checked; return; }
        const f = document.createElement('form');
        f.method = 'post'; f.action = el.dataset.action;
        const fields = Object.assign({csrf_token: document.querySelector('meta[name=csrf-token]').content}, JSON.parse(el.dataset.fields || '{}'));
        for (const [k, v] of Object.entries(fields)) { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); }
        el.disabled = true; document.body.appendChild(f); f.submit();
    }
    </script>
</head>
<body class="bg-light <?= $currentDir==='kitchen' ? 'service-kitchen' : (in_array($currentDir,['pos','slips','receipts'],true) ? 'service-cashier' : '') ?>">

<nav class="navbar navbar-expand-xl navbar-dark app-navbar mb-4">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="/restaurant-backoffice/index.php">
            <i class="ti ti-chef-hat"></i> ครัวธนุรเวท
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if (FEATURE_TABLES): ?><li class="nav-item"><a class="nav-link<?= navActive('tables',$currentDir) ?>" href="/restaurant-backoffice/modules/tables/index.php"><i class="ti ti-layout-grid"></i> ผังโต๊ะ</a></li><?php endif; ?>
                <?php if (FEATURE_QUEUE): ?><li class="nav-item"><a class="nav-link<?= navActive('queue',$currentDir) ?>" href="/restaurant-backoffice/modules/queue/index.php"><i class="ti ti-list-numbers"></i> คิว</a></li><?php endif; ?>
                <?php if (FEATURE_RESERVATIONS): ?><li class="nav-item"><a class="nav-link<?= navActive('reservations',$currentDir) ?>" href="/restaurant-backoffice/modules/reservations/index.php"><i class="ti ti-calendar-event"></i> จองโต๊ะ</a></li><?php endif; ?>
                <li class="nav-item"><a class="nav-link<?= navActive('orders',$currentDir) ?>" href="/restaurant-backoffice/modules/orders/index.php"><i class="ti ti-shopping-cart"></i> ออเดอร์</a></li>
                <li class="nav-item"><a class="nav-link<?= navActive('kitchen',$currentDir) ?>" href="/restaurant-backoffice/modules/kitchen/index.php"><i class="ti ti-flame"></i> ครัว</a></li>
                <li class="nav-item"><a class="nav-link<?= navActive('pos',$currentDir) ?>" href="/restaurant-backoffice/modules/pos/index.php"><i class="ti ti-cash-register"></i> คิดเงิน</a></li>
                <?php if (in_array($currentRole, ['owner', 'manager', 'cashier', 'staff'], true)): ?>
                <li class="nav-item"><a class="nav-link<?= navActive('receipts',$currentDir) ?>" href="/restaurant-backoffice/modules/receipts/index.php"><i class="ti ti-receipt"></i> ใบเสร็จ</a></li>
                <?php endif; ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"><i class="ti ti-adjustments"></i> จัดการ</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/restaurant-backoffice/modules/menu/index.php"><i class="ti ti-tools-kitchen-2 me-2"></i>เมนูอาหาร</a></li>
                        <li><a class="dropdown-item" href="/restaurant-backoffice/modules/stock/index.php"><i class="ti ti-box me-2"></i>สต๊อกวัตถุดิบ</a></li>
                        <li><a class="dropdown-item" href="/restaurant-backoffice/kiosk/index.php" target="_blank"><i class="ti ti-device-tablet me-2"></i>เปิดหน้าตู้สั่งอาหาร</a></li>
                        <?php if ($canManage): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/restaurant-backoffice/modules/dashboard/index.php"><i class="ti ti-layout-dashboard me-2"></i>แดชบอร์ด</a></li>
                        <li><a class="dropdown-item" href="/restaurant-backoffice/modules/revenue/summary.php"><i class="ti ti-chart-bar me-2"></i>รายได้</a></li>
                        <li><a class="dropdown-item" href="/restaurant-backoffice/modules/members/index.php"><i class="ti ti-user-heart me-2"></i>สมาชิก</a></li>
                        <li><a class="dropdown-item" href="/restaurant-backoffice/modules/refunds/index.php"><i class="ti ti-receipt-refund me-2"></i>คำขอคืนเงิน</a></li>
                        <li><a class="dropdown-item" href="/restaurant-backoffice/modules/revenue/service.php">รายงานบริการ / ตั้งค่าประเภทเมนู</a></li>
                        <li><a class="dropdown-item" href="/restaurant-backoffice/modules/logs/index.php">ประวัติการทำงาน (Logs)</a></li>
                        <li><a class="dropdown-item" href="/restaurant-backoffice/kiosk/board.php" target="_blank">เปิดจอคิวรับอาหาร</a></li>
                        <li><a class="dropdown-item" href="/restaurant-backoffice/modules/settings/index.php"><i class="ti ti-settings me-2"></i>ตั้งค่าร้าน</a></li>
<li><a class="dropdown-item" href="/restaurant-backoffice/modules/settings/printers.php"><i class="ti ti-printer me-2"></i>เครื่องพิมพ์ Kiosk / Cashier</a></li>
                        <?php if ($currentRole === 'owner'): ?><li><a class="dropdown-item" href="/restaurant-backoffice/modules/users/index.php"><i class="ti ti-users me-2"></i>บัญชีพนักงาน</a></li><?php endif; ?>
                        <?php endif; ?>
                    </ul>
                </li>
            </ul>
            <span class="navbar-text text-light me-3">
                <i class="ti ti-user-circle"></i>
                <?= htmlspecialchars(Auth::fullName()) ?>
                <?php $roleLabels = ['owner'=>'เจ้าของร้าน','manager'=>'ผู้จัดการ','cashier'=>'แคชเชียร์','kitchen'=>'ครัว','staff'=>'พนักงาน']; ?>
                <span class="badge text-bg-secondary ms-1"><?= $roleLabels[$currentRole] ?? htmlspecialchars((string) $currentRole) ?></span>
            </span>
            <a href="/restaurant-backoffice/switch_user.php" class="btn btn-outline-warning btn-sm me-2">
                <i class="ti ti-repeat"></i> สลับพนักงาน
            </a>
            <a href="/restaurant-backoffice/logout.php" class="btn btn-outline-light btn-sm">
                <i class="ti ti-logout"></i> ออกจากระบบ
            </a>
        </div>
    </div>
</nav>

<div class="container-xl pb-5">
<div class="service-context"><?= $currentDir==='kitchen' ? 'ครัว · จัดเตรียมอาหาร' : (in_array($currentDir,['pos','slips','receipts'],true) ? 'แคชเชียร์ · รับเงินและส่งมอบ' : 'หลังร้าน · จัดการระบบ') ?></div>
