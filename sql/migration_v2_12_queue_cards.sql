-- v2.12 บัตรคิว: ลูกค้าหยิบบัตรแล้วกรอกรหัสบนบัตรก่อนสั่งที่ตู้ (รันหลัง v2.11)
CREATE TABLE IF NOT EXISTS queue_cards (
    card_id INT AUTO_INCREMENT PRIMARY KEY,
    card_no INT NOT NULL UNIQUE,
    code CHAR(4) NOT NULL UNIQUE,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS queue_card_id INT NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS queue_card_no INT NULL;
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_orders_queue_card (queue_card_id, status);

INSERT IGNORE INTO queue_cards (card_no, code) VALUES
(1,'4827'),(2,'1935'),(3,'7062'),(4,'3518'),(5,'9241'),
(6,'5703'),(7,'2689'),(8,'8150'),(9,'6374'),(10,'4096');
