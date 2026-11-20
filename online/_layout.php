<?php
// หัว/ท้ายของหน้าลูกค้าออนไลน์
require_once __DIR__ . '/../classes/AppSetting.php';

function onlineHead(string $title, bool $autoRefresh = false): void
{
    $shop = AppSetting::get('store_name', 'ครัวธนุรเวท');
    ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($autoRefresh): ?><meta http-equiv="refresh" content="20"><?php endif; ?>
    <title><?= htmlspecialchars($title . ' — ' . $shop) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.44.0/dist/tabler-icons.min.css" rel="stylesheet">
    <link href="../assets/css/customer.css?v=<?= filemtime(__DIR__ . '/../assets/css/customer.css') ?>" rel="stylesheet">
</head>
<body class="cust-body">
    <?php
}

function onlineTopbar(?array $member = null): void
{
    $shop = AppSetting::get('store_name', 'ครัวธนุรเวท');
    ?>
<div class="cust-topbar">
    <a href="index.php" class="cust-brand text-white text-decoration-none"><i class="ti ti-chef-hat"></i> <?= htmlspecialchars($shop) ?></a>
    <div class="spacer"></div>
    <?php if ($member): ?>
        <span class="cust-pill d-none d-sm-inline-flex"><i class="ti ti-user-circle"></i> <?= htmlspecialchars($member['display_name']) ?></span>
        <span class="cust-pill cust-pill-accent"><i class="ti ti-star"></i> <?= (int) $member['points'] ?> แต้ม</span>
        <a href="logout.php" class="cust-pill" title="ออกจากระบบ"><i class="ti ti-logout"></i></a>
    <?php endif; ?>
</div>
    <?php
}

function onlineFoot(): void
{
    echo "</body>\n</html>\n";
}
