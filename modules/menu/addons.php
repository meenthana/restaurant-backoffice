<?php
// ตั้งตัวเลือกเสริมของเมนู และกลุ่มตัวเลือก (เช่น เนื้อสัตว์ เลือกได้หลายอย่าง)
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Addon.php';
require_once __DIR__ . '/../../classes/AddonGroup.php';
require_once __DIR__ . '/../../classes/Auth.php';
require_once __DIR__ . '/../../classes/Security.php';
Auth::requireAnyRole(['owner','manager']);

$errors = [];
$groupsReady = AddonGroup::ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::verifyCsrf();
    $action = $_POST['action'] ?? '';
    $groupId = (int) ($_POST['group_id'] ?? 0) ?: null;
    $sort = (int) ($_POST['sort_order'] ?? 0);

    try {
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $price = (float) ($_POST['extra_price'] ?? 0);
            if ($name === '') throw new Exception('กรุณากรอกชื่อตัวเลือกเสริม');
            if ($price < 0) throw new Exception('ราคาต้องไม่ติดลบ (ใส่ 0 ได้ถ้าไม่คิดเงินเพิ่ม)');
            $newId = Addon::create($name, $price, null, null);
            Addon::setGroup($newId, $groupId, $sort);
            header('Location: addon_effects.php?addon_id=' . $newId . '&added=1');
            exit;
        } elseif ($action === 'update') {
            $addonId = (int) ($_POST['addon_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $price = (float) ($_POST['extra_price'] ?? 0);
            if ($name === '') throw new Exception('กรุณากรอกชื่อตัวเลือกเสริม');
            if ($price < 0) throw new Exception('ราคาต้องไม่ติดลบ');
            $old = Addon::find($addonId);
            Addon::update($addonId, $name, $price, $old['ingredient_id'] ?? null, isset($old['ingredient_qty']) ? (float) $old['ingredient_qty'] : null);
            Addon::setGroup($addonId, $groupId, $sort);
            header('Location: addons.php?updated=1');
            exit;
        } elseif ($action === 'delete') {
            $result = Addon::delete((int) ($_POST['addon_id'] ?? 0));
            header('Location: addons.php?' . ($result === 'deleted' ? 'deleted=1' : 'deactivated=1'));
            exit;
        } elseif ($action === 'toggle') {
            Addon::toggleActive((int) ($_POST['addon_id'] ?? 0));
            header('Location: addons.php?toggled=1');
            exit;
        } elseif ($action === 'group_save' && $groupsReady) {
            AddonGroup::save($groupId, (string) ($_POST['group_name'] ?? ''), (int) ($_POST['min_select'] ?? 0),
                             (int) ($_POST['max_select'] ?? 0), $sort);
            header('Location: addons.php?updated=1');
            exit;
        } elseif ($action === 'group_delete' && $groupsReady && $groupId) {
            AddonGroup::delete($groupId);
            header('Location: addons.php?updated=1');
            exit;
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

$addons = Addon::allIncludingInactive();
$groups = AddonGroup::all();
$groupName = [];
foreach ($groups as $g) $groupName[(int) $g['group_id']] = $g['name'];
if ($groupsReady) {
    usort($addons, fn($a, $b) => [!$a['is_active'], $a['group_id'] === null, (int) $a['group_id'], (int) $a['sort_order'], $a['name']]
                             <=> [!$b['is_active'], $b['group_id'] === null, (int) $b['group_id'], (int) $b['sort_order'], $b['name']]);
}

require_once __DIR__ . '/../../includes/header.php';

function groupSelect(array $groups, $current): string
{
    $html = '<select name="group_id" class="form-select form-select-sm" aria-label="กลุ่ม"><option value="">ไม่มีกลุ่ม</option>';
    foreach ($groups as $g) {
        $sel = (int) $current === (int) $g['group_id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $g['group_id'] . '"' . $sel . '>' . htmlspecialchars($g['name']) . '</option>';
    }
    return $html . '</select>';
}
?>

<?php pageHeader('ตัวเลือกเสริม', 'เช่น ไข่ดาว พิเศษ หรือเนื้อสัตว์ของเมนูตามสั่ง · จัดเป็นกลุ่มเพื่อกำหนดจำนวนที่เลือกได้'); ?>

<?php if (isset($_GET['added'])): ?><div class="alert alert-success">เพิ่มตัวเลือกเสริมสำเร็จแล้ว</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-success">บันทึกแล้ว</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">ลบตัวเลือกเสริมสำเร็จแล้ว</div><?php endif; ?>
<?php if (isset($_GET['deactivated'])): ?>
    <div class="alert alert-warning">ตัวเลือกเสริมนี้เคยถูกใช้ในออเดอร์แล้ว จึงลบไม่ได้ ระบบปิดใช้งานให้แทน (ไม่โผล่ให้เลือกตอนสั่งใหม่อีก)</div>
<?php endif; ?>
<?php if (isset($_GET['toggled'])): ?><div class="alert alert-success">เปลี่ยนสถานะการใช้งานสำเร็จแล้ว</div><?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if (!$groupsReady): ?>
    <div class="alert alert-warning">ยังไม่มีกลุ่มตัวเลือก กรุณารัน <code>sql/migration_v3_0_counter_diy.sql</code> ก่อน</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white fw-semibold">ตัวเลือกเสริมทั้งหมด</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>ชื่อ</th><th>ราคาเพิ่ม</th><?php if ($groupsReady): ?><th>กลุ่ม</th><th style="width:80px">ลำดับ</th><?php endif; ?><th>ผลต่อสต๊อก</th><th>สถานะ</th><th style="width:110px;">จัดการ</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$addons): ?>
                        <tr><td colspan="7" class="text-muted text-center py-4">ยังไม่มีตัวเลือกเสริมในระบบ</td></tr>
                        <?php endif; ?>
                        <?php foreach ($addons as $a): $fid = 'addon-form-' . (int) $a['addon_id']; ?>
                        <tr class="<?= $a['is_active'] ? '' : 'table-secondary text-muted' ?>">
                            <td style="min-width:130px;">
                                <form method="post" action="addons.php" id="<?= $fid ?>"><?= Security::csrfInput() ?><input type="hidden" name="addon_id" value="<?= (int) $a['addon_id'] ?>"></form>
                                <input type="text" name="name" form="<?= $fid ?>" class="form-control form-control-sm" value="<?= htmlspecialchars($a['name']) ?>" aria-label="ชื่อ">
                            </td>
                            <td style="min-width:90px;"><input type="number" step="0.01" min="0" name="extra_price" form="<?= $fid ?>" class="form-control form-control-sm" value="<?= $a['extra_price'] ?>" aria-label="ราคาเพิ่ม"></td>
                            <?php if ($groupsReady): ?>
                            <td style="min-width:120px;"><?= str_replace('<select ', '<select form="' . $fid . '" ', groupSelect($groups, $a['group_id'] ?? null)) ?></td>
                            <td><input type="number" name="sort_order" form="<?= $fid ?>" class="form-control form-control-sm" value="<?= (int) ($a['sort_order'] ?? 0) ?>" aria-label="ลำดับ"></td>
                            <?php endif; ?>
                            <td class="small">
                                <?php $sum = Addon::effectSummary((int) $a['addon_id']); ?>
                                <?= $sum !== '' ? htmlspecialchars($sum) : '<span class="text-muted">ไม่กระทบสต๊อก</span>' ?>
                                <a href="addon_effects.php?addon_id=<?= $a['addon_id'] ?>" class="ms-1"><i class="ti ti-edit"></i> ตั้งค่า</a>
                            </td>
                            <td><?= statusToggle('addons.php', ['action' => 'toggle', 'addon_id' => (int) $a['addon_id']], (bool) $a['is_active'], 'ใช้งาน', 'ปิดใช้งาน') ?></td>
                            <td class="text-nowrap">
                                <button type="submit" form="<?= $fid ?>" name="action" value="update" class="btn btn-sm btn-outline-dark" title="บันทึก" aria-label="บันทึก"><i class="ti ti-device-floppy"></i></button>
                                <button type="submit" form="<?= $fid ?>" name="action" value="delete" class="btn btn-sm btn-outline-danger" title="ลบ" aria-label="ลบ"
                                        onclick="return confirm('ยืนยันลบ &quot;<?= htmlspecialchars(addslashes($a['name'])) ?>&quot; ใช่ไหม?');"><i class="ti ti-trash"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="small text-muted mt-2">เลือกว่าเมนูไหนใช้ตัวเลือกใดได้ที่หน้า <a href="index.php">แก้ไขเมนู</a> (ติ๊กตัวเลือกเสริมของเมนูนั้น)</p>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header bg-white fw-semibold"><i class="ti ti-plus"></i> เพิ่มตัวเลือกเสริมใหม่</div>
            <div class="card-body">
                <form method="post" action="addons.php">
                    <?= Security::csrfInput() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label" for="new-addon-name">ชื่อ</label>
                        <input type="text" id="new-addon-name" name="name" class="form-control" placeholder="เช่น หมูกรอบ" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new-addon-price">ราคาที่บวกเพิ่ม (บาท)</label>
                        <input type="number" id="new-addon-price" step="0.01" min="0" name="extra_price" class="form-control" value="0" required>
                    </div>
                    <?php if ($groupsReady): ?>
                    <div class="mb-3">
                        <label class="form-label">กลุ่ม</label>
                        <?= groupSelect($groups, null) ?>
                    </div>
                    <?php endif; ?>
                    <div class="form-text mb-3">บันทึกแล้วจะไปหน้าตั้งค่าว่าตัวเลือกนี้ใช้วัตถุดิบเพิ่ม หรือตัดวัตถุดิบออก</div>
                    <button type="submit" class="btn btn-dark w-100"><i class="ti ti-device-floppy"></i> บันทึก</button>
                </form>
            </div>
        </div>

        <?php if ($groupsReady): ?>
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="ti ti-stack-2"></i> กลุ่มตัวเลือก</div>
            <div class="card-body">
                <p class="small text-muted">เลือกขั้นต่ำ 0 = ไม่บังคับ · เลือกได้สูงสุด 0 = ไม่จำกัด · ขั้นต่ำบังคับเฉพาะเมนูตามสั่ง (DIY)</p>
                <?php foreach ($groups as $g): $gf = 'group-form-' . (int) $g['group_id']; ?>
                <form method="post" action="addons.php" class="border rounded p-2 mb-2" id="<?= $gf ?>">
                    <?= Security::csrfInput() ?>
                    <input type="hidden" name="group_id" value="<?= (int) $g['group_id'] ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-12"><label class="form-label small mb-0" for="<?= $gf ?>-name">ชื่อกลุ่ม</label>
                            <input id="<?= $gf ?>-name" name="group_name" class="form-control form-control-sm" value="<?= htmlspecialchars($g['name']) ?>"></div>
                        <div class="col-4"><label class="form-label small mb-0" for="<?= $gf ?>-min">ขั้นต่ำ</label>
                            <input id="<?= $gf ?>-min" type="number" min="0" name="min_select" class="form-control form-control-sm" value="<?= (int) $g['min_select'] ?>"></div>
                        <div class="col-4"><label class="form-label small mb-0" for="<?= $gf ?>-max">สูงสุด</label>
                            <input id="<?= $gf ?>-max" type="number" min="0" name="max_select" class="form-control form-control-sm" value="<?= (int) $g['max_select'] ?>"></div>
                        <div class="col-4"><label class="form-label small mb-0" for="<?= $gf ?>-sort">ลำดับ</label>
                            <input id="<?= $gf ?>-sort" type="number" name="sort_order" class="form-control form-control-sm" value="<?= (int) $g['sort_order'] ?>"></div>
                        <div class="col-12 d-flex justify-content-between align-items-center">
                            <span class="small text-muted"><?= htmlspecialchars(AddonGroup::label($g)) ?> · <?= (int) $g['addon_count'] ?> ตัวเลือก</span>
                            <span class="text-nowrap">
                                <button name="action" value="group_save" class="btn btn-sm btn-outline-dark" aria-label="บันทึกกลุ่ม"><i class="ti ti-device-floppy"></i></button>
                                <button name="action" value="group_delete" class="btn btn-sm btn-outline-danger" aria-label="ลบกลุ่ม"
                                        onclick="return confirm('ลบกลุ่มนี้? ตัวเลือกในกลุ่มยังอยู่ แค่ไม่มีกลุ่ม')"><i class="ti ti-trash"></i></button>
                            </span>
                        </div>
                    </div>
                </form>
                <?php endforeach; ?>
                <form method="post" action="addons.php" class="border border-dashed rounded p-2">
                    <?= Security::csrfInput() ?>
                    <input type="hidden" name="action" value="group_save">
                    <div class="row g-2 align-items-end">
                        <div class="col-12"><label class="form-label small mb-0" for="new-group-name">เพิ่มกลุ่มใหม่</label>
                            <input id="new-group-name" name="group_name" class="form-control form-control-sm" placeholder="เช่น ผัก" required></div>
                        <div class="col-4"><label class="form-label small mb-0" for="new-group-min">ขั้นต่ำ</label><input id="new-group-min" type="number" min="0" name="min_select" class="form-control form-control-sm" value="0"></div>
                        <div class="col-4"><label class="form-label small mb-0" for="new-group-max">สูงสุด</label><input id="new-group-max" type="number" min="0" name="max_select" class="form-control form-control-sm" value="0"></div>
                        <div class="col-4"><button class="btn btn-sm btn-dark w-100"><i class="ti ti-plus"></i> เพิ่ม</button></div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<p class="mt-3"><a href="index.php"><i class="ti ti-arrow-left"></i> กลับไปหน้ารายการเมนู</a></p>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
