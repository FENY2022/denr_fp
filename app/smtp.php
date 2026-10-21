<?php
declare(strict_types=1);

/**
 * Minimal authenticated SMTP client for FP-OMS.
 * Credentials are read from .env/environment only; never hard-code secrets here.
 */

function smtp_read_response($stream): array
{
    $lines = [];
    $code = 0;

    while (($line = fgets($stream, 8192)) !== false) {
        $lines[] = rtrim($line, "\r\n");
        if (preg_match('/^(\d{3})([ -])/', $line, $matches)) {
            $code = (int)$matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        }
    }

    return [$code, $lines];
}

function smtp_expect($stream, array $expected, string $stage): array
{
    [$code, $lines] = smtp_read_response($stream);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('SMTP ' . $stage . ' failed with response code ' . $code . '.');
    }
    return [$code, $lines];
}

function smtp_command($stream, string $command, array $expected, string $stage): array
{
    if (fwrite($stream, $command . "\r\n") === false) {
        throw new RuntimeException('SMTP ' . $stage . ' write failed.');
    }
    return smtp_expect($stream, $expected, $stage);
}

function smtp_header_text(string $value): string
{
    $value = str_replace(["\r", "\n"], '', trim($value));
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_message_id(string $from): string
{
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $domain = preg_replace('/[^A-Za-z0-9.-]/', '', $domain) ?: 'localhost';
    return '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
}

function smtp_send_html(string $to, string $subject, string $html, string $plain = ''): bool
{
    if (strtolower(env_value('MAIL_ENABLED', 'false')) !== 'true') {
        return false;
    }

    $host = trim(env_value('MAIL_HOST'));
    $port = (int)env_value('MAIL_PORT', '587');
    $encryption = strtolower(trim(env_value('MAIL_ENCRYPTION', 'tls')));
    $username = trim(env_value('MAIL_USERNAME'));
    $password = env_value('MAIL_PASSWORD');
    $from = trim(env_value('MAIL_FROM_ADDRESS'));
    $fromName = trim(env_value('MAIL_FROM_NAME', 'DENR FP-OMS'));
    $timeout = max(5, min(60, (int)env_value('MAIL_TIMEOUT', '15')));

    if ($host === '' || $port < 1 || $port > 65535) {
        error_log('FP-OMS SMTP configuration incomplete: MAIL_HOST/MAIL_PORT.');
        return false;
    }
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        error_log('FP-OMS SMTP configuration invalid: MAIL_ENCRYPTION must be tls, ssl, or none.');
        return false;
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('FP-OMS SMTP configuration/message rejected: invalid sender or recipient address.');
        return false;
    }
    if (($username === '') !== ($password === '')) {
        error_log('FP-OMS SMTP configuration incomplete: MAIL_USERNAME and MAIL_PASSWORD must both be set or both be empty.');
        return false;
    }

    $verifyPeer = strtolower(env_value('MAIL_VERIFY_PEER', 'true')) !== 'false';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => $verifyPeer,
            'verify_peer_name' => $verifyPeer,
            'allow_self_signed' => !$verifyPeer,
            'peer_name' => $host,
        ],
    ]);

    $transport = $encryption === 'ssl' ? 'tls://' : 'tcp://';
    $errno = 0;
    $errstr = '';

    try {
        $stream = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($stream)) {
            throw new RuntimeException('SMTP connection failed (' . $errno . ').');
        }

        stream_set_timeout($stream, $timeout);
        smtp_expect($stream, [220], 'greeting');

        $helloName = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'fpoms.local')) ?: 'fpoms.local';
        smtp_command($stream, 'EHLO ' . $helloName, [250], 'EHLO');

        if ($encryption === 'tls') {
            smtp_command($stream, 'STARTTLS', [220], 'STARTTLS');
            $crypto = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new RuntimeException('SMTP TLS negotiation failed.');
            }
            smtp_command($stream, 'EHLO ' . $helloName, [250], 'EHLO after STARTTLS');
        }

        if ($username !== '') {
            smtp_command($stream, 'AUTH LOGIN', [334], 'AUTH');
            smtp_command($stream, base64_encode($username), [334], 'username authentication');
            smtp_command($stream, base64_encode($password), [235], 'password authentication');
        }

        smtp_command($stream, 'MAIL FROM:<' . $from . '>', [250], 'MAIL FROM');
        smtp_command($stream, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
        smtp_command($stream, 'DATA', [354], 'DATA');

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: ' . smtp_message_id($from),
            'From: ' . smtp_header_text($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . smtp_header_text($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: DENR FP-OMS SMTP',
        ];

        $payload = implode("\r\n", $headers)
            . "\r\n\r\n"
            . rtrim(chunk_split(base64_encode($html), 76, "\r\n"), "\r\n")
            . "\r\n.\r\n";

        if (fwrite($stream, $payload) === false) {
            throw new RuntimeException('SMTP message write failed.');
        }
        smtp_expect($stream, [250], 'message delivery');
        @smtp_command($stream, 'QUIT', [221], 'QUIT');
        fclose($stream);
        return true;
    } catch (Throwable $e) {
        if (isset($stream) && is_resource($stream)) {
            @fwrite($stream, "QUIT\r\n");
            @fclose($stream);
        }
        error_log('FP-OMS SMTP delivery failure: ' . $e->getMessage());
        return false;
    }
}
