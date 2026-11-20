-- ============================================================
-- อัปเดตฐานข้อมูลเป็น v2.7 (รวม v2.3 - v2.7 ไว้ในไฟล์เดียว)
-- ใช้กับฐานข้อมูลเดิมที่มีข้อมูลอยู่แล้ว ข้อมูลเดิมไม่หาย
-- รันซ้ำได้ ไม่ว่าเคยรันตัวไหนไปแล้วบ้าง
-- วิธีใช้: phpMyAdmin > เลือก restaurant_backoffice > แท็บ SQL > วางทั้งไฟล์ > Go
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
-- ใช้ IF NOT EXISTS ปลอดภัย รันซ้ำได้
-- ============================================================

USE restaurant_backoffice;

ALTER TABLE menu
  ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL;

SELECT 'เพิ่มคอลัมน์ image_path ในตาราง menu สำเร็จแล้ว' AS result;


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
-- ส่วนอัปเดต V2.6 : Reorder Point ต่อวัตถุดิบ
-- ใช้ IF NOT EXISTS ปลอดภัย รันซ้ำได้
-- ============================================================

USE restaurant_backoffice;

ALTER TABLE ingredients
  ADD COLUMN IF NOT EXISTS reorder_point DECIMAL(10,2) NULL;

SELECT 'เพิ่มคอลัมน์ reorder_point ในตาราง ingredients สำเร็จแล้ว' AS result;


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


