-- DENR CARAGA FP-OMS | 026: managed staff session revocation and login security logs.
-- Apply once with a database administrator before using Force Logout / Login Logs.
-- Existing users, passwords, roles, permit records, and applicant records are preserved.

SET @session_version_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'session_version'
);
SET @session_version_sql := IF(
    @session_version_exists = 0,
    'ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1',
    'SELECT 1'
);
PREPARE fpoms_stmt FROM @session_version_sql;
EXECUTE fpoms_stmt;
DEALLOCATE PREPARE fpoms_stmt;

CREATE TABLE IF NOT EXISTS account_login_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    username_snapshot VARCHAR(190) NULL,
    event_type VARCHAR(40) NOT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT 'unknown',
    user_agent VARCHAR(500) NOT NULL DEFAULT 'Unknown',
    actor_user_id BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account_login_user_created (user_id,created_at),
    INDEX idx_account_login_event_created (event_type,created_at),
    INDEX idx_account_login_actor_created (actor_user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
