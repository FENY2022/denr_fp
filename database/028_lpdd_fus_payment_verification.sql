-- DENR CARAGA FP-OMS | 028 – LPDD-FUS payment verification following Step 4.
-- Apply ONCE after migrations 014, 022, 027 and back up database + storage.
-- Payment verification is handled by the existing LPDD-FUS role.
-- No payment is presumed from the upload alone: explicit LPDD-FUS verification required.

CREATE TABLE IF NOT EXISTS permit_payment_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id BIGINT UNSIGNED NOT NULL,
  status ENUM('awaiting_payment','verified') NOT NULL DEFAULT 'awaiting_payment',
  notified_at DATETIME NULL,
  receipt_number VARCHAR(90) NULL,
  amount_paid DECIMAL(13,2) NULL,
  payment_date DATE NULL,
  original_name VARCHAR(220) NULL,
  stored_path VARCHAR(500) NULL,
  mime_type VARCHAR(100) NULL,
  file_size BIGINT UNSIGNED NULL,
  sha256 CHAR(64) NULL,
  verified_by_user_id BIGINT UNSIGNED NULL,
  verified_at DATETIME NULL,
  notes VARCHAR(2000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_application (application_id),
  KEY idx_payment_status (status,verified_at),
  KEY idx_payment_verifier (verified_by_user_id,verified_at),
  CONSTRAINT fk_fp_payment_app FOREIGN KEY (application_id)
    REFERENCES permit_step2_applications(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fp_payment_verifier FOREIGN KEY (verified_by_user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transitional enum preserves payment audit rows from deployments that used
-- the previous separate payment-role implementation.
ALTER TABLE permit_step2_events
  MODIFY COLUMN action ENUM(
    'submitted','returned_for_correction','resubmitted','forwarded_to_ro',
    'documents_received','reverted','ro_documents_received','forwarded_to_lpdd',
    'step4_review_started','step4_returned_for_compliance',
    'step4_resubmitted','step4_complete','ro_archived',
    'payment_verified_fuu','payment_verified_lpdd_fus'
  ) NOT NULL;

UPDATE permit_step2_events
SET action='payment_verified_lpdd_fus'
WHERE action='payment_verified_fuu';

ALTER TABLE permit_step2_events
  MODIFY COLUMN action ENUM(
    'submitted','returned_for_correction','resubmitted','forwarded_to_ro',
    'documents_received','reverted','ro_documents_received','forwarded_to_lpdd',
    'step4_review_started','step4_returned_for_compliance',
    'step4_resubmitted','step4_complete','ro_archived','payment_verified_lpdd_fus'
  ) NOT NULL;

-- Backfill only the tracking queue for previously completed Step 4 cases.
-- No backfilled payment is marked as verified and no historical notifications
-- are invented.
INSERT INTO permit_payment_verifications (application_id,status)
SELECT a.id,'awaiting_payment' FROM permit_step2_applications a
LEFT JOIN permit_payment_verifications p ON p.application_id=a.id
WHERE a.status='step4_complete' AND p.id IS NULL;

SHOW TABLES LIKE 'permit_payment_verifications';
SHOW COLUMNS FROM permit_payment_verifications;
