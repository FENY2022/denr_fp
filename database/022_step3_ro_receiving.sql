-- DENR CARAGA FP-OMS Annex A Step 3. Run once after migrations 014 and 020.
-- Back up the database and uploaded documents first. No Step 4 payment is added.
ALTER TABLE permit_step2_applications
  MODIFY COLUMN status ENUM('submitted','corrections_required','forwarded_to_ro','reverted','ro_received','forwarded_to_lpdd')
  NOT NULL DEFAULT 'submitted';
ALTER TABLE permit_step2_events
  MODIFY COLUMN action ENUM('submitted','returned_for_correction','resubmitted','forwarded_to_ro','documents_received','reverted','ro_documents_received','forwarded_to_lpdd') NOT NULL;
CREATE TABLE IF NOT EXISTS permit_step3_movements (
  application_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  revision INT UNSIGNED NOT NULL,
  received_at DATETIME NOT NULL,
  received_by_user_id BIGINT UNSIGNED NULL,
  receipt_remarks TEXT NULL,
  forwarded_at DATETIME NULL,
  forwarded_by_user_id BIGINT UNSIGNED NULL,
  forwarding_remarks TEXT NULL,
  KEY idx_step3_received (received_at),
  KEY idx_step3_forwarded (forwarded_at),
  CONSTRAINT fk_step3_app FOREIGN KEY(application_id) REFERENCES permit_step2_applications(id) ON DELETE RESTRICT,
  CONSTRAINT fk_step3_received_by FOREIGN KEY(received_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_step3_forwarded_by FOREIGN KEY(forwarded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SHOW TABLES LIKE 'permit_step3_movements';
