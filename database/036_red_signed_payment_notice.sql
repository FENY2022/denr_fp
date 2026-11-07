-- FP-OMS 036 | RED-signed Template No. 2: Complete Documents / Advise to Pay
-- Apply after migration 035. The application also auto-initializes this table on first use.\n-- This is additive and does not alter existing deficiency notices.
CREATE TABLE IF NOT EXISTS fpoms_payment_notices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id BIGINT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL,
  prepared_by BIGINT UNSIGNED NOT NULL,
  draft_path VARCHAR(400) NOT NULL,
  draft_sha256 CHAR(64) NOT NULL,
  status ENUM('pending_red','signed','released') NOT NULL DEFAULT 'pending_red',
  artifact_id BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  released_by BIGINT UNSIGNED NULL,
  released_at DATETIME NULL,
  email_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  email_last_attempt_at DATETIME NULL,
  email_sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_notice_revision (application_id,revision),
  KEY idx_payment_notice_status (status,created_at),
  CONSTRAINT fk_payment_notice_app FOREIGN KEY (application_id)
    REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_notice_prepared_by FOREIGN KEY (prepared_by)
    REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payment_notice_approved_by FOREIGN KEY (approved_by)
    REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payment_notice_released_by FOREIGN KEY (released_by)
    REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payment_notice_artifact FOREIGN KEY (artifact_id)
    REFERENCES permit_flow_artifacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SHOW TABLES LIKE 'fpoms_payment_notices';
