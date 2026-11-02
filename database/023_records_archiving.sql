-- DENR CARAGA FP-OMS: Records archive for completed Step 3 handoffs.
-- Back up denr_fp_oms and private files; run AFTER migration 022.
-- Archiving a Records action does not close the client's permit application.
-- The application remains forwarded_to_lpdd for Step 4 processing.
CREATE TABLE IF NOT EXISTS permit_records_archives (
  application_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  archived_at DATETIME NOT NULL,
  archived_by_user_id BIGINT UNSIGNED NULL,
  archive_remarks VARCHAR(2000) NULL,
  KEY idx_records_archives_date (archived_at),
  KEY idx_records_archives_user (archived_by_user_id),
  CONSTRAINT fk_records_archive_app FOREIGN KEY (application_id)
    REFERENCES permit_step2_applications(id) ON DELETE RESTRICT,
  CONSTRAINT fk_records_archive_staff FOREIGN KEY (archived_by_user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve all prior Step 2 and Step 3 audit action names.
ALTER TABLE permit_step2_events
  MODIFY COLUMN action ENUM(
    'submitted','returned_for_correction','resubmitted','forwarded_to_ro',
    'documents_received','reverted','ro_documents_received','forwarded_to_lpdd',
    'ro_archived'
  ) NOT NULL;

SHOW TABLES LIKE 'permit_records_archives';
SHOW COLUMNS FROM permit_step2_events LIKE 'action';
