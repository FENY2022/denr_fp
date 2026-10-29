-- FP-OMS 015: authenticated FP Step 2 draft supporting files.
-- Run ONCE after migration 014, and after backing up denr_fp_oms + storage.
-- New table only; existing registration and permit records are unchanged.
CREATE TABLE IF NOT EXISTS permit_step2_draft_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    draft_hash CHAR(64) NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    original_name VARCHAR(220) NOT NULL,
    stored_path VARCHAR(300) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uq_step2_draft_path (stored_path),
    KEY idx_step2_draft_owner (client_id,draft_hash,application_id,expires_at),
    KEY idx_step2_draft_expire (expires_at),
    CONSTRAINT fk_step2_draft_client FOREIGN KEY (client_id)
        REFERENCES user_client(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SHOW TABLES LIKE 'permit_step2_draft_documents';
