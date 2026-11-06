-- DENR CARAGA FP-OMS migration 034 — applicant project area in hectares.
-- Apply after migration 033. Additive only; no existing application rows are deleted.
--
-- Submitted applications store the normalized numeric area.
ALTER TABLE permit_step2_applications
    ADD COLUMN project_area_hectares DECIMAL(14,4) NULL AFTER project_location;

-- Drafts keep text so partial decimal typing (for example "15.") can autosave safely.
ALTER TABLE permit_step2_form_drafts
    ADD COLUMN project_area_hectares VARCHAR(24) NOT NULL DEFAULT '' AFTER project_location;

SHOW COLUMNS FROM permit_step2_applications LIKE 'project_area_hectares';
SHOW COLUMNS FROM permit_step2_form_drafts LIKE 'project_area_hectares';
