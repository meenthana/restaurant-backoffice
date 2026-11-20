<?php
// (ปิดใช้) ระบบจองโต๊ะเดิม
require_once __DIR__ . '/../config/database.php';

class Reservation
{
    public static function create(array $data, ?int $createdBy): int
    {
        $guestCount = (int) ($data['guest_count'] ?? 0);
        $timestamp = strtotime((string) ($data['reservation_at'] ?? ''));
        if ($guestCount < 1) {
            throw new Exception('จำนวนลูกค้าต้องมากกว่า 0');
        }
        if (!$timestamp || $timestamp < time() - 300) {
            throw new Exception('วันและเวลาจองไม่ถูกต้อง');
        }
        if (trim((string) ($data['guest_name'] ?? '')) === '' || trim((string) ($data['phone'] ?? '')) === '') {
            throw new Exception('กรุณาระบุชื่อลูกค้าและเบอร์โทร');
        }

        $db = getDB();
        $db->beginTransaction();
        try {
            $code = 'RSV-' . date('ymd', $timestamp) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $stmt = $db->prepare(
            "INSERT INTO reservations
             (reservation_code, member_id, guest_name, phone, guest_count, reservation_at,
              duration_minutes, status, note, deposit_amount, created_by)
             VALUES (:code, :member_id, :guest_name, :phone, :guest_count, :reservation_at,
                     :duration, 'confirmed', :note, :deposit, :created_by)"
        );
            $stmt->execute([
            'code' => $code,
            'member_id' => !empty($data['member_id']) ? (int) $data['member_id'] : null,
            'guest_name' => trim((string) $data['guest_name']),
            'phone' => trim((string) $data['phone']),
            'guest_count' => $guestCount,
            'reservation_at' => date('Y-m-d H:i:s', $timestamp),
            'duration' => max(30, (int) ($data['duration_minutes'] ?? 120)),
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'deposit' => max(0, (float) ($data['deposit_amount'] ?? 0)),
            'created_by' => $createdBy,
        ]);
            $id = (int) $db->lastInsertId();
            self::assignTables($id, array_map('intval', $data['table_ids'] ?? []));
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function assignTables(int $reservationId, array $tableIds): void
    {
        $db = getDB();
        $tableIds = array_values(array_unique(array_filter(array_map('intval', $tableIds))));
        if ($tableIds) {
            $reservationStmt = $db->prepare('SELECT reservation_at, duration_minutes FROM reservations WHERE reservation_id=:id');
            $reservationStmt->execute(['id'=>$reservationId]);
            $reservation = $reservationStmt->fetch();
            if (!$reservation) {
                throw new Exception('ไม่พบข้อมูลการจอง');
            }
            $placeholders = implode(',', array_fill(0, count($tableIds), '?'));
            $params = array_merge([
                $reservation['reservation_at'],
                date('Y-m-d H:i:s', strtotime($reservation['reservation_at']) + ((int)$reservation['duration_minutes'] * 60)),
                $reservationId,
            ], $tableIds);
            $conflict = $db->prepare(
                "SELECT GROUP_CONCAT(DISTINCT t.table_code ORDER BY t.table_code SEPARATOR ', ')
                 FROM reservations r
                 JOIN reservation_tables rt ON rt.reservation_id=r.reservation_id
                 JOIN restaurant_tables t ON t.table_id=rt.table_id
                 WHERE r.reservation_at < ?
                   AND DATE_ADD(r.reservation_at, INTERVAL r.duration_minutes MINUTE) > ?
                   AND r.reservation_id <> ?
                   AND r.status IN ('pending','confirmed','checked_in','seated')
                   AND rt.table_id IN ($placeholders)"
            );
            // ลำดับพารามิเตอร์: เวลาสิ้นสุดใหม่, เวลาเริ่มใหม่, id ปัจจุบัน, โต๊ะ
            $params[0] = date('Y-m-d H:i:s', strtotime($reservation['reservation_at']) + ((int)$reservation['duration_minutes'] * 60));
            $params[1] = $reservation['reservation_at'];
            $conflict->execute($params);
            $codes = $conflict->fetchColumn();
            if ($codes) {
                throw new Exception('โต๊ะซ้ำกับการจองอื่นในช่วงเวลาเดียวกัน: ' . $codes);
            }
        }
        $db->prepare('DELETE FROM reservation_tables WHERE reservation_id = :id')->execute(['id' => $reservationId]);
        if (!$tableIds) {
            return;
        }
        $stmt = $db->prepare('INSERT INTO reservation_tables (reservation_id, table_id) VALUES (:reservation_id, :table_id)');
        foreach (array_unique($tableIds) as $tableId) {
            $stmt->execute(['reservation_id' => $reservationId, 'table_id' => $tableId]);
        }
    }

    public static function upcoming(?string $date = null): array
    {
        $date ??= date('Y-m-d');
        $stmt = getDB()->prepare(
            "SELECT r.*, GROUP_CONCAT(t.table_code ORDER BY t.table_code SEPARATOR ' + ') AS table_codes
             FROM reservations r
             LEFT JOIN reservation_tables rt ON rt.reservation_id = r.reservation_id
             LEFT JOIN restaurant_tables t ON t.table_id = rt.table_id
             WHERE DATE(r.reservation_at) = :date
             GROUP BY r.reservation_id
             ORDER BY r.reservation_at"
        );
        $stmt->execute(['date' => $date]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = getDB()->prepare(
            "SELECT r.*, GROUP_CONCAT(t.table_code ORDER BY t.table_code SEPARATOR ' + ') AS table_codes
             FROM reservations r
             LEFT JOIN reservation_tables rt ON rt.reservation_id = r.reservation_id
             LEFT JOIN restaurant_tables t ON t.table_id = rt.table_id
             WHERE r.reservation_id = :id GROUP BY r.reservation_id"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function changeStatus(int $id, string $status): void
    {
        $allowed = ['pending','confirmed','checked_in','seated','completed','cancelled','no_show'];
        if (!in_array($status, $allowed, true)) {
            throw new Exception('สถานะการจองไม่ถูกต้อง');
        }
        getDB()->prepare('UPDATE reservations SET status = :status WHERE reservation_id = :id')
               ->execute(['status' => $status, 'id' => $id]);
    }
}
