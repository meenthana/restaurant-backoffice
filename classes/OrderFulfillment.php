<?php
// ตัดสต๊อกตามสูตรของทุกรายการในออเดอร์ (สูตรหลัก + ผลของตัวเลือกเสริม)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/StockBatch.php';
require_once __DIR__ . '/../config/features.php';

class OrderFulfillment
{
    // กลุ่มวัตถุดิบ: main = ของไม่พอห้ามทำ, secondary/garnish = ตัดเท่าที่มี ไม่บล็อกครัว
    public const TYPE_LABELS = [
        'main'      => 'หลัก',
        'secondary' => 'รอง',
        'garnish'   => 'ตกแต่ง',
    ];

    // ตัดสต๊อกตอนครัวเริ่มทำ เรียกซ้ำได้ รายการที่ตัดแล้วจะข้าม
    public static function deductStockForOrder(int $orderId): void
    {
        if (!FEATURE_AUTO_STOCK) return;
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT order_item_id, menu_id, quantity FROM order_items
             WHERE order_id = :id AND stock_deducted_at IS NULL AND voided_at IS NULL'
        );
        $stmt->execute(['id' => $orderId]);

        $mark = $db->prepare('UPDATE order_items SET stock_deducted_at = NOW(), cost_of_goods = :cost WHERE order_item_id = :id');

        foreach ($stmt->fetchAll() as $item) {
            $orderItemId = (int) $item['order_item_id'];
            $qty = (int) $item['quantity'];
            $cost = 0.0;

            foreach (self::ingredientsForItem($orderItemId, (int) $item['menu_id']) as $ing) {
                if (!(int) $ing['track_inventory']) continue;
                $need = $ing['per_dish'] * $qty;
                if ($need <= 0) continue;
                $cost += StockBatch::deductFEFO(
                    $ing['ingredient_id'],
                    $need,
                    $orderId,
                    $orderItemId,
                    'ตัดตามสูตร x' . $qty . ($ing['from_addon'] ? ' (รวมตัวเลือกเสริม)' : ''),
                    $ing['ingredient_type'] !== 'main'
                );
            }
            $mark->execute(['cost' => $cost, 'id' => $orderItemId]);
        }
    }

    // วัตถุดิบที่ใช้ต่อ 1 จาน หลังรวมตัวเลือกเสริมแล้ว: [ingredient_id => [...]]
    public static function ingredientsForItem(int $orderItemId, int $menuId): array
    {
        $db = getDB();
        $result = [];

        $recipe = $db->prepare(
            'SELECT r.ingredient_id, r.quantity_required, i.name, i.unit, i.ingredient_type, i.track_inventory
             FROM recipe r JOIN ingredients i ON i.ingredient_id = r.ingredient_id
             WHERE r.menu_id = :m'
        );
        $recipe->execute(['m' => $menuId]);
        foreach ($recipe->fetchAll() as $r) {
            $result[(int) $r['ingredient_id']] = [
                'ingredient_id'   => (int) $r['ingredient_id'],
                'name'            => $r['name'],
                'unit'            => $r['unit'],
                'ingredient_type' => $r['ingredient_type'],
                'track_inventory' => (int) $r['track_inventory'],
                'per_dish'        => (float) $r['quantity_required'],
                'from_addon'      => false,
            ];
        }

        $effects = $db->prepare(
            'SELECT e.ingredient_id, e.effect, e.quantity, i.name, i.unit, i.ingredient_type, i.track_inventory
             FROM order_item_addons oia
             JOIN addon_ingredient_effects e ON e.addon_id = oia.addon_id
             JOIN ingredients i ON i.ingredient_id = e.ingredient_id
             WHERE oia.order_item_id = :id'
        );
        $effects->execute(['id' => $orderItemId]);
        $removed = [];
        foreach ($effects->fetchAll() as $e) {
            $id = (int) $e['ingredient_id'];
            if ($e['effect'] === 'remove') {
                $removed[$id] = true;
                continue;
            }
            if (!isset($result[$id])) {
                $result[$id] = [
                    'ingredient_id'   => $id,
                    'name'            => $e['name'],
                    'unit'            => $e['unit'],
                    'ingredient_type' => $e['ingredient_type'],
                    'track_inventory' => (int) $e['track_inventory'],
                    'per_dish'        => 0.0,
                    'from_addon'      => true,
                ];
            }
            $result[$id]['per_dish'] += (float) $e['quantity'];
            $result[$id]['from_addon'] = true;
        }
        // "ไม่ใส่" ตัดเฉพาะส่วนที่มาจากสูตรหลัก ถ้าสั่งเพิ่มวัตถุดิบเดียวกันด้วยให้คิดเฉพาะส่วนเพิ่ม
        foreach (array_keys($removed) as $id) {
            if (!isset($result[$id])) continue;
            $result[$id]['per_dish'] = self::addedAmount($orderItemId, $id);
        }
        return $result;
    }

    private static function addedAmount(int $orderItemId, int $ingredientId): float
    {
        $stmt = getDB()->prepare(
            "SELECT COALESCE(SUM(e.quantity), 0) FROM order_item_addons oia
             JOIN addon_ingredient_effects e ON e.addon_id = oia.addon_id
             WHERE oia.order_item_id = :oi AND e.ingredient_id = :ing AND e.effect = 'add'"
        );
        $stmt->execute(['oi' => $orderItemId, 'ing' => $ingredientId]);
        return (float) $stmt->fetchColumn();
    }
}
