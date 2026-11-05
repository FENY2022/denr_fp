-- FP-OMS migration 032: versioned generated deficiency notices / RED approval.
-- Apply after 031. Keep this migration under source control.
-- Signature graphics and rendered PDFs are encrypted under private storage.

CREATE TABLE IF NOT EXISTS fpoms_notice_config (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    signatory_name VARCHAR(180) NOT NULL DEFAULT 'MA. THERESA J. ALLEN',
    signatory_title VARCHAR(180) NOT NULL DEFAULT 'OIC, Regional Executive Director',
    contact_emails VARCHAR(400) NOT NULL DEFAULT 'r13@denr.gov.ph; lasangutilizationlpdd@gmail.com',
    footer_text VARCHAR(300) NOT NULL DEFAULT 'DENR-XIII, Ambago, Butuan City, Philippines | Hotline No. 0981-017-3551 | E-Mail: r13@denr.gov.ph',
    template_name VARCHAR(240) DEFAULT NULL,
    template_path VARCHAR(300) DEFAULT NULL,
    signature_path VARCHAR(300) DEFAULT NULL,
    signature_sha256 CHAR(64) DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_fp_notice_config_editor FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO fpoms_notice_config (id) VALUES (1);

CREATE TABLE IF NOT EXISTS fpoms_deficiency_notices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    reviewer_user_id BIGINT UNSIGNED NOT NULL,
    payload_json LONGTEXT NOT NULL,
    draft_path VARCHAR(300) NOT NULL,
    draft_sha256 CHAR(64) NOT NULL,
    status ENUM('pending_red','signed') NOT NULL DEFAULT 'pending_red',
    artifact_id BIGINT UNSIGNED DEFAULT NULL,
    approved_by BIGINT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fp_notice_application_revision (application_id,revision),
    KEY idx_fp_notice_pending (status,created_at),
    CONSTRAINT fk_fp_notice_app FOREIGN KEY (application_id)
        REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_fp_notice_reviewer FOREIGN KEY (reviewer_user_id)
        REFERENCES users(id),
    CONSTRAINT fk_fp_notice_approver FOREIGN KEY (approved_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_fp_notice_artifact FOREIGN KEY (artifact_id)
        REFERENCES permit_flow_artifacts(id) ON DELETE SET NULL,
    CONSTRAINT chk_fp_notice_payload CHECK (JSON_VALID(payload_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
