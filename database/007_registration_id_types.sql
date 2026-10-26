-- DENR FP-OMS — multiple registration attachments / Valid ID classification
-- Apply ONCE, after existing core schema and before deploying the new PHP upload forms.
-- Existing records remain intact; historical Valid IDs have NULL id_type.
-- Example: mysql -u root -p denr_fp_oms < database/007_registration_id_types.sql

ALTER TABLE registration_documents
    ADD COLUMN id_type VARCHAR(80) NULL AFTER document_type;
