<?php
declare(strict_types=1);

// Daily cron / Windows Task Scheduler. CLI only.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../app/bootstrap.php';
if (!step2_drafts_schema_ready()) {
    fwrite(STDERR,"Apply migration 015 before FP draft cleanup.\n");
    exit(1);
}
// Migration 016 makes Step 2 drafts persistent: never run the former 24h cleanup.
if (step2_persistent_ready()) {
    echo "FP draft persistence active: automatic draft deletion disabled.\n";
    exit(0);
}
$removed = 0;
$failed = 0;
try {
    do {
        $rows = query_all(
            'SELECT id,stored_path FROM permit_step2_draft_documents
             WHERE expires_at<=NOW() ORDER BY id LIMIT 200'
        );
        foreach ($rows as $row) {
            $stmt = db()->prepare(
                'DELETE FROM permit_step2_draft_documents WHERE id=? AND expires_at<=NOW()'
            );
            $stmt->execute([(int)$row['id']]);
            if ($stmt->rowCount() !== 1) continue;
            $path = step2_draft_real_path((string)$row['stored_path']);
            if ($path !== null && !@unlink($path)) $failed++;
            $removed++;
        }
    } while (count($rows) === 200);
    echo "Expired FP supporting drafts removed: {$removed}\n";
    if ($failed) {
        fwrite(STDERR,"Could not delete {$failed} expired files.\n");
        exit(1);
    }
    echo "Active FP drafts preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'FP draft cleanup failed: '.$error->getMessage()."\n");
    exit(1);
}
