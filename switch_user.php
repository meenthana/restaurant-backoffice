<?php
// สลับพนักงานด้วย PIN
require_once __DIR__ . '/classes/Auth.php';
Auth::boot();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$staff = Auth::pinLoginableStaff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $pin = $_POST['pin'] ?? '';

    if (Auth::switchViaPin($userId, $pin)) {
        header('Location: index.php');
        exit;
    }
    $error = 'PIN ไม่ถูกต้อง กรุณาลองใหม่';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สลับพนักงาน</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.44.0/dist/tabler-icons.min.css" rel="stylesheet">
    <style>
        body { background:#16302B; min-height:100vh; }
        .staff-card { cursor:pointer; border:2px solid transparent; transition:.15s; }
        .staff-card:hover, .staff-card.selected { border-color:#D89B3C; background:#FFF8EA; }
        .avatar-lg { width:56px; height:56px; border-radius:50%; background:#16302B; color:#D89B3C; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:22px; }
        .pin-pad button { font-size:26px; padding:18px 0; }
        #pinDisplay { letter-spacing:14px; font-size:32px; }
    </style>
</head>
<body class="d-flex align-items-center py-4">
<div class="container" style="max-width:520px;">
    <div class="card shadow-lg border-0">
        <div class="card-body p-4">
            <h1 class="h4 text-center mb-1"><i class="ti ti-users"></i> สลับพนักงาน</h1>
            <p class="text-muted text-center small mb-3">เลือกชื่อแล้วกรอก PIN ของตัวเอง</p>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2 text-center"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!$staff): ?>
                <div class="alert alert-warning">ยังไม่มีพนักงานที่ตั้งค่า PIN ไว้ — ไปตั้งค่าได้ที่หน้าจัดการพนักงาน</div>
            <?php else: ?>

            <form method="post" action="switch_user.php" id="pinForm">
                <input type="hidden" name="user_id" id="selectedUserId" value="">
                <input type="hidden" name="pin" id="pinValue" value="">

                <div id="staffStep">
                    <div class="row g-2 mb-3">
                        <?php foreach ($staff as $s): ?>
                        <div class="col-4">
                            <div class="staff-card card text-center p-2" data-user-id="<?= $s['user_id'] ?>" data-user-name="<?= htmlspecialchars($s['full_name']) ?>" onclick="selectStaff(this)">
                                <div class="avatar-lg mx-auto mb-1"><?= htmlspecialchars(mb_substr($s['full_name'], 0, 1)) ?></div>
                                <div class="small fw-semibold text-truncate"><?= htmlspecialchars($s['full_name']) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div id="pinStep" style="display:none;">
                    <div class="text-center mb-2 fw-semibold" id="selectedName"></div>
                    <div id="pinDisplay" class="text-center font-monospace mb-3">••••</div>
                    <div class="row g-2 pin-pad">
                        <?php foreach ([1,2,3,4,5,6,7,8,9] as $n): ?>
                        <div class="col-4"><button type="button" class="btn btn-outline-dark w-100" onclick="pressDigit('<?= $n ?>')"><?= $n ?></button></div>
                        <?php endforeach; ?>
                        <div class="col-4"><button type="button" class="btn btn-outline-secondary w-100" onclick="backToStaff()"><i class="ti ti-arrow-left"></i></button></div>
                        <div class="col-4"><button type="button" class="btn btn-outline-dark w-100" onclick="pressDigit('0')">0</button></div>
                        <div class="col-4"><button type="button" class="btn btn-outline-danger w-100" onclick="clearPin()"><i class="ti ti-backspace"></i></button></div>
                    </div>
                    <button type="submit" class="btn btn-dark w-100 mt-3" id="submitBtn" disabled>ยืนยัน</button>
                </div>
            </form>
            <?php endif; ?>

            <a href="index.php" class="btn btn-link w-100 mt-2 text-muted">ยกเลิก กลับหน้าหลัก</a>
        </div>
    </div>
</div>

<script>
let pin = '';

function selectStaff(el) {
    document.querySelectorAll('.staff-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('selectedUserId').value = el.dataset.userId;
    document.getElementById('selectedName').textContent = el.dataset.userName;
    document.getElementById('staffStep').style.display = 'none';
    document.getElementById('pinStep').style.display = 'block';
    pin = '';
    renderPin();
}

function backToStaff() {
    document.getElementById('staffStep').style.display = 'block';
    document.getElementById('pinStep').style.display = 'none';
    pin = '';
    renderPin();
}

function pressDigit(d) {
    if (pin.length >= 6) return;
    pin += d;
    renderPin();
}

function clearPin() {
    pin = pin.slice(0, -1);
    renderPin();
}

function renderPin() {
    document.getElementById('pinDisplay').textContent = '•'.repeat(pin.length).padEnd(4, '◦') || '◦◦◦◦';
    document.getElementById('pinValue').value = pin;
    document.getElementById('submitBtn').disabled = pin.length < 4;
}
</script>
</body>
</html>
