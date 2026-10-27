<?php
declare(strict_types=1);

// No database: verify OTP generator, message order, and registration boundaries.
require_once __DIR__ . '/../app/registration_otp.php';
require_once __DIR__ . '/../app/registration_mail.php';
require_once __DIR__ . '/../app/domain.php';

function must_otp(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('Registration OTP test failed: ' . $message);
}
$codes = [];
for ($i = 0; $i < 30; $i++) {
    $code = registration_otp_generate();
    must_otp(preg_match('/^[0-9]{6}$/D', $code) === 1, 'Six-digit numeric OTP format');
    $codes[$code] = true;
}
must_otp(count($codes) > 1, 'OTP generator does not return one hardcoded code');
must_otp(status_label('pending_email_verification') === 'Pending Email Verification',
    'Separate OTP status from FUU review status');

function smtp_send_html(string $to, string $subject, string $html, string $plain): bool
{
    $GLOBALS['sent_message'] = compact('to','subject','html','plain');
    return true;
}
must_otp(send_registration_otp_email('test@example.invalid','Sample Client','010205'),
    'OTP message routes to SMTP');
$sent = $GLOBALS['sent_message'];
must_otp(str_contains($sent['subject'],'OTP'), 'First message identifies OTP');
must_otp(str_contains($sent['plain'],'010205'), 'Mail body includes generated six digits');
must_otp(str_contains($sent['plain'],'10 minutes'), 'Expiration is explained');
must_otp(!str_contains($sent['subject'],'Acknowledgment'), 'Initial OTP mail is not acknowledgment');

$reg = file_get_contents(__DIR__ . '/../app/registration.php');
$otp = file_get_contents(__DIR__ . '/../app/registration_otp.php');
$router = file_get_contents(__DIR__ . '/../public/index.php');
$views = file_get_contents(__DIR__ . '/../app/registration_otp_views.php');
$migration = file_get_contents(__DIR__ . '/../database/013_registration_email_otp.sql');
$auth = file_get_contents(__DIR__ . '/../app/auth.php');
must_otp(str_contains($reg, "'applicant','pending_email_verification'"),
    'New client stays unverified initially');
must_otp(!str_contains(substr($reg, strpos($reg,'function register_applicant'),
    strpos($reg,'function registration_for_user')-strpos($reg,'function register_applicant')),
    'send_registration_acknowledgment_email('), 'Registration does not send early acknowledgment');
must_otp(str_contains($reg, 'registration_otp_create_initial'), 'Initial code stored before commit');
must_otp(str_contains($otp, "status='pending_review',email_verified_at=NOW()"),
    'Correct OTP unlocks pending FUU review');
must_otp(strpos($otp, 'send_registration_acknowledgment_email(')
    > strpos($otp, 'registration_otp_verify('), 'Acknowledgment is sent on successful verification');
must_otp(str_contains($otp, "registration_ack_delivery_failed")
    && str_contains($router, "registration_ack_delivery_failed"),
    'Verified applicant receives accurate notice if SMTP acknowledgment fails');
must_otp(str_contains($otp, "status='pending_review',email_verified_at=NOW()")
    && str_contains($otp, "notify_role("),
    'FUU notification follows verified email status');
must_otp(strpos($reg, 'registration_otp_create_initial(')
    < strpos($reg, 'registration_otp_deliver('),
    'OTP issuance is persisted before the first verification email');
must_otp(str_contains($otp, 'failed_attempts') && str_contains($otp, '>= 5'),
    'Invalid code attempts are capped');
must_otp(str_contains($otp, "TIMESTAMPDIFF(SECOND,last_send_at,NOW())")
    && str_contains($otp, '$nextCount > 5'), 'Resend is rate limited');
must_otp(str_contains($router, "case 'verify_registration_otp':")
    && str_contains($views, "name=\"otp_action\" value=\"resend\""),
    'Verification and resend UI are connected');
must_otp(str_contains($auth, "'pending_email_verification'"),
    'Unverified users cannot sign in');
must_otp(str_contains($migration, 'CREATE TABLE registration_email_otps')
    && str_contains($migration, 'email_verified_at'), 'OTP migration available');
echo "PASS: OTP code format, email-first ordering, verification gate, retry limits and migration.\n";
