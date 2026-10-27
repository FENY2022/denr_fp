<?php
declare(strict_types=1);

// Read-only verification after migrations 011 and 012.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../app/bootstrap.php';

try {
    $users = query_one("SELECT COUNT(*) AS n FROM users WHERE user_kind='applicant'");
    $clients = query_one("SELECT COUNT(*) AS n FROM user_client WHERE user_kind='applicant'");
    $staff = query_one("SELECT COUNT(*) AS n FROM users WHERE user_kind='internal'");
    $dangling = query_one(
        'SELECT COUNT(*) AS n FROM applicant_registrations ar
         LEFT JOIN user_client c ON c.id=ar.client_id WHERE c.id IS NULL'
    );
    $duplicateEmails = query_one(
        'SELECT COUNT(*) AS n FROM user_client c
         INNER JOIN users u ON LOWER(TRIM(u.email))=LOWER(TRIM(c.email))'
    );
    $crossNotifications = query_one(
        'SELECT COUNT(*) AS n FROM notifications WHERE user_id IS NOT NULL AND client_id IS NOT NULL'
    );
    $crossResets = query_one(
        'SELECT COUNT(*) AS n FROM password_reset_tokens WHERE user_id IS NOT NULL AND client_id IS NOT NULL'
    );
    $staffLogins = query_one(
        "SELECT COUNT(*) AS n FROM users WHERE user_kind='internal' AND status='active'
           AND email IS NOT NULL AND password_hash IS NOT NULL"
    );
    $draftTable = query_one(
        "SELECT COUNT(*) AS n FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='registration_draft_documents'"
    );
    printf("DENR staff: %d\n", (int)$staff['n']);
    printf("Separate client accounts: %d\n", (int)$clients['n']);
    printf("Active DENR staff with credentials: %d\n", (int)$staffLogins['n']);
    $problems = [];
    if ((int)$users['n'] !== 0) $problems[] = 'Old applicant accounts still exist in users.';
    if ((int)$dangling['n'] !== 0) $problems[] = 'Client registration references are missing.';
    if ((int)$duplicateEmails['n'] !== 0) $problems[] = 'A login email is duplicated across staff/client tables.';
    if ((int)$crossNotifications['n'] !== 0) $problems[] = 'A notification has two different owners.';
    if ((int)$crossResets['n'] !== 0) $problems[] = 'A password reset token has two different owners.';
    if ((int)$staffLogins['n'] < 1) $problems[] = 'No active DENR employee account has credentials.';
    if ((int)$draftTable['n'] !== 1) $problems[] = 'Registration draft migration 012 has not been applied.';

    foreach ($problems as $message) fwrite(STDERR, "FAIL: {$message}\n");
    if ($problems) exit(1);
    echo "PASS: staff/client accounts and private draft uploads are structurally separated.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Verification failed: " . $e->getMessage() . "\n");
    exit(1);
}
