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

/** Notify each eligible LPDD-FUS reviewer after verified registration is committed. */
function send_lpdd_fus_registration_alerts(int $registrationId, string $applicantName, string $companyName): void
{
    $reviewers = query_all(
        "SELECT DISTINCT u.id,u.email FROM users u
         JOIN user_roles ur ON ur.user_id=u.id
         JOIN roles r ON r.id=ur.role_id
         WHERE r.code='lpdd_fus' AND u.status <> 'suspended'"
    );
    $subject = 'New Applicant Registration – Pending Validation | DENR CARAGA FP-OMS';
    // Use only an explicitly configured public HTTPS origin; never email localhost links.
    $base = rtrim(trim(env_value('APP_URL')), '/');
    $parts = parse_url($base);
    $host = strtolower((string)($parts['host'] ?? ''));
    $publicUrl = ($parts !== false && ($parts['scheme'] ?? '') === 'https'
        && $host !== '' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);
    // Domain names are accepted; private IP addresses are not public deployment URLs.
    $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
    $publicUrl = ($parts !== false && ($parts['scheme'] ?? '') === 'https'
        && $host !== '' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        && (!$isIp || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false)
        && empty($parts['user']) && empty($parts['pass']) && empty($parts['query']) && empty($parts['fragment']));
    $loginUrl = $publicUrl ? $base . '/login?next=review-queue' : null;
    $safeLoginUrl = $loginUrl === null ? '' : htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeApplicant = htmlspecialchars($applicantName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCompany = htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = '<p>A new verified applicant registration is awaiting DENR CARAGA LPDD-FUS review.</p>'
        . '<p><strong>Applicant:</strong> ' . $safeApplicant . '<br><strong>Company:</strong> '
        . $safeCompany . '<br><strong>Registration ID:</strong> ' . $registrationId . '</p>'
        . '<p>Sign in to DENR CARAGA FP-OMS and open the Applicant Review Queue to validate the registration.</p>'
        . ($loginUrl !== null
            ? '<p style="margin:24px 0"><a href="' . $safeLoginUrl . '" style="background:#08783f;color:#ffffff;padding:13px 22px;text-decoration:none;border-radius:6px;font-weight:bold;display:inline-block">Login to FP-OMS – Review Application</a></p>'
                . '<p>If the button does not work, open: <a href="' . $safeLoginUrl . '">' . $safeLoginUrl . '</a></p>'
            : '<p>Open your organization-approved FP-OMS website and sign in to access the Applicant Review Queue.</p>')
        . '<p>This notification does not indicate approval or permit issuance. '
        . 'Review supporting documents only through the authenticated system.</p>';
    $plain = "New verified registration awaiting LPDD-FUS review.\nApplicant: {$applicantName}\n"
        . "Company: {$companyName}\nRegistration ID: {$registrationId}\n"
        . ($loginUrl !== null ? "Login to review: {$loginUrl}" : "Sign in through your organization-approved FP-OMS website and open the Applicant Review Queue.");
    foreach ($reviewers as $reviewer) {
        $sent = false;
        try {
            $email = trim((string)($reviewer['email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $sent = smtp_send_html($email, $subject,
                    registration_email_layout('New Applicant Registration – Pending Validation',
                        'LPDD-FUS Reviewing Personnel', $body), $plain);
            }
        } catch (Throwable $error) {
            error_log('FP-OMS LPDD-FUS email notification failure for reviewer ID '
                . (int)$reviewer['id'] . ': ' . $error->getMessage());
        }
        try {
            audit_client(null, 'registration.reviewer_email_notification', 'registration',
                $registrationId, ['reviewer_id'=>(int)$reviewer['id'],
                    'delivery'=>$sent ? 'smtp' : 'not_sent']);
        } catch (Throwable $error) {
            error_log('FP-OMS reviewer notification audit failure: ' . $error->getMessage());
        }
    }
}
