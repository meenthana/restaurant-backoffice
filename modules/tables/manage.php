<?php
require_once __DIR__ . '/../../config/features.php';
requireFeature(FEATURE_TABLES);
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
require_once __DIR__ . '/../../classes/RestaurantTable.php';
require_once __DIR__ . '/../../classes/AuditLog.php';
Auth::requireAnyRole(['owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

Security::verifyCsrf();
$action = $_POST['action'] ?? '';

try {
    if ($action === 'add') {
        $tableId = RestaurantTable::create(
            (string) ($_POST['table_code'] ?? ''),
            (int) ($_POST['capacity'] ?? 0),
            (string) ($_POST['zone_name'] ?? '')
        );
        AuditLog::write(Auth::id(), 'create', 'restaurant_table', $tableId, 'เพิ่มโต๊ะใหม่');
        $message = 'เพิ่มโต๊ะเรียบร้อยแล้ว';
    } elseif ($action === 'delete') {
        $tableId = (int) ($_POST['table_id'] ?? 0);
        RestaurantTable::remove($tableId);
        AuditLog::write(Auth::id(), 'delete', 'restaurant_table', $tableId, 'ลบโต๊ะออกจากผัง');
        $message = 'ลบโต๊ะออกจากผังเรียบร้อยแล้ว';
    } else {
        throw new Exception('คำสั่งจัดการโต๊ะไม่ถูกต้อง');
    }
    header('Location: index.php?success=' . urlencode($message));
} catch (Throwable $e) {
    header('Location: index.php?error=' . urlencode($e->getMessage()));
}
exit;
