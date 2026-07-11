-- Harpy FinTrack — skema database
-- 14 tabel: 13 sesuai docs/superpowers/specs/2026-07-12-harpy-fintrack-design.md §4
-- + login_attempts (rate-limit login).
-- Urutan CREATE TABLE mengikuti urutan dependensi FK (anak setelah induk).

SET NAMES utf8mb4;

-- 1. users -------------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. remember_tokens -----------------------------------------------------
CREATE TABLE remember_tokens (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    selector       VARCHAR(64) NOT NULL,
    validator_hash VARCHAR(255) NOT NULL,
    expires_at     DATETIME NOT NULL,
    UNIQUE KEY uq_remember_tokens_selector (selector),
    CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. spaces ---------------------------------------------------------------
CREATE TABLE spaces (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    name       VARCHAR(100) NOT NULL,
    type       ENUM('personal','business') NOT NULL DEFAULT 'personal',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_spaces_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. accounts ---------------------------------------------------------------
CREATE TABLE accounts (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id         INT UNSIGNED NOT NULL,
    name             VARCHAR(100) NOT NULL,
    type             ENUM('cash','bank','ewallet','other') NOT NULL DEFAULT 'cash',
    initial_balance  DECIMAL(15,2) NOT NULL DEFAULT 0,
    is_archived      TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_accounts_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. categories ---------------------------------------------------------------
CREATE TABLE categories (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id  INT UNSIGNED NOT NULL,
    name      VARCHAR(100) NOT NULL,
    type      ENUM('income','expense') NOT NULL,
    icon      VARCHAR(50) NULL,
    color     VARCHAR(20) NULL,
    parent_id INT UNSIGNED NULL,
    CONSTRAINT fk_categories_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. recurrings ---------------------------------------------------------------
-- (dibuat sebelum transactions krn transactions.recurring_id merujuk ke sini)
CREATE TABLE recurrings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id    INT UNSIGNED NOT NULL,
    account_id  INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    type        ENUM('income','expense') NOT NULL,
    amount      DECIMAL(15,2) NOT NULL,
    note        VARCHAR(255) NULL,
    frequency   ENUM('daily','weekly','monthly','yearly') NOT NULL,
    anchor_date DATE NOT NULL,
    next_run    DATE NOT NULL,
    mode        ENUM('auto','reminder') NOT NULL DEFAULT 'reminder',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_recurrings_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_recurrings_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_recurrings_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    INDEX idx_recurrings_due (space_id, is_active, next_run)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. goals ---------------------------------------------------------------
-- (dibuat sebelum transactions krn transactions.goal_id merujuk ke sini)
CREATE TABLE goals (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id      INT UNSIGNED NOT NULL,
    name          VARCHAR(100) NOT NULL,
    target_amount DECIMAL(15,2) NOT NULL,
    target_date   DATE NULL,
    is_done       TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_goals_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. transactions ---------------------------------------------------------------
CREATE TABLE transactions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id      INT UNSIGNED NOT NULL,
    account_id    INT UNSIGNED NOT NULL,
    category_id   INT UNSIGNED NULL,
    type          ENUM('income','expense','transfer') NOT NULL,
    amount        DECIMAL(15,2) NOT NULL,
    tx_date       DATE NOT NULL,
    note          VARCHAR(255) NULL,
    to_account_id INT UNSIGNED NULL,
    recurring_id  INT UNSIGNED NULL,
    goal_id       INT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transactions_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_transactions_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_transactions_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_to_account FOREIGN KEY (to_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_transactions_recurring FOREIGN KEY (recurring_id) REFERENCES recurrings(id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE SET NULL,
    INDEX idx_transactions_space_date (space_id, tx_date),
    INDEX idx_transactions_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. budgets ---------------------------------------------------------------
CREATE TABLE budgets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id    INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    period      CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    amount      DECIMAL(15,2) NOT NULL,
    CONSTRAINT fk_budgets_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_budgets_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    UNIQUE KEY uq_budgets_space_category_period (space_id, category_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. goal_entries ---------------------------------------------------------------
CREATE TABLE goal_entries (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    goal_id        INT UNSIGNED NOT NULL,
    transaction_id INT UNSIGNED NULL,
    amount         DECIMAL(15,2) NOT NULL,
    entry_date     DATE NOT NULL,
    CONSTRAINT fk_goal_entries_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
    CONSTRAINT fk_goal_entries_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. assets ---------------------------------------------------------------
CREATE TABLE assets (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    space_id   INT UNSIGNED NOT NULL,
    name       VARCHAR(100) NOT NULL,
    type       ENUM('stock','mutual_fund','gold','crypto','deposit','other') NOT NULL,
    code       VARCHAR(30) NULL,
    unit_label VARCHAR(20) NOT NULL DEFAULT 'unit',
    CONSTRAINT fk_assets_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. asset_transactions ---------------------------------------------------------------
CREATE TABLE asset_transactions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id       INT UNSIGNED NOT NULL,
    side           ENUM('buy','sell') NOT NULL,
    units          DECIMAL(20,8) NOT NULL,
    price_per_unit DECIMAL(15,2) NOT NULL,
    fee            DECIMAL(15,2) NOT NULL DEFAULT 0,
    tx_date        DATE NOT NULL,
    CONSTRAINT fk_asset_transactions_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. asset_prices ---------------------------------------------------------------
CREATE TABLE asset_prices (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id       INT UNSIGNED NOT NULL,
    price_per_unit DECIMAL(15,2) NOT NULL,
    priced_at      DATE NOT NULL,
    CONSTRAINT fk_asset_prices_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. login_attempts ---------------------------------------------------------------
CREATE TABLE login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(190) NOT NULL,
    ip           VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
