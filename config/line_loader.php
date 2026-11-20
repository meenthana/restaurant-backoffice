<?php
// ตั้งค่า LINE: อ่านจาก config/private.php ก่อน (LINE_LOGIN_CHANNEL_ID ฯลฯ) ถ้าไม่มีจึงใช้ config/line.php เดิม
// ไม่มีทั้งสองที่ = ปิด LINE และใช้ล็อกอินสาธิตแทน
require_once __DIR__ . '/env.php';

if (!defined('LINE_API_ENABLED')) {
    if ((string) restaurant_env('LINE_LOGIN_CHANNEL_ID') !== '') {
        define('LINE_API_ENABLED', (string) restaurant_env('LINE_ENABLED') !== '0');
        define('LINE_LOGIN_CHANNEL_ID', (string) restaurant_env('LINE_LOGIN_CHANNEL_ID'));
        define('LINE_LOGIN_CHANNEL_SECRET', (string) restaurant_env('LINE_LOGIN_CHANNEL_SECRET'));
        define('LINE_LOGIN_REDIRECT_URI', (string) restaurant_env('LINE_LOGIN_REDIRECT_URI'));
        define('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN', (string) restaurant_env('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'));
    } elseif (is_file(__DIR__ . '/line.php')) {
        require_once __DIR__ . '/line.php';
    }
    if (!defined('LINE_API_ENABLED')) define('LINE_API_ENABLED', false);
}
foreach (['LINE_LOGIN_CHANNEL_ID', 'LINE_LOGIN_CHANNEL_SECRET', 'LINE_LOGIN_REDIRECT_URI', 'LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] as $lineKey) {
    if (!defined($lineKey)) define($lineKey, '');
}
unset($lineKey);
// ชวนเพิ่มเพื่อนบัญชีร้านตอนล็อกอิน (normal / aggressive) เพื่อให้ส่งแจ้งเตือนได้
if (!defined('LINE_BOT_PROMPT')) define('LINE_BOT_PROMPT', (string) restaurant_env('LINE_BOT_PROMPT'));
