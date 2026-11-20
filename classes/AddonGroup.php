<?php
// กลุ่มตัวเลือกเสริม (เช่น เนื้อสัตว์ เลือกได้หลายอย่าง ต้องเลือกอย่างน้อย 1) ใช้กับเมนูตามสั่ง (DIY)
require_once __DIR__ . '/../config/database.php';

class AddonGroup
{
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try { getDB()->query('SELECT group_id FROM addon_groups LIMIT 1'); getDB()->query('SELECT group_id FROM addons LIMIT 1'); $ok = true; }
            catch (Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    public static function all(): array
    {
        if (!self::ready()) return [];
        return getDB()->query('SELECT g.*, (SELECT COUNT(*) FROM addons a WHERE a.group_id = g.group_id) AS addon_count
                               FROM addon_groups g ORDER BY g.sort_order, g.group_id')->fetchAll();
    }

    public static function save(?int $groupId, string $name, int $min, int $max, int $sort): void
    {
        $name = trim($name);
        if ($name === '') throw new Exception('กรุณากรอกชื่อกลุ่ม');
        $min = max(0, $min); $max = max(0, $max);
        if ($max > 0 && $min > $max) throw new Exception('จำนวนขั้นต่ำต้องไม่มากกว่าจำนวนสูงสุด');
        $db = getDB();
        if ($groupId) {
            $db->prepare('UPDATE addon_groups SET name = ?, min_select = ?, max_select = ?, sort_order = ? WHERE group_id = ?')
               ->execute([$name, $min, $max, $sort, $groupId]);
        } else {
            $db->prepare('INSERT INTO addon_groups (name, min_select, max_select, sort_order) VALUES (?, ?, ?, ?)')
               ->execute([$name, $min, $max, $sort]);
        }
    }

    // ลบกลุ่ม: ตัวเลือกในกลุ่มยังอยู่ แค่ไม่มีกลุ่ม
    public static function delete(int $groupId): void
    {
        $db = getDB();
        $db->prepare('UPDATE addons SET group_id = NULL WHERE group_id = ?')->execute([$groupId]);
        $db->prepare('DELETE FROM addon_groups WHERE group_id = ?')->execute([$groupId]);
    }

    public static function label(array $g): string
    {
        $min = (int) $g['min_select']; $max = (int) $g['max_select'];
        if ($max === 1) return $min >= 1 ? 'ต้องเลือก 1 อย่าง' : 'เลือกได้ 1 อย่าง';
        $txt = $max > 0 ? 'เลือกได้ไม่เกิน ' . $max . ' อย่าง' : 'เลือกได้หลายอย่าง';
        return $min > 0 ? $txt . ' · ต้องเลือกอย่างน้อย ' . $min : $txt;
    }

    // ตัวเลือกของเมนูนี้ จัดเป็นกลุ่ม (ตัวที่ไม่มีกลุ่มอยู่ท้ายสุดในชื่อ "ตัวเลือกอื่น")
    public static function forMenu(int $menuId): array
    {
        $db = getDB();
        $groups = [];
        foreach (self::all() as $g) $groups[(int) $g['group_id']] = $g + ['addons' => []];
        $sql = self::ready()
            ? 'SELECT a.* FROM addons a JOIN menu_addons ma ON ma.addon_id = a.addon_id WHERE ma.menu_id = ? AND a.is_active = 1 ORDER BY a.sort_order, a.name'
            : 'SELECT a.*, NULL AS group_id FROM addons a JOIN menu_addons ma ON ma.addon_id = a.addon_id WHERE ma.menu_id = ? AND a.is_active = 1 ORDER BY a.name';
        $stmt = $db->prepare($sql);
        $stmt->execute([$menuId]);
        $other = ['group_id' => 0, 'name' => 'ตัวเลือกอื่น', 'min_select' => 0, 'max_select' => 0, 'addons' => []];
        foreach ($stmt->fetchAll() as $a) {
            $gid = (int) ($a['group_id'] ?? 0);
            if ($gid && isset($groups[$gid])) $groups[$gid]['addons'][] = $a;
            else $other['addons'][] = $a;
        }
        $out = array_values(array_filter($groups, fn($g) => $g['addons']));
        if ($other['addons']) $out[] = $other;
        return $out;
    }

    // ตรวจจำนวนที่เลือกในแต่ละกลุ่ม · ขั้นต่ำบังคับเฉพาะเมนูตามสั่ง (DIY)
    public static function validate(int $menuId, array $addonIds, bool $enforceMin, string $menuName): void
    {
        if (!self::ready()) return;
        foreach (self::forMenu($menuId) as $g) {
            if (!(int) $g['group_id']) continue;
            $ids = array_map(fn($a) => (int) $a['addon_id'], $g['addons']);
            $n = count(array_intersect($ids, $addonIds));
            if ($enforceMin && $n < (int) $g['min_select']) {
                throw new Exception('"' . $menuName . '" ต้องเลือก' . $g['name'] . 'อย่างน้อย ' . (int) $g['min_select'] . ' อย่าง');
            }
            if ((int) $g['max_select'] > 0 && $n > (int) $g['max_select']) {
                throw new Exception('"' . $menuName . '" เลือก' . $g['name'] . 'ได้ไม่เกิน ' . (int) $g['max_select'] . ' อย่าง');
            }
        }
    }
}
