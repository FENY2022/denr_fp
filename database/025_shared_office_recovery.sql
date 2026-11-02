-- DENR CARAGA FP-OMS | Migration 025: shared office email recovery OTP.
-- Back up database first. Apply AFTER migration 021, and only once.
-- Creates a separate office-mailbox verification challenge; never alters staff
-- usernames, passwords, status, personal recovery emails, or login roles.
-- Personal per-account OTP remains in staff_password_reset_otps (021).
CREATE TABLE IF NOT EXISTS shared_office_recovery_otps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email_hash CHAR(64) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    requested_ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_office_otp_hash_time (email_hash,created_at),
    KEY idx_office_otp_ip_time (requested_ip,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
