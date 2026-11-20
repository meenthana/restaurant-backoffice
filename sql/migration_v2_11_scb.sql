-- v2.11 SCB QR Payment (รันหลัง v2.10) เพิ่มตารางเดียว ไม่แก้ข้อมูลเดิม
CREATE TABLE IF NOT EXISTS scb_payments (
    order_id INT NOT NULL PRIMARY KEY,
    ref1 VARCHAR(20) NOT NULL UNIQUE,
    ref2 VARCHAR(20) NOT NULL,
    ref3 VARCHAR(20) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    livemode TINYINT NOT NULL DEFAULT 0,
    status VARCHAR(24) NOT NULL DEFAULT 'creating',
    qr_raw TEXT NULL,
    transaction_id VARCHAR(64) NULL UNIQUE,
    payment_id INT NULL,
    checked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
