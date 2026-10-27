-- FP-OMS 013 | Email OTP verification before FUU review and acknowledgment
-- REQUIREMENTS: migration 011 (user_client) must already have been applied.
-- Back up denr_fp_oms and take the registration system offline during migration.
-- Apply ONCE before deploying the matching PHP application code.
-- Existing applicants keep their statuses and passwords unchanged.
-- "email_verified_at" is left NULL for legacy registrations: there is no
-- historical proof of OTP validation. They are not forced to re-register.
ALTER TABLE user_client
    MODIFY COLUMN status ENUM('pending_review','needs_correction','active','suspended','pending_setup','pending_email_verification')
        NOT NULL DEFAULT 'pending_review',
    ADD COLUMN email_verified_at DATETIME NULL AFTER activated_at;

CREATE TABLE registration_email_otps (
    client_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    otp_hash VARCHAR(255) NOT NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    resend_window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sends_in_window TINYINT UNSIGNED NOT NULL DEFAULT 1,
    last_send_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_registration_email_otp_client
        FOREIGN KEY (client_id) REFERENCES user_client(id) ON DELETE CASCADE,
    INDEX idx_registration_otp_expiration (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Read-only confirmation
SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_client'
  AND COLUMN_NAME IN ('status','email_verified_at');
SHOW CREATE TABLE registration_email_otps;
