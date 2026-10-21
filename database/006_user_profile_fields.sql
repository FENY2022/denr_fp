-- DENR FP-OMS — authenticated profile fields
-- Keeps users.name as the existing authoritative full-name field.

ALTER TABLE users
    ADD COLUMN username VARCHAR(80) NULL AFTER id,
    ADD COLUMN profile_photo VARCHAR(255) NULL AFTER email,
    ADD COLUMN office_level VARCHAR(150) NULL AFTER user_kind;

UPDATE users
SET username = CONCAT('fpuser', LPAD(id, 6, '0'))
WHERE username IS NULL OR TRIM(username) = '';

UPDATE users
SET office_level = CASE
    WHEN user_kind = 'internal' THEN 'Regional Office'
    ELSE 'External Applicant'
END
WHERE office_level IS NULL OR TRIM(office_level) = '';

ALTER TABLE users
    ADD UNIQUE KEY uq_users_username (username);
