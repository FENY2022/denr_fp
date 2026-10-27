<?php
declare(strict_types=1);

function view_verify_registration_otp(array $errors = [], array $notices = []): void
{
    if (current_user()) go('dashboard');
    $client = registration_otp_pending_client();
    if (!$client) {
        flash('warning', 'Sign in with your registration email to continue verification.');
        go('login');
    }
    $email = (string)$client['email'];
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $mask = substr($local, 0, 2) . str_repeat('*', max(2, min(12, strlen($local) - 2)))
        . '@' . $domain;
    $html = '<div class="row justify-content-center"><div class="col-md-8 col-lg-6">'
        . '<div class="panel"><h1 class="h4">Verify Registration Email</h1>'
        . errors_html($errors);
    foreach ($notices as $notice) {
        $html .= '<div class="alert alert-info">' . e((string)$notice) . '</div>';
    }
    if (!empty($_SESSION['registration_otp_delivery_failed'])) {
        $html .= '<div class="alert alert-warning">OTP delivery was not confirmed. '
            . 'Check SMTP configuration and retry using Resend OTP after 60 seconds.</div>';
    }
    $html .= '<p class="text-secondary">Enter the 6-digit code sent to <strong>'
        . e($mask) . '</strong>. FUU review begins only after verification.</p>'
        . '<form method="post" action="' . url('verify_registration_otp') . '">'
        . csrf_field()
        . '<input type="hidden" name="otp_action" value="verify">'
        . '<label class="form-label" for="registration_otp_code">One-Time Password (OTP)</label>'
        . '<input id="registration_otp_code" class="form-control form-control-lg text-center mb-2" '
        . 'type="text" name="otp_code" inputmode="numeric" autocomplete="one-time-code" '
        . 'pattern="[0-9]{6}" minlength="6" maxlength="6" required placeholder="000000">'
        . '<p class="form-text">Expires in 10 minutes. Maximum 5 incorrect attempts per code.</p>'
        . '<button class="btn btn-primary w-100" type="submit">Verify OTP</button></form>'
        . '<hr><form method="post" action="' . url('verify_registration_otp') . '">'
        . csrf_field()
        . '<input type="hidden" name="otp_action" value="resend">'
        . '<button class="btn btn-outline-success w-100" type="submit">Resend OTP</button></form>'
        . '<p class="small text-secondary mt-3">Resend after 60 seconds; maximum 5 emails '
        . 'per hour. Your acknowledgment email is sent after OTP verification.</p>'
        . '</div></div></div>';
    render_page('Verify Registration OTP', $html);
}
