-- FP-OMS 039 | Auditable field validation/assessment schedule communication
-- Apply after migration 038. The existing Step 4 schedule remains authoritative;
-- these columns record where, how, when and by whom it was communicated.
ALTER TABLE permit_flow_cases
  ADD COLUMN field_validation_location VARCHAR(300) NULL AFTER field_validation_schedule,
  ADD COLUMN field_schedule_notes TEXT NULL AFTER field_validation_location,
  ADD COLUMN field_schedule_communicated_at DATETIME NULL AFTER field_schedule_notes,
  ADD COLUMN field_schedule_communicated_by BIGINT UNSIGNED NULL AFTER field_schedule_communicated_at,
  ADD KEY idx_flow_schedule_communicated (field_schedule_communicated_at),
  ADD CONSTRAINT fk_flow_schedule_communicator
    FOREIGN KEY (field_schedule_communicated_by) REFERENCES users(id) ON DELETE SET NULL;

SHOW COLUMNS FROM permit_flow_cases LIKE 'field_schedule_communicated_at';
