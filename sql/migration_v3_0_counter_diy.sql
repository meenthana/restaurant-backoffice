-- v3.0 บัตร 2 ประเภท + เมนูตามสั่ง (DIY) ที่แคชเชียร์ + คลังคำหมายเหตุสำหรับสั่งด้วยเสียง (รันหลัง v2.13)
-- รันซ้ำได้ ไม่ลบข้อมูลเดิม

-- 1) ประเภทบัตรคิว: kiosk = บัตรตู้ (เมนูจานเดียว) · counter = บัตรอาหารตามสั่ง (สั่งกับพนักงาน)
ALTER TABLE queue_cards
  ADD COLUMN IF NOT EXISTS card_type ENUM('kiosk','counter') NOT NULL DEFAULT 'kiosk' AFTER card_no;

-- 2) เมนูตามสั่ง (DIY): เมนูฐานแสดงเฉพาะหน้าแคชเชียร์ ไม่แสดงที่ตู้/ออนไลน์
ALTER TABLE menu ADD COLUMN IF NOT EXISTS is_diy TINYINT(1) NOT NULL DEFAULT 0;

-- 3) กลุ่มตัวเลือกเสริม: เลือกขั้นต่ำ/สูงสุด (0 = ไม่จำกัด)
CREATE TABLE IF NOT EXISTS addon_groups (
  group_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  min_select INT UNSIGNED NOT NULL DEFAULT 0,
  max_select INT UNSIGNED NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE addons
  ADD COLUMN IF NOT EXISTS group_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0;

-- 4) ข้อมูลเริ่มต้นตามร้านจริง (แก้/ลบได้ในหลังบ้าน) · ใส่เฉพาะที่ยังไม่มี
INSERT INTO addon_groups (name, min_select, max_select, sort_order)
  SELECT 'เนื้อสัตว์', 1, 0, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM addon_groups WHERE name = 'เนื้อสัตว์');
INSERT INTO addon_groups (name, min_select, max_select, sort_order)
  SELECT 'ไข่', 0, 0, 2 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM addon_groups WHERE name = 'ไข่');
INSERT INTO addon_groups (name, min_select, max_select, sort_order)
  SELECT 'พิเศษ', 0, 1, 3 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM addon_groups WHERE name = 'พิเศษ');

INSERT INTO addons (name, extra_price, group_id, sort_order)
  SELECT t.name, 10, (SELECT group_id FROM addon_groups WHERE name = 'เนื้อสัตว์' LIMIT 1), t.ord
  FROM (SELECT 'หมูสับ' AS name, 1 AS ord UNION ALL SELECT 'หมูชิ้น', 2 UNION ALL SELECT 'หมูกรอบ', 3
        UNION ALL SELECT 'ไก่', 4 UNION ALL SELECT 'กุ้ง', 5 UNION ALL SELECT 'หมึก', 6 UNION ALL SELECT 'เนื้อ', 7) t
  WHERE NOT EXISTS (SELECT 1 FROM addons a WHERE a.name = t.name);
INSERT INTO addons (name, extra_price, group_id, sort_order)
  SELECT 'ไข่ดาว', 10, (SELECT group_id FROM addon_groups WHERE name = 'ไข่' LIMIT 1), 1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM addons WHERE name = 'ไข่ดาว');
INSERT INTO addons (name, extra_price, group_id, sort_order)
  SELECT 'พิเศษ', 10, (SELECT group_id FROM addon_groups WHERE name = 'พิเศษ' LIMIT 1), 1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM addons WHERE name = 'พิเศษ');
UPDATE addons SET group_id = (SELECT group_id FROM addon_groups WHERE name = 'ไข่' LIMIT 1)
  WHERE name IN ('ไข่ดาว', 'ไข่เจียว') AND group_id IS NULL;
UPDATE addons SET group_id = (SELECT group_id FROM addon_groups WHERE name = 'พิเศษ' LIMIT 1)
  WHERE name = 'พิเศษ' AND group_id IS NULL;

INSERT INTO menu (name, category, price, is_diy)
  SELECT t.name, 'ตามสั่ง (DIY)', 40, 1
  FROM (SELECT 'กะเพรา' AS name UNION ALL SELECT 'ผัดพริกแกง' UNION ALL SELECT 'ผัดพริกเผา' UNION ALL SELECT 'ผัดผงกะหรี่') t
  WHERE NOT EXISTS (SELECT 1 FROM menu m WHERE m.name = t.name AND m.is_diy = 1);

-- เมนูฐาน DIY ใช้ได้ทุกตัวเลือกในกลุ่มเนื้อสัตว์ ไข่ พิเศษ (ปรับรายเมนูได้ที่หน้าแก้ไขเมนู)
INSERT IGNORE INTO menu_addons (menu_id, addon_id)
  SELECT m.menu_id, a.addon_id FROM menu m
  JOIN addons a ON a.group_id IN (SELECT group_id FROM addon_groups WHERE name IN ('เนื้อสัตว์', 'ไข่', 'พิเศษ'))
  WHERE m.is_diy = 1;
