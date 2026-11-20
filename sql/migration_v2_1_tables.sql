-- ============================================================
-- อัปเดต V2.1 : จัดการโต๊ะ ป้องกันรหัสซ้ำ และซ่อมสถานะค้าง
-- ใช้กับฐานข้อมูล V2 เดิมได้โดยไม่ลบประวัติออเดอร์
-- ============================================================

USE restaurant_backoffice;

START TRANSACTION;

-- หากฐานข้อมูลเก่าเคยอนุญาตรหัสโต๊ะซ้ำ ให้เก็บรายการแรกไว้และซ่อนรายการซ้ำ
CREATE TEMPORARY TABLE tmp_duplicate_table_codes AS
SELECT UPPER(TRIM(table_code)) AS normalized_code, MIN(table_id) AS keep_table_id
FROM restaurant_tables
GROUP BY UPPER(TRIM(table_code))
HAVING COUNT(*) > 1;

UPDATE restaurant_tables t
JOIN tmp_duplicate_table_codes d
  ON UPPER(TRIM(t.table_code)) = d.normalized_code
 AND t.table_id <> d.keep_table_id
SET t.notes = CONCAT_WS(' ', t.notes, CONCAT('[ซ่อนรหัสโต๊ะซ้ำเดิม: ', t.table_code, ']')),
    t.table_code = CONCAT('DEL', t.table_id),
    t.status = 'out_of_service',
    t.is_active = 0;

DROP TEMPORARY TABLE tmp_duplicate_table_codes;

UPDATE restaurant_tables
SET table_code = UPPER(TRIM(table_code))
WHERE is_active = 1;

-- ซ่อมโต๊ะที่ค้างเป็นใช้งาน แต่ไม่มีรอบโต๊ะเปิดอยู่แล้ว
UPDATE restaurant_tables t
SET t.status = 'available'
WHERE t.status = 'occupied'
  AND NOT EXISTS (
    SELECT 1
    FROM table_session_tables tst
    JOIN table_sessions ts ON ts.session_id = tst.session_id AND ts.status = 'open'
    WHERE tst.table_id = t.table_id
  );

-- ยืนยันสถานะของโต๊ะที่ผูกอยู่กับรอบโต๊ะเปิด
UPDATE restaurant_tables t
JOIN table_session_tables tst ON tst.table_id = t.table_id
JOIN table_sessions ts ON ts.session_id = tst.session_id AND ts.status = 'open'
SET t.status = 'occupied'
WHERE t.is_active = 1;

COMMIT;

-- เพิ่ม Unique Index เฉพาะกรณีฐานข้อมูลเก่ายังไม่มีข้อกำหนดรหัสโต๊ะไม่ซ้ำ
SET @has_unique_table_code = (
  SELECT COUNT(*) FROM (
    SELECT index_name
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'restaurant_tables'
      AND non_unique = 0
    GROUP BY index_name
    HAVING COUNT(*) = 1 AND SUM(column_name = 'table_code') = 1
  ) unique_indexes
);
SET @unique_table_sql = IF(
  @has_unique_table_code > 0,
  'SELECT 1',
  'ALTER TABLE restaurant_tables ADD UNIQUE INDEX uq_restaurant_tables_code (table_code)'
);
PREPARE unique_table_statement FROM @unique_table_sql;
EXECUTE unique_table_statement;
DEALLOCATE PREPARE unique_table_statement;
