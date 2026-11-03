-- DENR CARAGA FP-OMS | 024: optional realtime notification settings.
-- Configuration -> Realtime Notifications uses an existing table without DDL.
-- It attempts one-time creation only when the table is genuinely missing.
-- Run this SQL with an administrator account when the app DB user has no CREATE privilege.
-- Back up denr_fp_oms before applying. No existing users or permits modified.
-- Pusher secrets are stored as AES-256-GCM ciphertext by PHP using the
-- already existing private FP-OMS encryption master key outside the webroot.
CREATE TABLE IF NOT EXISTS fpoms_realtime_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    app_id VARCHAR(32) NOT NULL DEFAULT '',
    app_key VARCHAR(128) NOT NULL DEFAULT '',
    secret_ciphertext TEXT NULL,
    cluster VARCHAR(32) NOT NULL DEFAULT 'ap1',
    last_tested_at DATETIME NULL,
    last_test_ok TINYINT(1) NULL,
    last_test_note VARCHAR(180) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
      ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
