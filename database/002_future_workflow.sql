-- Phase 2+ data structures only. These tables do NOT activate Step 2–11 features in Phase 1.
CREATE TABLE IF NOT EXISTS permit_applications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    applicant_user_id BIGINT UNSIGNED NOT NULL,
    reference_number VARCHAR(35) NOT NULL UNIQUE,
    project_name VARCHAR(220) NOT NULL,
    project_location TEXT NOT NULL,
    proposed_area_hectares DECIMAL(16,4) NULL,
    current_step TINYINT UNSIGNED NOT NULL DEFAULT 2,
    status ENUM('draft','submitted','under_review','for_correction','approved','released','denied','cancelled') NOT NULL DEFAULT 'draft',
    submitted_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_app_owner (applicant_user_id,status),
    INDEX idx_app_workflow (current_step,status),
    CONSTRAINT fk_app_applicant FOREIGN KEY (applicant_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_app_step CHECK (current_step BETWEEN 2 AND 11)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    document_code VARCHAR(70) NOT NULL,
    original_name VARCHAR(220) NOT NULL,
    stored_path VARCHAR(300) NOT NULL UNIQUE,
    mime_type VARCHAR(80) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_app_docs (application_id,document_code,version),
    CONSTRAINT fk_ad_application FOREIGN KEY (application_id) REFERENCES permit_applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_ad_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    step_number TINYINT UNSIGNED NOT NULL,
    performed_by BIGINT UNSIGNED NOT NULL,
    action_code VARCHAR(70) NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NULL,
    remarks TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_workflow_app (application_id,created_at),
    CONSTRAINT fk_wa_application FOREIGN KEY (application_id) REFERENCES permit_applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_wa_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permit_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL UNIQUE,
    permit_number VARCHAR(65) NOT NULL UNIQUE,
    issued_at DATE NULL,
    expires_at DATE NULL,
    approved_by BIGINT UNSIGNED NULL,
    released_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pr_application FOREIGN KEY (application_id) REFERENCES permit_applications(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pr_user FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
