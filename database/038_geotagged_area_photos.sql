-- FP-OMS migration 038: geotagged project-area photo documentation.
-- Creates private draft photos and immutable submitted photo records.
-- Existing applications are unchanged. Photos are optional and stored encrypted outside public/.

CREATE TABLE IF NOT EXISTS permit_step2_area_photo_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    original_name VARCHAR(220) NOT NULL,
    stored_path VARCHAR(300) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(11,7) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_step2_area_photo_draft_path (stored_path),
    KEY idx_step2_area_photo_draft_owner (client_id,application_id,created_at),
    CONSTRAINT fk_step2_area_photo_draft_client FOREIGN KEY (client_id)
        REFERENCES user_client(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permit_step2_area_photos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    original_name VARCHAR(220) NOT NULL,
    stored_path VARCHAR(300) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(11,7) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_step2_area_photo_path (stored_path),
    KEY idx_step2_area_photo_app (application_id,revision,id),
    CONSTRAINT fk_step2_area_photo_app FOREIGN KEY (application_id)
        REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_step2_area_photo_client FOREIGN KEY (client_id)
        REFERENCES user_client(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
