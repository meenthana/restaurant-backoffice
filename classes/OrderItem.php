<?php
// รายการอาหารในออเดอร์ และตัวเลือกเสริมที่เลือก
require_once __DIR__ . '/../config/database.php';

class OrderItem
{
    public static function add(int $orderId, int $menuId, int $quantity): int
    {
        $db = getDB();

        // ล็อกราคา ณ เวลาที่สั่ง (ไม่อ้างอิง menu.price ตรงๆ)
        $stmt = $db->prepare('SELECT price FROM menu WHERE menu_id = :id');
        $stmt->execute(['id' => $menuId]);
        $unitPrice = $stmt->fetchColumn();

        if ($unitPrice === false) {
            throw new Exception("ไม่พบเมนู #$menuId");
        }

        $stmt = $db->prepare(
            'INSERT INTO order_items (order_id, menu_id, quantity, unit_price)
             VALUES (:order_id, :menu_id, :quantity, :unit_price)'
        );
        $stmt->execute([
            'order_id'   => $orderId,
            'menu_id'    => $menuId,
            'quantity'   => $quantity,
            'unit_price' => $unitPrice,
        ]);

        return (int) $db->lastInsertId();
    }

    // แก้ไขจำนวนของรายการที่มีอยู่แล้ว (ราคาต่อหน่วยเดิมที่ล็อกไว้ไม่เปลี่ยน)
    public static function updateQuantity(int $orderItemId, int $quantity): void
    {
        $db = getDB();
        $stmt = $db->prepare('UPDATE order_items SET quantity = :qty WHERE order_item_id = :id');
        $stmt->execute(['qty' => $quantity, 'id' => $orderItemId]);
    }

    // ลบรายการอาหารออกจากออเดอร์ (Add-on ที่ผูกไว้จะถูกลบตามด้วยผ่าน FOREIGN KEY CASCADE)
    public static function remove(int $orderItemId): void
    {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM order_items WHERE order_item_id = :id');
        $stmt->execute(['id' => $orderItemId]);
    }

    public static function getByOrder(int $orderId): array
    {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT oi.*, m.name AS menu_name
             FROM order_items oi
             JOIN menu m ON m.menu_id = oi.menu_id
             WHERE oi.order_id = :order_id'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    // บันทึก Add-on ที่เลือกไว้ให้กับรายการอาหารหนึ่งบรรทัด พร้อมล็อกชื่อ/ราคา ณ เวลาสั่ง
    public static function addAddon(int $orderItemId, int $addonId): void
    {
        $db = getDB();

        $stmt = $db->prepare('SELECT name, extra_price FROM addons WHERE addon_id = :id AND is_active = 1');
        $stmt->execute(['id' => $addonId]);
        $addon = $stmt->fetch();

        if (!$addon) {
            throw new Exception("ไม่พบตัวเลือกเสริม #$addonId");
        }

        $ins = $db->prepare(
            'INSERT INTO order_item_addons (order_item_id, addon_id, addon_name, addon_price)
             VALUES (:order_item_id, :addon_id, :name, :price)'
        );
        $ins->execute([
            'order_item_id' => $orderItemId,
            'addon_id'      => $addonId,
            'name'          => $addon['name'],
            'price'         => $addon['extra_price'],
        ]);
    }

    // ดึง Add-on ทั้งหมดที่เลือกไว้สำหรับรายการอาหารหนึ่งบรรทัด
    public static function getAddons(int $orderItemId): array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM order_item_addons WHERE order_item_id = :id');
        $stmt->execute(['id' => $orderItemId]);
        return $stmt->fetchAll();
    }
}
