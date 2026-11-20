<?php
// บันทึกประวัติการกระทำของพนักงาน
require_once __DIR__ . '/../config/database.php';

class AuditLog
{
    public static function write(?int $userId, string $action, string $entityType, $entityId = null, ?string $detail = null): void
    {
        try {
            $stmt = getDB()->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, detail, ip_address)
                 VALUES (:user_id, :action, :entity_type, :entity_id, :detail, :ip)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId === null ? null : (string) $entityId,
                'detail' => $detail,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Audit log ต้องไม่ทำให้งานหลักล้มเหลวในกรณีที่ยังไม่ได้รัน migration V2
        }
    }
}

