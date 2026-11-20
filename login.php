<?php
// หน้าล็อกอินพนักงาน
require_once __DIR__ . '/classes/Auth.php';
Auth::boot();

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } elseif (Auth::attempt($username, $password)) {
        $target = match (Auth::role()) {
            'kitchen' => 'modules/kitchen/index.php',
            'owner', 'manager' => 'modules/dashboard/index.php',
            default => 'modules/orders/index.php',
        };
        header('Location: ' . $target);
        exit;
    } else {
        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ — ระบบจัดการหลังบ้านร้านอาหาร</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.44.0/dist/tabler-icons.min.css" rel="stylesheet">
</head>
<body class="bg-dark d-flex align-items-center" style="min-height:100vh;">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-11 col-sm-8 col-md-5 col-lg-4">
            <div class="card shadow-lg border-0">
                <div class="card-body p-4">
                    <h4 class="fw-bold mb-0"><i class="ti ti-chef-hat"></i> ครัวธนุรเวท</h4>
                    <p class="text-muted small mb-3">ระบบหลังบ้านร้านอาหาร</p>

                    <h5 class="mb-1">เข้าสู่ระบบ</h5>
                    <p class="text-muted small mb-3">สำหรับพนักงานและเจ้าของร้าน</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="post" action="login.php">
                        <div class="mb-3">
                            <label for="username" class="form-label"><i class="ti ti-user"></i> ชื่อผู้ใช้</label>
                            <input type="text" class="form-control" id="username" name="username"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label"><i class="ti ti-lock"></i> รหัสผ่าน</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-dark w-100"><i class="ti ti-login-2"></i> เข้าสู่ระบบ</button>
                    </form>
                </div>
            </div>
            <p class="text-center text-light small mt-3 opacity-75">ระบบจัดการหลังบ้านร้านอาหาร — ใช้ภายในร้านเท่านั้น</p>
        </div>
    </div>
</div>
</body>
</html>
