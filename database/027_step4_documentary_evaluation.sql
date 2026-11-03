-- DENR CARAGA FP-OMS | 027: Annex A Step 4 Documentary Evaluation.
-- Apply once AFTER migrations 014, 019, 020 and 022.
-- This adds LPDD-FUS Step 4 review history and requirement-level assessments.
-- Existing Step 2/3 records and uploaded files are preserved.

ALTER TABLE permit_step2_applications
  MODIFY COLUMN status ENUM(
    'submitted','corrections_required','forwarded_to_ro','reverted',
    'ro_received','forwarded_to_lpdd',
    'step4_under_evaluation','step4_corrections_required',
    'step4_resubmitted','step4_complete'
  ) NOT NULL DEFAULT 'submitted';

ALTER TABLE permit_step2_events
  MODIFY COLUMN action ENUM(
    'submitted','returned_for_correction','resubmitted','forwarded_to_ro',
    'documents_received','reverted','ro_documents_received','forwarded_to_lpdd',
    'step4_review_started','step4_returned_for_compliance',
    'step4_resubmitted','step4_complete'
  ) NOT NULL;

CREATE TABLE IF NOT EXISTS permit_step4_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id BIGINT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NULL,
  review_status ENUM('under_evaluation','returned','complete')
    NOT NULL DEFAULT 'under_evaluation',
  overall_remarks TEXT NULL,
  applicant_instructions TEXT NULL,
  field_validation_schedule DATETIME NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_step4_app_revision (application_id,revision),
  KEY idx_step4_status (review_status,started_at),
  KEY idx_step4_reviewer (reviewer_user_id,started_at),
  CONSTRAINT fk_step4_app FOREIGN KEY (application_id)
    REFERENCES permit_step2_applications(id) ON DELETE RESTRICT,
  CONSTRAINT fk_step4_reviewer FOREIGN KEY (reviewer_user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permit_step4_requirement_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  review_id BIGINT UNSIGNED NOT NULL,
  requirement_type VARCHAR(48) NOT NULL,
  assessment ENUM('complete','incomplete','insufficient','not_applicable') NOT NULL,
  remarks VARCHAR(1500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_step4_review_requirement (review_id,requirement_type),
  KEY idx_step4_requirement_assessment (assessment),
  CONSTRAINT fk_step4_requirement_review FOREIGN KEY (review_id)
    REFERENCES permit_step4_reviews(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SHOW TABLES LIKE 'permit_step4_%';
SHOW COLUMNS FROM permit_step2_applications LIKE 'status';
SHOW COLUMNS FROM permit_step2_events LIKE 'action';
