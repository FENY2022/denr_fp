<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

const FPOMS_ROOT = __DIR__ . '/..';

function prompt_value(string $label, string $default = ''): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    fwrite(STDOUT, $label . $suffix . ': ');
    $value = trim((string)fgets(STDIN));
    return $value !== '' ? $value : $default;
}

function prompt_secret(string $label): string
{
    fwrite(STDOUT, $label . ': ');
    $masked = DIRECTORY_SEPARATOR === '/' && is_executable('/usr/bin/stty');

    if ($masked) {
        @shell_exec('stty -echo');
    }

    $value = trim((string)fgets(STDIN));

    if ($masked) {
        @shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }

    return $value;
}

function env_quote(string $value): string
{
    if ($value === '' || preg_match('/\s|["#=]/', $value)) {
        return '"' . addcslashes($value, "\\\"") . '"';
    }
    return $value;
}

function update_env_file(string $path, array $values): void
{
    $content = is_file($path) ? (string)file_get_contents($path) : '';
    if ($content !== '' && !str_ends_with($content, "\n")) {
        $content .= "\n";
    }

    foreach ($values as $key => $value) {
        $line = $key . '=' . env_quote((string)$value);
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $line, $content, 1) ?? $content;
        } else {
            $content .= $line . "\n";
        }
    }

    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write .env. Check file/folder permissions.');
    }
}

try {
    $envPath = FPOMS_ROOT . '/.env';

    fwrite(STDOUT, "DENR FP-OMS Gmail SMTP Setup\n");
    fwrite(STDOUT, "The Gmail App Password will be written only to the local .env, which is ignored by Git.\n\n");

    $username = strtolower(prompt_value('Gmail SMTP account', 'feasibilitypermit@gmail.com'));
    if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The Gmail SMTP account is not a valid email address.');
    }

    $appPassword = preg_replace('/\s+/', '', prompt_secret('NEW Gmail App Password (16 characters)'));
    if (!is_string($appPassword) || strlen($appPassword) !== 16 || !preg_match('/^[A-Za-z0-9]+$/', $appPassword)) {
        throw new RuntimeException('Gmail App Password must contain exactly 16 letters/numbers after spaces are removed.');
    }

    $appUrl = prompt_value('FP-OMS APP_URL', 'http://localhost/denr_fp/denr_fp/public');

    update_env_file($envPath, [
        'APP_URL' => $appUrl,
        'MAIL_ENABLED' => 'true',
        'MAIL_HOST' => 'smtp.gmail.com',
        'MAIL_PORT' => '587',
        'MAIL_ENCRYPTION' => 'tls',
        'MAIL_USERNAME' => $username,
        'MAIL_PASSWORD' => $appPassword,
        'MAIL_FROM_NAME' => 'DENR FP-OMS',
        'MAIL_FROM_ADDRESS' => $username,
        'MAIL_VERIFY_PEER' => 'true',
        'MAIL_TIMEOUT' => '20',
        'MAIL_DEBUG' => 'false',
    ]);

    fwrite(STDOUT, "\nSMTP configuration saved to .env.\n");
    fwrite(STDOUT, "Gmail account: {$username}\n");
    fwrite(STDOUT, "App Password: [saved securely; not displayed]\n");
    fwrite(STDOUT, "Next: php scripts/test_smtp.php\n");
} catch (Throwable $e) {
    fwrite(STDERR, "SMTP setup failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
