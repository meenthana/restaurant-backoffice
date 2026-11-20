-- Migration สำหรับฐานข้อมูลเดิม: ระบบผังโต๊ะ + ประเภทบริการ
USE restaurant_backoffice;

CREATE TABLE IF NOT EXISTS restaurant_tables (
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

CREATE TABLE IF NOT EXISTS table_sessions (
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

CREATE TABLE IF NOT EXISTS table_session_tables (
  session_id INT UNSIGNED NOT NULL,
  table_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (session_id, table_id),
  CONSTRAINT fk_tst_session
    FOREIGN KEY (session_id) REFERENCES table_sessions(session_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_tst_table
    FOREIGN KEY (table_id) REFERENCES restaurant_tables(table_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE orders
  ADD COLUMN service_type ENUM('dine_in','takeaway') NOT NULL DEFAULT 'dine_in' AFTER channel,
  ADD COLUMN table_session_id INT UNSIGNED NULL AFTER service_type,
  ADD COLUMN scheduled_at DATETIME NULL AFTER note,
  ADD CONSTRAINT fk_orders_table_session
    FOREIGN KEY (table_session_id) REFERENCES table_sessions(session_id)
    ON DELETE SET NULL;

UPDATE orders
SET service_type = CASE WHEN channel = 'online' THEN 'takeaway' ELSE 'dine_in' END;

INSERT IGNORE INTO restaurant_tables
  (table_code, capacity, zone_name, position_x, position_y, merge_group)
VALUES
  ('T01', 2,  'โซน A', 1, 1, 'A1'),
  ('T02', 2,  'โซน A', 2, 1, 'A1'),
  ('T03', 2,  'โซน A', 3, 1, 'A2'),
  ('T04', 2,  'โซน A', 4, 1, 'A2'),
  ('T05', 4,  'โซน B', 1, 2, 'B1'),
  ('T06', 4,  'โซน B', 2, 2, 'B1'),
  ('T07', 4,  'โซน B', 3, 2, 'B2'),
  ('T08', 4,  'โซน B', 4, 2, 'B2'),
  ('T09', 6,  'โซน C', 1, 3, 'C1'),
  ('T10', 12, 'โซน C', 3, 3, 'C1');
