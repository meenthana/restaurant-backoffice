-- Run after v2.9. Additive migration; do not import install_v2_full.sql into an existing database.
CREATE TABLE IF NOT EXISTS gateway_payments (
    order_id INT NOT NULL PRIMARY KEY,
    request_key CHAR(32) NOT NULL UNIQUE,
    charge_id VARCHAR(64) NULL UNIQUE,
    amount_satang BIGINT NOT NULL,
    livemode TINYINT NOT NULL DEFAULT 0,
    status VARCHAR(24) NOT NULL DEFAULT 'creating',
    qr_url TEXT NULL,
    expires_at VARCHAR(40) NULL,
    payment_id INT NULL,
    checked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS gateway_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    detail VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

