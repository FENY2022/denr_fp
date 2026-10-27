<?php
declare(strict_types=1);

function view_verify_registration_otp(array $errors = [], array $notices = []): void
{
    if (current_user()) go('dashboard');
    $client = registration_otp_pending_client();
    if (!$client) {
        flash('warning','Sign in with your registration email to resend the verification link.');
        go('login');
    }
    $email = (string)$client['email'];
    [$local,$domain] = array_pad(explode('@',$email,2),2,'');
    $mask = substr($local,0,2) . str_repeat('*',max(2,min(12,strlen($local)-2))) . '@' . $domain;
    $html = '<div class="row justify-content-center"><div class="col-md-8 col-lg-6"><div class="panel">'
        . '<h1 class="h4">Confirm Your Email Address</h1>' . errors_html($errors);
    foreach ($notices as $notice) $html .= '<div class="alert alert-info">' . e((string)$notice) . '</div>';
    if (!empty($_SESSION['registration_otp_delivery_failed']))
        $html .= '<div class="alert alert-warning">Email delivery could not be confirmed. Check the configured APP_URL and SMTP settings, then resend after 60 seconds.</div>';
    $html .= '<p>We sent a secure email verification link to <strong>' . e($mask)
        . '</strong>. Open the email and click <strong>Verify My Email Address</strong>. No OTP is required.</p>'
        . '<p class="text-secondary small">The link expires after 30 minutes and works once. FUU review begins after confirmation.</p>'
        . '<form method="post" action="' . url('verify_registration_otp') . '">' . csrf_field()
        . '<input type="hidden" name="otp_action" value="resend">'
        . '<button class="btn btn-outline-success w-100" type="submit">Resend Verification Email</button></form>'
        . '<p class="small text-secondary mt-3">Resend after 60 seconds; maximum 5 verification emails per hour.</p>'
        . '</div></div></div>';
    render_page('Confirm Registration Email',$html);
}
