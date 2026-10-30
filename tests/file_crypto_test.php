<?php
declare(strict_types=1);

// DB-free test: first-use setup, key persistence, AES-GCM, tampering and old files.
$sandbox = sys_get_temp_dir() . '/fpoms-crypto-test-' . bin2hex(random_bytes(8));
$project = $sandbox . '/site';
if (!mkdir($project . '/storage', 0700, true)) throw new RuntimeException('Test directory failed.');
define('PROJECT_ROOT', $project);
putenv('FPOMS_KEY_DIR=' . $sandbox . '/private-keys');
function env_value(string $name, string $fallback = ''): string
{
    $value = getenv($name);
    return $value === false ? $fallback : $value;
}
require_once __DIR__ . '/../app/file_keys.php';
require_once __DIR__ . '/../app/file_crypto.php';
function crypto_assert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('FP-OMS crypto check: ' . $label);
}
function crypto_cleanup(string $directory): void
{
    if (!is_dir($directory)) return;
    foreach (new FilesystemIterator($directory) as $item) {
        if ($item->isDir() && !$item->isLink()) crypto_cleanup($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($directory);
}
try {
    $key = fpoms_master_key();
    crypto_assert(strlen($key) === 32, 'generated key is 256 bits');
    crypto_assert(hash_equals($key, fpoms_master_key()), 'key is stable within the process');
    crypto_assert(!fpoms_path_within(fpoms_key_directory(), PROJECT_ROOT), 'key outside project');
    crypto_assert(is_file(fpoms_key_directory() . '/master.key'), 'key saved once');
    crypto_assert(is_file(PROJECT_ROOT . '/storage/.fpoms-key-initialized'), 'recovery marker saved');

    $plain = random_bytes(8192);
    $a = fpoms_file_encrypt($plain);
    $b = fpoms_file_encrypt($plain);
    crypto_assert(fpoms_file_encrypted($a), 'ciphertext has file marker');
    crypto_assert($a !== $b, 'new random nonce for same file');
    crypto_assert(fpoms_file_decrypt($a) === $plain, 'roundtrip');
    crypto_assert(fpoms_file_decrypt($plain) === $plain, 'legacy files remain readable');

    $modified = $a;
    $end = strlen($modified) - 1;
    $modified[$end] = chr(ord($modified[$end]) ^ 1);
    try {
        fpoms_file_decrypt($modified);
        throw new RuntimeException('Tampering was accepted');
    } catch (RuntimeException $e) {
        crypto_assert(str_contains($e->getMessage(), 'integrity'), 'tampered ciphertext rejected');
    }
    $stored = $project . '/storage/example.pdf';
    fpoms_file_write_encrypted_bytes($plain, $stored);
    crypto_assert(fpoms_file_path_encrypted($stored), 'disk file is encrypted');
    crypto_assert(hash_file('sha256', $stored) !== hash('sha256', $plain), 'no plaintext on disk');
    crypto_assert(fpoms_file_plaintext_sha256($stored) === hash('sha256', $plain), 'plaintext digest preserved');
    crypto_assert(fpoms_file_plaintext_size($stored) === strlen($plain), 'plaintext size preserved');
    fpoms_file_copy_encrypted($stored, $stored . '.copy');
    crypto_assert(fpoms_file_read($stored . '.copy') === $plain, 'decrypt saved copy');
    crypto_assert(file_get_contents($stored) !== file_get_contents($stored . '.copy'), 'fresh nonce on copies');
} finally {
    crypto_cleanup($sandbox);
}
echo "Automatic key provisioning and file encryption checks passed\n";
