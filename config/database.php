<?php
// ตั้งค่าการเชื่อมต่อฐานข้อมูล (แก้ชื่อ DB / user / password ที่นี่)
// Load private hosting settings before evaluating database environment variables.
require_once __DIR__ . '/env.php';
if (restaurant_env('RESTAURANT_ENV') === 'production') {
    ini_set('display_errors', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
}

define('DB_HOST', restaurant_env('RESTAURANT_DB_HOST') ?: 'localhost');
define('DB_NAME', restaurant_env('RESTAURANT_DB_NAME') ?: 'restaurant_backoffice');
define('DB_USER', restaurant_env('RESTAURANT_DB_USER') ?: 'root');
define('DB_PASS', restaurant_env('RESTAURANT_DB_PASS') !== false ? restaurant_env('RESTAURANT_DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

// เวลาไทยทั้ง PHP และ MySQL (โฮสต์ต่างประเทศใช้เวลาเครื่องตัวเอง)
date_default_timezone_set('Asia/Bangkok');

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // ถ้า MySQL ไม่ตอบภายใน 5 วินาที ให้แจ้ง error เลย แทนที่หน้าเว็บจะหมุนค้าง
            PDO::ATTR_TIMEOUT            => 5,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            http_response_code(503);
            error_log('Restaurant database connection failed');
            die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ กรุณาติดต่อผู้ดูแลระบบ');
        }
    }

    return $pdo;
}
