<?php
// เพิ่มพนักงาน
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
Auth::requireRole('owner');

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'staff';
    $pin = trim($_POST['pin'] ?? '');

    if ($username === '' || $fullName === '' || $password === '') {
        $error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
    } elseif (strlen($password) < 8) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    } elseif (!in_array($role, ['manager','cashier','kitchen','staff'], true)) {
        $error = 'บทบาทไม่ถูกต้อง';
    } elseif ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) {
        $error = 'PIN ต้องเป็นตัวเลข 4-6 หลักเท่านั้น (เว้นว่างได้ถ้ายังไม่ตั้งตอนนี้)';
    } else {
        $db = getDB();
        $check = $db->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
        $check->execute(['u' => $username]);

        if ((int) $check->fetchColumn() > 0) {
            $error = 'มีชื่อผู้ใช้นี้ในระบบแล้ว กรุณาใช้ชื่ออื่น';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pinHash = $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null;
            $stmt = $db->prepare('INSERT INTO users (username, password_hash, full_name, role, pin_hash) VALUES (:u, :h, :f, :r, :p)');
            $stmt->execute(['u' => $username, 'h' => $hash, 'f' => $fullName, 'r' => $role, 'p' => $pinHash]);

            header('Location: index.php');
            exit;
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('เพิ่มพนักงาน'); ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:440px;">
    <div class="card-body">
        <form method="post" action="create.php">
            <div class="mb-3">
                <label class="form-label">ชื่อ-นามสกุล</label>
                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">ชื่อผู้ใช้ (username)</label>
                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">บทบาท</label>
                <select name="role" class="form-select" required>
                    <option value="staff">พนักงานทั่วไป</option>
                    <option value="cashier">แคชเชียร์</option>
                    <option value="kitchen">พนักงานครัว</option>
                    <option value="manager">ผู้จัดการ</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">ตั้งรหัสผ่านเริ่มต้น (อย่างน้อย 8 ตัวอักษร)</label>
                <input type="password" name="password" class="form-control" required minlength="8">
            </div>
            <div class="mb-3">
                <label class="form-label">ตั้ง PIN สำหรับสลับพนักงานเร็ว (ไม่บังคับ ตั้งทีหลังได้)</label>
                <input type="password" name="pin" class="form-control" inputmode="numeric" pattern="\d{4,6}" placeholder="ตัวเลข 4-6 หลัก">
            </div>
            <div class="form-text mb-3">แจ้งรหัสผ่านให้พนักงานโดยตรง</div>
            <button type="submit" class="btn btn-dark w-100"><i class="ti ti-user-plus"></i> เพิ่มพนักงาน</button>
        </form>
    </div>
</div>

<p class="mt-3"><a href="index.php"><i class="ti ti-arrow-left"></i> กลับไปหน้ารายชื่อผู้ใช้งาน</a></p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
