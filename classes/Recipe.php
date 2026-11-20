<?php
// สูตรอาหาร: วัตถุดิบที่ใช้ต่อ 1 จาน
require_once __DIR__ . '/../config/database.php';

class Recipe
{
    // ดึงรายการวัตถุดิบทั้งหมดที่ใช้ในเมนูนี้ (BOM)
    public static function getByMenu(int $menuId): array
    {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT r.*, i.name AS ingredient_name, i.unit, i.ingredient_type, i.track_inventory
             FROM recipe r
             JOIN ingredients i ON i.ingredient_id = r.ingredient_id
             WHERE r.menu_id = :menu_id'
        );
        $stmt->execute(['menu_id' => $menuId]);
        return $stmt->fetchAll();
    }

    // บันทึก BOM ของเมนู จาก array คู่ ingredient_id => quantity_required
    public static function saveForMenu(int $menuId, array $ingredientIds, array $quantities): void
    {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO recipe (menu_id, ingredient_id, quantity_required) VALUES (:menu_id, :ingredient_id, :qty)'
        );

        foreach ($ingredientIds as $i => $ingredientId) {
            $ingredientId = (int) $ingredientId;
            $qty = (float) ($quantities[$i] ?? 0);

            if ($ingredientId <= 0 || $qty <= 0) {
                continue; // ข้ามแถวว่าง
            }

            $stmt->execute(['menu_id' => $menuId, 'ingredient_id' => $ingredientId, 'qty' => $qty]);
        }
    }

    // แทนที่ BOM เดิมทั้งหมดของเมนูนี้ด้วยชุดใหม่ (ใช้ตอนแก้ไขเมนู)
    public static function replaceForMenu(int $menuId, array $ingredientIds, array $quantities): void
    {
        $db = getDB();
        $del = $db->prepare('DELETE FROM recipe WHERE menu_id = :menu_id');
        $del->execute(['menu_id' => $menuId]);

        self::saveForMenu($menuId, $ingredientIds, $quantities);
    }
}
