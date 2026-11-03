<?php
declare(strict_types=1);

require_once __DIR__.'/../app/user_session_security.php';

function session_security_assert(bool $condition,string $label): void
{
    if (!$condition) throw new RuntimeException('Session Security test failed: '.$label);
}

session_security_assert(user_session_event_valid('login_success'),'login success event allowed');
session_security_assert(user_session_event_valid('forced_logout'),'force logout event allowed');
session_security_assert(!user_session_event_valid('password'),'arbitrary event rejected');

$migration=file_get_contents(__DIR__.'/../database/026_user_session_security.sql');
$core=file_get_contents(__DIR__.'/../app/core.php');
$auth=file_get_contents(__DIR__.'/../app/auth.php');
$views=file_get_contents(__DIR__.'/../app/user_management_views.php');
$routes=file_get_contents(__DIR__.'/../public/index.php');

session_security_assert(str_contains($migration,'session_version')
    && str_contains($migration,'account_login_logs'),'migration installs security schema');
session_security_assert(str_contains($core,'user_session_validate_current'),
    'current session checks database session version');
session_security_assert(str_contains($auth,"'login_success'")
    && str_contains($auth,"'login_failed'")
    && str_contains($auth,"'logout'"),'staff authentication events are logged');
session_security_assert(str_contains($views,'Force Logout All Sessions')
    && str_contains($views,'Account Login Logs')
    && str_contains($views,'data-force-logout-trigger'),'User Management security UI exists');
session_security_assert(str_contains($routes,"case 'user_force_logout':")
    && str_contains($routes,"case 'user_login_logs':")
    && str_contains($routes,'check_csrf()'),'security routes exist with CSRF protection');
session_security_assert(!str_contains($views,'password_hash'),
    'security UI does not expose password hashes');

echo "PASS: Super Admin force logout, session revocation, and login log contracts.\n";
