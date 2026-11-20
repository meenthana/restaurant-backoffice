<?php
// ห้ามบันทึก Channel Secret หรือ Access Token จริงลง Git/ZIP
// ตั้งค่าผ่าน Environment Variables ของ Apache หรือแก้ไฟล์นี้เฉพาะบนเครื่องใช้งาน
$lineEnabled = filter_var(getenv('LINE_API_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
define('LINE_API_ENABLED', $lineEnabled);
define('LINE_LOGIN_CHANNEL_ID', getenv('LINE_LOGIN_CHANNEL_ID') ?: '');
define('LINE_LOGIN_CHANNEL_SECRET', getenv('LINE_LOGIN_CHANNEL_SECRET') ?: '');
define('LINE_LOGIN_REDIRECT_URI', getenv('LINE_LOGIN_REDIRECT_URI') ?: 
'https://indiana-sig-willing-ratio.trycloudflare.com/restaurant-backoffice/online/callback.php/restaurant-backoffice/online/callback.php');
define('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN', getenv('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN') ?: '');

