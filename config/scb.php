<?php
// ตั้งค่า SCB QR Payment ผ่าน Environment Variable (ห้ามเขียนคีย์ลงไฟล์นี้)
require_once __DIR__ . '/env.php';
define('SCB_ENABLED', restaurant_env('SCB_ENABLED') === '1');
define('SCB_MODE', restaurant_env('SCB_MODE') === 'live' ? 'live' : 'sandbox');
define('SCB_BASE_URL', restaurant_env('SCB_BASE_URL') ?: (SCB_MODE === 'live'
    ? 'https://api.partners.scb/partners'
    : 'https://api-sandbox.partners.scb/partners/sandbox'));
define('SCB_API_KEY', restaurant_env('SCB_API_KEY') ?: '');
define('SCB_API_SECRET', restaurant_env('SCB_API_SECRET') ?: '');
define('SCB_BILLER_ID', restaurant_env('SCB_BILLER_ID') ?: '');
define('SCB_REF3_PREFIX', strtoupper(restaurant_env('SCB_REF3_PREFIX') ?: ''));
