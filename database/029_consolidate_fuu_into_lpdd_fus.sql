-- DENR CARAGA FP-OMS | 029 – consolidate legacy FUU role into LPDD-FUS.
-- Apply ONCE after migration 028.
-- This safely upgrades installations that previously created a separate FUU role.
-- Fresh installations using the revised migration 028 can also run this migration.

INSERT INTO roles (code,label,description)
VALUES (
  'lpdd_fus',
  'LPDD-FUS',
  'Registration review, documentary evaluation, Cashier payment verification, Official Receipt upload and FP drafting'
)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),
  description=VALUES(description);

-- Preserve access for users that were previously assigned only to the legacy FUU role.
INSERT IGNORE INTO user_roles (user_id,role_id)
SELECT ur.user_id,lpdd.id
FROM user_roles ur
JOIN roles legacy ON legacy.id=ur.role_id AND legacy.code='fuu'
JOIN roles lpdd ON lpdd.code='lpdd_fus';

-- Remove legacy role assignments, then remove the legacy role itself.
DELETE ur
FROM user_roles ur
JOIN roles legacy ON legacy.id=ur.role_id
WHERE legacy.code='fuu';

DELETE FROM roles WHERE code='fuu';

-- Temporarily allow both audit action names so historical rows can be renamed.
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

-- Final enum contains LPDD-FUS terminology only.
ALTER TABLE permit_step2_events
  MODIFY COLUMN action ENUM(
    'submitted','returned_for_correction','resubmitted','forwarded_to_ro',
    'documents_received','reverted','ro_documents_received','forwarded_to_lpdd',
    'step4_review_started','step4_returned_for_compliance',
    'step4_resubmitted','step4_complete','ro_archived','payment_verified_lpdd_fus'
  ) NOT NULL;

SELECT code,label FROM roles WHERE code IN ('lpdd_fus','fuu');
SHOW COLUMNS FROM permit_step2_events LIKE 'action';
