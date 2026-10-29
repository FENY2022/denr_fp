<?php
declare(strict_types=1);

// Pure PHP regression test: no SMTP, HTTP requests, or MySQL database needed.
require_once __DIR__ . '/../app/registration_diagnostics.php';

function assert_registration_fix(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('Registration security regression: ' . $message);
}

$GLOBALS['test_schema_rows'] = [];
function query_all(string $sql, array $params = []): array
{
    assert_registration_fix(str_contains($sql, 'information_schema.COLUMNS'),
        'Preflight is read-only and inspects schema metadata');
    return $GLOBALS['test_schema_rows'];
}

$requirements = [
    'user_client'=>['id','name','email','password_hash','user_kind','status','email_verified_at'],
    'applicant_registrations'=>['client_id','company_name','company_address',
        'representative_name','representative_position','contact_number','organization_type'],
    'registration_documents'=>['registration_id','id_type','original_name',
        'stored_path','mime_type','file_size','sha256','revision','requirement_type'],
    'registration_draft_documents'=>['id','document_type','requirement_type'],
    'registration_email_otps'=>['client_id','otp_hash','issued_at','expires_at',
        'failed_attempts','resend_window_started_at','sends_in_window','last_send_at'],
    'audit_logs'=>['actor_client_id','action','target_type','target_id']
];
foreach ($requirements as $table => $columns) {
    foreach ($columns as $column) {
        $GLOBALS['test_schema_rows'][] = [
            'TABLE_NAME'=>$table,'COLUMN_NAME'=>$column,
            'COLUMN_TYPE'=>$column === 'status'
                ? "enum('pending_review','pending_email_verification','active')" : 'varchar(255)'
        ];
    }
}
assert_registration_fix(registration_schema_issues() === [],
    'Fully migrated client database passes readiness checks');

$saved = $GLOBALS['test_schema_rows'];
$GLOBALS['test_schema_rows'] = array_values(array_filter($saved,
    static fn(array $row): bool => !($row['TABLE_NAME'] === 'registration_email_otps'
        && $row['COLUMN_NAME'] === 'otp_hash')
));
assert_registration_fix(in_array('registration_email_otps.otp_hash', registration_schema_issues(), true),
    'Partially missing SQL 013 OTP table is detected');

$GLOBALS['test_schema_rows'] = $saved;
foreach ($GLOBALS['test_schema_rows'] as &$row) {
    if ($row['TABLE_NAME'] === 'user_client' && $row['COLUMN_NAME'] === 'status') {
        $row['COLUMN_TYPE'] = "enum('pending_review','active')";
    }
}
unset($row);
assert_registration_fix(in_array('user_client.status (pending_email_verification)',
    registration_schema_issues(), true),
    'Old client status enum incompatible with OTP is detected');
$GLOBALS['test_schema_rows'] = $saved;

$drafts = file_get_contents(__DIR__ . '/../app/registration_drafts.php');
$uploadsJs = file_get_contents(__DIR__ . '/../public/assets/registration-attachments.js');
$landing = file_get_contents(__DIR__ . '/../app/landing.php');
$landingJs = file_get_contents(__DIR__ . '/../public/assets/landing-registration-success.js');
$css = file_get_contents(__DIR__ . '/../public/assets/style.css');
$registration = file_get_contents(__DIR__ . '/../app/registration.php');
$bootstrap = file_get_contents(__DIR__ . '/../app/bootstrap.php');

assert_registration_fix(str_contains($drafts, '<progress class="selected-file-progress"')
    && !str_contains($drafts, 'style="width:100%"'),
    'Saved attachment progress bar never emits blocked inline style');
assert_registration_fix(str_contains($uploadsJs, "document.createElement('progress')")
    && str_contains($uploadsJs, 'progress.bar.value =')
    && !str_contains($uploadsJs, '.style.width'),
    'Live progress updates use native progress value, not CSP-blocked inline CSS');
assert_registration_fix(str_contains($css, '.selected-file-progress::-webkit-progress-value')
    && str_contains($css, '.selected-file-progress::-moz-progress-bar'),
    'Progress styling is in external stylesheet');
assert_registration_fix(str_contains($landing, 'src="assets/landing-registration-success.js')
    && !str_contains($landing, "echo '<script>"),
    'Registration modal uses external script, not inline JavaScript');
assert_registration_fix(str_contains($landingJs, "window.localStorage.removeItem("),
    'Registration form draft is still cleared by external script');
assert_registration_fix(str_contains($bootstrap, "registration_diagnostics.php"),
    'Registration diagnostic preflight is loaded');
assert_registration_fix(str_contains($registration, 'registration_schema_issues()')
    && str_contains($registration, 'registration_failure_reference('),
    'Registration reports missing schema and provides safe diagnostic references');
assert_registration_fix(str_contains($registration, "\$ex->errorInfo[1] ?? 0") && str_contains($registration,'1062'),
    'Duplicate errors are distinguished from foreign-key constraint failures');

echo "PASS: strict-CSP registration progress/modal and read-only OTP schema diagnostic checks.\n";
