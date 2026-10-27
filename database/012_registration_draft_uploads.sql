-- FP-OMS 012: temporary saved attachments on PUBLIC registration.
-- Apply AFTER migration 011. Back up database and private storage before import.
-- Drafts are bound to a random server-side session token, never to a public ID.
-- Anonymous draft uploads remain private and expire after 24 hours.
-- Run "php scripts/cleanup_registration_drafts.php" daily to remove expired files.
CREATE TABLE IF NOT EXISTS registration_draft_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    draft_hash CHAR(64) NOT NULL,
    document_type ENUM('valid_id','supporting_document') NOT NULL,
    id_type VARCHAR(80) NULL,
    original_name VARCHAR(220) NOT NULL,
    stored_path VARCHAR(300) NOT NULL UNIQUE,
    mime_type VARCHAR(80) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    INDEX idx_draft_owner (draft_hash, expires_at),
    INDEX idx_draft_expiration (expires_at),
    INDEX idx_draft_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Read-only verification:
SHOW CREATE TABLE registration_draft_documents;
