-- v2.13 ใช้แต้มสะสมเป็นส่วนลด (Redeem)
-- เพิ่มชนิดรายการ 'redeem' และค่าตั้งต้นเพดานการใช้แต้ม
ALTER TABLE member_point_transactions
  MODIFY transaction_type ENUM('earn','adjustment','reversal','redeem') NOT NULL;
INSERT IGNORE INTO store_settings (setting_key, setting_value) VALUES ('point_redeem_cap_percent', '50');
