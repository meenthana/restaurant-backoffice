-- ============================================================
-- ส่วนอัปเดต V2.3 : PIN Quick Login สำหรับพนักงาน
-- ใช้ IF NOT EXISTS ปลอดภัย รันซ้ำได้
-- ============================================================

USE restaurant_backoffice;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS pin_hash VARCHAR(255) NULL;

SELECT 'เพิ่มคอลัมน์ pin_hash สำเร็จแล้ว' AS result;
