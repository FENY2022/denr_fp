<?php
declare(strict_types=1);

// DB-free contracts for the independent LPDD-FUS schedule communication action.
function schedule_assert(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException('Field validation schedule: '.$label);
}
function schedule_source(string $path): string
{
    $data=file_get_contents(__DIR__.'/../'.$path);
    if (!is_string($data)) throw new RuntimeException('Cannot read '.$path);
    return $data;
}

$flow=schedule_source('app/permit_flow.php');
$views=schedule_source('app/permit_flow_views.php');
$application=schedule_source('app/permit_step2_views.php');
$router=schedule_source('public/index.php');
$migration=schedule_source('database/039_field_validation_schedule_communication.sql');
$rangeMigration=schedule_source('database/043_field_validation_schedule_range.sql');
$picker=schedule_source('public/assets/field-validation-range.js');
schedule_assert(str_contains($rangeMigration,'field_validation_schedule_end')
    && str_contains($flow,'field_validation_schedule_end')
    && str_contains($flow,"\$end=step4_parse_schedule")
    && str_contains($flow,'later than From date and time')
    && str_contains($picker,'fp-flight-months')
    && str_contains($picker,'to.value=date;choosingEnd=true')
    && str_contains($picker,'to.value=date;choosingEnd=false')
    && str_contains($picker,"' • 1 Day'")
    && str_contains($picker,"+' Days'")
    && str_contains($views,'data-fp-flight-calendar')
    && !str_contains($views,'data-fp-flight-open')
    && str_contains($views,'data-fp-flight-popover></div>')
    && str_contains($views,'type="hidden" name="field_validation_from_date"')
    && str_contains($views,'type="hidden" name="field_validation_to_date"'),
    'From/To calendar is always visible and displays inclusive days');

schedule_assert(str_contains($migration,'field_validation_location')
    && str_contains($migration,'field_schedule_notes')
    && str_contains($migration,'field_schedule_communicated_at')
    && str_contains($migration,'field_schedule_communicated_by')
    && str_contains($migration,'FOREIGN KEY (field_schedule_communicated_by)'),
    'migration stores the schedule communication receipt and actor');
schedule_assert(str_contains($flow,'function permit_flow_communicate_schedule(')
    && str_contains($flow,'permit_flow_staff_can_act($staff,4)')
    && str_contains($flow,"(int)\$case['current_step']!==4")
    && str_contains($flow,"(string)\$app['status']!=='forwarded_to_lpdd'")
    && str_contains($flow,'fpoms_payment_notice_is_released'),
    'only LPDD-FUS can communicate a schedule at the valid Step 4 state');
schedule_assert(str_contains($flow,"(\$input['schedule_reviewed']??'')!=='yes'")
    && str_contains($flow,'getTimestamp()<=time()')
    && str_contains($flow,'mb_strlen($location)<5')
    && str_contains($flow,'mb_strlen($notes)<10'),
    'server validates confirmation, future date, venue and coordination details');
schedule_assert(str_contains($flow,"'field_schedule_recommunicated':'field_schedule_communicated'")
    && str_contains($flow,'permit_flow_record_event(')
    && str_contains($flow,"['cenro_twg','penro_user']")
    && str_contains($flow,'create_client_notification(')
    && str_contains($flow,'permit_flow_email('),
    'initial and updated schedules are audited and communicated to all parties');
schedule_assert(str_contains($views,'Provide &amp; Communicate')
    && str_contains($views,'name="field_validation_from_date"')
    && str_contains($views,'name="field_validation_to_date"')
    && str_contains($views,'name="field_validation_from_time"')
    && str_contains($views,'name="field_validation_to_time"')
    && str_contains($views,'name="field_validation_location"')
    && str_contains($views,'name="field_schedule_notes"')
    && str_contains($views,'name="schedule_reviewed"')
    && str_contains($views,"url('permit_flow_schedule'"),
    'LPDD-FUS has a dedicated schedule review form');
schedule_assert(str_contains($views,'function permit_flow_schedule_summary_html(')
    && str_contains($views,'Field Validation / Assessment Schedule')
    && str_contains($application,'permit_flow_schedule_summary_html($app,$viewer)'),
    'latest communicated schedule is visible on the applicant application page');
schedule_assert(str_contains($flow,'Save and communicate the field validation/assessment schedule first.')
    && str_contains($views,'Provide and communicate the field validation/assessment schedule')
    && str_contains($views,'$scheduleMissing'),
    'Step 4 cannot be completed before schedule communication');
schedule_assert(str_contains($router,"case 'permit_flow_schedule':")
    && str_contains($router,'check_csrf();')
    && str_contains($router,'permit_flow_communicate_schedule('),
    'schedule route is POST-only, CSRF-protected and calls the domain action');

echo "Field validation schedule communication checks passed.\n";
