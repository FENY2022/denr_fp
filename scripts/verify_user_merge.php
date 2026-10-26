<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../app/bootstrap.php';

/** Read-only verification after applying migration 009. */
try {
    $oldTable = query_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_designations' LIMIT 1"
    );
    $stats = query_one(
        "SELECT COUNT(*) AS total,
                SUM(user_kind='internal') AS internal_count,
                SUM(user_kind='internal' AND status='pending_setup') AS pending_count,
                SUM(user_kind='internal' AND status='active') AS active_count,
                SUM(source_key IS NOT NULL) AS imported_count
         FROM users"
    );
    $unsafePending = query_one(
        "SELECT COUNT(*) AS n FROM users
         WHERE status='pending_setup'
           AND (user_kind<>'internal' OR email IS NOT NULL OR password_hash IS NOT NULL
                OR must_change_password<>1 OR activated_at IS NOT NULL)"
    );
    $missingRoles = query_one(
        "SELECT COUNT(*) AS n FROM users u
         WHERE u.source_key IS NOT NULL AND u.user_kind='internal'
         AND NOT EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id)"
    );
    $admins = query_one(
        "SELECT COUNT(*) AS n FROM users u
         JOIN user_roles ur ON ur.user_id=u.id
         JOIN roles r ON r.id=ur.role_id
         WHERE r.code='super_admin' AND u.status='active'
           AND u.email IS NOT NULL AND u.password_hash IS NOT NULL"
    );

    printf("Total users: %d\n", (int)$stats['total']);
    printf("Internal users: %d\n", (int)$stats['internal_count']);
    printf("Pending Setup: %d\n", (int)$stats['pending_count']);
    printf("Active internal users: %d\n", (int)$stats['active_count']);
    printf("Merged source records: %d\n", (int)$stats['imported_count']);

    $problems = [];
    if ($oldTable) $problems[] = 'The old user_designations table still exists.';
    if ((int)$unsafePending['n'] > 0) $problems[] = 'Some pending accounts have credentials or an invalid status.';
    if ((int)$missingRoles['n'] > 0) $problems[] = 'Some merged users have no assigned role.';
    if ((int)$admins['n'] < 1) $problems[] = 'No active administrator with credentials and a super_admin role exists.';
    if ($problems) {
        foreach ($problems as $message) fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: Accounts are consolidated; pending staff have no login credentials and administrator access is preserved.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Merge verification failed: " . $e->getMessage() . "\n");
    exit(1);
}
