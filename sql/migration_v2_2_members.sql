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
