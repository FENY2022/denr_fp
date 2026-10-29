-- FP-OMS migration 018: Registration organization type and categorized requirements.
-- Run ONCE after previous registration migrations; back up database + private storage.
-- All added columns have safe historical defaults, preserving older registrations.
-- Do not re-run statements if columns already exist.
ALTER TABLE applicant_registrations
    ADD COLUMN organization_type VARCHAR(32) NOT NULL DEFAULT 'individual' AFTER company_name;

ALTER TABLE registration_draft_documents
    ADD COLUMN requirement_type VARCHAR(40) NOT NULL DEFAULT 'other' AFTER id_type;

ALTER TABLE registration_documents
    ADD COLUMN requirement_type VARCHAR(40) NOT NULL DEFAULT 'other' AFTER id_type;

-- Verify schema; no existing files/records are deleted:
SHOW COLUMNS FROM applicant_registrations LIKE 'organization_type';
SHOW COLUMNS FROM registration_draft_documents LIKE 'requirement_type';
SHOW COLUMNS FROM registration_documents LIKE 'requirement_type';
