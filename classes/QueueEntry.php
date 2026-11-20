<?php
// (ปิดใช้) ระบบคิวรอโต๊ะเดิม
require_once __DIR__ . '/../config/database.php';

class QueueEntry
{
    public static function create(array $data, ?int $createdBy): int
    {
        $guestCount = (int) ($data['guest_count'] ?? 0);
        if ($guestCount < 1 || trim((string) ($data['guest_name'] ?? '')) === '') {
            throw new Exception('กรุณาระบุชื่อลูกค้าและจำนวนคน');
        }
        $db = getDB();
        $today = date('Y-m-d');
        $count = $db->prepare('SELECT COUNT(*) FROM queue_entries WHERE queue_date = :date');
        $count->execute(['date' => $today]);
        $number = 'Q' . str_pad((string) ((int) $count->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);

        $stmt = $db->prepare(
            "INSERT INTO queue_entries
             (queue_number, queue_date, source, reservation_id, member_id, guest_name, phone, guest_count, status, note, created_by)
             VALUES (:number, :date, :source, :reservation_id, :member_id, :guest_name, :phone, :guest_count, 'waiting', :note, :created_by)"
        );
        $stmt->execute([
            'number' => $number,
            'date' => $today,
            'source' => ($data['source'] ?? 'walkin') === 'reservation' ? 'reservation' : 'walkin',
            'reservation_id' => !empty($data['reservation_id']) ? (int) $data['reservation_id'] : null,
            'member_id' => !empty($data['member_id']) ? (int) $data['member_id'] : null,
            'guest_name' => trim((string) $data['guest_name']),
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'guest_count' => $guestCount,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'created_by' => $createdBy,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function today(): array
    {
        return getDB()->query(
            "SELECT q.*, TIMESTAMPDIFF(MINUTE, q.created_at, NOW()) AS wait_minutes
             FROM queue_entries q
             WHERE q.queue_date = CURRENT_DATE
             ORDER BY FIELD(q.status, 'called','waiting','seated','no_show','cancelled'), q.created_at"
        )->fetchAll();
    }

    public static function call(int $id): void
    {
        getDB()->prepare("UPDATE queue_entries SET status = 'called', called_at = NOW() WHERE queue_entry_id = :id AND status = 'waiting'")
               ->execute(['id' => $id]);
    }

    public static function cancel(int $id, string $status = 'cancelled'): void
    {
        if (!in_array($status, ['cancelled', 'no_show'], true)) {
            throw new Exception('สถานะคิวไม่ถูกต้อง');
        }
        getDB()->prepare("UPDATE queue_entries SET status = :status WHERE queue_entry_id = :id AND status IN ('waiting','called')")
               ->execute(['status' => $status, 'id' => $id]);
    }
}

