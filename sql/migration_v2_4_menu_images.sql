-- ============================================================
-- ส่วนอัปเดต V2.4 : รูปภาพเมนู
-- ใช้ IF NOT EXISTS ปลอดภัย รันซ้ำได้
-- ============================================================

USE restaurant_backoffice;

ALTER TABLE menu
  ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL;

SELECT 'เพิ่มคอลัมน์ image_path ในตาราง menu สำเร็จแล้ว' AS result;
