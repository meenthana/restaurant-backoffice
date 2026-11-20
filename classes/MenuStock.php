<?php
// เช็กว่าวัตถุดิบหลักพอทำเมนูไหม (หักของที่ออเดอร์ค้างอยู่จะใช้แล้ว) ใช้ปิดเมนูอัตโนมัติ
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/features.php';

class MenuStock
{
    private static ?array $recipes = null;   // [menu_id => [ingredient_id => qty]]
    private static ?array $effects = null;   // [addon_id => [[ingredient_id, effect, qty]]]
    private static ?array $free = null;      // [ingredient_id => จำนวนที่ยังใช้ได้]
    private static array $names = [];        // [ingredient_id => [name, unit]]

    // ล้างค่าที่จำไว้ (เรียกหลังมีการตัด/รับสต๊อกในคำขอเดียวกัน)
    public static function reset(): void
    {
        self::$recipes = self::$effects = self::$free = null;
    }

    private static function load(): void
    {
        if (self::$recipes !== null) return;
        $db = getDB();
        self::$recipes = [];
        $rows = $db->query(
            "SELECT r.menu_id, r.ingredient_id, r.quantity_required, i.name, i.unit
             FROM recipe r JOIN ingredients i ON i.ingredient_id = r.ingredient_id
             WHERE i.ingredient_type = 'main' AND i.track_inventory = 1"
        )->fetchAll();
        foreach ($rows as $r) {
            self::$recipes[(int) $r['menu_id']][(int) $r['ingredient_id']] = (float) $r['quantity_required'];
            self::$names[(int) $r['ingredient_id']] = [$r['name'], $r['unit']];
        }

        self::$effects = [];
        $rows = $db->query(
            "SELECT e.addon_id, e.ingredient_id, e.effect, e.quantity, i.name, i.unit
             FROM addon_ingredient_effects e JOIN ingredients i ON i.ingredient_id = e.ingredient_id
             WHERE i.ingredient_type = 'main' AND i.track_inventory = 1"
        )->fetchAll();
        foreach ($rows as $r) {
            self::$effects[(int) $r['addon_id']][] = [(int) $r['ingredient_id'], $r['effect'], (float) $r['quantity']];
            self::$names[(int) $r['ingredient_id']] = [$r['name'], $r['unit']];
        }

        $stock = [];
        foreach ($db->query(
            "SELECT ingredient_id, COALESCE(SUM(quantity_remaining), 0) AS q
             FROM stock_batches WHERE quantity_remaining > 0 GROUP BY ingredient_id"
        )->fetchAll() as $r) {
            $stock[(int) $r['ingredient_id']] = (float) $r['q'];
        }

        // ของที่ออเดอร์วันนี้ค้างอยู่จะใช้ (ครัวยังไม่เริ่มทำ จึงยังไม่ถูกตัด) ออเดอร์เก่าที่ค้างไม่นับ
        $pending = $db->query(
            "SELECT oi.order_item_id, oi.menu_id, oi.quantity,
                    GROUP_CONCAT(oia.addon_id) AS addon_ids
             FROM order_items oi
             JOIN orders o ON o.order_id = oi.order_id
             LEFT JOIN order_item_addons oia ON oia.order_item_id = oi.order_item_id
             WHERE o.status IN ('pending','active','ready')
               AND o.opened_at >= CURDATE()
               AND oi.stock_deducted_at IS NULL AND oi.voided_at IS NULL
             GROUP BY oi.order_item_id, oi.menu_id, oi.quantity"
        )->fetchAll();
        foreach ($pending as $p) {
            $addons = $p['addon_ids'] ? array_map('intval', explode(',', $p['addon_ids'])) : [];
            foreach (self::perDish((int) $p['menu_id'], $addons) as $ing => $q) {
                $stock[$ing] = ($stock[$ing] ?? 0) - $q * (int) $p['quantity'];
            }
        }
        self::$free = $stock;
    }

    // วัตถุดิบหลักที่ใช้ต่อ 1 จาน (รวมผลของตัวเลือกเสริม)
    public static function perDish(int $menuId, array $addonIds = []): array
    {
        self::load();
        $need = self::$recipes[$menuId] ?? [];
        $added = [];
        $removed = [];
        foreach ($addonIds as $a) {
            foreach (self::$effects[(int) $a] ?? [] as [$ing, $effect, $q]) {
                if ($effect === 'remove') $removed[$ing] = true;
                else $added[$ing] = ($added[$ing] ?? 0) + $q;
            }
        }
        foreach (array_keys($removed) as $ing) unset($need[$ing]);
        foreach ($added as $ing => $q) $need[$ing] = ($need[$ing] ?? 0) + $q;
        return array_filter($need, fn($q) => $q > 0);
    }

    // ทำได้อีกกี่จาน (null = ไม่มีวัตถุดิบหลักที่นับสต๊อก ไม่จำกัด)
    public static function maxDishes(int $menuId, array $addonIds = []): ?int
    {
        if (!FEATURE_AUTO_STOCK) return null;
        $need = self::perDish($menuId, $addonIds);
        if (!$need) return null;
        $max = PHP_INT_MAX;
        foreach ($need as $ing => $q) {
            $max = min($max, (int) floor(max(0, self::$free[$ing] ?? 0) / $q + 1e-9));
        }
        return $max;
    }

    public static function isOut(int $menuId): bool
    {
        if (!FEATURE_AUTO_STOCK) return false;
        $max = self::maxDishes($menuId);
        return $max !== null && $max < 1;
    }

    // ตัวเลือกเสริมนี้ใช้วัตถุดิบหลักที่หมดแล้วหรือไม่ (เช่น ไข่ดาว ตอนไข่หมด)
    public static function addonOut(int $addonId): bool
    {
        if (!FEATURE_AUTO_STOCK) return false;
        self::load();
        foreach (self::$effects[$addonId] ?? [] as [$ing, $effect, $q]) {
            if ($effect === 'add' && max(0, self::$free[$ing] ?? 0) + 1e-9 < $q) return true;
        }
        return false;
    }

    // เช็กทั้งตะกร้า [[menu_id, qty, addons[]], ...] คืนข้อความปัญหา (ว่าง = ผ่าน)
    public static function checkCart(array $lines, array $menuNames = []): array
    {
        if (!FEATURE_AUTO_STOCK) return [];
        self::load();
        $total = [];
        $byMenu = [];
        foreach ($lines as $l) {
            foreach (self::perDish((int) $l['menu_id'], $l['addons'] ?? []) as $ing => $q) {
                $total[$ing] = ($total[$ing] ?? 0) + $q * (int) $l['qty'];
                $byMenu[$ing][(int) $l['menu_id']] = true;
            }
        }
        $errors = [];
        foreach ($total as $ing => $q) {
            $free = max(0, self::$free[$ing] ?? 0);
            if ($q <= $free + 1e-9) continue;
            $menus = array_keys($byMenu[$ing]);
            if (count($menus) === 1) {
                $m = $menus[0];
                $line = array_values(array_filter($lines, fn($l) => (int) $l['menu_id'] === $m));
                $max = self::maxDishes($m, $line[0]['addons'] ?? []);
                $name = $menuNames[$m] ?? ('เมนู #' . $m);
                $withAddon = !empty($line[0]['addons']) ? ' (แบบที่เลือก)' : '';
                $errors[$m] = $max > 0
                    ? '"' . $name . '"' . $withAddon . ' เหลือทำได้อีก ' . $max . ' จาน'
                    : '"' . $name . '" หมดชั่วคราว';
            } else {
                [$ingName] = self::$names[$ing] ?? ['วัตถุดิบ'];
                $errors['ing' . $ing] = $ingName . ' ไม่พอสำหรับทุกรายการในตะกร้า กรุณาลดจำนวนลง';
            }
        }
        return array_values($errors);
    }

    // สถานะของทุกเมนูสำหรับหน้าลูกค้า: [menu_id => [out => ป้าย|null, max => จำนวน|null, addonsOut => [addon_id]]]
    public static function availabilityMap(): array
    {
        require_once __DIR__ . '/Menu.php';
        require_once __DIR__ . '/Addon.php';
        $map = [];
        foreach (Menu::all() as $m) {
            $id = (int) $m['menu_id'];
            $addonsOut = [];
            foreach (Addon::forMenu($id) as $a) {
                if (self::addonOut((int) $a['addon_id'])) $addonsOut[] = (int) $a['addon_id'];
            }
            $map[$id] = [
                'out'       => Menu::unavailableReason($m),
                'max'       => self::maxDishes($id),
                'addonsOut' => $addonsOut,
            ];
        }
        return $map;
    }
}
