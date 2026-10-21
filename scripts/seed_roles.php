<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../app/bootstrap.php';
try {
    $stmt = db()->prepare('INSERT INTO roles (code,label,description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description)');
    foreach (role_definitions() as $code => $info) {
        $stmt->execute([$code,$info[0],$info[1]]);
        echo 'Seeded: ' . $code . PHP_EOL;
    }
    echo "Role seeding complete.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Seed failed: " . $e->getMessage() . "\n");
    exit(1);
}
