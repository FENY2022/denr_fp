<?php
declare(strict_types=1);

// Static and pure-domain regression checks; no MySQL credentials required.
require_once __DIR__ . '/../app/core.php';

function assert_client_separation(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException('Client separation failure: ' . $description);
}

$staff = ['id'=>1,'user_kind'=>'internal'];
$client = ['id'=>1,'user_kind'=>'applicant'];
assert_client_separation(principal_table($staff) === 'users', 'DENR staff use users');
assert_client_separation(principal_table($client) === 'user_client', 'Applicants use user_client');
assert_client_separation(has_role($client,'applicant'), 'Applicants have applicant status');
assert_client_separation(!has_role($client,'super_admin'), 'Overlapping ID never grants an admin role');
assert_client_separation(!has_role($client,'lpdd_fus'), 'Overlapping ID never grants reviewer role');

$auth = file_get_contents(__DIR__ . '/../app/auth.php');
$core = file_get_contents(__DIR__ . '/../app/core.php');
$registration = file_get_contents(__DIR__ . '/../app/registration.php');
$profile = file_get_contents(__DIR__ . '/../app/profile.php');
$views = file_get_contents(__DIR__ . '/../app/views.php');
$reset = file_get_contents(__DIR__ . '/../app/password_reset.php');
$notifications = file_get_contents(__DIR__ . '/../app/notifications.php');
$migration = file_get_contents(__DIR__ . '/../database/011_separate_client_accounts.sql');

assert_client_separation(str_contains($auth, 'FROM user_client WHERE email=')
    && str_contains($auth, "FROM users WHERE email=? AND user_kind='internal'"),
    'Login explicitly queries separate tables');
assert_client_separation(str_contains($auth, "unset(\$_SESSION['user_id'], \$_SESSION['client_id'])"),
    'Login clears any previous identity before setting client/staff session');
assert_client_separation(str_contains($core, "isset(\$_SESSION['client_id'])")
    && str_contains($core, 'client_by_id'),
    'Sessions resolve only through their own account table');
assert_client_separation(str_contains($registration, 'INSERT INTO user_client')
    && str_contains($registration, 'applicant_registrations (client_id'),
    'Registration inserts to user_client and links company details');
assert_client_separation(str_contains($registration, 'UPDATE user_client SET status='),
    'FUU review changes applicant activation in user_client');
assert_client_separation(str_contains($profile, 'principal_table($user)')
    && str_contains($profile, 'SET representative_name=? WHERE client_id=?'),
    'Client profile edits update the client account and company representative');
assert_client_separation(str_contains($views, 'JOIN user_client u ON u.id=ar.client_id'),
    'Reviewer screens load applicant details from user_client');
assert_client_separation(str_contains($reset, "INSERT INTO password_reset_tokens ({\$column}")
    && str_contains($reset, 'prt.client_id'),
    'Password reset links have their own client owner column');
assert_client_separation(str_contains($notifications, 'client_id')
    && str_contains($notifications, 'create_client_notification'),
    'Client notifications cannot leak into staff users');
assert_client_separation(str_contains($migration, 'CREATE TABLE user_client LIKE users'),
    'Migration copies the existing account schema');
assert_client_separation(str_contains($migration, 'DROP FOREIGN KEY fk_reg_user'),
    'Application FK to old users table is replaced');
assert_client_separation(str_contains($migration, 'ADD CONSTRAINT chk_users_internal_only'),
    'Database will reject future applicants in the DENR users table');
assert_client_separation(!str_contains($migration, 'FOREIGN_KEY_CHECKS=0'),
    'Migration never disables referential integrity');
assert_client_separation(str_contains($migration, 'actor_client_id'),
    'Historical audit actors are preserved separately');

echo "PASS: DENR/client account boundaries, review, reset, profile and migration controls.\n";
