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

/** Send once for a newly committed registration. Never include passwords or ID attachments. */
function send_registration_acknowledgment_email(string $email, string $name): bool
{
    $subject = 'Acknowledgment of Registration – DENR CARAGA FP-OMS';
    $body = '<p style="line-height:1.65;margin:0 0 15px">This is to acknowledge that we have successfully received your applicant account registration submitted through the <strong>DENR CARAGA Feasibility Permit Online Management System (FP-OMS)</strong>.</p>'
        . '<p style="line-height:1.65;margin:0 0 15px">Please be informed that your registration is currently <strong>PENDING VALIDATION</strong> by the concerned Forest Utilization Unit (FUU), through the assigned LPDD-FUS reviewing personnel. Your submitted information and supporting documents will be checked for completeness, accuracy, and compliance with applicable registration requirements.</p>'
        . '<p style="line-height:1.65;margin:0 0 15px">Once validation is completed, you will receive another email regarding your account status. If additional information, corrections, or supporting documents are required, you will be notified accordingly.</p>'
        . '<p style="line-height:1.65;margin:0 0 15px"><strong>Successful registration does not automatically constitute account approval or issuance of a Feasibility Permit.</strong> You may sign in to FP-OMS to monitor your registration status.</p>'
        . '<p style="line-height:1.65;margin:0 0 15px">Thank you for using DENR CARAGA FP-OMS. We appreciate your cooperation and commitment to responsible environmental management.</p>';
    $plain = "Greetings from the Department of Environment and Natural Resources!\n\n"
        . "Dear {$name},\n\n"
        . "This is to acknowledge that we have successfully received your applicant account registration submitted through the DENR CARAGA Feasibility Permit Online Management System (FP-OMS).\n\n"
        . "Your registration is PENDING VALIDATION by the concerned Forest Utilization Unit (FUU), through the assigned LPDD-FUS reviewing personnel. Submitted information and supporting documents will be checked for completeness, accuracy, and applicable requirements.\n\n"
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
            . '<p style="line-height:1.65;margin:0 0 15px">Your account will remain pending until the required corrections have been submitted and reviewed.</p>';
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
