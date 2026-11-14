-- FP-OMS 043 | field validation range (apply once after 039, after DB backup)
-- Existing schedules are preserved.
ALTER TABLE permit_flow_cases
  ADD COLUMN field_validation_schedule_end DATETIME NULL AFTER field_validation_schedule;
SHOW COLUMNS FROM permit_flow_cases LIKE 'field_validation_schedule_end';
