<?php
// ตั้ง PIN พนักงาน
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
Auth::requireRole('owner');

$userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$db = getDB();
$stmt = $db->prepare('SELECT * FROM users WHERE user_id = :id');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    die('ไม่พบผู้ใช้งานนี้');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'clear') {
        Auth::clearPin($userId);
        header('Location: index.php?pin_cleared=1');
        exit;
    }

    $pin = trim($_POST['pin'] ?? '');
    $pinConfirm = trim($_POST['pin_confirm'] ?? '');

    if (!preg_match('/^\d{4,6}$/', $pin)) {
        $error = 'PIN ต้องเป็นตัวเลข 4-6 หลักเท่านั้น';
    } elseif ($pin !== $pinConfirm) {
        $error = 'PIN ทั้งสองช่องไม่ตรงกัน';
    } else {
        Auth::setPin($userId, $pin);
        header('Location: index.php?pin_set=1');
        exit;
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('ตั้ง PIN', $user['full_name'] . ' · ใช้สลับพนักงานที่หน้าเครื่อง'); ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!empty($user['pin_hash'])): ?>
    <div class="alert alert-info">พนักงานคนนี้ตั้ง PIN ไว้แล้ว — กรอกใหม่ด้านล่างเพื่อเปลี่ยน หรือกดปุ่มยกเลิก PIN เพื่อลบออก</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <form method="post" action="set_pin.php">
                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                    <div class="mb-3">
                        <label class="form-label">PIN ใหม่ (ตัวเลข 4-6 หลัก)</label>
                        <input type="password" name="pin" class="form-control" inputmode="numeric" pattern="\d{4,6}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ยืนยัน PIN อีกครั้ง</label>
                        <input type="password" name="pin_confirm" class="form-control" inputmode="numeric" pattern="\d{4,6}" required>
                    </div>
                    <button type="submit" class="btn btn-dark w-100"><i class="ti ti-device-floppy"></i> บันทึก PIN</button>
                </form>
            </div>
        </div>
    </div>
    <?php if (!empty($user['pin_hash'])): ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <p class="text-muted small">ยกเลิก PIN ของพนักงานคนนี้ (จะสลับด้วย PIN ไม่ได้อีกจนกว่าจะตั้งใหม่)</p>
                <form method="post" action="set_pin.php" onsubmit="return confirm('ยืนยันยกเลิก PIN ของ <?= htmlspecialchars(addslashes($user['full_name'])) ?> ใช่ไหม?');">
                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="btn btn-outline-danger w-100"><i class="ti ti-trash"></i> ยกเลิก PIN</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<p class="mt-3"><a href="index.php"><i class="ti ti-arrow-left"></i> กลับไปหน้ารายชื่อผู้ใช้งาน</a></p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
