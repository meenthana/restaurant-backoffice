-- ============================================================
-- ส่วนอัปเดต V2.6 : Reorder Point ต่อวัตถุดิบ
-- ใช้ IF NOT EXISTS ปลอดภัย รันซ้ำได้
-- ============================================================

USE restaurant_backoffice;

ALTER TABLE ingredients
  ADD COLUMN IF NOT EXISTS reorder_point DECIMAL(10,2) NULL;

SELECT 'เพิ่มคอลัมน์ reorder_point ในตาราง ingredients สำเร็จแล้ว' AS result;
