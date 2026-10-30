<?php
declare(strict_types=1);
// DB-free tests for authenticated ciphertext, key validation, and legacy compatibility.
function env_value(string $key, string $fallback = ''): string
{
    $value = getenv($key);
    return $value === false ? $fallback : $value;
}
require_once __DIR__ . '/../app/file_crypto.php';
function crypto_assert(bool $condition,string $message): void
{
    if (!$condition) throw new RuntimeException('Encryption test failed: '.$message);
}
putenv('FPOMS_FILE_KEY_ID=v1');
putenv('FPOMS_FILE_KEY_B64='.base64_encode(random_bytes(32)));
putenv('FPOMS_REQUIRE_ENCRYPTED_FILES=false');
$raw = random_bytes(16384);
$first = fpoms_file_encrypt($raw);
$second = fpoms_file_encrypt($raw);
crypto_assert(fpoms_file_encrypted($first),'ciphertext marked');
crypto_assert($first !== $second,'unique random nonce');
crypto_assert(fpoms_file_decrypt($first) === $raw,'plaintext round trip');
crypto_assert(fpoms_file_decrypt($raw) === $raw,'legacy compatibility');
$tampered = $first;
$last = strlen($tampered)-1;
$tampered[$last] = chr(ord($tampered[$last]) ^ 1);
try {
    fpoms_file_decrypt($tampered);
    throw new RuntimeException('Corrupt ciphertext accepted');
} catch (RuntimeException $error) {
    crypto_assert(str_contains($error->getMessage(),'integrity'),'reject modified ciphertext');
}
$path = tempnam(sys_get_temp_dir(), 'fpoms-');
try {
    fpoms_file_write_encrypted_bytes($raw,$path);
    crypto_assert(fpoms_file_path_encrypted($path),'ciphertext written to storage');
    crypto_assert(fpoms_file_plaintext_sha256($path)===hash('sha256',$raw),'plaintext SHA');
    crypto_assert(fpoms_file_plaintext_size($path)===strlen($raw),'plaintext size');
    fpoms_file_copy_encrypted($path, $path.'.copy');
    crypto_assert(file_get_contents($path)!==file_get_contents($path.'.copy'),'copy uses fresh nonce');
    crypto_assert(fpoms_file_read($path.'.copy')===$raw,'copy correctly decrypted');
    @unlink($path.'.copy');
} finally {
    @unlink($path);
}
putenv('FPOMS_REQUIRE_ENCRYPTED_FILES=true');
try {
    fpoms_file_decrypt('unencrypted');
    throw new RuntimeException('Plaintext accepted in strict mode');
} catch (RuntimeException $error) {
    crypto_assert(str_contains($error->getMessage(),'disabled'),'strict mode blocks plaintext');
}
putenv('FPOMS_FILE_KEY_B64='.base64_encode(random_bytes(32)));
try {
    fpoms_file_decrypt($first);
    throw new RuntimeException('Wrong key accepted');
} catch (RuntimeException $error) {
    crypto_assert(str_contains($error->getMessage(),'integrity'),'wrong key rejected');
}
echo "File crypto tests passed\n";
