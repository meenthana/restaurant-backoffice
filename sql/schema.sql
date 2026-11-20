-- ============================================================
-- ระบบบริหารจัดการร้านอาหารและ POS ครบวงจร V2
-- Full Database Schema (MariaDB 10.4+ / MySQL 8.0+)
-- ============================================================

CREATE DATABASE IF NOT EXISTS restaurant_backoffice
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE restaurant_backoffice;

-- ------------------------------------------------------------
-- 0. users : พนักงาน/เจ้าของร้าน สำหรับล็อกอินและผูกความรับผิดชอบ (Accountability)
-- ------------------------------------------------------------
CREATE TABLE users (
  user_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)   NOT NULL UNIQUE,
  password_hash VARCHAR(255)  NOT NULL,   -- เก็บด้วย password_hash() ของ PHP ห้ามเก็บ plain text
  full_name     VARCHAR(150)  NOT NULL,
  role          ENUM('owner','staff') NOT NULL DEFAULT 'staff',
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 1. menu : รายการเมนูอาหารและราคาปัจจุบัน
-- ------------------------------------------------------------
CREATE TABLE menu (
  menu_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150)   NOT NULL,
  category     VARCHAR(100)   NOT NULL,
  price        DECIMAL(10,2)  NOT NULL,
  is_active    TINYINT(1)     NOT NULL DEFAULT 1,
  created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
               ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. ingredients : รายชื่อวัตถุดิบและหน่วยนับ
-- ------------------------------------------------------------
CREATE TABLE ingredients (
  ingredient_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150)  NOT NULL,
  unit          VARCHAR(20)   NOT NULL,   -- เช่น กรัม, ตัว, ฟอง, มิลลิลิตร
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. recipe (BOM) : ความสัมพันธ์เมนู <-> วัตถุดิบ และปริมาณที่ใช้ต่อ 1 จาน
-- ------------------------------------------------------------
CREATE TABLE recipe (
  recipe_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_id            INT UNSIGNED   NOT NULL,
  ingredient_id      INT UNSIGNED   NOT NULL,
  quantity_required  DECIMAL(10,2) NOT NULL,  -- ปริมาณที่ใช้ต่อ 1 เสิร์ฟ ตามหน่วยของ ingredients.unit
  CONSTRAINT fk_recipe_menu
    FOREIGN KEY (menu_id) REFERENCES menu(menu_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_recipe_ingredient
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id)
    ON DELETE RESTRICT,
  UNIQUE KEY uq_recipe_menu_ingredient (menu_id, ingredient_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5b. addons : ตัวเลือกเสริม (เช่น ไข่ดาว, ไข่เจียว, พิเศษ, เอาน้ำซุป)
--     master list ใช้ร่วมกันได้หลายเมนู แต่ละเมนูเลือกได้ว่าจะเปิดใช้ตัวไหนผ่าน menu_addons
-- ------------------------------------------------------------
CREATE TABLE addons (
  addon_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(150)   NOT NULL,
  extra_price     DECIMAL(10,2)  NOT NULL DEFAULT 0,   -- ราคาที่บวกเพิ่ม เช่น ไข่ดาว +10 บาท
  ingredient_id   INT UNSIGNED   NULL,                  -- วัตถุดิบที่ต้องหักเพิ่ม (ถ้ามี) เช่น ไข่ดาว -> ไข่ไก่
  ingredient_qty  DECIMAL(10,2)  NULL,                  -- ปริมาณที่หักต่อ 1 ครั้งที่เลือก
  is_active       TINYINT(1)     NOT NULL DEFAULT 1,
  created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_addon_ingredient
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id)
    ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5c. menu_addons : กำหนดว่าเมนูไหนเปิดให้เลือก Add-on ตัวไหนได้บ้าง
-- ------------------------------------------------------------
CREATE TABLE menu_addons (
  menu_id   INT UNSIGNED NOT NULL,
  addon_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (menu_id, addon_id),
  CONSTRAINT fk_menuaddon_menu
    FOREIGN KEY (menu_id) REFERENCES menu(menu_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_menuaddon_addon
    FOREIGN KEY (addon_id) REFERENCES addons(addon_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3b. members : ลูกค้าสมาชิกที่ผูกกับบัญชี LINE สำหรับสั่งออนไลน์และสะสมแต้ม
-- ------------------------------------------------------------
CREATE TABLE members (
  member_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  line_user_id    VARCHAR(64)   NOT NULL UNIQUE,   -- User ID จาก LINE Login (ในโหมด Mock จะเป็นค่าจำลอง)
  display_name    VARCHAR(150)  NOT NULL,
  points          INT UNSIGNED  NOT NULL DEFAULT 0,
  no_show_count   INT UNSIGNED  NOT NULL DEFAULT 0,
  is_suspended    TINYINT(1)    NOT NULL DEFAULT 0,  -- ระงับสิทธิ์สั่งออนไลน์เมื่อ no_show_count เกินเกณฑ์
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3c. restaurant_tables / table_sessions : ผังโต๊ะและรอบการใช้งานโต๊ะ
-- ------------------------------------------------------------
CREATE TABLE restaurant_tables (
  table_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_code     VARCHAR(20)  NOT NULL UNIQUE,
  capacity       INT UNSIGNED NOT NULL,
  zone_name      VARCHAR(100) NOT NULL DEFAULT 'โซนหลัก',
  position_x     INT          NOT NULL DEFAULT 0,
  position_y     INT          NOT NULL DEFAULT 0,
  status         ENUM('available','held','reserved','occupied','cleaning','out_of_service')
                 NOT NULL DEFAULT 'available',
  merge_group    VARCHAR(30)  NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE table_sessions (
  session_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  guest_count    INT UNSIGNED NOT NULL,
  status         ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
  opened_by      INT UNSIGNED NOT NULL,
  opened_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at      DATETIME     NULL,
  CONSTRAINT fk_table_sessions_user
    FOREIGN KEY (opened_by) REFERENCES users(user_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE table_session_tables (
  session_id INT UNSIGNED NOT NULL,
  table_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (session_id, table_id),
  CONSTRAINT fk_tst_session FOREIGN KEY (session_id) REFERENCES table_sessions(session_id) ON DELETE CASCADE,
  CONSTRAINT fk_tst_table FOREIGN KEY (table_id) REFERENCES restaurant_tables(table_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. orders : ออเดอร์แต่ละใบ ควบคุมสถานะด้วย Finite State Machine
--    status ขยายเพิ่มจากเดิมเพื่อรองรับออเดอร์ออนไลน์:
--    pending (รอยืนยัน) -> active (กำลังเตรียม) -> ready (พร้อมรับ, เฉพาะออนไลน์)
--    -> closed (ปิด/รับแล้ว) หรือ no_show (ไม่มารับ, เฉพาะออนไลน์)
--    ออเดอร์หน้าร้านใช้แค่ pending -> active -> closed เหมือนเดิม (ข้าม ready)
-- ------------------------------------------------------------
CREATE TABLE orders (
  order_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  status           ENUM('pending','active','ready','closed','no_show','cancelled') NOT NULL DEFAULT 'pending',
  channel          ENUM('walkin','online') NOT NULL DEFAULT 'walkin',
  service_type     ENUM('dine_in','takeaway') NOT NULL DEFAULT 'dine_in',
  table_session_id INT UNSIGNED  NULL,
  member_id        INT UNSIGNED  NULL,           -- ผูกกับสมาชิกถ้าสั่งออนไลน์ (หรือ walk-in ที่เป็นสมาชิกด้วยก็ได้)
  note             VARCHAR(255)  NULL,            -- เช่น เลขโต๊ะ/ชื่ออ้างอิง (ไม่มี logic จัดโต๊ะ)
  scheduled_at     DATETIME      NULL,            -- เวลานัดรับสำหรับออเดอร์ออนไลน์
  pickup_deadline  DATETIME      NULL,            -- กรอบเวลาที่ต้องมารับ (เฉพาะออนไลน์ ตั้งตอนเปลี่ยนเป็น ready)
  is_paid          TINYINT(1)    NOT NULL DEFAULT 0,
  payment_method   ENUM('cash','transfer') NULL,
  paid_at          DATETIME      NULL,
  opened_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at        DATETIME      NULL,
  CONSTRAINT fk_orders_table_session
    FOREIGN KEY (table_session_id) REFERENCES table_sessions(session_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_orders_member
    FOREIGN KEY (member_id) REFERENCES members(member_id)
    ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO restaurant_tables
  (table_code, capacity, zone_name, position_x, position_y, merge_group)
VALUES
  ('T01', 2,  'โซน A', 1, 1, 'A1'), ('T02', 2,  'โซน A', 2, 1, 'A1'),
  ('T03', 2,  'โซน A', 3, 1, 'A2'), ('T04', 2,  'โซน A', 4, 1, 'A2'),
  ('T05', 4,  'โซน B', 1, 2, 'B1'), ('T06', 4,  'โซน B', 2, 2, 'B1'),
  ('T07', 4,  'โซน B', 3, 2, 'B2'), ('T08', 4,  'โซน B', 4, 2, 'B2'),
  ('T09', 6,  'โซน C', 1, 3, 'C1'), ('T10', 12, 'โซน C', 3, 3, 'C1');

-- ------------------------------------------------------------
-- 5. order_items : รายการอาหารในแต่ละออเดอร์ พร้อมล็อกราคา ณ เวลาสั่ง
-- ------------------------------------------------------------
CREATE TABLE order_items (
  order_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED  NOT NULL,
  menu_id       INT UNSIGNED  NOT NULL,
  quantity      INT UNSIGNED  NOT NULL,
  unit_price    DECIMAL(10,2) NOT NULL,          -- ราคา ณ เวลาที่สั่ง (ล็อกไว้ ไม่อ้างอิง menu.price ตรงๆ)
  subtotal      DECIMAL(10,2) AS (quantity * unit_price) STORED,
  cost_of_goods DECIMAL(10,2) NULL,              -- ต้นทุนจริงที่หักจาก stock (คำนวณตอนปิดออเดอร์ด้วย FEFO)
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_orderitems_order
    FOREIGN KEY (order_id) REFERENCES orders(order_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_orderitems_menu
    FOREIGN KEY (menu_id) REFERENCES menu(menu_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5d. order_item_addons : ตัวเลือกเสริมที่ถูกเลือกจริงในแต่ละรายการอาหาร
--     ล็อกชื่อ+ราคา ณ เวลาสั่ง เช่นเดียวกับ order_items.unit_price
-- ------------------------------------------------------------
CREATE TABLE order_item_addons (
  order_item_addon_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_item_id       INT UNSIGNED  NOT NULL,
  addon_id             INT UNSIGNED  NOT NULL,
  addon_name           VARCHAR(150)  NOT NULL,   -- ล็อกชื่อ ณ เวลาสั่ง
  addon_price          DECIMAL(10,2) NOT NULL,   -- ล็อกราคา ณ เวลาสั่ง
  created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_itemaddon_orderitem
    FOREIGN KEY (order_item_id) REFERENCES order_items(order_item_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_itemaddon_addon
    FOREIGN KEY (addon_id) REFERENCES addons(addon_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. stock_batches : ล็อตวัตถุดิบแต่ละครั้งที่ซื้อเข้า/แปรรูป
-- ------------------------------------------------------------
CREATE TABLE stock_batches (
  batch_id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ingredient_id       INT UNSIGNED   NOT NULL,
  source_batch_id     INT UNSIGNED   NULL,   -- ไม่ NULL เมื่อ batch นี้เกิดจากการแปรรูป (Stock Conversion)
  quantity_purchased  DECIMAL(10,2)  NOT NULL,
  quantity_remaining  DECIMAL(10,2)  NOT NULL,
  cost_per_unit       DECIMAL(10,4)  NOT NULL,
  purchase_date       DATE           NOT NULL,
  expiry_date         DATE           NULL,
  received_by         INT UNSIGNED   NULL,   -- ผู้บันทึกรับของเข้า (Accountability)
  receipt_photo        VARCHAR(255)   NULL,   -- path รูปหลักฐานใบส่งของ/ใบเสร็จ
  created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_batch_ingredient
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_batch_source
    FOREIGN KEY (source_batch_id) REFERENCES stock_batches(batch_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_batch_received_by
    FOREIGN KEY (received_by) REFERENCES users(user_id)
    ON DELETE SET NULL,
  INDEX idx_batch_ingredient_expiry (ingredient_id, expiry_date)   -- ใช้เร่งความเร็ว query แบบ FEFO
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7. stock_transactions : ประวัติการเคลื่อนไหวของสต๊อกทุกประเภท
--    type: purchase (ซื้อเข้า), order_deduction (ตัดขาย),
--          wastage (ของเสีย), conversion_out/conversion_in (แปรรูป)
-- ------------------------------------------------------------
CREATE TABLE stock_transactions (
  transaction_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id                INT UNSIGNED  NOT NULL,
  type                    ENUM('purchase','order_deduction','wastage',
                                'conversion_out','conversion_in') NOT NULL,
  quantity                DECIMAL(10,2) NOT NULL,   -- ค่าบวก=เข้า, ค่าลบ=ออก
  related_order_id        INT UNSIGNED  NULL,
  related_order_item_id   INT UNSIGNED  NULL,
  note                    VARCHAR(255)  NULL,
  created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_txn_batch
    FOREIGN KEY (batch_id) REFERENCES stock_batches(batch_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_txn_order
    FOREIGN KEY (related_order_id) REFERENCES orders(order_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_txn_order_item
    FOREIGN KEY (related_order_item_id) REFERENCES order_items(order_item_id)
    ON DELETE SET NULL,
  INDEX idx_txn_batch (batch_id),
  INDEX idx_txn_order (related_order_id)
) ENGINE=InnoDB;

-- ============================================================
-- ตัวอย่างข้อมูลเริ่มต้น (Seed Data) สำหรับทดสอบระบบ
-- ============================================================
-- หมายเหตุ: ไม่ seed ตาราง users ไว้ที่นี่โดยเจตนา
-- ให้สร้างบัญชีเจ้าของร้าน (Owner) คนแรกผ่านหน้า setup.php เท่านั้น
-- เพราะ setup.php จะ hash รหัสผ่านให้ถูกต้องจริงด้วย password_hash()
-- ถ้า insert user ไว้ในไฟล์ .sql ตรงๆ จะได้ password hash ปลอมที่ login ไม่ได้
-- และจะทำให้ setup.php บล็อกตัวเอง (เข้าใจผิดว่ามี user อยู่แล้ว)

INSERT INTO ingredients (name, unit) VALUES
  ('หมูสับ', 'กรัม'),
  ('หมูชิ้น', 'กรัม'),
  ('กุ้ง', 'ตัว'),
  ('ไข่ไก่', 'ฟอง'),
  ('เส้นผัดไทย', 'กรัม');

INSERT INTO menu (name, category, price) VALUES
  ('กะเพราหมูสับ', 'อาหารจานเดียว', 60.00),
  ('ผัดไทยกุ้งสด', 'อาหารจานเดียว', 150.00),
  ('ต้มยำกุ้ง', 'ต้ม/แกง', 220.00);

-- BOM: กะเพราหมูสับ ใช้หมูสับ 100 กรัม + ไข่ไก่ 1 ฟอง
INSERT INTO recipe (menu_id, ingredient_id, quantity_required) VALUES
  (1, 1, 100.00),
  (1, 4, 1.00);

-- BOM: ผัดไทยกุ้งสด ใช้เส้นผัดไทย 150 กรัม + กุ้ง 5 ตัว + ไข่ไก่ 1 ฟอง
INSERT INTO recipe (menu_id, ingredient_id, quantity_required) VALUES
  (2, 5, 150.00),
  (2, 3, 5.00),
  (2, 4, 1.00);

-- ตัวอย่างล็อตวัตถุดิบ (ซื้อหมู 1,000 กรัม แบ่งเป็นหมูสับ)
-- received_by เว้นว่างไว้ (NULL) เพราะยังไม่มี user จนกว่าจะสร้างผ่าน setup.php
INSERT INTO stock_batches
  (ingredient_id, quantity_purchased, quantity_remaining, cost_per_unit, purchase_date, expiry_date)
VALUES
  (1, 1000.00, 1000.00, 0.18, CURRENT_DATE, DATE_ADD(CURRENT_DATE, INTERVAL 5 DAY));

INSERT INTO stock_transactions (batch_id, type, quantity, note)
VALUES (1, 'purchase', 1000.00, 'ซื้อหมูสับเข้าคลัง 1 กก.');

-- ตัวเลือกเสริม (Add-on) ตัวอย่าง
-- ไข่ดาว/ไข่เจียว ผูกกับวัตถุดิบ ไข่ไก่ (ingredient_id = 4) เพื่อให้หักสต๊อกเพิ่มได้ตอนสั่ง
INSERT INTO addons (name, extra_price, ingredient_id, ingredient_qty) VALUES
  ('ไข่ดาว', 10.00, 4, 1.00),
  ('ไข่เจียว', 15.00, 4, 2.00),
  ('พิเศษ', 15.00, NULL, NULL),
  ('เอาน้ำซุป', 10.00, NULL, NULL);

-- กำหนดว่าแต่ละเมนูเลือก Add-on ตัวไหนได้บ้าง
INSERT INTO menu_addons (menu_id, addon_id) VALUES
  (1, 1), (1, 2), (1, 3),   -- กะเพราหมูสับ: ไข่ดาว, ไข่เจียว, พิเศษ
  (2, 1), (2, 3),           -- ผัดไทยกุ้งสด: ไข่ดาว, พิเศษ
  (3, 3), (3, 4);           -- ต้มยำกุ้ง: พิเศษ, เอาน้ำซุป

-- หลัง Import ไฟล์นี้ ให้ Import migration_v2_complete.sql ต่อทันที
-- เพื่อสร้างตารางคิว จองโต๊ะ ครัว การชำระ/คืนเงิน และบันทึกเหตุการณ์ของ V2
