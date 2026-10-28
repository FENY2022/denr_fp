-- DENR CARAGA FP-OMS | Annex A Step 2: application submission / LPDD-FUS initial check.
-- Apply only AFTER migrations 001-013 and client/staff separation (011).
-- This is an ADDITIVE migration; does not modify registration or scaffolded
-- Step 3-11 tables. Take a full database and private-storage backup first.
--
-- Annex A Step 2 process:
-- Applicant -> LPDD-FUS initial review
--   incomplete: reviewer lists deficiencies; applicant corrects/resubmits
--   complete: forward to RO Receiving/Releasing (Step 3; not implemented here)
--
-- The precise official FP document checklist is NOT specified in this diagram.
-- Therefore this phase accepts general supporting files, pending LPDD/FUS approval
-- of the official checklist. No SLA clock is started by this migration.

CREATE TABLE IF NOT EXISTS permit_step2_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    project_title VARCHAR(180) NOT NULL,
    project_location VARCHAR(300) NOT NULL,
    purpose TEXT NULL,
    status ENUM('submitted','corrections_required','forwarded_to_ro') NOT NULL DEFAULT 'submitted',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    forwarded_at DATETIME NULL,
    reviewer_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_step2_client (client_id, created_at),
    KEY idx_step2_queue (status, last_submitted_at),
    CONSTRAINT fk_step2_client FOREIGN KEY (client_id)
        REFERENCES user_client(id) ON DELETE RESTRICT,
    CONSTRAINT fk_step2_reviewer FOREIGN KEY (reviewer_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permit_step2_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    original_name VARCHAR(220) NOT NULL,
    stored_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_step2_documents_app (application_id, revision, id),
    CONSTRAINT fk_step2_document_app FOREIGN KEY (application_id)
        REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_step2_document_client FOREIGN KEY (client_id)
        REFERENCES user_client(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permit_step2_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    actor_client_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    action ENUM('submitted','returned_for_correction','resubmitted','forwarded_to_ro') NOT NULL,
    revision INT UNSIGNED NOT NULL,
    remarks TEXT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_step2_events_app (application_id, occurred_at, id),
    CONSTRAINT fk_step2_event_app FOREIGN KEY (application_id)
        REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_step2_event_client FOREIGN KEY (actor_client_id)
        REFERENCES user_client(id) ON DELETE SET NULL,
    CONSTRAINT fk_step2_event_staff FOREIGN KEY (actor_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Read-only verification (should show all three new tables).
-- Use SHOW TABLES instead of information_schema, which may be restricted
-- on some local XAMPP / MariaDB accounts:
SHOW TABLES LIKE 'permit_step2_%';
