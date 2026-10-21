<?php
declare(strict_types=1);

require_once PROJECT_ROOT . '/lib/phpmailer/src/Exception.php';
require_once PROJECT_ROOT . '/lib/phpmailer/src/PHPMailer.php';
require_once PROJECT_ROOT . '/lib/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Send an HTML email through authenticated SMTP using the same PHPMailer
 * transport pattern used by the PNPKI/D-Sign application.
 *
 * SMTP credentials are read only from .env/environment variables.
 */
function smtp_send_html(string $to, string $subject, string $html, string $plain = ''): bool
{
    if (strtolower(env_value('MAIL_ENABLED', 'false')) !== 'true') {
        error_log('FP-OMS mail delivery skipped: MAIL_ENABLED is not true.');
        return false;
    }

    $host = trim(env_value('MAIL_HOST', 'smtp.gmail.com'));
    $port = (int)env_value('MAIL_PORT', '587');
    $encryption = strtolower(trim(env_value('MAIL_ENCRYPTION', 'tls')));
    $username = trim(env_value('MAIL_USERNAME'));
    $password = env_value('MAIL_PASSWORD');
    $from = trim(env_value('MAIL_FROM_ADDRESS', $username));
    $fromName = trim(env_value('MAIL_FROM_NAME', 'DENR FP-OMS'));
    $timeout = max(5, min(60, (int)env_value('MAIL_TIMEOUT', '20')));
    $verifyPeer = strtolower(env_value('MAIL_VERIFY_PEER', 'true')) !== 'false';

    if ($host === '' || $port < 1 || $port > 65535) {
        error_log('FP-OMS PHPMailer configuration incomplete: MAIL_HOST/MAIL_PORT.');
        return false;
    }
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        error_log('FP-OMS PHPMailer configuration invalid: MAIL_ENCRYPTION must be tls, ssl, or none.');
        return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('FP-OMS PHPMailer rejected an invalid sender or recipient email address.');
        return false;
    }
    if (($username === '') !== ($password === '')) {
        error_log('FP-OMS PHPMailer configuration incomplete: MAIL_USERNAME and MAIL_PASSWORD must both be configured.');
        return false;
    }
    if (strcasecmp($host, 'smtp.gmail.com') === 0 && ($username === '' || $password === '')) {
        error_log('FP-OMS Gmail SMTP requires MAIL_USERNAME and a Gmail App Password in MAIL_PASSWORD.');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->Timeout = $timeout;
        $mail->SMTPAuth = $username !== '';

        if ($mail->SMTPAuth) {
            $mail->Username = $username;
            $mail->Password = $password;
        }

        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS = true;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'allow_self_signed' => !$verifyPeer,
            ],
        ];

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $plain !== ''
            ? $plain
            : trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $debug = strtolower(env_value('MAIL_DEBUG', 'false')) === 'true';
        if ($debug) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = static function (string $message, int $level): void {
                $safe = preg_replace('/(AUTH|PASS|PASSWORD|USERNAME).*/i', '$1 [redacted]', $message);
                error_log('FP-OMS PHPMailer debug L' . $level . ': ' . trim((string)$safe));
            };
        }

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('FP-OMS PHPMailer delivery failure: ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        error_log('FP-OMS mail delivery failure: ' . $e->getMessage());
        return false;
    }
}
