<?php
// จัดการหมวดหมู่เมนู: เพิ่ม เปลี่ยนชื่อ เรียงลำดับ ลบ
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Menu.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireLogin();

$cats = Menu::categories();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::verifyCsrf();
    $do = $_POST['do'] ?? '';
    $i = (int) ($_POST['i'] ?? -1);
    $name = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
    $counts = Menu::categoryCounts();
    try {
        if ($do === 'add') {
            if ($name === '' || mb_strlen($name) > 50) throw new Exception('กรุณากรอกชื่อหมวด (ไม่เกิน 50 ตัวอักษร)');
            if (in_array($name, $cats, true)) throw new Exception('มีหมวด "' . $name . '" อยู่แล้ว');
            $cats[] = $name;
            $msg = 'เพิ่มหมวด "' . $name . '" แล้ว';
        } elseif (!isset($cats[$i])) {
            throw new Exception('ไม่พบหมวดนี้');
        } elseif ($do === 'rename') {
            if ($name === '' || mb_strlen($name) > 50) throw new Exception('กรุณากรอกชื่อหมวด (ไม่เกิน 50 ตัวอักษร)');
            if ($name !== $cats[$i] && in_array($name, $cats, true)) throw new Exception('มีหมวด "' . $name . '" อยู่แล้ว');
            Menu::renameCategory($cats[$i], $name);
            $msg = 'เปลี่ยนชื่อหมวดแล้ว เมนูในหมวดนี้ย้ายตามอัตโนมัติ';
            $cats[$i] = $name;
        } elseif ($do === 'up' || $do === 'down') {
            $j = $do === 'up' ? $i - 1 : $i + 1;
            if (isset($cats[$j])) [$cats[$i], $cats[$j]] = [$cats[$j], $cats[$i]];
            $msg = 'เรียงลำดับหมวดแล้ว';
        } elseif ($do === 'delete') {
            if (!empty($counts[$cats[$i]])) throw new Exception('ยังมีเมนูในหมวดนี้ ' . $counts[$cats[$i]] . ' รายการ ย้ายเมนูไปหมวดอื่นก่อนจึงจะลบได้');
            $msg = 'ลบหมวด "' . $cats[$i] . '" แล้ว';
            array_splice($cats, $i, 1);
        } else {
            throw new Exception('คำสั่งไม่ถูกต้อง');
        }
        Menu::saveCategories($cats, Auth::id());
        header('Location: categories.php?msg=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
$counts = Menu::categoryCounts();
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

require_once __DIR__ . '/../../includes/header.php';
pageHeader('หมวดหมู่เมนู', 'ลำดับในหน้านี้คือลำดับที่แสดงบนตู้และหน้าสั่งออนไลน์',
    '<a href="index.php" class="btn btn-outline-secondary"><i class="ti ti-arrow-left"></i> กลับรายการเมนู</a>');
?>
<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><?= $e($_GET['msg']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

<form method="post" class="card mb-3"><div class="card-body d-flex gap-2 flex-wrap align-items-end">
    <?= Security::csrfInput() ?><input type="hidden" name="do" value="add">
    <div class="flex-grow-1"><label class="form-label">เพิ่มหมวดใหม่</label>
        <input type="text" name="name" class="form-control" maxlength="50" placeholder="เช่น ข้าวผัด, เครื่องดื่ม" required></div>
    <button class="btn btn-dark"><i class="ti ti-plus"></i> เพิ่มหมวด</button>
</div></form>

<div class="card"><div class="table-responsive">
<table class="table align-middle mb-0">
    <thead class="table-light"><tr><th style="width:90px">ลำดับ</th><th>ชื่อหมวด</th><th>จำนวนเมนู</th><th class="text-end">จัดการ</th></tr></thead>
    <tbody>
    <?php if (!$cats): ?><tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีหมวดหมู่</td></tr><?php endif; ?>
    <?php foreach ($cats as $i => $cat): $n = (int) ($counts[$cat] ?? 0); ?>
    <tr>
        <td class="text-nowrap">
            <form method="post" class="d-inline"><?= Security::csrfInput() ?><input type="hidden" name="i" value="<?= $i ?>">
                <button name="do" value="up" class="btn btn-sm btn-outline-secondary" title="เลื่อนขึ้น" <?= $i === 0 ? 'disabled' : '' ?>><i class="ti ti-arrow-up"></i></button>
                <button name="do" value="down" class="btn btn-sm btn-outline-secondary" title="เลื่อนลง" <?= $i === count($cats) - 1 ? 'disabled' : '' ?>><i class="ti ti-arrow-down"></i></button>
            </form>
        </td>
        <td>
            <form method="post" class="d-flex gap-2"><?= Security::csrfInput() ?><input type="hidden" name="i" value="<?= $i ?>">
                <input type="text" name="name" class="form-control form-control-sm" maxlength="50" value="<?= $e($cat) ?>" required style="max-width:260px">
                <button name="do" value="rename" class="btn btn-sm btn-outline-dark" title="บันทึกชื่อ"><i class="ti ti-device-floppy"></i></button>
            </form>
        </td>
        <td><span class="badge text-bg-light border"><?= $n ?> เมนู</span></td>
        <td class="text-end">
            <form method="post" class="d-inline" onsubmit="return confirm('ลบหมวด <?= $e(addslashes($cat)) ?>?')"><?= Security::csrfInput() ?><input type="hidden" name="i" value="<?= $i ?>">
                <button name="do" value="delete" class="btn btn-sm btn-outline-danger" title="<?= $n ? 'ย้ายเมนูออกก่อนจึงจะลบได้' : 'ลบหมวด' ?>" <?= $n ? 'disabled' : '' ?>><i class="ti ti-trash"></i></button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
