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

-- ============================================================
-- ส่วนขยาย V2 (รวมไว้สำหรับติดตั้งใหม่ในไฟล์เดียว)
-- ============================================================
-- ============================================================
-- Restaurant Backoffice V2 - Database Upgrade
-- ใช้สำหรับฐานข้อมูลเดิมที่ติดตั้ง schema.sql เวอร์ชันก่อนหน้าแล้ว
-- สำรองฐานข้อมูลก่อน Import และรันไฟล์นี้เพียงหนึ่งครั้ง
-- รองรับ MariaDB 10.4+ / MySQL 8+
-- ============================================================

USE restaurant_backoffice;

ALTER TABLE users
  MODIFY role ENUM('owner','manager','cashier','kitchen','staff') NOT NULL DEFAULT 'staff';

ALTER TABLE members
  ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NULL AFTER display_name,
  ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL AFTER phone,
  ADD COLUMN IF NOT EXISTS last_visit_at DATETIME NULL AFTER is_suspended;

ALTER TABLE restaurant_tables
  ADD COLUMN IF NOT EXISTS table_shape ENUM('square','round','rectangle') NOT NULL DEFAULT 'square' AFTER zone_name,
  ADD COLUMN IF NOT EXISTS notes VARCHAR(255) NULL AFTER merge_group;

ALTER TABLE table_sessions
  ADD COLUMN IF NOT EXISTS reservation_id INT UNSIGNED NULL AFTER opened_by,
  ADD COLUMN IF NOT EXISTS queue_entry_id INT UNSIGNED NULL AFTER reservation_id,
  ADD COLUMN IF NOT EXISTS session_note VARCHAR(255) NULL AFTER queue_entry_id;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS order_code VARCHAR(30) NULL AFTER order_id,
  ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid','partial','paid','refunded','partially_refunded') NOT NULL DEFAULT 'unpaid' AFTER is_paid,
  ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER payment_status,
  ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(255) NULL AFTER closed_at,
  ADD COLUMN IF NOT EXISTS cancelled_by INT UNSIGNED NULL AFTER cancel_reason,
  ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER cancelled_by;

ALTER TABLE order_items
  ADD COLUMN IF NOT EXISTS item_note VARCHAR(255) NULL AFTER quantity,
  ADD COLUMN IF NOT EXISTS voided_at DATETIME NULL AFTER cost_of_goods,
  ADD COLUMN IF NOT EXISTS voided_by INT UNSIGNED NULL AFTER voided_at,
  ADD COLUMN IF NOT EXISTS void_reason VARCHAR(255) NULL AFTER voided_by;

CREATE TABLE IF NOT EXISTS store_settings (
  setting_key   VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_by    INT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
  reservation_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_code VARCHAR(30) NOT NULL UNIQUE,
  member_id        INT UNSIGNED NULL,
  guest_name       VARCHAR(150) NOT NULL,
  phone            VARCHAR(30) NOT NULL,
  guest_count      INT UNSIGNED NOT NULL,
  reservation_at   DATETIME NOT NULL,
  duration_minutes INT UNSIGNED NOT NULL DEFAULT 120,
  status           ENUM('pending','confirmed','checked_in','seated','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
  note             VARCHAR(255) NULL,
  deposit_amount   DECIMAL(10,2) NOT NULL DEFAULT 0,
  refund_status    ENUM('none','pending','completed','rejected') NOT NULL DEFAULT 'none',
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservation_member FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE SET NULL,
  CONSTRAINT fk_reservation_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_reservation_time_status (reservation_at, status),
  INDEX idx_reservation_phone (phone)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservation_tables (
  reservation_id INT UNSIGNED NOT NULL,
  table_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (reservation_id, table_id),
  CONSTRAINT fk_res_table_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_res_table_table FOREIGN KEY (table_id) REFERENCES restaurant_tables(table_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS queue_entries (
  queue_entry_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  queue_number    VARCHAR(20) NOT NULL,
  queue_date      DATE NOT NULL,
  source          ENUM('walkin','reservation') NOT NULL DEFAULT 'walkin',
  reservation_id  INT UNSIGNED NULL,
  member_id       INT UNSIGNED NULL,
  guest_name      VARCHAR(150) NOT NULL,
  phone           VARCHAR(30) NULL,
  guest_count     INT UNSIGNED NOT NULL,
  status          ENUM('waiting','called','seated','cancelled','no_show') NOT NULL DEFAULT 'waiting',
  note            VARCHAR(255) NULL,
  called_at       DATETIME NULL,
  seated_at       DATETIME NULL,
  table_session_id INT UNSIGNED NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_queue_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE SET NULL,
  CONSTRAINT fk_queue_member FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE SET NULL,
  CONSTRAINT fk_queue_session FOREIGN KEY (table_session_id) REFERENCES table_sessions(session_id) ON DELETE SET NULL,
  CONSTRAINT fk_queue_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
  UNIQUE KEY uq_queue_daily_number (queue_date, queue_number),
  INDEX idx_queue_date_status (queue_date, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kitchen_tickets (
  ticket_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        INT UNSIGNED NOT NULL,
  round_no        INT UNSIGNED NOT NULL DEFAULT 1,
  status          ENUM('queued','cooking','ready','served','cancelled') NOT NULL DEFAULT 'queued',
  priority        ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
  note            VARCHAR(255) NULL,
  sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at      DATETIME NULL,
  ready_at        DATETIME NULL,
  served_at       DATETIME NULL,
  updated_by      INT UNSIGNED NULL,
  CONSTRAINT fk_ticket_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_user FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
  UNIQUE KEY uq_ticket_order_round (order_id, round_no),
  INDEX idx_ticket_status_time (status, sent_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kitchen_ticket_items (
  ticket_id     INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (ticket_id, order_item_id),
  CONSTRAINT fk_ticket_item_ticket FOREIGN KEY (ticket_id) REFERENCES kitchen_tickets(ticket_id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_item_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(order_item_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  payment_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        INT UNSIGNED NOT NULL,
  transaction_type ENUM('payment','refund') NOT NULL DEFAULT 'payment',
  method          ENUM('cash','transfer','mock_online') NOT NULL,
  amount          DECIMAL(10,2) NOT NULL,
  status          ENUM('pending','completed','void') NOT NULL DEFAULT 'completed',
  reference_no    VARCHAR(100) NULL,
  note            VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE RESTRICT,
  CONSTRAINT fk_payment_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_payment_order_status (order_id, status),
  INDEX idx_payment_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS refund_requests (
  refund_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        INT UNSIGNED NOT NULL,
  payment_id      INT UNSIGNED NULL,
  amount          DECIMAL(10,2) NOT NULL,
  reason          VARCHAR(255) NOT NULL,
  kitchen_started TINYINT(1) NOT NULL DEFAULT 0,
  status          ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  requested_by    INT UNSIGNED NOT NULL,
  approved_by     INT UNSIGNED NULL,
  requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at      DATETIME NULL,
  CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE SET NULL,
  CONSTRAINT fk_refund_request_user FOREIGN KEY (requested_by) REFERENCES users(user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_approve_user FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_refund_status (status, requested_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_status_logs (
  log_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED NOT NULL,
  from_status   VARCHAR(30) NULL,
  to_status     VARCHAR(30) NOT NULL,
  changed_by    INT UNSIGNED NULL,
  note          VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_log_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
  CONSTRAINT fk_order_log_user FOREIGN KEY (changed_by) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_order_log_order_time (order_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  audit_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NULL,
  action        VARCHAR(100) NOT NULL,
  entity_type   VARCHAR(50) NOT NULL,
  entity_id     VARCHAR(50) NULL,
  detail        TEXT NULL,
  ip_address    VARCHAR(45) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  INDEX idx_audit_entity (entity_type, entity_id),
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

-- เพิ่ม Foreign Key หลังสร้างตารางปลายทางแล้ว
ALTER TABLE table_sessions
  ADD CONSTRAINT fk_table_session_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_table_session_queue FOREIGN KEY (queue_entry_id) REFERENCES queue_entries(queue_entry_id) ON DELETE SET NULL;

ALTER TABLE orders
  ADD CONSTRAINT fk_orders_cancel_user FOREIGN KEY (cancelled_by) REFERENCES users(user_id) ON DELETE SET NULL;

ALTER TABLE order_items
  ADD CONSTRAINT fk_order_item_void_user FOREIGN KEY (voided_by) REFERENCES users(user_id) ON DELETE SET NULL;

INSERT INTO store_settings (setting_key, setting_value) VALUES
  ('store_name', 'ครัวธนุรเวท'),
  ('store_phone', ''),
  ('store_address', ''),
  ('currency', 'THB'),
  ('pickup_lead_minutes', '30'),
  ('pickup_window_minutes', '30'),
  ('reservation_grace_minutes', '15'),
  ('default_dining_minutes', '120'),
  ('online_payment_mode', 'mock')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

UPDATE orders
SET order_code = CONCAT('ORD-', DATE_FORMAT(opened_at, '%y%m%d'), '-', LPAD(order_id, 5, '0'))
WHERE order_code IS NULL OR order_code = '';

UPDATE orders SET payment_status = IF(is_paid = 1, 'paid', 'unpaid');

-- ย้ายข้อมูลการชำระเงินเดิมเข้าสู่ตาราง payments โดยไม่สร้างซ้ำ
INSERT INTO payments (order_id, transaction_type, method, amount, status, note, created_at)
SELECT o.order_id,
       'payment',
       IF(o.channel = 'online', 'mock_online', IFNULL(o.payment_method, 'cash')),
       COALESCE((SELECT SUM(oi.subtotal) FROM order_items oi WHERE oi.order_id = o.order_id), 0)
       + COALESCE((SELECT SUM(oia.addon_price * oi2.quantity)
                   FROM order_item_addons oia
                   JOIN order_items oi2 ON oi2.order_item_id = oia.order_item_id
                   WHERE oi2.order_id = o.order_id), 0),
       'completed',
       'นำเข้าจากข้อมูลการชำระเงินเวอร์ชันเดิม',
       COALESCE(o.paid_at, o.opened_at)
FROM orders o
WHERE o.is_paid = 1
  AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.order_id AND p.transaction_type = 'payment');

-- สร้างใบงานครัวรอบแรกให้ออเดอร์ที่ยังเปิดอยู่
INSERT INTO kitchen_tickets (order_id, round_no, status, sent_at)
SELECT o.order_id, 1,
       CASE o.status
         WHEN 'active' THEN 'cooking'
         WHEN 'ready' THEN 'ready'
         ELSE 'queued'
       END,
       o.opened_at
FROM orders o
WHERE o.status IN ('pending','active','ready')
  AND NOT EXISTS (SELECT 1 FROM kitchen_tickets kt WHERE kt.order_id = o.order_id);

INSERT IGNORE INTO kitchen_ticket_items (ticket_id, order_item_id)
SELECT kt.ticket_id, oi.order_item_id
FROM kitchen_tickets kt
JOIN order_items oi ON oi.order_id = kt.order_id;



-- ============================================================
-- ส่วนอัปเดต V2.1 : การจัดการโต๊ะ
-- ============================================================
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


-- ============================================================
-- ส่วนอัปเดต V2.2 : สมาชิกและคะแนน
-- ============================================================
-- ============================================================
-- อัปเดต V2.2 : ระบบสมาชิกและประวัติแต้ม
-- ใช้ต่อจากฐานข้อมูล V2.1
-- ============================================================

USE restaurant_backoffice;

CREATE TABLE IF NOT EXISTS member_point_transactions (
  point_transaction_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id            INT UNSIGNED NOT NULL,
  transaction_type     ENUM('earn','adjustment','reversal') NOT NULL,
  points_change        INT NOT NULL,
  balance_after        INT UNSIGNED NOT NULL,
  order_id             INT UNSIGNED NULL,
  note                 VARCHAR(255) NULL,
  created_by           INT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_member_point_member FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
  CONSTRAINT fk_member_point_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE SET NULL,
  CONSTRAINT fk_member_point_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
  UNIQUE KEY uq_member_point_order_type (member_id, order_id, transaction_type),
  INDEX idx_member_point_history (member_id, created_at)
) ENGINE=InnoDB;

INSERT INTO member_point_transactions
  (member_id, transaction_type, points_change, balance_after, note)
SELECT m.member_id, 'adjustment', m.points, m.points, 'ยอดแต้มยกมาจากระบบเดิม'
FROM members m
WHERE m.points > 0
  AND NOT EXISTS (
    SELECT 1 FROM member_point_transactions mpt WHERE mpt.member_id = m.member_id
  );

INSERT INTO store_settings (setting_key, setting_value) VALUES
  ('baht_per_point', '25')
ON DUPLICATE KEY UPDATE setting_value = setting_value;


-- ============================================================
-- ส่วนอัปเดต V2.3 : PIN Quick Login
-- ============================================================
-- ============================================================
-- ส่วนอัปเดต V2.3 : PIN Quick Login สำหรับพนักงาน
-- ใช้ IF NOT EXISTS ปลอดภัย รันซ้ำได้
-- ============================================================

USE restaurant_backoffice;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS pin_hash VARCHAR(255) NULL;

SELECT 'เพิ่มคอลัมน์ pin_hash สำเร็จแล้ว' AS result;


-- ============================================================
-- ส่วนอัปเดต V2.4 : รูปภาพเมนู
-- ============================================================
-- ============================================================
-- ส่วนอัปเดต V2.4 : รูปภาพเมนู
-- ใช้ IF NOT EXISTS ปลอดภัย รันซ้ำได้
-- ============================================================

USE restaurant_backoffice;

ALTER TABLE menu
  ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL;

SELECT 'เพิ่มคอลัมน์ image_path ในตาราง menu สำเร็จแล้ว' AS result;


-- ============================================================
-- ส่วนอัปเดต V2.5 : ปิดขายเมนูชั่วคราว
-- ============================================================
-- ============================================================
-- ส่วนอัปเดต V2.5 : ปิดขายเมนูชั่วคราว (86 Item)
-- ใช้ IF NOT EXISTS ปลอดภัย รันซ้ำได้
-- ============================================================

USE restaurant_backoffice;

-- ถ้ามีค่าและเป็นวันที่ปัจจุบันหรือหลังจากนี้ = หมดชั่วคราว
-- ถ้าเป็นวันที่ผ่านไปแล้วหรือเป็น NULL = ขายได้ตามปกติ (รีเซ็ตอัตโนมัติข้ามวัน ไม่ต้องพึ่ง cron job)
ALTER TABLE menu
  ADD COLUMN IF NOT EXISTS sold_out_date DATE NULL;

SELECT 'เพิ่มคอลัมน์ sold_out_date ในตาราง menu สำเร็จแล้ว' AS result;


-- ============================================================
-- ส่วนอัปเดต V2.6 : Reorder Point
-- ============================================================
-- ============================================================
-- ส่วนอัปเดต V2.6 : Reorder Point ต่อวัตถุดิบ
-- ใช้ IF NOT EXISTS ปลอดภัย รันซ้ำได้
-- ============================================================

USE restaurant_backoffice;

ALTER TABLE ingredients
  ADD COLUMN IF NOT EXISTS reorder_point DECIMAL(10,2) NULL;

SELECT 'เพิ่มคอลัมน์ reorder_point ในตาราง ingredients สำเร็จแล้ว' AS result;


-- migration_v2_7_selfservice.sql
-- ============================================================
-- Migration v2.7 : Self-Service Kiosk + ตรวจสลิป + สั่งด้วยเสียง
-- รันซ้ำได้ปลอดภัย (IF NOT EXISTS / INSERT IGNORE ทุกจุด)
-- ต้องรัน v2.3 - v2.6 มาก่อนแล้ว
-- ============================================================

USE restaurant_backoffice;

-- ------------------------------------------------------------
-- 1. ออเดอร์จากตู้ Self-Service
--    ENUM ต้องประกาศค่าเดิมครบทุกตัว ไม่อย่างนั้น MySQL จะเก็บค่าที่หายไปเป็นค่าว่าง
-- ------------------------------------------------------------
ALTER TABLE orders
  MODIFY COLUMN channel ENUM('walkin','online','kiosk') NOT NULL DEFAULT 'walkin';

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS queue_no     SMALLINT UNSIGNED NULL AFTER order_code,
  ADD COLUMN IF NOT EXISTS access_token CHAR(32) NULL;

-- เลขคิวเริ่มนับ 1 ใหม่ทุกวัน จึงค้นด้วยวันที่บ่อย
ALTER TABLE orders
  ADD INDEX IF NOT EXISTS idx_orders_queue (opened_at, queue_no);

-- ------------------------------------------------------------
-- 2. วิธีชำระเงินแบบโอนแล้วตรวจสลิป
-- ------------------------------------------------------------
ALTER TABLE payments
  MODIFY COLUMN method ENUM('cash','transfer','transfer_slip','mock_online') NOT NULL;

-- ------------------------------------------------------------
-- 3. payment_slips : สลิปที่ลูกค้าส่งเข้ามา
--    trans_ref เป็น UNIQUE = กันการส่งสลิปใบเดิมซ้ำระดับฐานข้อมูล
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_slips (
  slip_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        INT UNSIGNED NOT NULL,
  payment_id      INT UNSIGNED NULL,
  file_path       VARCHAR(255) NULL,
  trans_ref       VARCHAR(64)  NULL,
  sending_bank    VARCHAR(20)  NULL,
  sender_name     VARCHAR(150) NULL,
  receiver_name   VARCHAR(150) NULL,
  amount          DECIMAL(10,2) NULL,
  slip_datetime   DATETIME NULL,
  expected_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  status          ENUM('pending','verified','rejected','duplicate','manual') NOT NULL DEFAULT 'pending',
  verify_source   ENUM('api','mock','manual') NOT NULL DEFAULT 'mock',
  reject_reason   VARCHAR(255) NULL,
  raw_response    TEXT NULL,
  verified_by     INT UNSIGNED NULL,
  verified_at     DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_slip_order   FOREIGN KEY (order_id)    REFERENCES orders(order_id)     ON DELETE CASCADE,
  CONSTRAINT fk_slip_payment FOREIGN KEY (payment_id)  REFERENCES payments(payment_id) ON DELETE SET NULL,
  CONSTRAINT fk_slip_user    FOREIGN KEY (verified_by) REFERENCES users(user_id)       ON DELETE SET NULL,
  UNIQUE KEY uniq_slip_transref (trans_ref),
  INDEX idx_slip_order (order_id),
  INDEX idx_slip_status (status, created_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. สั่งอาหารด้วยเสียง
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voice_order_logs (
  log_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source          ENUM('staff','customer','kiosk') NOT NULL DEFAULT 'staff',
  user_id         INT UNSIGNED NULL,
  member_id       INT UNSIGNED NULL,
  transcript      VARCHAR(500) NOT NULL,
  parsed_json     TEXT NULL,
  matched_count   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  unmatched_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  confirmed       TINYINT(1) NOT NULL DEFAULT 0,
  order_id        INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_voice_created (created_at)
) ENGINE=InnoDB;

-- เผื่อเคยรันเวอร์ชันก่อนหน้าที่ยังไม่มีค่า 'kiosk'
ALTER TABLE voice_order_logs
  MODIFY COLUMN source ENUM('staff','customer','kiosk') NOT NULL DEFAULT 'staff';

-- คำเรียกลัดของเมนู เช่น "กะเพราหมู" -> "ข้าวกะเพราหมูสับ"
CREATE TABLE IF NOT EXISTS menu_aliases (
  alias_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_id   INT UNSIGNED NOT NULL,
  alias     VARCHAR(150) NOT NULL,
  CONSTRAINT fk_alias_menu FOREIGN KEY (menu_id) REFERENCES menu(menu_id) ON DELETE CASCADE,
  UNIQUE KEY uniq_alias (alias)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. ค่าตั้งต้น (ใส่เฉพาะที่ยังไม่มี ไม่ทับค่าที่ร้านตั้งไว้แล้ว)
-- ------------------------------------------------------------
INSERT IGNORE INTO store_settings (setting_key, setting_value) VALUES
  ('promptpay_id',                 ''),     -- เบอร์มือถือหรือเลขบัตรประชาชนที่ผูกพร้อมเพย์
  ('kiosk_idle_seconds',           '90'),   -- ไม่มีคนแตะหน้าจอกี่วินาที ถึงล้างตะกร้ากลับหน้าแรก
  ('kiosk_unpaid_expire_minutes',  '30'),   -- ออเดอร์เงินสดที่ไม่มาจ่ายเกินกี่นาที ยกเลิกให้อัตโนมัติ
  ('slip_verify_mode',             'mock'), -- mock | api
  ('slip_amount_tolerance',        '1.00'),
  ('slip_max_age_hours',           '24'),
  ('voice_order_enabled',          '1'),
  ('voice_match_threshold',        '0.62');

SELECT 'Migration v2.7 (Self-Service + สลิป + เสียง) เสร็จสมบูรณ์' AS result;


-- migration_v2_8_stock_abc.sql
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


-- migration_v2_9_service_flow.sql
-- Additive service upgrade. Back up the live DB first. MariaDB DDL implicitly commits.
-- No historical orders, stock balances or points are reset.
USE restaurant_backoffice;
ALTER TABLE members MODIFY COLUMN line_user_id VARCHAR(100) NULL;

CREATE TABLE IF NOT EXISTS member_phone_accounts (
 phone VARCHAR(10) NOT NULL PRIMARY KEY,
 member_id INT UNSIGNED NULL,
 UNIQUE KEY uq_phone_member(member_id),
 CONSTRAINT fk_phone_member FOREIGN KEY(member_id) REFERENCES members(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS menu_queue_rules (
 menu_id INT UNSIGNED NOT NULL PRIMARY KEY,
 counts_as_dish TINYINT(1) NOT NULL DEFAULT 1,
 CONSTRAINT fk_queue_menu FOREIGN KEY(menu_id) REFERENCES menu(menu_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_service_details (
 order_id INT UNSIGNED NOT NULL PRIMARY KEY,
 queue_type ENUM('single','multi','other') NOT NULL,
 dish_count INT UNSIGNED NOT NULL,
 receipt_preference ENUM('paper','digital') NOT NULL DEFAULT 'digital',
 tracking_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 pickup_hash VARCHAR(255) NOT NULL,
 vat_rate DECIMAL(5,2) NULL,
 gross_amount DECIMAL(12,2) NULL,
 net_amount DECIMAL(12,2) NULL,
 vat_amount DECIMAL(12,2) NULL,
 tax_recorded_at DATETIME NULL,
 UNIQUE KEY uq_service_tracking(tracking_hash),
 CONSTRAINT fk_service_order FOREIGN KEY(order_id) REFERENCES orders(order_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existing enum members retain their original order.
ALTER TABLE stock_transactions MODIFY COLUMN type ENUM('purchase','order_deduction','wastage','conversion_out','conversion_in','adjustment') NOT NULL;
SELECT 'Service migration complete; configure menu dish counting in the Service report page.' AS result;
