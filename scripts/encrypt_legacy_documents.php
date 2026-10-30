<?php
declare(strict_types=1);

// Idempotent CLI-only legacy document migration. Back up DB, storage and keys first.
// Relative file paths and SQL metadata are unchanged.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../app/bootstrap.php';

$execute = in_array('--execute', $argv, true);
if (in_array('--help', $argv, true)) {
    echo "Usage: php scripts/encrypt_legacy_documents.php [--dry-run|--execute]\n";
    exit(0);
}
if (array_diff(array_slice($argv, 1), ['--dry-run','--execute'])
    || ($execute && in_array('--dry-run', $argv, true))) {
    fwrite(STDERR, "Invalid flags. Select --dry-run OR --execute.\n");
    exit(2);
}
$folders = ['registrations','registration_drafts','fp_step2','fp_step2_drafts','profile_photos'];
$totals = ['newly_encrypted'=>0,'already_encrypted'=>0,'remaining_plaintext'=>0,'errors'=>0];
try {
    if ($execute) fpoms_file_key(fpoms_file_key_id());
    foreach ($folders as $folder) {
        $dir = PROJECT_ROOT . '/storage/' . $folder;
        if (!is_dir($dir)) continue;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) throw new RuntimeException('Symlink found: '.$file->getPathname());
            if (!$file->isFile()) continue;
            $path = $file->getPathname();
            if (!preg_match('/\.(pdf|jpg|jpeg|png|webp)$/iD', $path)) {
                throw new RuntimeException('Unexpected storage file: '.$path);
            }
            if (fpoms_file_path_encrypted($path)) {
                fpoms_file_read($path); // Tag verification for existing ciphertext.
                ++$totals['already_encrypted'];
                continue;
            }
            ++$totals['remaining_plaintext'];
            if (!$execute) {
                echo 'DRY-RUN '.substr($path, strlen(PROJECT_ROOT)+1)."\n";
                continue;
            }
            $plain = file_get_contents($path);
            if ($plain === false) throw new RuntimeException('Cannot read: '.$path);
            $checksum = hash('sha256', $plain);
            fpoms_file_write_encrypted_bytes($plain, $path);
            if (!fpoms_file_path_encrypted($path)
                || !hash_equals($checksum, fpoms_file_plaintext_sha256($path))) {
                throw new RuntimeException('Encryption verification failed: '.$path);
            }
            ++$totals['newly_encrypted'];
            --$totals['remaining_plaintext'];
            echo 'ENCRYPTED '.substr($path, strlen(PROJECT_ROOT)+1)."\n";
        }
    }
} catch (Throwable $error) {
    ++$totals['errors'];
    fwrite(STDERR, 'STOPPED: '.$error->getMessage()."\n");
}
echo json_encode($totals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
if ($totals['errors']) exit(1);
if (!$execute && $totals['remaining_plaintext']) echo "No changes. Back up first; then use --execute.\n";
