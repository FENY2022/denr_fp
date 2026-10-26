<?php
declare(strict_types=1);

// No database connection needed. Validates source-data classifications,
// non-destructive SQL import, and shared-office-email safety conditions.
require_once __DIR__ . '/../app/domain.php';
require_once __DIR__ . '/../app/user_management.php';

function test_office_email(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Office Email test failed: ' . $message);
}

$sql = user_management_email_reference_columns_sql();
test_office_email(str_contains($sql, "u.status='pending_setup'"),
    'Suggestions are restricted to Pending Setup accounts');
test_office_email(str_contains($sql, 'COUNT(*) FROM users ref'),
    'Suggestion checks all users, not only filtered table rows');
test_office_email(str_contains($sql, 'LOWER(TRIM(ref.reference_email))'),
    'Reference uniqueness ignores case and surrounding whitespace');
test_office_email(str_contains($sql, 'NOT EXISTS'),
    'Address already in use as an existing login is not suggested');
test_office_email(str_contains($sql, 'suggested_login_email'),
    'Returned column is distinct from user login email');
test_office_email(str_contains($sql, 'office_email_shared'),
    'Reference-sharing flag is available to the UI');

$migration = file_get_contents(__DIR__ . '/../database/010_restore_office_email_references.sql');
test_office_email(is_string($migration) && $migration !== '', 'Repair script is available');
test_office_email(str_contains($migration, "SET u.reference_email=src.reference_email"),
    'Repair writes only the source reference field');
test_office_email(str_contains($migration, "u.status='pending_setup'"),
    'Repair is restricted to pending personnel');
test_office_email(str_contains($migration, "TRIM(u.reference_email)=''"),
    'Repair only fills missing references');
test_office_email(!preg_match('/SET\s+u\.(?:email|password_hash|status)\s*=/i', $migration),
    'Repair never changes personal login email, password or status');

preg_match_all("/^\s*\('([a-z0-9-]+)',\s*'([^']+)'\)[,;]$/m", $migration, $matches);
test_office_email(count($matches[1]) === 34, 'All 34 source-keyed designation records are covered');
test_office_email(count(array_unique($matches[1])) === 34, 'All designation source keys are distinct');

$counts = [];
$pending = [];
foreach ($matches[1] as $index => $key) {
    $email = strtolower(trim($matches[2][$index]));
    $counts[$email] = ($counts[$email] ?? 0) + 1;
    if ($key !== 'a01-1') $pending[] = $email;
}
$uniquePending = count(array_filter($pending, static fn(string $email): bool => $counts[$email] === 1));
$sharedPending = count(array_filter($pending, static fn(string $email): bool => $counts[$email] > 1));
test_office_email($uniquePending === 6, 'Six pending users have single-person reference emails');
test_office_email($sharedPending === 27, 'Twenty-seven pending users share office references');

$view = file_get_contents(__DIR__ . '/../app/user_management_views.php');
test_office_email(is_string($view) && str_contains($view, '<th>Office Email (Reference)</th>'),
    'User Management has a separate Office Email column');
test_office_email(str_contains($view, 'data-user-suggested-email='),
    'Only server-calculated candidates are supplied to the Set Up button');
test_office_email(str_contains($view, 'data-user-reference-shared='),
    'Shared office warning is supplied to the Set Up modal');

$js = file_get_contents(__DIR__ . '/../public/assets/user-setup-modal.js');
test_office_email(is_string($js) && str_contains($js, 'button.dataset.userSuggestedEmail'),
    'Client prefill uses the server-calculated suggestion');
test_office_email(str_contains($js, 'button.dataset.userReferenceShared'),
    'Client warns about shared office emails');

echo "PASS: 34 email references, 6 suggestions, 27 shared records; login credentials remain protected.\n";
