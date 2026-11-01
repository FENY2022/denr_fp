<?php
declare(strict_types=1);

// DB/SMTP-free checks for username recovery screens and OTP email domain separation.
require_once __DIR__ . '/../app/staff_username.php';
require_once __DIR__ . '/../app/staff_password_otp.php';
require_once __DIR__ . '/../app/password_reset.php';

function check_username_recovery(bool $yes,string $message): void {
    if (!$yes) throw new RuntimeException('Username recovery: '.$message);
}
function e(?string $s): string {
    return htmlspecialchars($s ?? '',ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
}
function url(string $route, array $query=[]): string {
    $route = str_replace('_','-',$route);
    return $route . ($query ? '?' . http_build_query($query) : '');
}
function current_user(): ?array { return null; }
function go(string $route): void { throw new RuntimeException('Unexpected redirect: '.$route); }
function csrf_field(): string { return '<input name="csrf_token" value="test" type="hidden">'; }
function errors_html(array $errors): string { return $errors ? '<div>'.e(implode('; ',$errors)).'</div>' : ''; }
function field(string $name,string $label,string $value,string $type,bool $required,string $attributes=''): string {
    return '<label>'.e($label).'<input name="'.e($name).'" type="'.e($type)
        .'" value="'.e($value).'" '.$attributes.'></label>';
}
function form_value(string $key): string { return ''; }
$GLOBALS['last_render'] = '';
function render_page(string $title,string $html): void {
    $GLOBALS['last_render'] = $html;
}
$GLOBALS['last_email'] = null;
function smtp_send_html(string $recipient,string $subject,string $html): bool {
    $GLOBALS['last_email'] = compact('recipient','subject','html');
    return true;
}

$testUser = ['name'=>'Test <b>Staff</b>','email'=>'staff@example.invalid'];
check_username_recovery(staff_otp_send_email($testUser,'014267','username'), 'username email sent');
check_username_recovery(str_contains($GLOBALS['last_email']['subject'],'Username Recovery OTP'),
    'username-specific subject');
check_username_recovery(str_contains($GLOBALS['last_email']['html'],'014267')
    && !str_contains($GLOBALS['last_email']['html'],'<b>Staff</b>'),
    'OTP email contains code but escapes name');
check_username_recovery(staff_otp_send_email($testUser,'246801','password'), 'password email still works');
check_username_recovery(str_contains($GLOBALS['last_email']['subject'],'Password Recovery OTP'),
    'password-specific subject retained');
check_username_recovery(!staff_otp_send_email($testUser,'123456','other'), 'unknown purpose rejected');

view_forgot_password();
check_username_recovery(str_contains($GLOBALS['last_render'],'Forgot Username')
    && str_contains($GLOBALS['last_render'],'Forgot Password')
    && str_contains($GLOBALS['last_render'],'href="forgot-username"'),
    'password recovery shows both choices');
view_forgot_username();
check_username_recovery(str_contains($GLOBALS['last_render'],'href="forgot-password"')
    && str_contains($GLOBALS['last_render'],'name="email"')
    && str_contains($GLOBALS['last_render'],'action="forgot-username"'),
    'username form takes personal recovery email');
check_username_recovery(!str_contains($GLOBALS['last_render'],'<script'),
    'username form contains no inline scripts');
$_SESSION['staff_username_pending_email'] = 'staff@example.invalid';
view_staff_username_verification();
check_username_recovery(str_contains($GLOBALS['last_render'],'name="otp"')
    && str_contains($GLOBALS['last_render'],'action="verify-username"')
    && str_contains($GLOBALS['last_render'],'[0-9]{6}')
    && !str_contains($GLOBALS['last_render'],'staff_username'),
    'username remains hidden until OTP verification');
$_SESSION['staff_recovered_username'] = 'denrstaff321';
view_staff_username_recovered();
check_username_recovery(str_contains($GLOBALS['last_render'],'denrstaff321')
    && !isset($_SESSION['staff_recovered_username']),
    'verified username shown once then cleared from session');
$_SESSION['staff_recovered_username'] = 'staff<script>';
try {
    view_staff_username_recovered();
    throw new RuntimeException('Invalid username was displayed');
} catch (RuntimeException $ex) {
    check_username_recovery(str_contains($ex->getMessage(),'forgot_username'),
        'untrusted session username never displayed');
}

$script = file_get_contents(__DIR__.'/../public/index.php');
$service = file_get_contents(__DIR__.'/../app/staff_password_otp.php');
$login = file_get_contents(__DIR__.'/../app/views.php');
check_username_recovery(str_contains($script,"case 'forgot_username':")
    && str_contains($script,"case 'verify_username':")
    && str_contains($script,"case 'username_recovered':"),
    'username request, verification and result routes exist');
check_username_recovery(str_contains($script,"staff_otp_request(\$email,'username')"),
    'username requests use purpose-aware OTP');
check_username_recovery(str_contains($service,"'username:'.\$code"),
    'username OTP hash includes a purpose-specific domain separator');
check_username_recovery(str_contains($service,"password_verify('username:'.\$code")
    && str_contains($service,"password_verify(\$code,"),
    'separate username and password OTP verification');
check_username_recovery(str_contains($service,"failed_attempts']>=5")
    && str_contains($service,'used_at=NOW()'),
    'attempt limit and one-time use retained');
check_username_recovery(str_contains($login,'Forgot Username or Password?'),
    'login has combined account recovery link');

echo "PASS: username recovery screens, email OTP separation and one-time display.\n";
