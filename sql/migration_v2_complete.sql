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

