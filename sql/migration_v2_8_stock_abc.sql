-- v2.8 ตัดสต๊อกแบบแบ่งกลุ่มวัตถุดิบ (หลัก / รอง / ของตกแต่ง) + ตัวเลือกเสริมที่เพิ่มหรือตัดวัตถุดิบ
-- รันซ้ำได้ ไม่ลบข้อมูลเดิม

-- 1) กลุ่มวัตถุดิบ และเปิด/ปิดการนับสต๊อก
ALTER TABLE ingredients
  ADD COLUMN IF NOT EXISTS ingredient_type ENUM('main','secondary','garnish') NOT NULL DEFAULT 'main' AFTER unit,
  ADD COLUMN IF NOT EXISTS track_inventory TINYINT(1) NOT NULL DEFAULT 1 AFTER ingredient_type;

-- 2) ผลของตัวเลือกเสริมต่อสต๊อก (1 ตัวเลือก กระทบได้หลายวัตถุดิบ)
--    add    = ใช้เพิ่มตามจำนวน quantity ต่อ 1 จาน   เช่น ไข่ดาว: ไข่ +1, น้ำมัน +10
--    remove = ไม่ใส่วัตถุดิบนี้ (ตัดออกจากสูตรทั้งหมด)  เช่น ไม่ใส่หอมใหญ่
CREATE TABLE IF NOT EXISTS addon_ingredient_effects (
  effect_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  addon_id      INT UNSIGNED NOT NULL,
  ingredient_id INT UNSIGNED NOT NULL,
  effect        ENUM('add','remove') NOT NULL DEFAULT 'add',
  quantity      DECIMAL(10,2) DEFAULT NULL,
  PRIMARY KEY (effect_id),
  UNIQUE KEY uq_addon_ingredient (addon_id, ingredient_id),
  KEY fk_effect_ingredient (ingredient_id),
  CONSTRAINT fk_effect_addon FOREIGN KEY (addon_id) REFERENCES addons (addon_id) ON DELETE CASCADE,
  CONSTRAINT fk_effect_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients (ingredient_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ย้ายค่าเดิม (ตัวเลือกเสริมที่ผูกวัตถุดิบไว้ 1 ตัว) มาไว้ตารางใหม่
INSERT IGNORE INTO addon_ingredient_effects (addon_id, ingredient_id, effect, quantity)
SELECT addon_id, ingredient_id, 'add', ingredient_qty
FROM addons
WHERE ingredient_id IS NOT NULL AND ingredient_qty > 0;

-- 3) ประเภทการเคลื่อนไหว "ปรับยอดตามนับจริง"
ALTER TABLE stock_transactions
  MODIFY COLUMN type ENUM('purchase','order_deduction','wastage','conversion_out','conversion_in','adjustment') NOT NULL;

-- 4) จดว่ารายการอาหารนี้ตัดสต๊อกไปแล้ว (กันตัดซ้ำ)
ALTER TABLE order_items
  ADD COLUMN IF NOT EXISTS stock_deducted_at DATETIME DEFAULT NULL;

UPDATE order_items oi
JOIN orders o ON o.order_id = oi.order_id
SET oi.stock_deducted_at = COALESCE(o.closed_at, NOW())
WHERE oi.stock_deducted_at IS NULL AND o.status = 'closed';

UPDATE order_items oi
SET oi.stock_deducted_at = NOW()
WHERE oi.stock_deducted_at IS NULL
  AND EXISTS (SELECT 1 FROM stock_transactions st
              WHERE st.related_order_item_id = oi.order_item_id AND st.type = 'order_deduction');
