<?php
// (ปิดใช้) ข้อมูลโต๊ะเดิม
require_once __DIR__ . '/../config/database.php';

class RestaurantTable
{
    public static function all(): array
    {
        $db = getDB();
        return $db->query(
            "SELECT t.*,
                    ts.session_id,
                    ts.guest_count,
                    ts.opened_at,
                    o.order_id,
                    o.status AS order_status,
                    COALESCE((SELECT SUM(oi.subtotal) FROM order_items oi WHERE oi.order_id = o.order_id), 0)
                      + COALESCE((SELECT SUM(oia.addon_price * oi2.quantity)
                                  FROM order_item_addons oia
                                  JOIN order_items oi2 ON oi2.order_item_id = oia.order_item_id
                                  WHERE oi2.order_id = o.order_id), 0) AS order_total
             FROM restaurant_tables t
             LEFT JOIN (
                 SELECT tst_open.session_id, tst_open.table_id
                 FROM table_session_tables tst_open
                 JOIN table_sessions ts_open ON ts_open.session_id = tst_open.session_id
                                            AND ts_open.status = 'open'
             ) tst ON tst.table_id = t.table_id
             LEFT JOIN table_sessions ts ON ts.session_id = tst.session_id
             LEFT JOIN orders o ON o.table_session_id = ts.session_id
                                AND o.status NOT IN ('closed','cancelled','no_show')
                                AND o.order_id = (
                                    SELECT MAX(o2.order_id) FROM orders o2
                                    WHERE o2.table_session_id = ts.session_id
                                      AND o2.status NOT IN ('closed','cancelled','no_show')
                                )
             WHERE t.is_active = 1
             ORDER BY t.position_y, t.position_x, t.table_code"
        )->fetchAll();
    }

    public static function create(string $tableCode, int $capacity, string $zoneName): int
    {
        $tableCode = strtoupper(trim($tableCode));
        $zoneName = trim($zoneName) ?: 'โซนหลัก';
        if (!preg_match('/^[A-Z0-9_-]{1,20}$/', $tableCode)) {
            throw new Exception('รหัสโต๊ะใช้ได้เฉพาะ A-Z, 0-9, ขีดกลาง และขีดล่าง ไม่เกิน 20 ตัวอักษร');
        }
        if ($capacity < 1 || $capacity > 50) {
            throw new Exception('จำนวนที่นั่งต้องอยู่ระหว่าง 1-50 ที่นั่ง');
        }

        $db = getDB();
        $position = $db->prepare(
            'SELECT COALESCE(MAX(position_x), 0) + 1 FROM restaurant_tables WHERE zone_name = :zone AND is_active = 1'
        );
        $position->execute(['zone' => $zoneName]);
        $positionX = (int) $position->fetchColumn();

        try {
            $stmt = $db->prepare(
                "INSERT INTO restaurant_tables
                 (table_code, capacity, zone_name, position_x, position_y, status, is_active)
                 VALUES (:code, :capacity, :zone, :position_x, 0, 'available', 1)"
            );
            $stmt->execute([
                'code' => $tableCode,
                'capacity' => $capacity,
                'zone' => $zoneName,
                'position_x' => $positionX,
            ]);
            return (int) $db->lastInsertId();
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new Exception("รหัสโต๊ะ {$tableCode} มีอยู่แล้ว กรุณาใช้รหัสอื่น");
            }
            throw $e;
        }
    }

    public static function remove(int $tableId): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT * FROM restaurant_tables WHERE table_id = :id FOR UPDATE');
            $lock->execute(['id' => $tableId]);
            $table = $lock->fetch();
            if (!$table || !(int) $table['is_active']) {
                throw new Exception('ไม่พบโต๊ะที่ต้องการลบ');
            }
            if (!in_array($table['status'], ['available', 'cleaning', 'out_of_service'], true)) {
                throw new Exception('ลบโต๊ะไม่ได้ในขณะที่กำลังใช้งานหรือมีการจอง');
            }

            $history = $db->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM table_session_tables WHERE table_id = :session_table_id) +
                    (SELECT COUNT(*) FROM reservation_tables WHERE table_id = :reservation_table_id)'
            );
            $history->execute([
                'session_table_id' => $tableId,
                'reservation_table_id' => $tableId,
            ]);

            if ((int) $history->fetchColumn() === 0) {
                $db->prepare('DELETE FROM restaurant_tables WHERE table_id = :id')->execute(['id' => $tableId]);
            } else {
                $deletedCode = 'DEL' . $tableId . '-' . substr($table['table_code'], 0, 10);
                $stmt = $db->prepare(
                    "UPDATE restaurant_tables
                     SET table_code = :deleted_code, is_active = 0, status = 'out_of_service',
                         notes = CONCAT_WS(' ', notes, :note)
                     WHERE table_id = :id"
                );
                $stmt->execute([
                    'deleted_code' => $deletedCode,
                    'note' => '[ลบโต๊ะเดิม: ' . $table['table_code'] . ']',
                    'id' => $tableId,
                ]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function find(int $tableId): ?array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM restaurant_tables WHERE table_id = :id AND is_active = 1');
        $stmt->execute(['id' => $tableId]);
        $table = $stmt->fetch();
        return $table ?: null;
    }

    public static function markAvailable(int $tableId): void
    {
        $db = getDB();
        $stmt = $db->prepare("UPDATE restaurant_tables SET status = 'available' WHERE table_id = :id AND status = 'cleaning'");
        $stmt->execute(['id' => $tableId]);
    }

    public static function available(): array
    {
        return getDB()->query(
            "SELECT * FROM restaurant_tables WHERE is_active = 1 AND status = 'available'
             ORDER BY capacity, zone_name, table_code"
        )->fetchAll();
    }

    public static function setOutOfService(int $tableId, bool $out): void
    {
        $status = $out ? 'out_of_service' : 'available';
        $stmt = getDB()->prepare(
            "UPDATE restaurant_tables SET status = :status
             WHERE table_id = :id AND status IN ('available','out_of_service')"
        );
        $stmt->execute(['status' => $status, 'id' => $tableId]);
    }
}
