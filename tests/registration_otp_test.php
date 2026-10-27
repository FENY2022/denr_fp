<?php
declare(strict_types=1);

// Static and pure function assertions; no production database or SMTP necessary.
require_once __DIR__ . '/../app/registration_otp.php';
require_once __DIR__ . '/../app/registration_mail.php';
require_once __DIR__ . '/../app/domain.php';
require_once __DIR__ . '/../app/views.php';

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
must_otp(count($codes) > 1, 'OTP generator produces random values');
must_otp(status_label('pending_email_verification') === 'Pending Email Verification',
    'Email verification is a separate status from FUU review');

// Stub SMTP so the exact message and send ordering can be checked safely.
function smtp_send_html(string $to, string $subject, string $html, string $plain): bool
{
    $GLOBALS['sent_message'] = compact('to', 'subject', 'html', 'plain');
    return true;
}
must_otp(send_registration_otp_email('test@example.invalid', 'Sample Client', '010205'),
    'OTP email invokes SMTP');
$sent = $GLOBALS['sent_message'];
must_otp(str_contains($sent['subject'], 'OTP'), 'First email is explicitly the OTP');
must_otp(str_contains($sent['plain'], '010205'), 'OTP email contains the code');
must_otp(str_contains($sent['plain'], '10 minutes'), 'OTP expiration is stated');
must_otp(!str_contains($sent['subject'], 'Acknowledgment'),
    'OTP email must not send an acknowledgment');

must_otp(send_registration_acknowledgment_email('test@example.invalid', 'Sample Client'),
    'Acknowledgment email invokes SMTP');
$ack = $GLOBALS['sent_message'];
must_otp($ack['subject'] === 'Acknowledgment of Registration – DENR CARAGA FP-OMS',
    'Acknowledgment email has the requested exact subject');
must_otp(str_contains($ack['plain'], 'PENDING VALIDATION'),
    'Acknowledgment clearly distinguishes registration from FUU approval');

$reg = file_get_contents(__DIR__ . '/../app/registration.php');
$otp = file_get_contents(__DIR__ . '/../app/registration_otp.php');
$router = file_get_contents(__DIR__ . '/../public/index.php');
$views = file_get_contents(__DIR__ . '/../app/registration_otp_views.php');
$signInViews = file_get_contents(__DIR__ . '/../app/views.php');
$modalScript = file_get_contents(__DIR__ . '/../public/assets/otp-verification-success.js');
$migration = file_get_contents(__DIR__ . '/../database/013_registration_email_otp.sql');
$auth = file_get_contents(__DIR__ . '/../app/auth.php');

$regStart = strpos($reg, 'function register_applicant(');
$regEnd = strpos($reg, 'function registration_for_user(');
must_otp($regStart !== false && $regEnd !== false && $regEnd > $regStart,
    'Registration function boundaries are present');
$registerBody = substr($reg, $regStart, $regEnd - $regStart);
must_otp(str_contains($registerBody, "'applicant','pending_email_verification'")
    && str_contains($registerBody, 'registration_otp_create_initial('),
    'New registration saves an unverified client and OTP');
must_otp(!str_contains($registerBody, 'send_registration_acknowledgment_email('),
    'No premature acknowledgment during registration submission');

$verify = strpos($otp, 'function registration_otp_verify(');
$commit = strpos($otp, '$pdo->commit();', strpos($otp, "SET status='pending_review',email_verified_at=NOW()"));
$sendAck = strpos($otp, '$acknowledgmentSent = send_registration_acknowledgment_email(');
$notifyFuu = strpos($otp, "notify_role(", $verify);
must_otp($verify !== false && $commit !== false && $sendAck !== false
    && $notifyFuu !== false && $verify < $commit
    && $commit < $sendAck && $sendAck < $notifyFuu,
    'Acknowledgment is attempted immediately after successful OTP DB commit and BEFORE FUU staff notification');
must_otp(str_contains($otp, "status='pending_review',email_verified_at=NOW()"),
    'Verified clients are queued for FUU review');
must_otp(str_contains($otp, 'registration_ack_delivery_failed')
    && str_contains($otp, "['delivery'=>") && str_contains($otp, "'not_sent'"),
    'SMTP delivery result is reported honestly and audited');
must_otp(str_contains($otp, 'failed_attempts') && str_contains($otp, '>= 5'),
    'OTP invalid attempts are capped');
must_otp(str_contains($otp, 'cooldown_seconds') && str_contains($otp, '$nextCount > 5'),
    'Resend is rate limited');
must_otp(str_contains($auth, "'pending_email_verification'"),
    'Unverified clients cannot sign in');
must_otp(str_contains($migration, 'CREATE TABLE registration_email_otps')
    && str_contains($migration, 'email_verified_at'),
    'OTP schema migration remains present');

must_otp(str_contains($router, "case 'verify_registration_otp':")
    && str_contains($views, 'value="resend"'),
    'OTP verification and resend UI still work');
must_otp(str_contains($router, "registration_verified_modal")
    && !str_contains($router, "flash('success', 'Email verified; registration submitted for FUU validation.')"),
    'OTP verification success opens a modal instead of a success flash');
must_otp(str_contains($signInViews, 'unset($_SESSION[' . "'registration_verified_modal'" . '])')
    && str_contains($signInViews, 'registration_verified_modal_html('),
    'OTP modal state is consumed once on the sign-in page');
$successModal = registration_verified_modal_html(false);
$warningModal = registration_verified_modal_html(true);
must_otp(str_contains($successModal, 'id="otp-verification-success-modal"')
    && str_contains($successModal, 'Email Successfully Verified!')
    && str_contains($successModal, 'Continue to Login'),
    'Verified registration shows the intended success modal');
must_otp(!str_contains($successModal, 'could not be confirmed')
    && str_contains($warningModal, 'could not be confirmed'),
    'Only failed SMTP acknowledgment causes a warning in the modal');
must_otp(str_contains($signInViews, 'src="assets/otp-verification-success.js?v=1"')
    && str_contains($modalScript, 'dialog.show()'),
    'Modal is opened by external strict-CSP-compatible JavaScript');
must_otp(!str_contains($successModal, '<script')
    && !str_contains($successModal, 'style='),
    'Success modal markup contains no inline executable scripts or styles');

echo "PASS: OTP -> immediate acknowledgment -> FUU notification, modal, SMTP warning and migration checks.\n";
