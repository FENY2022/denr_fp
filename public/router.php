<?php
// For PHP's built-in development server only. Production: Apache /public vhost + .htaccess.
declare(strict_types=1);
$path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$public = __DIR__;
if (str_starts_with($path, '/assets/') && is_file($public . $path)) return false;
if (in_array($path, ['/manifest.webmanifest', '/service-worker.js'], true)) return false;
if ($path === '/index.php') return false;
if ($path === '/') {
    require $public . '/index.php';
    return true;
}
if (preg_match('~^/([a-z][a-z0-9-]*)/?$~i', $path, $matches)) {
    $_GET['page'] = $matches[1];
    require $public . '/index.php';
    return true;
}
http_response_code(404);
echo 'Not Found';
return true;
