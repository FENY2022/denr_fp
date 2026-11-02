<?php
declare(strict_types=1);

// Database-free contract tests. Use a staging MariaDB + SMTP for OTP E2E.
require_once __DIR__ . '/../app/staff_username.php';
function check_staff_auth(bool $pass,string $message): void {
    if (!$pass) throw new RuntimeException('Staff login/OTP contract: '.$message);
}
check_staff_auth(staff_username_valid('juandelacruz2'),'normal username');
check_staff_auth(staff_username_valid('fpuser000123'),'legacy username');
check_staff_auth(!staff_username_valid('a'),'too short');
check_staff_auth(!staff_username_valid('a@denr.gov.ph'),'email not allowed as username');
check_staff_auth(!staff_username_valid('admin<script>'),'HTML rejected');
check_staff_auth(!staff_username_valid('4juan'),'username starts with letter');
check_staff_auth(staff_username_generated('Juan Dela Cruz',28)==='juandelacruz28','named ID suffix');
check_staff_auth(staff_username_generated('Maria Santos',25)==='mariasantos25','unique imported account');
check_staff_auth(staff_username_for_account('','Juan Dela Cruz',28)==='juandelacruz28','auto generate');
check_staff_auth(staff_username_for_account('custom_admin','Name',1)==='custom_admin','admin override');
$auth=file_get_contents(__DIR__.'/../app/auth.php');
$views=file_get_contents(__DIR__.'/../app/views.php');
$routes=file_get_contents(__DIR__.'/../public/index.php');
$mgmt=file_get_contents(__DIR__.'/../app/user_management.php');
$mgmtView=file_get_contents(__DIR__.'/../app/user_management_views.php');
$reset=file_get_contents(__DIR__.'/../app/password_reset.php');
$otp=file_get_contents(__DIR__.'/../app/staff_password_otp.php');
$sql=file_get_contents(__DIR__.'/../database/021_staff_username_login_otp.sql');
$setupJs=file_get_contents(__DIR__.'/../public/assets/user-setup-modal.js');
$editJs=file_get_contents(__DIR__.'/../public/assets/user-edit-modal.js');
check_staff_auth(str_contains($auth,"WHERE username=? AND user_kind='internal'"),
    'DENR login queries staff username');
check_staff_auth(str_contains($auth,"WHERE email=? AND user_kind='applicant'"),
    'applicant login remains email based');
check_staff_auth(!str_contains($auth,"WHERE email=? AND user_kind='internal'"),
    'staff email is not a sign-in credential');
check_staff_auth(str_contains($views,"field('identifier','Username (DENR) or Email (Applicant)'"),
    'login form accepts one identifier');
check_staff_auth(str_contains($routes,"safe_post('identifier',190)"),
    'login controller uses the submitted identifier');
check_staff_auth(str_contains($routes,"case 'staff_reset_password':")
    && str_contains($routes,'staff_otp_request($email)'),
    'staff OTP request and verify routes exist');
check_staff_auth(str_contains($reset,"SELECT id FROM users WHERE email=?")
    && str_contains($reset,'Only unambiguous applicant identities use email links.'),
    'ambiguous cross-table recovery emails are refused');
check_staff_auth(str_contains($otp,"SELECT id FROM user_client WHERE email=? LIMIT 1"),
    'staff recovery also rejects client/staff mailbox conflicts');
check_staff_auth(str_contains($otp,'random_int(0,999999)')
    && str_contains($otp,"password_hash(")
    && str_contains($otp,"'username:'.$code")
    && str_contains($otp,'password_verify($code,'),
    'OTP uses cryptographically secure randomness and purpose-separated password hashes');
check_staff_auth(str_contains($otp,'INTERVAL 10 MINUTE')
    && str_contains($otp,"failed_attempts']>=5"),
    'OTP expires after ten minutes and locks after five attempts');
check_staff_auth(str_contains($otp,'status=\'active\''),
    'only active staff receive or redeem OTP');
check_staff_auth(str_contains($otp,'UPDATE users SET password_hash=')
    && str_contains($otp,'used_at=NOW()'),
    'OTP is consumed with the password update');
check_staff_auth(str_contains($sql,'CREATE TABLE staff_password_reset_otps')
    && str_contains($sql,"username=CONCAT(")
    && str_contains($sql,"username IS NULL OR TRIM(username)=''"),
    'migration backfills only missing usernames and creates OTP table');
check_staff_auth(!preg_match('/UPDATE users[^;]*SET[^;]*password_hash/s',$sql),
    'migration leaves existing password hashes unchanged');
check_staff_auth(str_contains($mgmt,'staff_username_taken')
    && str_contains($mgmtView,'staff_username_backfill')
    && str_contains($mgmt,'UPDATE users SET name=?,username=?'),
    'User Management supports unique username edits and backfill');
check_staff_auth(str_contains($mgmtView,'<th>Username</th>')
    && str_contains($mgmtView,'data-user-username='),
    'username appears in User Management list and staff modal data');
check_staff_auth(str_contains($setupJs,"setField('username', button.dataset.userUsername)")
    && str_contains($editJs,"setProfileField('username', button.dataset.userUsername)"),
    'setup/edit modals prefill assigned username');
check_staff_auth(str_contains($mgmtView,'Recovery Email / OTP'),
    'email is clearly separated from login');
echo "PASS: staff usernames, applicant isolation, OTP security and UI contracts.\n";
