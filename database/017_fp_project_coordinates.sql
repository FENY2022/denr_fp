-- DENR CARAGA FP-OMS | Migration 017: Project GPS pinpoint.
-- Apply only ONCE after 014 and 016; back up denr_fp_oms and storage first.
-- Nullable to preserve existing permit applications and saved drafts.
-- Draft fields are VARCHAR to preserve partial user input during autosave.
-- Submitted application fields use WGS84 decimal degrees (EPSG:4326).
ALTER TABLE permit_step2_applications
    ADD COLUMN project_latitude DECIMAL(10,7) NULL AFTER project_location,
    ADD COLUMN project_longitude DECIMAL(11,7) NULL AFTER project_latitude;

ALTER TABLE permit_step2_form_drafts
    ADD COLUMN project_latitude VARCHAR(32) NOT NULL DEFAULT '' AFTER project_location,
    ADD COLUMN project_longitude VARCHAR(32) NOT NULL DEFAULT '' AFTER project_latitude;

-- Verify after applying. Both application columns should be nullable.
SHOW COLUMNS FROM permit_step2_applications LIKE 'project_latitude';
SHOW COLUMNS FROM permit_step2_applications LIKE 'project_longitude';
SHOW COLUMNS FROM permit_step2_form_drafts LIKE 'project_latitude';
SHOW COLUMNS FROM permit_step2_form_drafts LIKE 'project_longitude';
