-- FP-OMS | Migration 031 – updated Annex A 11-step workflow.
-- One-time additive migration, after 030. Back up database + encrypted storage.
-- Legacy applications remain flow_version=1; only new applications use flow_version=2.
ALTER TABLE permit_step2_applications
  ADD COLUMN flow_version TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER revision;

CREATE TABLE IF NOT EXISTS permit_flow_cases (
  application_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  current_step TINYINT UNSIGNED NOT NULL DEFAULT 4,
  field_validation_schedule DATETIME NULL,
  notice_text TEXT NULL,
  cenro_instructions TEXT NULL,
  completed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_fp_flow_case_app FOREIGN KEY (application_id)
    REFERENCES permit_step2_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permit_flow_artifacts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id BIGINT UNSIGNED NOT NULL,
  step_number TINYINT UNSIGNED NOT NULL,
  category VARCHAR(60) NOT NULL,
  original_name VARCHAR(220) NOT NULL,
  stored_path VARCHAR(400) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_flow_artifact_category (application_id,step_number,category),
  CONSTRAINT fk_fp_flow_artifact_app FOREIGN KEY (application_id)
    REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_fp_flow_artifact_staff FOREIGN KEY (uploaded_by)
    REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permit_flow_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id BIGINT UNSIGNED NOT NULL,
  step_number TINYINT UNSIGNED NOT NULL,
  action VARCHAR(70) NOT NULL,
  remarks TEXT NULL,
  actor_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_flow_events (application_id,id),
  CONSTRAINT fk_fp_flow_event_app FOREIGN KEY (application_id)
    REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_fp_flow_event_staff FOREIGN KEY (actor_id)
    REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (code,label,description)
VALUES
 ('smd','SMD / GIS Mapping','RRDC Day 1 final mapping and scheduling support'),
 ('cdd','CDD','RRDC scheduling, meeting minutes and agreements')
ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description);

-- IMPORTANT: Migrating already-submitted legacy cases is intentionally excluded;
-- existing Step 3 and Step 4 decisions, payment records and audit trails remain intact.
SHOW COLUMNS FROM permit_step2_applications LIKE 'flow_version';
SHOW TABLES LIKE 'permit_flow_%';
