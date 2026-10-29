-- FP-OMS Migration 019: classify Step 2 feasibility permit documentary uploads.
-- Run once in denr_fp_oms AFTER migrations 014 and 015, with DB/storage backups.
-- Historical documents remain intact and are labelled 'other', not guessed.
-- Do not rerun if the columns already exist.
ALTER TABLE permit_step2_documents
    ADD COLUMN requirement_type VARCHAR(48) NOT NULL DEFAULT 'other' AFTER revision;
ALTER TABLE permit_step2_draft_documents
    ADD COLUMN requirement_type VARCHAR(48) NOT NULL DEFAULT 'other' AFTER application_id;

SHOW COLUMNS FROM permit_step2_documents LIKE 'requirement_type';
SHOW COLUMNS FROM permit_step2_draft_documents LIKE 'requirement_type';
