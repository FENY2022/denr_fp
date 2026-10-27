<?php
declare(strict_types=1);

/**
 * Safe scheduled cleanup for expired anonymous registration draft uploads.
 * Suggested Windows Task Scheduler action:
 * C:\xampp\php\php.exe C:\xampp\htdocs\denr_fp\scripts\cleanup_registration_drafts.php
 *
 * Never remove active drafts. This script is CLI-only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../app/bootstrap.php';

$removed = 0;
$failed = 0;
try {
    do {
        $rows = query_all(
            'SELECT id,stored_path FROM registration_draft_documents
             WHERE expires_at <= NOW() ORDER BY id LIMIT 200'
        );
        foreach ($rows as $row) {
            // Rows are removed individually to avoid deleting a file for a newly refreshed record.
            $stmt = db()->prepare(
                'DELETE FROM registration_draft_documents
                 WHERE id=? AND expires_at<=NOW()'
            );
            $stmt->execute([(int)$row['id']]);
            if ($stmt->rowCount() !== 1) continue;
            $path = registration_draft_real_path((string)$row['stored_path']);
            if ($path !== null && !@unlink($path)) $failed++;
            $removed++;
        }
    } while (count($rows) === 200);

    echo "Expired draft rows removed: {$removed}\n";
    if ($failed > 0) {
        fwrite(STDERR, "Warning: {$failed} expired draft files could not be deleted.\n");
        exit(1);
    }
    echo "PASS: active registration drafts were preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'Draft cleanup failed: ' . $error->getMessage() . "\n");
    exit(1);
}
