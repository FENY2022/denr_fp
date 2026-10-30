-- FP-OMS Migration 020: applicant 30-minute revert and DENR receipt marker.
-- Apply ONCE after 014 and 019, with full database and private storage backup.
-- Existing submissions, files and review history are not removed.
-- For reruns after partial execution, inspect columns/ENUM values manually first.
ALTER TABLE permit_step2_applications
  MODIFY COLUMN status ENUM('submitted','corrections_required','forwarded_to_ro','reverted')
    NOT NULL DEFAULT 'submitted',
  ADD COLUMN received_at DATETIME NULL AFTER reviewed_at,
  ADD COLUMN received_by_user_id BIGINT UNSIGNED NULL AFTER received_at,
  ADD KEY idx_step2_received_by (received_by_user_id),
  ADD CONSTRAINT fk_step2_received_by FOREIGN KEY (received_by_user_id)
    REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE permit_step2_events
  MODIFY COLUMN action ENUM(
    'submitted','returned_for_correction','resubmitted','forwarded_to_ro',
    'documents_received','reverted'
  ) NOT NULL;

SHOW COLUMNS FROM permit_step2_applications LIKE 'received_at';
SHOW COLUMNS FROM permit_step2_applications LIKE 'received_by_user_id';
SHOW COLUMNS FROM permit_step2_applications LIKE 'status';
SHOW COLUMNS FROM permit_step2_events LIKE 'action';
