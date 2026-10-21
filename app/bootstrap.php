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
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Content-Security-Policy: default-src \'self\'; style-src \'self\' https://cdn.jsdelivr.net https://fonts.googleapis.com; script-src \'self\' https://cdn.jsdelivr.net; img-src \'self\' data:; font-src \'self\' https://cdn.jsdelivr.net https://fonts.gstatic.com; object-src \'none\'; base-uri \'self\'; frame-ancestors \'none\'; form-action \'self\'');
    header('Cache-Control: no-store, private');
}
require_once __DIR__ . '/domain.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/registration.php';
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/views.php';
