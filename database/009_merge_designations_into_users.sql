-- DENR FP-OMS / 009 Merge the pending designations into the single users table.
-- Designed for MariaDB 10.4+ and schema migrated through 008_user_management.sql.
-- IMPORTANT: Back up denr_fp_oms first! Apply this ONCE on the existing database.
-- Expected for the supplied 2026-10-26 export: 1 existing admin + 33 pending users = 34 users.
-- This DOES NOT create passwords and DOES NOT change any existing password hash, account ID or role.
-- All imported personnel remain non-loginable until a RICTU administrator sets a unique email and password.

-- 1) The users table accommodates unprovisioned records without fabricated credentials.
ALTER TABLE users
    MODIFY COLUMN email VARCHAR(190) NULL,
    MODIFY COLUMN password_hash VARCHAR(255) NULL,
    MODIFY COLUMN status ENUM('pending_review','needs_correction','active','suspended','pending_setup') NOT NULL DEFAULT 'pending_review',
    ADD COLUMN source_key VARCHAR(64) NULL,
    ADD COLUMN reference_email VARCHAR(190) NULL,
    ADD COLUMN designation_notes VARCHAR(255) NULL,
    ADD UNIQUE KEY uq_users_source_key (source_key);

-- 2) Data movement is transactional. Existing linked identities are preserved.
START TRANSACTION;

-- Automatic matching is deliberately strict: SAME name AND SAME login/reference email.
-- Never match on shared office reference email alone.
UPDATE user_designations d
INNER JOIN users u
    ON u.user_kind='internal'
   AND LOWER(TRIM(u.name))=LOWER(TRIM(d.full_name))
   AND u.email IS NOT NULL
   AND d.reference_email IS NOT NULL
   AND LOWER(TRIM(u.email))=LOWER(TRIM(d.reference_email))
SET d.linked_user_id=u.id
WHERE d.linked_user_id IS NULL;

-- Existing users are enriched, not recreated. Their IDs, credentials and status stay intact.
UPDATE users u
INNER JOIN user_designations d ON d.linked_user_id=u.id
SET u.source_key=d.source_key,
    u.reference_email=d.reference_email,
    u.designation_notes=d.notes,
    u.contact_number=COALESCE(NULLIF(u.contact_number,''),d.contact_number),
    u.office_station=COALESCE(NULLIF(u.office_station,''),d.office_station)
WHERE u.user_kind='internal'
  AND (u.source_key IS NULL OR u.source_key=d.source_key);

-- Staged staff receive NO login email, NO password, and NO active status.
INSERT INTO users
    (name,email,password_hash,user_kind,office_level,contact_number,office_station,
     status,must_change_password,activated_at,source_key,reference_email,designation_notes,created_at)
SELECT
    d.full_name,NULL,NULL,'internal',
    CASE
        WHEN d.suggested_role='cenro_twg' THEN 'CENRO'
        WHEN d.suggested_role='penro_user' THEN 'PENRO'
        ELSE 'Regional Office'
    END,
    d.contact_number,d.office_station,
    'pending_setup',1,NULL,d.source_key,d.reference_email,d.notes,d.created_at
FROM user_designations d
WHERE d.linked_user_id IS NULL;

-- Link the newly inserted rows back to their source for reconciliation.
UPDATE user_designations d
INNER JOIN users u ON u.source_key=d.source_key AND u.user_kind='internal'
SET d.linked_user_id=u.id
WHERE d.linked_user_id IS NULL;

-- Copy proposed functional roles for pending users (existing user roles remain unchanged).
INSERT INTO user_roles (user_id,role_id)
SELECT u.id,r.id
FROM user_designations d
INNER JOIN users u ON u.id=d.linked_user_id AND u.status='pending_setup'
INNER JOIN roles r ON r.code=d.suggested_role
LEFT JOIN user_roles ur ON ur.user_id=u.id AND ur.role_id=r.id
WHERE ur.user_id IS NULL;

-- 3) Hard-stop guard: prohibit dropping any record until every designation has
-- exactly one preserved users row AND each pending user has the designated role.
CREATE TEMPORARY TABLE fp_oms_designation_merge_guard (
    ok TINYINT NOT NULL,
    CONSTRAINT chk_fp_oms_designation_merge_guard CHECK (ok = 1)
);
INSERT INTO fp_oms_designation_merge_guard (ok)
SELECT CASE WHEN
    (SELECT COUNT(*) FROM user_designations)
    =
    (SELECT COUNT(*) FROM user_designations d
     INNER JOIN users u ON u.id=d.linked_user_id
     WHERE u.user_kind='internal' AND u.source_key=d.source_key)
    AND
    NOT EXISTS (
        SELECT 1 FROM user_designations d
        INNER JOIN users u ON u.id=d.linked_user_id
        LEFT JOIN roles r ON r.code=d.suggested_role
        LEFT JOIN user_roles ur ON ur.user_id=u.id AND ur.role_id=r.id
        WHERE r.id IS NULL
           OR (u.status='pending_setup' AND ur.user_id IS NULL)
    )
THEN 1 ELSE 0 END;

COMMIT;
DROP TEMPORARY TABLE fp_oms_designation_merge_guard;

-- 4) Remove the now redundant table only AFTER successful reconciliation.
DROP TABLE user_designations;

-- 5) Verification (on the supplied export: 34 users, of which 33 pending setup).
SELECT COUNT(*) AS total_users,
       SUM(status='pending_setup') AS pending_setup,
       SUM(status='active') AS active_users,
       SUM(source_key IS NOT NULL) AS merged_designations
FROM users;
