<?php
// ตั้งตัวเลือกเสริมของเมนู
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Addon.php';

$errors = [];
$deleteResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $price = (float) ($_POST['extra_price'] ?? 0);

        if ($name === '') {
            $errors[] = 'กรุณากรอกชื่อตัวเลือกเสริม';
        } elseif ($price < 0) {
            $errors[] = 'ราคาต้องไม่ติดลบ (ใส่ 0 ได้ถ้าไม่คิดเงินเพิ่ม)';
        } else {
            $newId = Addon::create($name, $price, null, null);
            header('Location: addon_effects.php?addon_id=' . $newId . '&added=1');
            exit;
        }
    } elseif ($action === 'update') {
        $addonId = (int) ($_POST['addon_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $price = (float) ($_POST['extra_price'] ?? 0);

        if ($name === '') {
            $errors[] = 'กรุณากรอกชื่อตัวเลือกเสริม';
        } else {
            Addon::update($addonId, $name, $price, null, null);
            header('Location: addons.php?updated=1');
            exit;
        }
    } elseif ($action === 'delete') {
        $addonId = (int) ($_POST['addon_id'] ?? 0);
        $result = Addon::delete($addonId);
        header('Location: addons.php?' . ($result === 'deleted' ? 'deleted=1' : 'deactivated=1'));
        exit;
    } elseif ($action === 'toggle') {
        $addonId = (int) ($_POST['addon_id'] ?? 0);
        Addon::toggleActive($addonId);
        header('Location: addons.php?toggled=1');
        exit;
    }
}

$addons = Addon::allIncludingInactive();

require_once __DIR__ . '/../../includes/header.php';
?>

<?php pageHeader('ตัวเลือกเสริม', 'เช่น ไข่ดาว พิเศษ ไม่ใส่ผัก'); ?>

<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success">เพิ่มตัวเลือกเสริมสำเร็จแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success">แก้ไขตัวเลือกเสริมสำเร็จแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">ลบตัวเลือกเสริมสำเร็จแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['deactivated'])): ?>
    <div class="alert alert-warning">ตัวเลือกเสริมนี้เคยถูกใช้ในออเดอร์แล้ว จึงลบไม่ได้ — ระบบปิดใช้งานให้แทน (ไม่โผล่ให้เลือกตอนสั่งใหม่อีก)</div>
<?php endif; ?>
<?php if (isset($_GET['toggled'])): ?>
    <div class="alert alert-success">เปลี่ยนสถานะการใช้งานสำเร็จแล้ว</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-white fw-semibold">ตัวเลือกเสริมทั้งหมด</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>ชื่อ</th><th>ราคาเพิ่ม</th><th>ผลต่อสต๊อก</th><th>สถานะ</th><th style="width:190px;">จัดการ</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$addons): ?>
                        <tr><td colspan="5" class="text-muted text-center py-4">ยังไม่มีตัวเลือกเสริมในระบบ</td></tr>
                        <?php endif; ?>
                        <?php foreach ($addons as $a): ?>
                        <tr class="<?= $a['is_active'] ? '' : 'table-secondary text-muted' ?>">
                            <form method="post" action="addons.php">
                                <input type="hidden" name="addon_id" value="<?= $a['addon_id'] ?>">
                                <td style="min-width:130px;"><input type="text" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars($a['name']) ?>"></td>
                                <td style="min-width:100px;"><input type="number" step="0.01" name="extra_price" class="form-control form-control-sm" value="<?= $a['extra_price'] ?>"></td>
                                <td class="small">
                                    <?php $sum = Addon::effectSummary((int) $a['addon_id']); ?>
                                    <?= $sum !== '' ? htmlspecialchars($sum) : '<span class="text-muted">ไม่กระทบสต๊อก</span>' ?>
                                    <a href="addon_effects.php?addon_id=<?= $a['addon_id'] ?>" class="ms-1"><i class="ti ti-edit"></i> ตั้งค่า</a>
                                </td>
                                <td><?= statusToggle('addons.php', ['action' => 'toggle', 'addon_id' => (int) $a['addon_id']], (bool) $a['is_active'], 'ใช้งาน', 'ปิดใช้งาน') ?></td>
                                <td class="text-nowrap">
                                    <button type="submit" name="action" value="update" class="btn btn-sm btn-outline-dark" title="บันทึก"><i class="ti ti-device-floppy"></i></button>
                                    <button type="submit" name="action" value="delete" class="btn btn-sm btn-outline-danger" title="ลบ"
                                            onclick="return confirm('ยืนยันลบ &quot;<?= htmlspecialchars(addslashes($a['name'])) ?>&quot; ใช่ไหม?');"><i class="ti ti-trash"></i></button>
                                </td>
                            </form>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="ti ti-plus"></i> เพิ่มตัวเลือกเสริมใหม่</div>
            <div class="card-body">
                <form method="post" action="addons.php">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">ชื่อ</label>
                        <input type="text" name="name" class="form-control" placeholder="เช่น ไข่ดาว" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ราคาที่บวกเพิ่ม (บาท)</label>
                        <input type="number" step="0.01" name="extra_price" class="form-control" value="0" required>
                    </div>
                    <div class="form-text mb-3">บันทึกแล้วจะไปหน้าตั้งค่าว่าตัวเลือกนี้ใช้วัตถุดิบเพิ่ม หรือตัดวัตถุดิบออก</div>
                    <button type="submit" class="btn btn-dark w-100"><i class="ti ti-device-floppy"></i> บันทึก</button>
                </form>
            </div>
        </div>
    </div>
</div>

<p class="mt-3"><a href="index.php"><i class="ti ti-arrow-left"></i> กลับไปหน้ารายการเมนู</a></p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
