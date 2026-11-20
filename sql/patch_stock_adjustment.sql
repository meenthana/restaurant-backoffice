-- Target: restaurant_backoffice dump exported 2026-10-14.
-- Adds the missing stock adjustment transaction type used by modules/stock/adjust.php.
-- Existing enum values keep their original order; no rows are inserted or deleted.
-- Back up the current database before applying. DDL implicitly commits in MariaDB.
USE `restaurant_backoffice`;

ALTER TABLE `stock_transactions`
  MODIFY COLUMN `type`
  ENUM('purchase','order_deduction','wastage','conversion_out','conversion_in','adjustment') NOT NULL;

-- Verification: Type should include adjustment.
SHOW COLUMNS FROM `stock_transactions` LIKE 'type';
