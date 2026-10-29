<?php
declare(strict_types=1);

/**
 * Read-only schema preflight for registration.
 * Prevents opaque failures when SQL 011/013 was not applied fully.
 */
function registration_schema_issues(): array
{
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
    $rows = query_all(
        "SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
            ('user_client','applicant_registrations','registration_documents',
             'registration_email_otps','registration_draft_documents','audit_logs')"
    );
    $found = [];
    foreach ($rows as $row) {
        $found[(string)$row['TABLE_NAME']][(string)$row['COLUMN_NAME']]
            = (string)$row['COLUMN_TYPE'];
    }
    $missing = [];
    foreach ($requirements as $table => $columns) {
        foreach ($columns as $column) {
            if (!isset($found[$table][$column])) $missing[] = "{$table}.{$column}";
        }
    }
    $statusType = strtolower($found['user_client']['status'] ?? '');
    if ($statusType !== '' && !str_contains($statusType, "'pending_email_verification'")) {
        $missing[] = 'user_client.status (pending_email_verification)';
    }
    return $missing;
}

/**
 * Public users see only random references; detailed SQLSTATE/stage remains in
 * PHP error logs. Do not log passwords, OTP codes or registration form input.
 */
function registration_failure_reference(string $stage, ?Throwable $error = null, array $details = []): string
{
    $reference = 'REG-' . strtoupper(bin2hex(random_bytes(5)));
    $state = $error instanceof PDOException
        ? (string)($error->errorInfo[0] ?? $error->getCode()) : 'n/a';
    $driverCode = $error instanceof PDOException
        ? (string)($error->errorInfo[1] ?? 'n/a') : 'n/a';
    $cause = $error ? preg_replace('/[\r\n\t]+/', ' ', $error->getMessage()) : '';
    error_log('[FP-OMS][' . $reference . '] Registration stage=' . $stage
        . ' SQLSTATE=' . $state . ' DRIVER=' . $driverCode
        . ' Missing=' . implode(',', $details)
        . ' Cause=' . substr((string)$cause, 0, 1600));
    return $reference;
}
