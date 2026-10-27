-- DENR CARAGA FP-OMS 011: physically separate external applicants from DENR personnel.
-- For EXISTING installations migrated through 009 (and 010 if needed).
-- Designed for MariaDB 10.4.32 (XAMPP) / MySQL 8.
-- BACK UP the ENTIRE denr_fp_oms DATABASE and private storage before running.
-- One-time schema migration. Deploy matching PHP code only AFTER this migration succeeds.
-- Do not import this file a second time. Never disable FOREIGN_KEY_CHECKS.
--
-- Result:
--   users              = DENR internal personnel only
--   user_client        = external applicants only, original IDs and password hashes intact
--   applicant_registrations.client_id -> user_client.id
--   permit_applications.client_id    -> user_client.id
--   client notifications / reset links / audit history explicitly reference user_client
--   staff reviewers, approvals, audit, roles continue to reference users.
--
-- Stop if ANY statement errors; restore the full pre-migration backup before retrying.

-- 1. The new client table uses the complete installed users schema, including
--    username, profile_photo, account status and activation timestamps.
--    MySQL CREATE TABLE LIKE retains unique indexes, but does not copy foreign keys.
CREATE TABLE user_client LIKE users;
ALTER TABLE user_client
  ADD CONSTRAINT chk_user_client_kind CHECK (user_kind = 'applicant');

-- Preserve legacy IDs, hashed passwords, names, email, photos and timestamps.
INSERT INTO user_client
SELECT * FROM users WHERE user_kind='applicant';

-- The application should not be serving registrations during this migration.
-- If any user_client rows already existed, CREATE TABLE above stops safely.

-- 2. Move the foreign key for applicant registration ownership.
ALTER TABLE applicant_registrations DROP FOREIGN KEY fk_reg_user;
ALTER TABLE applicant_registrations
  CHANGE COLUMN user_id client_id BIGINT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_registration_client
    FOREIGN KEY (client_id) REFERENCES user_client(id) ON DELETE CASCADE;

-- 3. Repoint the future permit application owner. Existing application IDs
--    stay unchanged and reviewer signatures remain associated with DENR users.
ALTER TABLE permit_applications DROP FOREIGN KEY fk_app_applicant;
ALTER TABLE permit_applications
  CHANGE COLUMN applicant_user_id client_id BIGINT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_permit_client
    FOREIGN KEY (client_id) REFERENCES user_client(id) ON DELETE RESTRICT;

-- 4. Staff and client notification delivery have disjoint foreign keys.
ALTER TABLE notifications DROP FOREIGN KEY fk_notifications_user;
ALTER TABLE notifications
  MODIFY COLUMN user_id BIGINT UNSIGNED NULL,
  ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER user_id,
  ADD INDEX idx_notifications_client (client_id,read_at,created_at),
  ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_notifications_client FOREIGN KEY (client_id) REFERENCES user_client(id) ON DELETE CASCADE;
UPDATE notifications n
JOIN users u ON u.id=n.user_id AND u.user_kind='applicant'
SET n.client_id=n.user_id,n.user_id=NULL;

-- 5. Reset links retain their original hashes, expiration and used-at history.
ALTER TABLE password_reset_tokens DROP FOREIGN KEY fk_password_reset_user;
ALTER TABLE password_reset_tokens
  MODIFY COLUMN user_id BIGINT UNSIGNED NULL,
  ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER user_id,
  ADD INDEX idx_reset_client (client_id,created_at),
  ADD CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_reset_client FOREIGN KEY (client_id) REFERENCES user_client(id) ON DELETE CASCADE;
UPDATE password_reset_tokens prt
JOIN users u ON u.id=prt.user_id AND u.user_kind='applicant'
SET prt.client_id=prt.user_id,prt.user_id=NULL;

-- 6. Historical audit actors must not be nulled when removing legacy rows.
ALTER TABLE audit_logs
  ADD COLUMN actor_client_id BIGINT UNSIGNED NULL AFTER actor_user_id,
  ADD INDEX idx_audit_client (actor_client_id,created_at),
  ADD CONSTRAINT fk_audit_client FOREIGN KEY (actor_client_id)
     REFERENCES user_client(id) ON DELETE SET NULL;
UPDATE audit_logs a
JOIN users u ON u.id=a.actor_user_id AND u.user_kind='applicant'
SET a.actor_client_id=a.actor_user_id,a.actor_user_id=NULL;
UPDATE audit_logs a
JOIN users u ON a.target_type='user' AND a.target_id=u.id AND u.user_kind='applicant'
SET a.target_type='client';

-- 7. Workflow scaffold uploader/performer may be a client or a DENR employee.
--    Keep separate explicit columns so identical numeric IDs never cross accounts.
ALTER TABLE application_documents DROP FOREIGN KEY fk_ad_user;
ALTER TABLE application_documents
  MODIFY COLUMN uploaded_by BIGINT UNSIGNED NULL,
  ADD COLUMN uploaded_by_client_id BIGINT UNSIGNED NULL AFTER uploaded_by,
  ADD INDEX idx_application_document_client (uploaded_by_client_id),
  ADD CONSTRAINT fk_ad_user FOREIGN KEY (uploaded_by) REFERENCES users(id),
  ADD CONSTRAINT fk_ad_client FOREIGN KEY (uploaded_by_client_id) REFERENCES user_client(id);
UPDATE application_documents ad
JOIN users u ON u.id=ad.uploaded_by AND u.user_kind='applicant'
SET ad.uploaded_by_client_id=ad.uploaded_by,ad.uploaded_by=NULL;

ALTER TABLE workflow_actions DROP FOREIGN KEY fk_wa_user;
ALTER TABLE workflow_actions
  MODIFY COLUMN performed_by BIGINT UNSIGNED NULL,
  ADD COLUMN performed_by_client_id BIGINT UNSIGNED NULL AFTER performed_by,
  ADD INDEX idx_workflow_action_client (performed_by_client_id),
  ADD CONSTRAINT fk_wa_user FOREIGN KEY (performed_by) REFERENCES users(id),
  ADD CONSTRAINT fk_wa_client FOREIGN KEY (performed_by_client_id) REFERENCES user_client(id);
UPDATE workflow_actions wa
JOIN users u ON u.id=wa.performed_by AND u.user_kind='applicant'
SET wa.performed_by_client_id=wa.performed_by,wa.performed_by=NULL;

-- 8. Compare copies before deletion. This fails if any applicant is not copied
--    correctly. The DELETE below also matches only identities present in user_client.
CREATE TEMPORARY TABLE fpoms_client_migration_guard (
    ok TINYINT NOT NULL,
    CONSTRAINT chk_client_migration_guard CHECK (ok=1)
);
INSERT INTO fpoms_client_migration_guard (ok)
SELECT CASE WHEN
  NOT EXISTS (
    SELECT 1 FROM users u
    LEFT JOIN user_client c ON c.id=u.id
    WHERE u.user_kind='applicant'
      AND (c.id IS NULL
        OR NOT (u.email <=> c.email)
        OR NOT (u.password_hash <=> c.password_hash)
        OR NOT (u.name <=> c.name)
        OR NOT (u.status <=> c.status))
  )
  AND NOT EXISTS (
    SELECT 1 FROM applicant_registrations ar
    LEFT JOIN user_client c ON c.id=ar.client_id
    WHERE c.id IS NULL
  )
THEN 1 ELSE 0 END;

-- 9. Remove the *original applicant rows only*, after all references moved.
--    Existing DENR personnel rows, admin accounts, and reviewer references stay.
--    user_roles legacy applicant assignments cascade away with the deleted users.
DELETE u FROM users u
JOIN user_client c ON c.id=u.id
WHERE u.user_kind='applicant';

-- Prevent any future PHP code from putting applicants back into the DENR table.
ALTER TABLE users
  ADD CONSTRAINT chk_users_internal_only CHECK (user_kind='internal');

DROP TEMPORARY TABLE fpoms_client_migration_guard;

-- 10. Final read-only verification. Both applicant and staff IDs may overlap:
--     client identity is always distinguished by its table + session namespace.
SELECT
  (SELECT COUNT(*) FROM users WHERE user_kind='applicant') AS applicants_left_in_users,
  (SELECT COUNT(*) FROM users WHERE user_kind='internal') AS denr_personnel,
  (SELECT COUNT(*) FROM user_client WHERE user_kind='applicant') AS separate_client_accounts,
  (SELECT COUNT(*) FROM applicant_registrations) AS applicant_registration_records,
  (SELECT COUNT(*) FROM audit_logs WHERE actor_client_id IS NOT NULL) AS preserved_client_audit_actors;
