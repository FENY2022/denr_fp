-- DENR FP-OMS migration 016 — authenticated, non-expiring Step 2 drafts.
-- Apply ONLY after migration 015; backup database and private storage first.
-- Existing draft documents are retained, including previously session-scoped files.
-- This migration does not alter permanent submitted applications/documents.
CREATE TABLE IF NOT EXISTS permit_step2_form_drafts (
    client_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    project_title VARCHAR(180) NOT NULL DEFAULT '',
    project_location VARCHAR(300) NOT NULL DEFAULT '',
    purpose TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id,application_id),
    CONSTRAINT fk_step2_form_draft_client FOREIGN KEY (client_id)
      REFERENCES user_client(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allow NULL to mean "keep until submitted or explicitly cleared".
ALTER TABLE permit_step2_draft_documents MODIFY expires_at DATETIME NULL;
-- Convert all remaining draft rows (including expired but not yet cleaned) to persistent drafts.
UPDATE permit_step2_draft_documents SET expires_at=NULL;

-- Read-only checks:
SHOW TABLES LIKE 'permit_step2_form_drafts';
SHOW COLUMNS FROM permit_step2_draft_documents LIKE 'expires_at';
