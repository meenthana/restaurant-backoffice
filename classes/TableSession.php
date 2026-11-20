<?php
// (ปิดใช้) รอบการใช้โต๊ะเดิม
require_once __DIR__ . '/../config/database.php';

class TableSession
{
    public static function open(int $tableId, int $guestCount, int $openedBy): int
    {
        return self::openMultiple([$tableId], $guestCount, $openedBy);
    }

    public static function openMultiple(
        array $tableIds,
        int $guestCount,
        int $openedBy,
        ?int $queueEntryId = null,
        ?int $reservationId = null,
        ?string $note = null
    ): int
    {
        if ($guestCount < 1) {
            throw new Exception('จำนวนลูกค้าต้องมากกว่า 0');
        }

        $tableIds = array_values(array_unique(array_filter(array_map('intval', $tableIds))));
        if (!$tableIds) {
            throw new Exception('กรุณาเลือกโต๊ะอย่างน้อย 1 โต๊ะ');
        }

        $db = getDB();
        $db->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($tableIds), '?'));
            $lock = $db->prepare("SELECT * FROM restaurant_tables WHERE table_id IN ($placeholders) FOR UPDATE");
            $lock->execute($tableIds);
            $tables = $lock->fetchAll();
            if (count($tables) !== count($tableIds)) {
                throw new Exception('ไม่พบโต๊ะที่เลือกบางรายการ');
            }
            $capacity = 0;
            foreach ($tables as $table) {
                if (!$table['is_active'] || $table['status'] !== 'available') {
                    throw new Exception('มีโต๊ะที่ไม่ว่างหรือไม่พร้อมใช้งาน กรุณาเลือกใหม่');
                }
                $capacity += (int) $table['capacity'];
            }
            if ($guestCount > $capacity) {
                throw new Exception('จำนวนลูกค้าเกินความจุรวมของโต๊ะที่เลือก');
            }

            $session = $db->prepare(
                "INSERT INTO table_sessions
                 (guest_count, status, opened_by, reservation_id, queue_entry_id, session_note, opened_at)
                 VALUES (:guests, 'open', :user_id, :reservation_id, :queue_entry_id, :note, NOW())"
            );
            $session->execute([
                'guests' => $guestCount,
                'user_id' => $openedBy,
                'reservation_id' => $reservationId,
                'queue_entry_id' => $queueEntryId,
                'note' => trim((string) $note) ?: null,
            ]);
            $sessionId = (int) $db->lastInsertId();

            $link = $db->prepare('INSERT INTO table_session_tables (session_id, table_id) VALUES (:session_id, :table_id)');
            foreach ($tableIds as $tableId) {
                $link->execute(['session_id' => $sessionId, 'table_id' => $tableId]);
            }

            $update = $db->prepare("UPDATE restaurant_tables SET status = 'occupied' WHERE table_id IN ($placeholders)");
            $update->execute($tableIds);

            if ($queueEntryId) {
                $db->prepare("UPDATE queue_entries SET status = 'seated', seated_at = NOW(), table_session_id = :session_id WHERE queue_entry_id = :id")
                   ->execute(['session_id' => $sessionId, 'id' => $queueEntryId]);
            }
            if ($reservationId) {
                $db->prepare("UPDATE reservations SET status = 'seated' WHERE reservation_id = :id")
                   ->execute(['id' => $reservationId]);
            }

            $db->commit();
            return $sessionId;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function find(int $sessionId): ?array
    {
        $db = getDB();
        $stmt = $db->prepare(
            "SELECT ts.*, GROUP_CONCAT(t.table_code ORDER BY t.table_code SEPARATOR ' + ') AS table_codes
             FROM table_sessions ts
             JOIN table_session_tables tst ON tst.session_id = ts.session_id
             JOIN restaurant_tables t ON t.table_id = tst.table_id
             WHERE ts.session_id = :id
             GROUP BY ts.session_id"
        );
        $stmt->execute(['id' => $sessionId]);
        $session = $stmt->fetch();
        return $session ?: null;
    }

    public static function tables(int $sessionId): array
    {
        $stmt = getDB()->prepare(
            'SELECT t.* FROM restaurant_tables t
             JOIN table_session_tables tst ON tst.table_id = t.table_id
             WHERE tst.session_id = :session_id
             ORDER BY t.table_code'
        );
        $stmt->execute(['session_id' => $sessionId]);
        return $stmt->fetchAll();
    }

    public static function mergeTables(int $sessionId, array $tableIds): void
    {
        $tableIds = array_values(array_unique(array_filter(array_map('intval', $tableIds))));
        if (!$tableIds) {
            throw new Exception('กรุณาเลือกโต๊ะว่างอย่างน้อย 1 โต๊ะ');
        }

        $db = getDB();
        $db->beginTransaction();
        try {
            self::lockOpenSession($db, $sessionId);
            $placeholders = implode(',', array_fill(0, count($tableIds), '?'));
            $lock = $db->prepare("SELECT * FROM restaurant_tables WHERE table_id IN ($placeholders) FOR UPDATE");
            $lock->execute($tableIds);
            $tables = $lock->fetchAll();
            if (count($tables) !== count($tableIds)) {
                throw new Exception('ไม่พบโต๊ะที่เลือกบางรายการ');
            }
            foreach ($tables as $table) {
                if (!(int) $table['is_active'] || $table['status'] !== 'available') {
                    throw new Exception('มีโต๊ะที่เลือกไม่ว่าง กรุณาเลือกใหม่');
                }
            }

            $link = $db->prepare('INSERT INTO table_session_tables (session_id, table_id) VALUES (:session_id, :table_id)');
            foreach ($tableIds as $tableId) {
                $link->execute(['session_id' => $sessionId, 'table_id' => $tableId]);
            }
            $update = $db->prepare("UPDATE restaurant_tables SET status = 'occupied' WHERE table_id IN ($placeholders)");
            $update->execute($tableIds);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function moveTo(int $sessionId, int $targetTableId): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $session = self::lockOpenSession($db, $sessionId);
            $targetStmt = $db->prepare('SELECT * FROM restaurant_tables WHERE table_id = :id FOR UPDATE');
            $targetStmt->execute(['id' => $targetTableId]);
            $target = $targetStmt->fetch();
            if (!$target || !(int) $target['is_active'] || $target['status'] !== 'available') {
                throw new Exception('โต๊ะปลายทางไม่ว่างหรือไม่พร้อมใช้งาน');
            }
            if ((int) $target['capacity'] < (int) $session['guest_count']) {
                throw new Exception('จำนวนที่นั่งของโต๊ะปลายทางไม่เพียงพอ');
            }

            $currentTables = self::tables($sessionId);
            $currentIds = array_map(fn($table) => (int) $table['table_id'], $currentTables);
            if ($currentIds) {
                $placeholders = implode(',', array_fill(0, count($currentIds), '?'));
                $release = $db->prepare("UPDATE restaurant_tables SET status = 'available' WHERE table_id IN ($placeholders)");
                $release->execute($currentIds);
            }
            $db->prepare('DELETE FROM table_session_tables WHERE session_id = :session_id')
               ->execute(['session_id' => $sessionId]);
            $db->prepare('INSERT INTO table_session_tables (session_id, table_id) VALUES (:session_id, :table_id)')
               ->execute(['session_id' => $sessionId, 'table_id' => $targetTableId]);
            $db->prepare("UPDATE restaurant_tables SET status = 'occupied' WHERE table_id = :id")
               ->execute(['id' => $targetTableId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function releaseTable(int $sessionId, int $tableId): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $session = self::lockOpenSession($db, $sessionId);
            $tables = self::tables($sessionId);
            $selected = array_values(array_filter($tables, fn($table) => (int) $table['table_id'] === $tableId));
            if (!$selected) {
                throw new Exception('โต๊ะนี้ไม่ได้อยู่ในรอบการใช้งานที่เลือก');
            }

            if (count($tables) === 1) {
                $orders = $db->prepare("SELECT COUNT(*) FROM orders WHERE table_session_id = :id AND status NOT IN ('closed','cancelled','no_show')");
                $orders->execute(['id' => $sessionId]);
                if ((int) $orders->fetchColumn() > 0) {
                    throw new Exception('คืนโต๊ะไม่ได้ เนื่องจากยังมีออเดอร์ค้างอยู่ กรุณาคิดเงินหรือยกเลิกออเดอร์ก่อน');
                }
                $db->prepare("UPDATE table_sessions SET status = 'cancelled', closed_at = NOW() WHERE session_id = :id")
                   ->execute(['id' => $sessionId]);
            } else {
                $remainingCapacity = 0;
                foreach ($tables as $table) {
                    if ((int) $table['table_id'] !== $tableId) {
                        $remainingCapacity += (int) $table['capacity'];
                    }
                }
                if ($remainingCapacity < (int) $session['guest_count']) {
                    throw new Exception('คืนโต๊ะนี้ไม่ได้ เพราะที่นั่งที่เหลือไม่เพียงพอกับจำนวนลูกค้า');
                }
                $db->prepare('DELETE FROM table_session_tables WHERE session_id = :session_id AND table_id = :table_id')
                   ->execute(['session_id' => $sessionId, 'table_id' => $tableId]);
            }
            $db->prepare("UPDATE restaurant_tables SET status = 'available' WHERE table_id = :id")
               ->execute(['id' => $tableId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private static function lockOpenSession(PDO $db, int $sessionId): array
    {
        $stmt = $db->prepare("SELECT * FROM table_sessions WHERE session_id = :id AND status = 'open' FOR UPDATE");
        $stmt->execute(['id' => $sessionId]);
        $session = $stmt->fetch();
        if (!$session) {
            throw new Exception('ไม่พบรอบโต๊ะที่กำลังใช้งาน');
        }
        return $session;
    }

    public static function close(int $sessionId): void
    {
        $db = getDB();
        $session = self::find($sessionId);
        if (!$session || $session['status'] !== 'open') {
            return;
        }

        $db->beginTransaction();
        try {
            $close = $db->prepare("UPDATE table_sessions SET status = 'closed', closed_at = NOW() WHERE session_id = :id");
            $close->execute(['id' => $sessionId]);

            $tables = $db->prepare(
                "UPDATE restaurant_tables t
                 JOIN table_session_tables tst ON tst.table_id = t.table_id
                 SET t.status = 'cleaning'
                 WHERE tst.session_id = :session_id"
            );
            $tables->execute(['session_id' => $sessionId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function cancel(int $sessionId): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $orders = $db->prepare('SELECT COUNT(*) FROM orders WHERE table_session_id = :id');
            $orders->execute(['id' => $sessionId]);
            if ((int) $orders->fetchColumn() > 0) {
                throw new Exception('ยกเลิกรอบโต๊ะไม่ได้ เพราะมีออเดอร์แล้ว');
            }

            $cancel = $db->prepare("UPDATE table_sessions SET status = 'cancelled', closed_at = NOW() WHERE session_id = :id AND status = 'open'");
            $cancel->execute(['id' => $sessionId]);

            $tables = $db->prepare(
                "UPDATE restaurant_tables t
                 JOIN table_session_tables tst ON tst.table_id = t.table_id
                 SET t.status = 'available'
                 WHERE tst.session_id = :session_id AND t.status = 'occupied'"
            );
            $tables->execute(['session_id' => $sessionId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
