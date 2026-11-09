-- FP-OMS migration 037: structured Caraga project address + deterministic PENRO/CENRO routing.
-- The application can auto-add these nullable columns on first use when ALTER permission is available.
-- Safe to run manually. Existing applications remain valid and are not guessed/backfilled.

ALTER TABLE permit_step2_applications
    ADD COLUMN IF NOT EXISTS project_province_code VARCHAR(10) NULL AFTER project_location,
    ADD COLUMN IF NOT EXISTS project_province_name VARCHAR(120) NULL AFTER project_province_code,
    ADD COLUMN IF NOT EXISTS project_city_municipality_code VARCHAR(10) NULL AFTER project_province_name,
    ADD COLUMN IF NOT EXISTS project_city_municipality_name VARCHAR(120) NULL AFTER project_city_municipality_code,
    ADD COLUMN IF NOT EXISTS project_barangay_code VARCHAR(10) NULL AFTER project_city_municipality_name,
    ADD COLUMN IF NOT EXISTS project_barangay_name VARCHAR(120) NULL AFTER project_barangay_code,
    ADD COLUMN IF NOT EXISTS project_street_purok VARCHAR(180) NULL AFTER project_barangay_name,
    ADD COLUMN IF NOT EXISTS concerned_penro VARCHAR(120) NULL AFTER project_street_purok,
    ADD COLUMN IF NOT EXISTS concerned_cenro VARCHAR(120) NULL AFTER concerned_penro;

ALTER TABLE permit_step2_form_drafts
    ADD COLUMN IF NOT EXISTS project_province_code VARCHAR(10) NULL AFTER project_location,
    ADD COLUMN IF NOT EXISTS project_province_name VARCHAR(120) NULL AFTER project_province_code,
    ADD COLUMN IF NOT EXISTS project_city_municipality_code VARCHAR(10) NULL AFTER project_province_name,
    ADD COLUMN IF NOT EXISTS project_city_municipality_name VARCHAR(120) NULL AFTER project_city_municipality_code,
    ADD COLUMN IF NOT EXISTS project_barangay_code VARCHAR(10) NULL AFTER project_city_municipality_name,
    ADD COLUMN IF NOT EXISTS project_barangay_name VARCHAR(120) NULL AFTER project_barangay_code,
    ADD COLUMN IF NOT EXISTS project_street_purok VARCHAR(180) NULL AFTER project_barangay_name,
    ADD COLUMN IF NOT EXISTS concerned_penro VARCHAR(120) NULL AFTER project_street_purok,
    ADD COLUMN IF NOT EXISTS concerned_cenro VARCHAR(120) NULL AFTER concerned_penro;
