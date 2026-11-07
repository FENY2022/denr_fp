-- FP-OMS migration 036: RED-signed Template No. 2 complete-docs / advice-to-pay notice.
-- Auto-initialized on first use when the DB account has CREATE TABLE permission.
-- Safe to run manually after migrations 031 and 032.
CREATE TABLE IF NOT EXISTS fpoms_complete_notices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    received_by_user_id BIGINT UNSIGNED NOT NULL,
    payload_json LONGTEXT NOT NULL,
    draft_path VARCHAR(300) NOT NULL,
    draft_sha256 CHAR(64) NOT NULL,
    status ENUM('pending_red','signed','released') NOT NULL DEFAULT 'pending_red',
    artifact_id BIGINT UNSIGNED DEFAULT NULL,
    approved_by BIGINT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    released_by BIGINT UNSIGNED DEFAULT NULL,
    released_at DATETIME DEFAULT NULL,
    email_status ENUM('not_sent','sent','failed') NOT NULL DEFAULT 'not_sent',
    email_last_attempt_at DATETIME DEFAULT NULL,
    email_sent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fp_complete_notice_application_revision (application_id,revision),
    KEY idx_fp_complete_notice_status (status,created_at),
    KEY idx_fp_complete_notice_email (email_status),
    CONSTRAINT fk_fp_complete_notice_app FOREIGN KEY (application_id)
        REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_fp_complete_notice_receiver FOREIGN KEY (received_by_user_id)
        REFERENCES users(id),
    CONSTRAINT fk_fp_complete_notice_approver FOREIGN KEY (approved_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_fp_complete_notice_releaser FOREIGN KEY (released_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_fp_complete_notice_artifact FOREIGN KEY (artifact_id)
        REFERENCES permit_flow_artifacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
