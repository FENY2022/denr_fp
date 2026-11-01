-- DENR CARAGA FP-OMS / 021: username login and staff email recovery OTP.
-- Back up denr_fp_oms and private storage. Apply AFTER 009-020 migrations.
-- Run ONCE while sign-in is paused; never delete or replace existing users.
-- Existing username values are preserved, including Super Administrator.
-- No passwords, emails, roles, account statuses or original staff IDs change.

-- Every DENR user (including Pending Setup) gets a deterministic username.
-- The numeric employee ID suffix prevents same-name collisions. Any existing
-- username remains untouched. Name updates do not rename the account.
UPDATE users
SET username=CONCAT(
    CASE WHEN REGEXP_REPLACE(LOWER(COALESCE(name,'')), '[^a-z0-9]+', '')
                  REGEXP '^[a-z]'
         THEN LEFT(REGEXP_REPLACE(LOWER(name), '[^a-z0-9]+', ''), 25)
         ELSE CONCAT('denr',LEFT(REGEXP_REPLACE(
                   LOWER(COALESCE(name,'')), '[^a-z0-9]+', ''), 21))
    END,
    CAST(id AS CHAR))
WHERE user_kind='internal' AND (username IS NULL OR TRIM(username)='');

-- Enforce uniqueness at the database level (in addition to PHP checks).
-- A distinct index name is used because earlier profile releases may already
-- have an independently named username index. Stop if duplicates are detected.
ALTER TABLE users ADD UNIQUE KEY uq_staff_username_login (username);

-- Inspect: no blank usernames and no duplicates before enabling username sign-in.
SELECT id,name,username,status
FROM users WHERE user_kind='internal' ORDER BY id;

-- Hashes are produced by PHP password_hash; no plaintext OTP in the database.
CREATE TABLE staff_password_reset_otps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    requested_ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_staff_otp_user (user_id, created_at),
    KEY idx_staff_otp_ip (requested_ip, created_at),
    CONSTRAINT fk_staff_otp_user
       FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure the installed users.username column has a UNIQUE constraint.
-- Run SHOW INDEX FROM users; the earlier profile migration normally created it.
-- Never weaken uniqueness during deployment.
