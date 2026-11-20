<?php
// ติดตั้งระบบครั้งแรก
require_once __DIR__ . '/config/database.php';

$db = getDB();
$userCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($userCount > 0) {
    die('ระบบมีผู้ใช้งานอยู่แล้ว ไม่สามารถใช้หน้านี้ได้อีก กรุณาไปที่ <a href="login.php">login.php</a>');
}

$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if ($username === '' || $fullName === '' || $password === '') {
        $error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
    } elseif (strlen($password) < 8) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    } elseif ($password !== $confirm) {
        $error = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare(
            'INSERT INTO users (username, password_hash, full_name, role) VALUES (:u, :h, :f, :r)'
        );
        $stmt->execute(['u' => $username, 'h' => $hash, 'f' => $fullName, 'r' => 'owner']);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าเริ่มต้น — สร้างบัญชีเจ้าของร้าน</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.44.0/dist/tabler-icons.min.css" rel="stylesheet">
</head>
<body class="bg-dark d-flex align-items-center" style="min-height:100vh;">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-11 col-sm-8 col-md-6 col-lg-5">
            <div class="card shadow-lg border-0">
                <div class="card-body p-4">
                    <h4 class="fw-bold mb-0"><i class="ti ti-chef-hat"></i> ครัวคุณยาย</h4>
                    <p class="text-muted small mb-3">ตั้งค่าเริ่มต้นระบบ</p>

                    <h5 class="mb-1">สร้างบัญชีเจ้าของร้าน</h5>
                    <p class="text-muted small mb-3">ทำได้ครั้งเดียวเท่านั้น — ใช้สร้างบัญชี Owner คนแรกของระบบ</p>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="ti ti-circle-check"></i> สร้างบัญชีสำเร็จแล้ว! <a href="login.php">ไปหน้าล็อกอิน</a>
                            <hr>
                            <small>คำแนะนำ: ลบไฟล์ setup.php ออกจากเซิร์ฟเวอร์เพื่อความปลอดภัย เนื่องจากตอนนี้ระบบมีผู้ใช้งานแล้วและไฟล์นี้จะไม่ทำงานอีกต่อไป</small>
                        </div>
                    <?php else: ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger py-2"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <form method="post" action="setup.php">
                            <div class="mb-3">
                                <label for="full_name" class="form-label"><i class="ti ti-id-badge-2"></i> ชื่อ-นามสกุล</label>
                                <input type="text" class="form-control" id="full_name" name="full_name"
                                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="username" class="form-label"><i class="ti ti-user"></i> ชื่อผู้ใช้ (username)</label>
                                <input type="text" class="form-control" id="username" name="username"
                                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label"><i class="ti ti-lock"></i> รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)</label>
                                <input type="password" class="form-control" id="password" name="password" required minlength="8">
                            </div>
                            <div class="mb-3">
                                <label for="password_confirm" class="form-label"><i class="ti ti-lock-check"></i> ยืนยันรหัสผ่าน</label>
                                <input type="password" class="form-control" id="password_confirm" name="password_confirm" required minlength="8">
                            </div>
                            <button type="submit" class="btn btn-dark w-100"><i class="ti ti-user-plus"></i> สร้างบัญชีเจ้าของร้าน</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
