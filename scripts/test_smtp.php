<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../app/bootstrap.php';

function smtp_test_ask(string $label): string
{
    fwrite(STDOUT, $label . ': ');
    return trim((string)fgets(STDIN));
}

$to = strtolower(smtp_test_ask('Send SMTP test email to'));
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid recipient email.\n");
    exit(1);
}

$html = '<!doctype html><html><body style="font-family:Arial,sans-serif">'
    . '<h2 style="color:#08783f">DENR FP-OMS SMTP Test</h2>'
    . '<p>Your FP-OMS SMTP configuration successfully delivered this test message.</p>'
    . '<p style="font-size:12px;color:#666">Sent at ' . htmlspecialchars(date('Y-m-d h:i:s A T'), ENT_QUOTES, 'UTF-8') . '</p>'
    . '</body></html>';

if (!smtp_send_html($to, 'DENR FP-OMS SMTP Test', $html)) {
    fwrite(STDERR, "SMTP test failed. Check the PHP/Apache error log for a non-secret diagnostic message and verify MAIL_* values in .env.\n");
    exit(1);
}

fwrite(STDOUT, "SMTP test sent successfully to {$to}.\n");
