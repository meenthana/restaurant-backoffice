<?php
// หน้าแรก: ส่งไปหน้าออเดอร์ หรือหน้าล็อกอิน
require_once __DIR__ . '/classes/Auth.php';
Auth::boot();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$target = match (Auth::role()) {
    'kitchen' => 'modules/kitchen/index.php',
    'owner', 'manager' => 'modules/dashboard/index.php',
    default => 'modules/orders/index.php',
};
header('Location: ' . $target);
exit;
