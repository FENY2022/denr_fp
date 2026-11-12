-- FP-OMS migration 039: multiple Area Photo Documentation groups.
-- Apply after migration 038. Existing photos remain in Documentation Group 1.

ALTER TABLE permit_step2_area_photo_drafts
    ADD COLUMN IF NOT EXISTS documentation_group SMALLINT UNSIGNED NOT NULL DEFAULT 1
    AFTER application_id;

ALTER TABLE permit_step2_area_photos
    ADD COLUMN IF NOT EXISTS documentation_group SMALLINT UNSIGNED NOT NULL DEFAULT 1
    AFTER revision;
