<?php
declare(strict_types=1);

/**
 * Optional, explicit migration of legacy plaintext uploads.
 * Never runs during web requests or installation.
 *
 * FIRST BACK UP the entire storage tree and database, and securely back up
 * the automatically generated key directory. A failed migration exits nonzero.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../app/bootstrap.php';
$execute = in_array('--execute', $argv, true);
if (array_diff(array_slice($argv, 1), ['--dry-run', '--execute'])
    || ($execute && in_array('--dry-run', $argv, true))) {
    fwrite(STDERR, "Usage: php scripts/encrypt_legacy_documents.php [--dry-run|--execute]\n");
    exit(2);
}
$folders = ['registrations', 'registration_drafts', 'fp_step2', 'fp_step2_drafts', 'profile_photos'];
$stats = ['converted'=>0, 'encrypted_already'=>0, 'legacy_unconverted'=>0, 'errors'=>0];
try {
    foreach ($folders as $folder) {
        $root = PROJECT_ROOT . '/storage/' . $folder;
        if (!is_dir($root)) continue;
        $walker = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walker as $entry) {
            if ($entry->isLink()) throw new RuntimeException('Unexpected storage symlink: '.$entry->getPathname());
            if (!$entry->isFile()) continue;
            $path = $entry->getPathname();
            if (!preg_match('/\.(?:pdf|jpg|jpeg|png|webp)$/iD', $path)) {
                throw new RuntimeException('Unexpected file in document storage: '.$path);
            }
            if (fpoms_file_path_encrypted($path)) {
                fpoms_file_read($path);
                ++$stats['encrypted_already'];
                continue;
            }
            ++$stats['legacy_unconverted'];
            if (!$execute) {
                echo 'DRY-RUN '.substr($path, strlen(PROJECT_ROOT) + 1)."\n";
                continue;
            }
            $original = file_get_contents($path);
            if ($original === false) throw new RuntimeException('Cannot read: '.$path);
            $digest = hash('sha256', $original);
            fpoms_file_write_encrypted_bytes($original, $path);
            if (!fpoms_file_path_encrypted($path)
                || !hash_equals($digest, fpoms_file_plaintext_sha256($path))) {
                throw new RuntimeException('File conversion verification failed: '.$path);
            }
            ++$stats['converted'];
            --$stats['legacy_unconverted'];
            echo 'ENCRYPTED '.substr($path, strlen(PROJECT_ROOT)+1)."\n";
        }
    }
} catch (Throwable $error) {
    ++$stats['errors'];
    fwrite(STDERR, 'STOPPED: '.$error->getMessage()."\n");
}
echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
if ($stats['errors']) exit(1);
if (!$execute && $stats['legacy_unconverted']) echo "No files changed; take backups before --execute.\n";
