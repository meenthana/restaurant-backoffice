<?php
require_once __DIR__ . '/../../config/features.php';
requireFeature(FEATURE_TABLES);
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/RestaurantTable.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    RestaurantTable::markAvailable((int) ($_POST['table_id'] ?? 0));
}
header('Location: index.php');
exit;
