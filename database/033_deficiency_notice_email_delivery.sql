-- FP-OMS migration 033: delivery visibility for generated deficiency notices.
-- Apply once AFTER 032. Back up the database before running.
-- No existing user/permit records are removed.
ALTER TABLE fpoms_deficiency_notices
  ADD COLUMN email_status ENUM('not_sent','sent','failed') NOT NULL DEFAULT 'not_sent',
  ADD COLUMN email_last_attempt_at DATETIME DEFAULT NULL,
  ADD COLUMN email_sent_at DATETIME DEFAULT NULL,
  ADD KEY idx_fp_notice_email_status (email_status);

-- Existing signed notices are not presumed delivered.
-- The authorized RED/LPDD-FUS user can retry a released notice from its application.
