-- FP-OMS migration 040: independent Pinpoint Project Location per Area Photo Documentation group.
-- Apply after migrations 038 and 039.
-- Empty editable groups can retain their own pin before photos are uploaded.

CREATE TABLE IF NOT EXISTS permit_step2_area_photo_group_drafts (
    client_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    documentation_group SMALLINT UNSIGNED NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(11,7) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id,application_id,documentation_group),
    CONSTRAINT fk_step2_area_photo_group_draft_client FOREIGN KEY (client_id)
        REFERENCES user_client(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permit_step2_area_photo_groups (
    application_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    documentation_group SMALLINT UNSIGNED NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(11,7) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (application_id,revision,documentation_group),
    CONSTRAINT fk_step2_area_photo_group_app FOREIGN KEY (application_id)
        REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_step2_area_photo_group_client FOREIGN KEY (client_id)
        REFERENCES user_client(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
