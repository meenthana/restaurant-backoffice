<?php
// ตัวเลือกเสริมของเมนู (เช่น ไข่ดาว) และวัตถุดิบที่ใช้
require_once __DIR__ . '/../config/database.php';

class Addon
{
    public static function allActive(): array
    {
        $db = getDB();
        return $db->query(
            'SELECT a.*, i.name AS ingredient_name, i.unit
             FROM addons a
             LEFT JOIN ingredients i ON i.ingredient_id = a.ingredient_id
             WHERE a.is_active = 1
             ORDER BY a.name'
        )->fetchAll();
    }

    // ใช้สำหรับหน้าจัดการ Add-on — ต้องเห็นทั้งตัวที่ปิดใช้งานไปแล้วด้วย
    public static function allIncludingInactive(): array
    {
        $db = getDB();
        return $db->query(
            'SELECT a.*, i.name AS ingredient_name, i.unit
             FROM addons a
             LEFT JOIN ingredients i ON i.ingredient_id = a.ingredient_id
             ORDER BY a.is_active DESC, a.name'
        )->fetchAll();
    }

    // Add-on ที่เปิดให้เลือกได้สำหรับเมนูนี้โดยเฉพาะ
    public static function forMenu(int $menuId): array
    {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT a.*
             FROM addons a
             JOIN menu_addons ma ON ma.addon_id = a.addon_id
             WHERE ma.menu_id = :menu_id AND a.is_active = 1
             ORDER BY a.name'
        );
        $stmt->execute(['menu_id' => $menuId]);
        return $stmt->fetchAll();
    }

    public static function create(string $name, float $extraPrice, ?int $ingredientId, ?float $ingredientQty): int
    {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO addons (name, extra_price, ingredient_id, ingredient_qty)
             VALUES (:name, :price, :ing_id, :ing_qty)'
        );
        $stmt->execute([
            'name'    => $name,
            'price'   => $extraPrice,
            'ing_id'  => $ingredientId,
            'ing_qty' => $ingredientQty,
        ]);
        return (int) $db->lastInsertId();
    }

    // ผูก Add-on ที่เลือกไว้เข้ากับเมนู (ลบของเดิมทิ้งก่อนแล้วค่อยเพิ่มใหม่ทั้งหมด)
    public static function assignToMenu(int $menuId, array $addonIds): void
    {
        $db = getDB();
        $del = $db->prepare('DELETE FROM menu_addons WHERE menu_id = :menu_id');
        $del->execute(['menu_id' => $menuId]);

        if (empty($addonIds)) {
            return;
        }

        $stmt = $db->prepare('INSERT INTO menu_addons (menu_id, addon_id) VALUES (:menu_id, :addon_id)');
        foreach ($addonIds as $addonId) {
            $stmt->execute(['menu_id' => $menuId, 'addon_id' => (int) $addonId]);
        }
    }

    public static function find(int $addonId): ?array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM addons WHERE addon_id = :id');
        $stmt->execute(['id' => $addonId]);
        $addon = $stmt->fetch();
        return $addon ?: null;
    }

    public static function update(int $addonId, string $name, float $extraPrice, ?int $ingredientId, ?float $ingredientQty): void
    {
        $db = getDB();
        $stmt = $db->prepare(
            'UPDATE addons SET name = :name, extra_price = :price, ingredient_id = :ing_id, ingredient_qty = :ing_qty
             WHERE addon_id = :id'
        );
        $stmt->execute([
            'name'    => $name,
            'price'   => $extraPrice,
            'ing_id'  => $ingredientId,
            'ing_qty' => $ingredientQty,
            'id'      => $addonId,
        ]);
    }

    public static function toggleActive(int $addonId): void
    {
        $db = getDB();
        $stmt = $db->prepare('UPDATE addons SET is_active = NOT is_active WHERE addon_id = :id');
        $stmt->execute(['id' => $addonId]);
    }

    // ลบ Add-on จริงถ้ายังไม่เคยถูกใช้ในออเดอร์ไหนเลย
    public static function delete(int $addonId): string
    {
        $db = getDB();
        try {
            $stmt = $db->prepare('DELETE FROM addons WHERE addon_id = :id');
            $stmt->execute(['id' => $addonId]);
            return 'deleted';
        } catch (PDOException $e) {
            // SQLSTATE 23000 = Integrity constraint violation (ถูกใช้ในออเดอร์ไปแล้ว)
            self::toggleActiveTo($addonId, false);
            return 'deactivated';
        }
    }

    private static function toggleActiveTo(int $addonId, bool $active): void
    {
        $db = getDB();
        $stmt = $db->prepare('UPDATE addons SET is_active = :active WHERE addon_id = :id');
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $addonId]);
    }

    // ผลต่อสต๊อกของตัวเลือกเสริม (เพิ่มวัตถุดิบ / ไม่ใส่วัตถุดิบ)
    public static function effects(int $addonId): array
    {
        $stmt = getDB()->prepare(
            'SELECT e.*, i.name AS ingredient_name, i.unit, i.ingredient_type
             FROM addon_ingredient_effects e JOIN ingredients i ON i.ingredient_id = e.ingredient_id
             WHERE e.addon_id = :id ORDER BY e.effect, i.name'
        );
        $stmt->execute(['id' => $addonId]);
        return $stmt->fetchAll();
    }

    // ข้อความสรุปสั้นๆ เช่น "ไข่ไก่ +1 ฟอง, ไม่ใส่หอมใหญ่"
    public static function effectSummary(int $addonId): string
    {
        $parts = [];
        foreach (self::effects($addonId) as $e) {
            $parts[] = $e['effect'] === 'remove'
                ? 'ไม่ใส่' . $e['ingredient_name']
                : $e['ingredient_name'] . ' +' . rtrim(rtrim(number_format((float) $e['quantity'], 2, '.', ''), '0'), '.') . ' ' . $e['unit'];
        }
        return implode(', ', $parts);
    }

    public static function saveEffect(int $addonId, int $ingredientId, string $effect, ?float $quantity): void
    {
        $effect = $effect === 'remove' ? 'remove' : 'add';
        if ($effect === 'add' && (!$quantity || $quantity <= 0)) {
            throw new Exception('ถ้าเลือก "ใช้เพิ่ม" ต้องใส่ปริมาณมากกว่า 0');
        }
        getDB()->prepare(
            'INSERT INTO addon_ingredient_effects (addon_id, ingredient_id, effect, quantity)
             VALUES (:a, :i, :e, :q)
             ON DUPLICATE KEY UPDATE effect = VALUES(effect), quantity = VALUES(quantity)'
        )->execute(['a' => $addonId, 'i' => $ingredientId, 'e' => $effect, 'q' => $effect === 'add' ? $quantity : null]);
    }

    public static function deleteEffect(int $effectId): void
    {
        getDB()->prepare('DELETE FROM addon_ingredient_effects WHERE effect_id = :id')->execute(['id' => $effectId]);
    }
}
