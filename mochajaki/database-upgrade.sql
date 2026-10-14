-- Mochajaki — upgrade an existing database to the current schema.
--
-- Use this when your site already has accounts, orders or payments that you
-- want to keep. It only ADDS what is missing and never deletes a row.
--
-- For a brand-new installation you do not need this file: import database.sql.
--
-- How to run it (pick one):
--   phpMyAdmin  → select the mochajaki database → Import → choose this file
--   Terminal    → mysql --default-character-set=utf8mb4 -u root mochajaki < database-upgrade.sql
--
-- It is safe to run more than once: every step checks the database first and
-- skips anything that is already there.
--
-- What it adds, and why the application needs it:
--   1. login_attempts      — the sign-in / registration throttle counts failed
--                            attempts here. Without the table, signing in and
--                            registering fail with a database error.
--   2. orders.contact_number
--   3. orders.notes         — collected at checkout for delivery orders and
--                            shown in the admin panel.
--   4. an index on payments.order_id, used by the order list.

-- NOTE: this file never names a database. It upgrades whichever one you are
-- connected to, so it works even if yours is not called "mochajaki".

DELIMITER $$

DROP PROCEDURE IF EXISTS mochajaki_upgrade$$

CREATE PROCEDURE mochajaki_upgrade()
BEGIN
    /* Running this with no database selected would silently do nothing useful,
       so stop with an instruction instead. */
    IF DATABASE() IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
            'No database selected. In phpMyAdmin open your mochajaki database first, or run: mysql -u root mochajaki < database-upgrade.sql';
    END IF;

    /* ── 1. Failed sign-in attempts ─────────────────────────────────────── */
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts'
    ) THEN
        CREATE TABLE login_attempts (
            id         BIGINT AUTO_INCREMENT PRIMARY KEY,
            scope      ENUM('login','password','register') NOT NULL DEFAULT 'login',
            email      VARCHAR(190) NOT NULL,
            ip         VARCHAR(45) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_attempts_lookup (scope, email, created_at),
            INDEX idx_attempts_ip (scope, ip, created_at)
        ) ENGINE=InnoDB;

        SELECT 'added table login_attempts' AS upgraded;
    ELSE
        SELECT 'table login_attempts already present' AS upgraded;
    END IF;

    /* ── 2. Contact number on orders ────────────────────────────────────── */
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'contact_number'
    ) THEN
        ALTER TABLE orders ADD COLUMN contact_number VARCHAR(30) NULL AFTER delivery_address;
        SELECT 'added column orders.contact_number' AS upgraded;
    ELSE
        SELECT 'column orders.contact_number already present' AS upgraded;
    END IF;

    /* ── 3. Notes / special instructions on orders ──────────────────────── */
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'notes'
    ) THEN
        ALTER TABLE orders ADD COLUMN notes VARCHAR(300) NULL AFTER contact_number;
        SELECT 'added column orders.notes' AS upgraded;
    ELSE
        SELECT 'column orders.notes already present' AS upgraded;
    END IF;

    /* ── 4. Index used when the admin list joins payments ───────────────── */
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND INDEX_NAME = 'idx_payments_order'
    ) THEN
        ALTER TABLE payments ADD INDEX idx_payments_order (order_id);
        SELECT 'added index payments.idx_payments_order' AS upgraded;
    ELSE
        SELECT 'index payments.idx_payments_order already present' AS upgraded;
    END IF;

    /* ── 5. Payment status must accept "Pending Verification" ───────────── */
    -- Older schemas already allow it; this keeps very old copies working too.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'status'
          AND COLUMN_TYPE LIKE '%Pending Verification%'
    ) THEN
        ALTER TABLE payments MODIFY COLUMN status
            ENUM('Pending','Pending Verification','Paid','Failed','Refunded') NOT NULL DEFAULT 'Pending';
        SELECT 'updated payments.status options' AS upgraded;
    ELSE
        SELECT 'payments.status already accepts every status' AS upgraded;
    END IF;
END$$

DELIMITER ;

CALL mochajaki_upgrade();
DROP PROCEDURE mochajaki_upgrade;

SELECT 'Upgrade finished. Now run: php tests/database.check.php' AS next_step;
