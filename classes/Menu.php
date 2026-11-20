<?php
// เมนูอาหาร และสถานะหมดวันนี้
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AppSetting.php';

class Menu
{
    public static function all(bool $activeOnly = true): array
    {
        $db = getDB();
        $sql = 'SELECT * FROM menu';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY category, name';
        $rows = $db->query($sql)->fetchAll();
        // เรียงตามลำดับหมวดที่ร้านตั้งไว้ หมวดที่ไม่อยู่ในรายการไปท้ายสุด
        $pos = array_flip(self::categories());
        usort($rows, fn($a, $b) => [($pos[$a['category']] ?? 999), $a['name']] <=> [($pos[$b['category']] ?? 999), $b['name']]);
        return $rows;
    }

    // เมนูสำหรับลูกค้า (ตู้ ออนไลน์ หน้าพนักงานรับออเดอร์): ไม่รวมเมนูฐานตามสั่ง (DIY)
    public static function forCustomers(): array
    {
        return array_values(array_filter(self::all(), fn($m) => !self::isDiy($m)));
    }

    // เมนูฐานตามสั่ง (DIY) สำหรับหน้าแคชเชียร์
    public static function diyBases(): array
    {
        return array_values(array_filter(self::all(), fn($m) => self::isDiy($m)));
    }

    public static function isDiy(array $menu): bool
    {
        return !empty($menu['is_diy']);
    }

    public static function diyReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try { getDB()->query('SELECT is_diy FROM menu LIMIT 1'); $ok = true; }
            catch (Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    public static function setDiy(int $menuId, bool $diy): void
    {
        if (!self::diyReady()) return;
        getDB()->prepare('UPDATE menu SET is_diy = ? WHERE menu_id = ?')->execute([$diy ? 1 : 0, $menuId]);
    }

    // ---------- หมวดหมู่เมนู (เก็บรายชื่อและลำดับไว้ในตั้งค่าร้าน) ----------
    public static function categories(): array
    {
        $list = json_decode((string) AppSetting::get('menu_categories', ''), true);
        if (!is_array($list)) {
            $list = getDB()->query('SELECT DISTINCT category FROM menu ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
        }
        return array_values(array_filter(array_map('strval', $list), fn($c) => trim($c) !== ''));
    }

    public static function saveCategories(array $list, ?int $userId): void
    {
        $list = array_values(array_unique(array_filter(array_map('trim', $list), fn($c) => $c !== '')));
        AppSetting::setMany(['menu_categories' => json_encode($list, JSON_UNESCAPED_UNICODE)], $userId);
    }

    public static function categoryCounts(): array
    {
        return getDB()->query('SELECT category, COUNT(*) FROM menu GROUP BY category')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function renameCategory(string $old, string $new): void
    {
        getDB()->prepare('UPDATE menu SET category = :n WHERE category = :o')->execute(['n' => $new, 'o' => $old]);
    }

    public static function find(int $menuId): ?array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM menu WHERE menu_id = :id');
        $stmt->execute(['id' => $menuId]);
        $menu = $stmt->fetch();
        return $menu ?: null;
    }

    public static function update(int $menuId, string $name, string $category, float $price, bool $isActive, ?string $imagePath = null): void
    {
        $db = getDB();

        if ($imagePath !== null) {
            $stmt = $db->prepare(
                'UPDATE menu SET name = :name, category = :category, price = :price, is_active = :active, image_path = :image WHERE menu_id = :id'
            );
            $stmt->execute([
                'name'     => $name,
                'category' => $category,
                'price'    => $price,
                'active'   => $isActive ? 1 : 0,
                'image'    => $imagePath,
                'id'       => $menuId,
            ]);
        } else {
            $stmt = $db->prepare(
                'UPDATE menu SET name = :name, category = :category, price = :price, is_active = :active WHERE menu_id = :id'
            );
            $stmt->execute([
                'name'     => $name,
                'category' => $category,
                'price'    => $price,
                'active'   => $isActive ? 1 : 0,
                'id'       => $menuId,
            ]);
        }
    }

    public static function create(string $name, string $category, float $price, ?string $imagePath = null): int
    {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO menu (name, category, price, image_path) VALUES (:n, :c, :p, :img)');
        $stmt->execute(['n' => $name, 'c' => $category, 'p' => $price, 'img' => $imagePath]);
        return (int) $db->lastInsertId();
    }

    // ลบเมนูจริงถ้ายังไม่เคยถูกสั่งในออเดอร์ไหนเลย (ไม่ติด Foreign Key)
    public static function delete(int $menuId): string
    {
        $db = getDB();
        try {
            $db->prepare('DELETE FROM recipe WHERE menu_id = :id')->execute(['id' => $menuId]);
            $db->prepare('DELETE FROM menu_addons WHERE menu_id = :id')->execute(['id' => $menuId]);
            $stmt = $db->prepare('DELETE FROM menu WHERE menu_id = :id');
            $stmt->execute(['id' => $menuId]);
            return 'deleted';
        } catch (PDOException $e) {
            $stmt = $db->prepare('UPDATE menu SET is_active = 0 WHERE menu_id = :id');
            $stmt->execute(['id' => $menuId]);
            return 'deactivated';
        }
    }

    // รายชื่อหมวดหมู่ที่มีอยู่จริงในระบบตอนนี้ ไม่ซ้ำกัน ใช้ทำแท็บหมวดหมู่
    public static function distinctCategories(): array
    {
        $db = getDB();
        return $db->query('SELECT DISTINCT category FROM menu WHERE is_active = 1 ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
    }

    // เช็คว่าเมนูนี้ "หมดชั่วคราว" (86) อยู่ตอนนี้ไหม
    public static function isSoldOutToday(array $menu): bool
    {
        return !empty($menu['sold_out_date']) && $menu['sold_out_date'] >= date('Y-m-d');
    }

    // เหตุที่สั่งไม่ได้: ปิดเองวันนี้ หรือวัตถุดิบหลักไม่พอ (null = สั่งได้)
    public static function unavailableReason(array $menu): ?string
    {
        if (self::isSoldOutToday($menu)) return 'หมดวันนี้';
        require_once __DIR__ . '/MenuStock.php';
        if (MenuStock::isOut((int) $menu['menu_id'])) return 'หมดชั่วคราว';
        return null;
    }

    // ปิดขายเมนูชั่วคราวสำหรับวันนี้ — จะกลับมาขายได้เองอัตโนมัติพรุ่งนี้
    public static function markSoldOutToday(int $menuId): void
    {
        $db = getDB();
        $stmt = $db->prepare('UPDATE menu SET sold_out_date = CURRENT_DATE WHERE menu_id = :id');
        $stmt->execute(['id' => $menuId]);
    }

    // เปิดขายกลับคืนก่อนวันจะเปลี่ยน (เผื่อของกลับมามีใหม่ในวันเดียวกัน)
    public static function clearSoldOut(int $menuId): void
    {
        $db = getDB();
        $stmt = $db->prepare('UPDATE menu SET sold_out_date = NULL WHERE menu_id = :id');
        $stmt->execute(['id' => $menuId]);
    }
}
