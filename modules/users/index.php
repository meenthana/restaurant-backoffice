<?php
// บัญชีพนักงาน
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Auth.php';
Auth::requireRole('owner');

$db = getDB();
$users = $db->query('SELECT * FROM users ORDER BY created_at')->fetchAll();
$roleLabels = ['owner'=>'เจ้าของร้าน','manager'=>'ผู้จัดการ','cashier'=>'แคชเชียร์','kitchen'=>'พนักงานครัว','staff'=>'พนักงานทั่วไป'];

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('บัญชีพนักงาน', '', '<a href="create.php" class="btn btn-dark"><i class="ti ti-user-plus"></i> เพิ่มพนักงาน</a>'); ?>

<?php if (isset($_GET['pin_set'])): ?>
    <div class="alert alert-success">ตั้ง PIN สำเร็จแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['pin_cleared'])): ?>
    <div class="alert alert-success">ยกเลิก PIN สำเร็จแล้ว</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>ชื่อผู้ใช้</th><th>ชื่อ-นามสกุล</th><th>บทบาท</th><th>สถานะ</th><th>PIN</th><th>สมัครเมื่อ</th><th>จัดการ</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['full_name']) ?></td>
                    <td><?= $roleLabels[$u['role']] ?? htmlspecialchars($u['role']) ?></td>
                    <td><?= statusToggle('toggle.php', ['user_id' => (int) $u['user_id']], (bool) $u['is_active'], 'ใช้งาน', 'ปิดใช้งาน', $u['is_active'] ? 'ปิดใช้งานบัญชีนี้? พนักงานจะเข้าสู่ระบบไม่ได้' : '', $u['role'] === 'owner') ?></td>
                    <td><span class="badge <?= !empty($u['pin_hash']) ? 'text-bg-info' : 'text-bg-light border' ?>"><?= !empty($u['pin_hash']) ? 'ตั้งแล้ว' : 'ยังไม่ตั้ง' ?></span></td>
                    <td><?= $u['created_at'] ?></td>
                    <td class="text-nowrap">
                        <a href="set_pin.php?user_id=<?= $u['user_id'] ?>" class="btn btn-sm btn-outline-dark"><i class="ti ti-key"></i> PIN</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
