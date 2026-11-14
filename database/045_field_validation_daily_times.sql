-- Preserve existing range schedules.
ALTER TABLE permit_flow_cases ADD COLUMN field_validation_daily_times LONGTEXT NULL AFTER field_validation_schedule_end;
