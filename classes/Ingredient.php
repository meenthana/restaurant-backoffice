<?php
// วัตถุดิบ และรายการที่ต่ำกว่าจุดสั่งซื้อ
require_once __DIR__ . '/../config/database.php';

class Ingredient
{
    public static function all(): array
    {
        $db = getDB();
        return $db->query('SELECT * FROM ingredients ORDER BY name')->fetchAll();
    }

    public static function find(int $ingredientId): ?array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM ingredients WHERE ingredient_id = :id');
        $stmt->execute(['id' => $ingredientId]);
        $ing = $stmt->fetch();
        return $ing ?: null;
    }

    public static function update(int $ingredientId, string $name, string $unit, ?float $reorderPoint = null,
                                  string $type = 'main', bool $track = true): void
    {
        $db = getDB();
        $stmt = $db->prepare(
            'UPDATE ingredients SET name = :name, unit = :unit, reorder_point = :rp,
                ingredient_type = :type, track_inventory = :track WHERE ingredient_id = :id'
        );
        $stmt->execute(['name' => $name, 'unit' => $unit, 'rp' => $reorderPoint,
                        'type' => self::cleanType($type), 'track' => $track ? 1 : 0, 'id' => $ingredientId]);
    }

    public static function create(string $name, string $unit, ?float $reorderPoint = null,
                                  string $type = 'main', bool $track = true): int
    {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO ingredients (name, unit, reorder_point, ingredient_type, track_inventory)
             VALUES (:name, :unit, :rp, :type, :track)'
        );
        $stmt->execute(['name' => $name, 'unit' => $unit, 'rp' => $reorderPoint,
                        'type' => self::cleanType($type), 'track' => $track ? 1 : 0]);
        return (int) $db->lastInsertId();
    }

    private static function cleanType(string $type): string
    {
        return in_array($type, ['main', 'secondary', 'garnish'], true) ? $type : 'main';
    }

    // วัตถุดิบที่สต๊อกคงเหลือรวม (ทุกล็อต) ต่ำกว่าหรือเท่ากับ Reorder Point ที่ตั้งไว้
    public static function belowReorderPoint(): array
    {
        $db = getDB();
        // ห่อด้วย subquery เพราะ MariaDB ไม่ยอมให้ ORDER BY อ้างชื่อคอลัมน์ที่คำนวณจาก SUM() ร่วมกับ HAVING
        return $db->query(
            "SELECT * FROM (
                SELECT i.ingredient_id, i.name, i.unit, i.reorder_point,
                       COALESCE(SUM(sb.quantity_remaining), 0) AS current_stock
                FROM ingredients i
                LEFT JOIN stock_batches sb ON sb.ingredient_id = i.ingredient_id AND sb.quantity_remaining > 0
                WHERE i.reorder_point IS NOT NULL
                GROUP BY i.ingredient_id, i.name, i.unit, i.reorder_point
                HAVING current_stock <= i.reorder_point
             ) t
             ORDER BY (t.current_stock / NULLIF(t.reorder_point, 0)) ASC"
        )->fetchAll();
    }
}
