<?php
// อ่าน/บันทึกค่าตั้งค่าร้าน (ตาราง store_settings)
require_once __DIR__ . '/../config/database.php';

class AppSetting
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $stmt = getDB()->prepare('SELECT setting_value FROM store_settings WHERE setting_key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    public static function all(): array
    {
        $rows = getDB()->query('SELECT setting_key, setting_value FROM store_settings ORDER BY setting_key')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['setting_key']] = $row['setting_value'];
        }
        return $result;
    }

    public static function setMany(array $settings, ?int $userId): void
    {
        $stmt = getDB()->prepare(
            'INSERT INTO store_settings (setting_key, setting_value, updated_by)
             VALUES (:key, :value, :user_id)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        );
        foreach ($settings as $key => $value) {
            $stmt->execute(['key' => $key, 'value' => trim((string) $value), 'user_id' => $userId]);
        }
    }
}

