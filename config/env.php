<?php
// โหลดค่าลับ (รหัสฐานข้อมูล คีย์ชำระเงิน) จากไฟล์ส่วนตัว สำหรับโฮสติ้งที่ตั้ง Environment Variable ไม่ได้
// ลำดับ: ไฟล์ตาม RESTAURANT_PRIVATE_CONFIG > config/private.php · ไม่มีไฟล์ = ใช้ค่าจากเซิร์ฟเวอร์ (SetEnv) ตามเดิม
// โฮสต์ฟรีบางเจ้าปิด putenv() จึงอ่านบรรทัด putenv('KEY=VALUE') จากไฟล์เก็บไว้เองด้วย
if (!defined('RESTAURANT_PRIVATE_LOADED')) {
    define('RESTAURANT_PRIVATE_LOADED', true);
    $GLOBALS['RESTAURANT_PRIVATE_ENV'] = [];
    $restaurantPrivate = getenv('RESTAURANT_PRIVATE_CONFIG') ?: __DIR__ . '/private.php';
    if (is_file($restaurantPrivate)) {
        $src = (string) file_get_contents($restaurantPrivate);
        if (preg_match_all("/putenv\\(\\s*'([A-Z0-9_]+)=((?:[^'\\\\]|\\\\.)*)'\\s*\\)/", $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) $GLOBALS['RESTAURANT_PRIVATE_ENV'][$row[1]] = str_replace(["\\'", '\\\\'], ["'", '\\'], $row[2]);
        }
        if (function_exists('putenv')) require_once $restaurantPrivate;
    }
    unset($restaurantPrivate, $src, $m, $row);
}

if (!function_exists('restaurant_env')) {
    // ค่าจากไฟล์ส่วนตัวมาก่อน ไม่มีค่อยใช้ Environment Variable ของเซิร์ฟเวอร์
    function restaurant_env(string $key)
    {
        $own = $GLOBALS['RESTAURANT_PRIVATE_ENV'] ?? [];
        return array_key_exists($key, $own) ? $own[$key] : getenv($key);
    }
}
