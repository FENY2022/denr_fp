-- FP-OMS 038 | Applicant read receipt for the official Notice to Pay
-- Apply after migration 037. Existing notices remain unread until the owning
-- applicant successfully opens the protected PDF.
ALTER TABLE fpoms_payment_notices
  ADD COLUMN applicant_viewed_at DATETIME NULL AFTER released_at;

SHOW COLUMNS FROM fpoms_payment_notices LIKE 'applicant_viewed_at';
