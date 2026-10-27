<?php
declare(strict_types=1);

/**
 * Transactional FP-OMS registration messages.
 * All email delivery uses the existing PHPMailer SMTP configuration.
 */
function registration_email_layout(string $heading, string $applicantName, string $body): string
{
    $safeName = htmlspecialchars($applicantName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"></head>'
        . '<body style="margin:0;padding:0;background:#f4f7f5;font-family:Arial,sans-serif;color:#17352a">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f5;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #dbe7e0;border-radius:14px;overflow:hidden">'
        . '<tr><td style="background:#08783f;color:#ffffff;padding:24px 28px">'
        . '<strong style="font-size:20px">DENR CARAGA · FP-OMS</strong>'
        . '<div style="font-size:13px;margin-top:6px">Department of Environment and Natural Resources · Regional Office XIII</div></td></tr>'
        . '<tr><td style="padding:28px">'
        . '<h1 style="font-size:21px;color:#075c32;margin:0 0 20px">' . $safeHeading . '</h1>'
        . '<p style="line-height:1.65;margin:0 0 14px"><strong>Greetings from the Department of Environment and Natural Resources!</strong></p>'
        . '<p style="line-height:1.65;margin:0 0 16px">Dear ' . $safeName . ',</p>'
        . $body
        . '<p style="line-height:1.65;margin:22px 0 2px">Best regards,</p>'
        . '<p style="line-height:1.65;margin:0"><strong>DENR CARAGA – Regional Office XIII</strong><br>'
        . 'Feasibility Permit Online Management System (FP-OMS)<br>'
        . 'Department of Environment and Natural Resources</p>'
        . '</td></tr><tr><td style="padding:16px 28px;background:#f0f6f2;color:#64766d;font-size:12px">'
        . 'This is a system-generated email. Please do not reply directly to this message.'
        . '</td></tr></table></td></tr></table></body></html>';
}

/**
 * First registration email only: temporary code, NEVER the acknowledgment.
 * Do not include document names, uploads, passwords or account secrets.
 */
function send_registration_otp_email(string $email, string $name, string $code): bool
{
    if (!preg_match('/^[0-9]{6}$/D', $code)) return false;
    $subject = 'Verify Your Registration OTP – DENR CARAGA FP-OMS';
    $safe = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = '<p style="line-height:1.65">Please verify the email address used for your '
        . '<strong>DENR CARAGA FP-OMS</strong> registration.</p>'
        . '<p style="margin:20px 0;padding:16px;text-align:center;letter-spacing:8px;'
        . 'font-size:32px;font-weight:bold;color:#075c32;background:#edf7ef;border-radius:9px">'
        . $safe . '</p>'
        . '<p style="line-height:1.65">Enter this 6-digit OTP on the verification page within '
        . '<strong>10 minutes</strong>. It can be used only once. '
        . 'Do not share your OTP with anyone, including DENR personnel.</p>'
        . '<p style="line-height:1.65">Your registration will be forwarded for '
        . '<strong>Forest Utilization Section (FUU) validation only after email verification</strong>. '
        . 'If you did not register, you may disregard this email.</p>';
    $plain = "DENR CARAGA FP-OMS Email Verification\n\n"
        . "Dear {$name},\n\nYour registration OTP is: {$code}\n"
        . "This code expires in 10 minutes and may be used once. "
        . "Do not share your OTP. Your registration is not yet under Forest Utilization Section (FUU) review.\n\n"
        . "If you did not register, disregard this email.";
    return smtp_send_html(
        $email, $subject,
        registration_email_layout('Verify Your Registration Email', $name, $body),
        $plain
    );
}

/** Send a single-use email confirmation link instead of a six-digit OTP. */
function send_registration_verification_link_email(string $email, string $name, string $link): bool
{
    $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $subject = 'Confirm Your Email Address – DENR CARAGA FP-OMS';
    $body = '<p>Thank you for registering with DENR CARAGA FP-OMS. Please confirm your email address to submit your registration for Forest Utilization Section (FUU) validation.</p>'
        . '<p style="margin:24px 0"><a href="' . $safeLink . '" style="background:#08783f;color:#fff;padding:14px 24px;text-decoration:none;border-radius:6px;font-weight:bold;display:inline-block">Verify My Email Address</a></p>'
        . '<p>If the button does not work, copy this link into your browser:<br><a href="' . $safeLink . '">' . $safeLink . '</a></p>'
        . '<p>This one-time link expires in <strong>30 minutes</strong>. If you did not register, ignore this message.</p>';
    $plain = "Confirm your DENR CARAGA FP-OMS registration email using this one-time link (expires in 30 minutes):\n{$link}\n\nIf you did not register, ignore this message.";
    return smtp_send_html($email,$subject,registration_email_layout('Confirm Your Email Address',$name,$body),$plain);
}

/** Send only AFTER successful OTP verification for a committed registration. Never include passwords or ID attachments. */
function send_registration_acknowledgment_email(string $email, string $name): bool
{
    $subject = 'Acknowledgment of Registration – DENR CARAGA FP-OMS';
    $body = '<p style="line-height:1.65;margin:0 0 15px">This is to acknowledge that we have successfully received your applicant account registration submitted through the <strong>DENR CARAGA Feasibility Permit Online Management System (FP-OMS)</strong>.</p>'
        . '<p style="line-height:1.65;margin:0 0 15px">Please be informed that your registration is currently <strong>PENDING VALIDATION</strong> by the concerned Forest Utilization Section (FUU), through the assigned LPDD-FUS reviewing personnel. Your submitted information and supporting documents will be checked for completeness, accuracy, and compliance with applicable registration requirements.</p>'
        . '<p style="line-height:1.65;margin:0 0 15px">Once validation is completed, you will receive another email regarding your account status. If additional information, corrections, or supporting documents are required, you will be notified accordingly.</p>'
        . '<p style="line-height:1.65;margin:0 0 15px"><strong>Successful registration does not automatically constitute account approval or issuance of a Feasibility Permit.</strong> You may sign in to FP-OMS to monitor your registration status.</p>'
        . '<p style="line-height:1.65;margin:0 0 15px">Thank you for using DENR CARAGA FP-OMS. We appreciate your cooperation and commitment to responsible environmental management.</p>';
    $plain = "Greetings from the Department of Environment and Natural Resources!\n\n"
        . "Dear {$name},\n\n"
        . "This is to acknowledge that we have successfully received your applicant account registration submitted through the DENR CARAGA Feasibility Permit Online Management System (FP-OMS).\n\n"
        . "Your registration is PENDING VALIDATION by the concerned Forest Utilization Section (FUU), through the assigned LPDD-FUS reviewing personnel. Submitted information and supporting documents will be checked for completeness, accuracy, and applicable requirements.\n\n"
        . "You will receive another email after validation. If corrections or further documents are required, you will be notified.\n\n"
        . "Successful registration is not account approval or issuance of a Feasibility Permit. Sign in to FP-OMS to monitor your status.\n\n"
        . "Thank you for using DENR CARAGA FP-OMS.\n\n"
        . "Best regards,\nDENR CARAGA – Regional Office XIII\nFeasibility Permit Online Management System (FP-OMS)\n\n"
        . "This is a system-generated email. Please do not reply directly to this message.";
    return smtp_send_html($email, $subject, registration_email_layout('Registration Received – Pending Validation', $name, $body), $plain);
}

/** Send only after an LPDD-FUS decision has been committed. */
function send_registration_decision_email(string $email, string $name, string $decision, string $remarks): bool
{
    if (!in_array($decision, ['approved', 'needs_correction'], true)) {
        return false;
    }

    if ($decision === 'approved') {
        $subject = 'Account Registration Approved – DENR CARAGA FP-OMS';
        $heading = 'Registration Approved';
        $body = '<p style="line-height:1.65;margin:0 0 15px">Your applicant account registration has been reviewed and <strong>APPROVED</strong>. Your DENR CARAGA FP-OMS account is now active.</p>'
            . '<p style="line-height:1.65;margin:0 0 15px">You may sign in to FP-OMS to view your registration status. Account approval is <strong>not</strong> the issuance or approval of a Feasibility Permit.</p>'
            . '<p style="line-height:1.65;margin:0 0 15px">Thank you for your cooperation.</p>';
        $plain = "Your DENR CARAGA FP-OMS applicant account registration has been APPROVED and your account is now active.\n"
            . "You may sign in to FP-OMS to view your registration status. Account approval does not mean a Feasibility Permit has been issued or approved.";
    } else {
        $subject = 'Action Required: Registration Corrections – DENR CARAGA FP-OMS';
        $heading = 'Registration Corrections Required';
        $safeRemarks = nl2br(htmlspecialchars($remarks, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $body = '<p style="line-height:1.65;margin:0 0 15px">The concerned DENR reviewing personnel have returned your registration for <strong>CORRECTIONS</strong>.</p>'
            . '<p style="line-height:1.65;margin:0 0 10px">Please review the remarks below and sign in to FP-OMS to update and resubmit your registration:</p>'
            . '<div style="background:#fff8e7;border-left:4px solid #c68a20;padding:14px 16px;margin:0 0 16px;line-height:1.6;white-space:normal">' . $safeRemarks . '</div>'
            . '<p style="line-height:1.65;margin:0 0 15px">Your account will remain unapproved until the required corrections have been submitted and reviewed.</p>';
        $plain = "Your DENR CARAGA FP-OMS registration requires CORRECTIONS.\n\n"
            . "Reviewing personnel remarks:\n{$remarks}\n\n"
            . "Please sign in to FP-OMS, update the requested information, and resubmit your registration for review.";
    }

    $plain = "Greetings from the Department of Environment and Natural Resources!\n\nDear {$name},\n\n"
        . $plain . "\n\nBest regards,\nDENR CARAGA – Regional Office XIII\n"
        . "Feasibility Permit Online Management System (FP-OMS)\n\n"
        . "This is a system-generated email. Please do not reply directly to this message.";
    return smtp_send_html($email, $subject, registration_email_layout($heading, $name, $body), $plain);
}


/** Expiring HMAC-signed reviewer login hint; never authenticates or grants access. */
function reviewer_login_hint_create(int $userId): ?string
{
    $key = env_value('FPOMS_REVIEW_LINK_KEY');
    if (strlen($key) < 32) return null;
    $payload = $userId . '.' . (time() + 86400);
    return $payload . '.' . hash_hmac('sha256', $payload, $key);
}

function reviewer_login_hint_email(string $hint): ?string
{
    $key = env_value('FPOMS_REVIEW_LINK_KEY');
    if (strlen($key) < 32 || !preg_match('/^([1-9][0-9]*)\.([0-9]{10})\.([a-f0-9]{64})$/D', $hint, $matches)) return null;
    if ((int)$matches[2] < time() || (int)$matches[2] > time() + 86400) return null;
    $payload = $matches[1] . '.' . $matches[2];
    if (!hash_equals(hash_hmac('sha256', $payload, $key), $matches[3])) return null;
    $user = query_one("SELECT u.email FROM users u JOIN user_roles ur ON ur.user_id=u.id
        JOIN roles r ON r.id=ur.role_id
        WHERE u.id=? AND u.user_kind='internal' AND u.status='active' AND r.code='lpdd_fus'
        LIMIT 1", [(int)$matches[1]]);
    return $user && filter_var($user['email'], FILTER_VALIDATE_EMAIL) ? (string)$user['email'] : null;
}

/** Notify each eligible LPDD-FUS reviewer after verified registration is committed. */
function send_lpdd_fus_registration_alerts(int $registrationId, string $applicantName, string $companyName): void
{
    // Individual reviewer login emails are available only for activated LPDD/FUS users.
    $reviewers = query_all(
        "SELECT DISTINCT u.id, u.email FROM users u
         JOIN user_roles ur ON ur.user_id=u.id
         JOIN roles r ON r.id=ur.role_id
         WHERE r.code='lpdd_fus' AND u.user_kind='internal' AND u.status='active'"
    );

    // The office contact address is not a user login. Notify it independently even
    // while its LPDD/FUS staff accounts are pending setup. Office assignment is
    // recognized as well as role membership because imported users may not yet
    // have completed role provisioning.
    $officeContacts = query_all(
        "SELECT DISTINCT u.reference_email
         FROM users u
         WHERE u.user_kind='internal'
           AND u.status IN ('active','pending_setup')
           AND u.reference_email IS NOT NULL
           AND TRIM(u.reference_email) <> ''
           AND (
               UPPER(TRIM(u.office_station))='LPDD/FUS'
               OR EXISTS (
                   SELECT 1 FROM user_roles ur
                   JOIN roles r ON r.id=ur.role_id
                   WHERE ur.user_id=u.id AND r.code='lpdd_fus'
               )
           )"
    );

    // Key recipients by normalized address. Sending office mail first prevents
    // duplicate deliveries and avoids attaching an individual's signed login
    // hint to a mailbox shared by multiple staff.
    $recipients = [];
    foreach ($officeContacts as $office) {
        $email = strtolower(trim((string)($office['reference_email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[$email] = ['email' => $email, 'scope' => 'office'];
        }
    }
    foreach ($reviewers as $reviewer) {
        $email = strtolower(trim((string)($reviewer['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($recipients[$email])) {
            $recipients[$email] = [
                'email' => $email, 'scope' => 'reviewer',
                'reviewer_id' => (int)$reviewer['id']
            ];
        }
    }
    if (!$recipients) {
        error_log('FP-OMS LPDD/FUS email alert skipped: no valid active reviewer or office contact addresses.');
        return;
    }

    $subject = 'New Applicant Registration – Pending Validation | DENR CARAGA FP-OMS';
    // Build the reviewer link from the configured URL, never from HTTP Host
    // headers. Permit HTTP loopback only for local XAMPP testing; all other
    // deployments must use HTTPS to protect reviewer passwords in transit.
    $base = rtrim(trim(env_value('APP_URL')), '/');
    $parts = parse_url($base);
    $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    $validBase = ($parts !== false && filter_var($base, FILTER_VALIDATE_URL) !== false
        && $host !== '' && empty($parts['user']) && empty($parts['pass'])
        && empty($parts['query']) && empty($parts['fragment']));
    $usableUrl = $validBase && ($scheme === 'https' || ($scheme === 'http' && $isLoopback));
    $baseLoginUrl = $usableUrl ? $base . '/login?next=review-queue' : null;
    if ($baseLoginUrl === null) {
        error_log('FP-OMS LPDD/FUS login link unavailable: configure APP_URL to the accessible HTTPS system address (or HTTP localhost for local tests).');
    }

    $safeApplicant = htmlspecialchars($applicantName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCompany = htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = '<p>A new verified applicant registration is awaiting DENR CARAGA LPDD/FUS review.</p>'
        . '<p><strong>Applicant:</strong> ' . $safeApplicant . '<br><strong>Company:</strong> '
        . $safeCompany . '<br><strong>Registration ID:</strong> ' . $registrationId . '</p>'
        . '<p>Sign in to DENR CARAGA FP-OMS and open the Applicant Review Queue to validate the registration.</p>'
        . '<p>This notification does not indicate approval or permit issuance. '
        . 'Review supporting documents only through the authenticated system.</p>';

    foreach ($recipients as $recipient) {
        $sent = false;
        $scope = $recipient['scope'];
        try {
            // Signed email prefill is strictly for a unique active reviewer;
            // a shared LPDD/FUS office mailbox receives a generic login link.
            $hint = $scope === 'reviewer'
                ? reviewer_login_hint_create((int)$recipient['reviewer_id']) : null;
            $loginUrl = $baseLoginUrl === null ? null : $baseLoginUrl
                . ($hint !== null ? '&hint=' . rawurlencode($hint) : '');
            $safeUrl = $loginUrl === null ? '' : htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $button = $loginUrl !== null
                ? '<p style="margin:24px 0"><a href="' . $safeUrl . '" style="background:#08783f;color:#fff;padding:13px 22px;text-decoration:none;border-radius:6px;font-weight:bold;display:inline-block">Login to FP-OMS – Review Application</a></p>'
                    . '<p>If the button does not work, copy and open this link:<br><a href="' . $safeUrl . '">' . $safeUrl . '</a></p>'
                    . ($isLoopback
                        ? '<p style="color:#9a6700;font-size:13px"><strong>Local testing:</strong> This link works only on the computer running FP-OMS. For reviewers on other devices, configure an accessible HTTPS APP_URL.</p>'
                        : '')
                : '<p>FP-OMS login link is not configured. Please ask the ICT administrator to set the accessible HTTPS APP_URL.</p>';
            $plain = "New verified registration awaiting LPDD/FUS review.\n"
                . "Applicant: {$applicantName}\nCompany: {$companyName}\nRegistration ID: {$registrationId}\n"
                . ($loginUrl !== null ? "Login to review: {$loginUrl}"
                    : "Sign in through your organization-approved FP-OMS website and open the Applicant Review Queue.");
            $sent = smtp_send_html(
                $recipient['email'], $subject,
                registration_email_layout('New Applicant Registration – Pending Validation',
                    'LPDD/FUS Reviewing Personnel', $body . $button),
                $plain
            );
        } catch (Throwable $error) {
            error_log('FP-OMS LPDD/FUS email alert failed (' . $scope . '): ' . $error->getMessage());
        }
        try {
            $auditData = ['delivery' => $sent ? 'smtp' : 'not_sent', 'channel' => $scope];
            if ($scope === 'reviewer') $auditData['reviewer_id'] = (int)$recipient['reviewer_id'];
            audit_client(
                null,
                $scope === 'office' ? 'registration.office_email_notification' : 'registration.reviewer_email_notification',
                'registration', $registrationId, $auditData
            );
        } catch (Throwable $error) {
            error_log('FP-OMS LPDD/FUS email notification audit failure: ' . $error->getMessage());
        }
    }
}
