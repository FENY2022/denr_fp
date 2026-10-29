<?php
declare(strict_types=1);

// Database-free regression check for redesigned FP-OMS branding.
$root = dirname(__DIR__);
$expectedSizes = [
    'fpoms-icon-192.png' => 192,
    'fpoms-icon-512.png' => 512,
];
foreach ($expectedSizes as $filename => $pixels) {
    $path = $root . '/public/assets/' . $filename;
    $dimensions = is_file($path) ? @getimagesize($path) : false;
    if ($dimensions === false || $dimensions[0] !== $pixels || $dimensions[1] !== $pixels
        || ($dimensions['mime'] ?? '') !== 'image/png') {
        throw new RuntimeException($filename . ' must be a valid ' . $pixels . 'x' . $pixels . ' PNG.');
    }
}

$manifest = json_decode(
    (string)file_get_contents($root . '/public/manifest.webmanifest'),
    true, 512, JSON_THROW_ON_ERROR
);
if (count($manifest['icons'] ?? []) !== 2) {
    throw new RuntimeException('PWA manifest must offer exactly the two redesigned PNG icons.');
}
foreach ($manifest['icons'] as $icon) {
    if ($icon['type'] !== 'image/png'
        || !in_array($icon['src'], [
            'assets/fpoms-icon-192.png?v=24',
            'assets/fpoms-icon-512.png?v=24'
        ], true)) {
        throw new RuntimeException('Unexpected PWA icon source/type.');
    }
}

foreach (['app/views.php', 'app/landing.php', 'public/assets/app-shell.js'] as $file) {
    $source = (string)file_get_contents($root . '/' . $file);
    if (str_contains($source, 'fpoms-icon.svg')
        || !str_contains($source, 'fpoms-icon-192.png?v=24')) {
        throw new RuntimeException('Old logo or missing revised PNG reference in ' . $file);
    }
}
foreach (['app/views.php', 'app/landing.php'] as $file) {
    $source = (string)file_get_contents($root . '/' . $file);
    if (!str_contains($source, 'rel="icon" type="image/png"')
        || !str_contains($source, 'fpoms-icon-512.png?v=24')
        || !str_contains($source, 'manifest.webmanifest?v=24')
        || !str_contains($source, 'app-shell.js?v=22')) {
        throw new RuntimeException('Favicon, manifest or JS version missing in ' . $file);
    }
}
$serviceWorker = (string)file_get_contents($root . '/public/service-worker.js');
if (!str_contains($serviceWorker, "fp-oms-static-v24")
    || !str_contains($serviceWorker, 'fpoms-icon-192.png?v=24')
    || !str_contains($serviceWorker, 'fpoms-icon-512.png?v=24')
    || !str_contains($serviceWorker, 'manifest.webmanifest?v=24')
    || str_contains($serviceWorker, "endsWith('/assets/fpoms-icon.svg')")) {
    throw new RuntimeException('Service worker branding cache is out of date.');
}
echo "Branding PNG, favicon, manifest, shell and service-worker references passed.\n";
