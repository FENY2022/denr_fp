-- DENR CARAGA FP-OMS | 030: Annex 1 Type of Project for FP applications.
-- Run ONCE after migration 016 (and registration migration 018).
-- Back up denr_fp_oms and private storage before importing.
-- Existing applications retain 'unspecified' (not silently classified as Other).
ALTER TABLE permit_step2_applications
  ADD COLUMN project_type VARCHAR(20) NOT NULL DEFAULT 'unspecified' AFTER project_title,
  ADD COLUMN project_type_other VARCHAR(160) NULL AFTER project_type;

ALTER TABLE permit_step2_form_drafts
  ADD COLUMN project_type VARCHAR(20) NOT NULL DEFAULT '' AFTER project_title,
  ADD COLUMN project_type_other VARCHAR(160) NOT NULL DEFAULT '' AFTER project_type;

SHOW COLUMNS FROM permit_step2_applications LIKE 'project_type%';
SHOW COLUMNS FROM permit_step2_form_drafts LIKE 'project_type%';
