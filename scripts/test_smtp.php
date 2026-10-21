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

$enabled = strtolower(env_value('MAIL_ENABLED', 'false')) === 'true';
$host = trim(env_value('MAIL_HOST'));
$username = trim(env_value('MAIL_USERNAME'));
$password = env_value('MAIL_PASSWORD');

if (!$enabled) {
    fwrite(STDERR, "SMTP is disabled. Run: php scripts/configure_smtp.php\n");
    exit(1);
}
if ($host === '' || str_contains($host, 'example')) {
    fwrite(STDERR, "MAIL_HOST is not configured. Run: php scripts/configure_smtp.php\n");
    exit(1);
}
if ($username === '' || str_contains($username, 'your-gmail-account')) {
    fwrite(STDERR, "MAIL_USERNAME is not configured. Run: php scripts/configure_smtp.php\n");
    exit(1);
}
if ($password === '' || str_contains($password, 'your-gmail-app-password')) {
    fwrite(STDERR, "MAIL_PASSWORD is not configured. Generate a NEW Gmail App Password, then run: php scripts/configure_smtp.php\n");
    exit(1);
}

fwrite(STDOUT, "SMTP: {$host}:" . env_value('MAIL_PORT', '587') . " (" . env_value('MAIL_ENCRYPTION', 'tls') . ")\n");
fwrite(STDOUT, "Sender: {$username}\n");

$to = strtolower(smtp_test_ask('Send SMTP test email to'));
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid recipient email.\n");
    exit(1);
}

$html = '<!doctype html><html><body style="font-family:Arial,sans-serif">'
    . '<h2 style="color:#08783f">DENR FP-OMS SMTP Test</h2>'
    . '<p>Your FP-OMS PHPMailer + Gmail SMTP configuration successfully delivered this test message.</p>'
    . '<p style="font-size:12px;color:#666">Sent at ' . htmlspecialchars(date('Y-m-d h:i:s A T'), ENT_QUOTES, 'UTF-8') . '</p>'
    . '</body></html>';

if (!smtp_send_html($to, 'DENR FP-OMS SMTP Test', $html)) {
    fwrite(STDERR, "SMTP test failed. Check the PHP/Apache error log for 'FP-OMS PHPMailer delivery failure'.\n");
    fwrite(STDERR, "For temporary diagnostics set MAIL_DEBUG=true in .env, retry once, then set it back to false.\n");
    exit(1);
}

fwrite(STDOUT, "SMTP test sent successfully to {$to}. Check Inbox and Spam.\n");
