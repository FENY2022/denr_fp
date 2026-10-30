<?php
declare(strict_types=1);

const PROJECT_ROOT = __DIR__ . '/..';

function settings(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [];
    $path = PROJECT_ROOT . '/.env';
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) $value = substr($value, 1, -1);
            $cfg[trim($key)] = $value;
        }
    }
    return $cfg;
}

function env_value(string $key, string $fallback = ''): string
{
    return settings()[$key] ?? (getenv($key) ?: $fallback);
}

date_default_timezone_set(env_value('APP_TIMEZONE', 'Asia/Manila'));
ini_set('display_errors', env_value('APP_DEBUG', 'false') === 'true' ? '1' : '0');
error_reporting(E_ALL);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
session_name(env_value('SESSION_NAME', 'fp_oms_session'));
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), microphone=(), camera=(self)');
    header('Content-Security-Policy: default-src \'self\'; connect-src \'self\' https://cdn.jsdelivr.net; style-src \'self\' https://cdn.jsdelivr.net https://fonts.googleapis.com; script-src \'self\' https://cdn.jsdelivr.net; worker-src \'self\' blob:; img-src \'self\' data: blob: https://cdn.jsdelivr.net https://*.tile.openstreetmap.org; frame-src \'self\'; font-src \'self\' https://cdn.jsdelivr.net https://fonts.gstatic.com; object-src \'none\'; base-uri \'self\'; frame-ancestors \'none\'; form-action \'self\'');
    header('Cache-Control: no-store, private');
}
require_once __DIR__ . '/domain.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/file_keys.php';
require_once __DIR__ . '/file_crypto.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/smtp.php';
require_once __DIR__ . '/registration_qr.php';
require_once __DIR__ . '/registration_mail.php';
require_once __DIR__ . '/registration_otp.php';
require_once __DIR__ . '/registration_diagnostics.php';
require_once __DIR__ . '/password_reset.php';
require_once __DIR__ . '/registration.php';
require_once __DIR__ . '/registration_attachments.php';
require_once __DIR__ . '/registration_requirements.php';
require_once __DIR__ . '/permit_step2_requirements.php';
require_once __DIR__ . '/permit_step2.php';
require_once __DIR__ . '/permit_tracking.php';
require_once __DIR__ . '/permit_step2_revert.php';
require_once __DIR__ . '/permit_step2_drafts.php';
require_once __DIR__ . '/permit_step2_persistent.php';
require_once __DIR__ . '/registration_drafts.php';
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/user_management.php';
require_once __DIR__ . '/profile.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/registration_otp_views.php';
require_once __DIR__ . '/user_management_views.php';
require_once __DIR__ . '/setup.php';
require_once __DIR__ . '/permit_step2_views.php';
require_once __DIR__ . '/permit_tracking_views.php';
require_once __DIR__ . '/landing.php';
