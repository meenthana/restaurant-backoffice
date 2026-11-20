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
