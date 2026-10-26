-- DENR CARAGA FP-OMS | Optional restoration of OFFICE EMAIL references
-- Based on User Role Assignment & Information Form, 21 October 2026.
-- For installations already migrated through 009_merge_designations_into_users.sql.
--
-- This file is idempotent and safe to re-import:
--   * Fills only MISSING reference_email for existing source-keyed internal users.
--   * Does NOT overwrite existing reference data or verified individual login email.
--   * Does NOT modify users.email, password_hash, status, username or user_roles.
--   * Does NOT activate accounts; the administrator must verify identity in Set Up.
--   * A mailbox shared by multiple personnel is NEVER a suggested personal login.
--
-- Before importing: backup denr_fp_oms using phpMyAdmin > Export.

CREATE TEMPORARY TABLE IF NOT EXISTS fpoms_office_email_restore (
    source_key VARCHAR(64) PRIMARY KEY,
    reference_email VARCHAR(190) NOT NULL
) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM fpoms_office_email_restore;

INSERT INTO fpoms_office_email_restore (source_key,reference_email) VALUES
    ('a01-1', 'Venzonanthonie@gmail.com'),
    ('c01-1', 'cenronasipit@denr.gov.ph'),
    ('c01-2', 'cenronasipit@denr.gov.ph'),
    ('c02-1', 'cenrotubay@denr.gov.ph'),
    ('c02-2', 'cenrotubay@denr.gov.ph'),
    ('c03-1', 'cenrobayugan@denr.gov.ph'),
    ('c03-2', 'cenrobayugan@denr.gov.ph'),
    ('c04-1', 'cenrobunawan@denr.gov.ph'),
    ('c04-2', 'cenrobunawan@denr.gov.ph'),
    ('c05-1', 'cenroloreto@denr.gov.ph'),
    ('c05-2', 'cenroloreto@denr.gov.ph'),
    ('c06-1', 'cenrotalacogon@denr.gov.ph'),
    ('c06-2', 'cenrotalacogon@denr.gov.ph'),
    ('c07-1', 'cenrobislig@denr.gov.ph'),
    ('c07-2', 'cenrobislig@denr.gov.ph'),
    ('c08-1', 'cenrolianga@denr.gov.ph'),
    ('c08-2', 'cenrolianga@denr.gov.ph'),
    ('c09-1', 'cenrocantilan@denr.gov.ph'),
    ('c09-2', 'cenrocantilan@denr.gov.ph'),
    ('c10-1', 'penrosdnrps@gmail.com'),
    ('c10-2', 'penrosdnrps@gmail.com'),
    ('c11-1', 'penrodinagat@denr.gov.ph'),
    ('c11-2', 'penrodinagat@denr.gov.ph'),
    ('c12-1', 'cenrotubod@denr.gov.ph'),
    ('c12-2', 'cenrotubod@denr.gov.ph'),
    ('d01-1', 'Lasangutilizationlpdd@gmail.com'),
    ('d02-1', 'Lasangutilizationlpdd@gmail.com'),
    ('d03-1', 'Lasangutilizationlpdd@gmail.com'),
    ('d04-1', 'lpddr13@denr.gov.ph'),
    ('d05-1', 'records.r13@denr.gov.ph'),
    ('ard01-1', 'Ordts2021@gmail.com'),
    ('rrdc01-1', 'cbfmcaragaofficial@gmail.com'),
    ('rrdc02-1', 'kristofferbuy23@gmail.com'),
    ('red01-1', 'r13@denr.gov.ph');

START TRANSACTION;

UPDATE users u
INNER JOIN fpoms_office_email_restore src ON src.source_key=u.source_key
SET u.reference_email=src.reference_email
WHERE u.user_kind='internal'
  AND u.status='pending_setup'
  AND (u.reference_email IS NULL OR TRIM(u.reference_email)='');

SELECT ROW_COUNT() AS missing_office_references_restored;
COMMIT;

DROP TEMPORARY TABLE IF EXISTS fpoms_office_email_restore;

-- Read-only verification: Office Email and Login Email are distinct.
SELECT
    COUNT(*) AS internal_user_rows,
    SUM(CASE WHEN u.status='pending_setup' THEN 1 ELSE 0 END) AS pending_setup,
    SUM(CASE WHEN u.email IS NOT NULL THEN 1 ELSE 0 END) AS assigned_login_emails,
    SUM(CASE WHEN u.reference_email IS NOT NULL AND TRIM(u.reference_email)<>'' THEN 1 ELSE 0 END) AS office_emails_recorded
FROM users u WHERE u.user_kind='internal';

-- Read-only preview: the six single-person candidates from the submitted form
-- appear only while pending and only if not already assigned to any other login.
-- Being listed ONCE is not evidence that this address belongs to the employee.
SELECT u.id,u.name,u.reference_email AS email_suggestion_for_verification
FROM users u
WHERE u.user_kind='internal'
  AND u.status='pending_setup'
  AND u.reference_email IS NOT NULL AND TRIM(u.reference_email)<>''
  AND (SELECT COUNT(*) FROM users ref
       WHERE ref.user_kind='internal'
         AND LOWER(TRIM(ref.reference_email))=LOWER(TRIM(u.reference_email)))=1
  AND NOT EXISTS (
       SELECT 1 FROM users assigned
       WHERE assigned.email IS NOT NULL
         AND LOWER(TRIM(assigned.email))=LOWER(TRIM(u.reference_email))
  )
ORDER BY u.name;
