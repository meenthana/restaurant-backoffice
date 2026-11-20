<?php
// ล็อกอิน / สิทธิ์พนักงาน / สลับพนักงานด้วย PIN
require_once __DIR__ . '/../config/database.php';

class Auth
{
    // เริ่ม session ถ้ายังไม่เริ่ม (เรียกได้ปลอดภัยแม้เรียกซ้ำหลายครั้ง)
    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    // ตรวจ username/password แล้ว login ถ้าถูกต้อง คืนค่า true/false
    public static function attempt(string $username, string $password): bool
    {
        self::boot();
        $db = getDB();

        $stmt = $db->prepare('SELECT * FROM users WHERE username = :u AND is_active = 1');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        // password_verify เทียบรหัสผ่านกับ hash โดยไม่ต้องถอดรหัสกลับ (one-way hashing)
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true); // กัน session fixation ตอน login สำเร็จ
            $_SESSION['user_id']   = (int) $user['user_id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];
            return true;
        }

        return false;
    }

    public static function logout(): void
    {
        self::boot();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        self::boot();
        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        self::boot();
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): ?string
    {
        self::boot();
        return $_SESSION['role'] ?? null;
    }

    public static function fullName(): ?string
    {
        self::boot();
        return $_SESSION['full_name'] ?? null;
    }

    // ถ้ายังไม่ login ให้เด้งไปหน้า login ทันที เรียกไว้บนสุดของทุกหน้าที่ต้องป้องกัน
    public static function requireLogin(): void
    {
        self::boot();
        if (!self::check()) {
            $depth = self::pathDepthFromModules();
            header('Location: ' . str_repeat('../', $depth) . 'login.php');
            exit;
        }
    }    // ถ้า role ไม่ตรง ให้บล็อกการเข้าถึง (ใช้หลัง requireLogin เสมอ)
    public static function requireRole(string $role): void
    {
        self::requireLogin();
        if (self::role() !== $role) self::deny();
    }

    public static function requireAnyRole(array $roles): void
    {
        self::requireLogin();
        if (!in_array(self::role(), $roles, true)) self::deny();
    }

    // หน้าแจ้งไม่มีสิทธิ์ พร้อมปุ่มกลับหน้าหลัก
    private static function deny(): void
    {
        http_response_code(403);
        $home = '/restaurant-backoffice/index.php';
        echo '<!doctype html><html lang="th"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>ไม่มีสิทธิ์เข้าถึง</title><body style="font-family:Sarabun,Tahoma,sans-serif;background:#f4f1ea;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0">'
           . '<div style="background:#fff;border-radius:16px;padding:32px 40px;text-align:center;box-shadow:0 8px 24px rgba(0,0,0,.08)">'
           . '<h1 style="font-size:1.4rem;margin:0 0 8px">ไม่มีสิทธิ์เข้าถึงหน้านี้</h1>'
           . '<p style="color:#666;margin:0 0 20px">บัญชีนี้ (' . htmlspecialchars((string) self::role()) . ') ใช้หน้านี้ไม่ได้ ให้เจ้าของร้านกำหนดสิทธิ์ในหน้าบัญชีพนักงาน</p>'
           . '<a href="' . htmlspecialchars($home) . '" style="background:#1f4d3a;color:#fff;padding:10px 22px;border-radius:10px;text-decoration:none">กลับหน้าหลัก</a> '
           . '<a href="javascript:history.back()" style="color:#1f4d3a;margin-left:10px">ย้อนกลับ</a></div></body></html>';
        exit;
    }

    public static function canManage(): bool
    {
        return in_array(self::role(), ['owner', 'manager'], true);
    }

    // รายชื่อพนักงานที่เปิดใช้งานอยู่และตั้งค่า PIN ไว้แล้ว ใช้แสดงในหน้าสลับพนักงานด้วย PIN
    public static function pinLoginableStaff(): array
    {
        $db = getDB();
        return $db->query(
            "SELECT user_id, full_name, role FROM users
             WHERE is_active = 1 AND pin_hash IS NOT NULL
             ORDER BY full_name"
        )->fetchAll();
    }

    // สลับผู้ใช้งานปัจจุบันด้วย PIN โดยไม่ต้อง Logout เต็มรูปแบบ
    public static function switchViaPin(int $userId, string $pin): bool
    {
        self::boot();
        $db = getDB();

        $stmt = $db->prepare('SELECT * FROM users WHERE user_id = :id AND is_active = 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['pin_hash']) || !password_verify($pin, $user['pin_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user['user_id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];
        return true;
    }

    // ตั้ง/เปลี่ยน PIN ให้พนักงาน (เรียกจากหน้าจัดการพนักงาน โดย Owner/Manager เท่านั้น)
    public static function setPin(int $userId, string $pin): void
    {
        $db = getDB();
        $stmt = $db->prepare('UPDATE users SET pin_hash = :hash WHERE user_id = :id');
        $stmt->execute(['hash' => password_hash($pin, PASSWORD_DEFAULT), 'id' => $userId]);
    }

    public static function clearPin(int $userId): void
    {
        $db = getDB();
        $stmt = $db->prepare('UPDATE users SET pin_hash = NULL WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
    }

    // ช่วยคำนวณว่าไฟล์ที่เรียกอยู่ลึกจาก root กี่ชั้น (สำหรับ redirect กลับ login.php ที่ root)
    private static function pathDepthFromModules(): int
    {
        // modules/xxx/yyy.php อยู่ลึก 2 ชั้นจาก root เสมอในโครงสร้างโปรเจคนี้
        return 2;
    }
}
