<?php
declare(strict_types=1);

/** AES-256-GCM authenticated file encryption: magic, key-id length, key-id, nonce, tag, ciphertext. */
const FPOMS_FILE_MAGIC = 'FPOMSENC1';

function fpoms_file_key_id(): string
{
    $id = env_value('FPOMS_FILE_KEY_ID', 'v1');
    if (!preg_match('/^[A-Za-z0-9_-]{1,24}$/D', $id)) {
        throw new RuntimeException('Invalid document encryption key identifier.');
    }
    return $id;
}

function fpoms_file_key(string $id): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,24}$/D', $id)) {
        throw new RuntimeException('Invalid document encryption key identifier.');
    }
    if ($id === fpoms_file_key_id()) {
        $encoded = env_value('FPOMS_FILE_KEY_B64');
    } else {
        $old = json_decode(env_value('FPOMS_FILE_OLD_KEYS_JSON', '{}'), true);
        if (!is_array($old) || !isset($old[$id]) || !is_string($old[$id])) {
            throw new RuntimeException('Previous document encryption key is unavailable.');
        }
        $encoded = $old[$id];
    }
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Valid 32-byte document encryption key required.');
    }
    return $key;
}

function fpoms_file_encrypted(string $bytes): bool
{
    return str_starts_with($bytes, FPOMS_FILE_MAGIC);
}

function fpoms_file_encrypt(string $plaintext): string
{
    $id = fpoms_file_key_id();
    $nonce = random_bytes(12);
    $header = FPOMS_FILE_MAGIC . chr(strlen($id)) . $id;
    $tag = '';
    $cipher = openssl_encrypt(
        $plaintext, 'aes-256-gcm', fpoms_file_key($id), OPENSSL_RAW_DATA,
        $nonce, $tag, $header, 16
    );
    if ($cipher === false || strlen($tag) !== 16) {
        throw new RuntimeException('Could not encrypt document.');
    }
    return $header . $nonce . $tag . $cipher;
}

function fpoms_file_decrypt(string $payload): string
{
    if (!fpoms_file_encrypted($payload)) {
        if (env_value('FPOMS_REQUIRE_ENCRYPTED_FILES', 'false') === 'true') {
            throw new RuntimeException('Legacy unencrypted document access is disabled.');
        }
        return $payload;
    }
    $offset = strlen(FPOMS_FILE_MAGIC);
    if (strlen($payload) < $offset + 30) {
        throw new RuntimeException('Encrypted document is incomplete.');
    }
    $length = ord($payload[$offset]);
    $id = substr($payload, $offset + 1, $length);
    $headerLength = $offset + 1 + $length;
    if ($length < 1 || $length > 24 || !preg_match('/^[A-Za-z0-9_-]{1,24}$/D', $id)
        || strlen($payload) < $headerLength + 28) {
        throw new RuntimeException('Encrypted document header is invalid.');
    }
    $plain = openssl_decrypt(
        substr($payload, $headerLength + 28), 'aes-256-gcm', fpoms_file_key($id),
        OPENSSL_RAW_DATA, substr($payload, $headerLength, 12),
        substr($payload, $headerLength + 12, 16), substr($payload, 0, $headerLength)
    );
    if ($plain === false) {
        throw new RuntimeException('Encrypted document integrity verification failed.');
    }
    return $plain;
}

function fpoms_file_read(string $path): string
{
    $bytes = file_get_contents($path);
    if ($bytes === false) throw new RuntimeException('Stored document is unreadable.');
    return fpoms_file_decrypt($bytes);
}

function fpoms_file_plaintext_sha256(string $path): string
{
    return hash('sha256', fpoms_file_read($path));
}

function fpoms_file_plaintext_size(string $path): int
{
    return strlen(fpoms_file_read($path));
}

function fpoms_file_write_encrypted_bytes(string $plaintext, string $destination): void
{
    $encrypted = fpoms_file_encrypt($plaintext);
    $tmp = $destination . '.' . bin2hex(random_bytes(8)) . '.tmp';
    try {
        if (file_put_contents($tmp, $encrypted, LOCK_EX) !== strlen($encrypted)
            || !chmod($tmp, 0600) || !rename($tmp, $destination)) {
            throw new RuntimeException('Could not finalize encrypted document.');
        }
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
}

function fpoms_file_store_upload(string $tempUpload, string $destination): void
{
    if (!is_uploaded_file($tempUpload)) throw new RuntimeException('Invalid uploaded document.');
    $plaintext = file_get_contents($tempUpload);
    if ($plaintext === false) throw new RuntimeException('Could not read uploaded document.');
    fpoms_file_write_encrypted_bytes($plaintext, $destination);
}

function fpoms_file_copy_encrypted(string $source, string $destination): void
{
    fpoms_file_write_encrypted_bytes(fpoms_file_read($source), $destination);
}

function fpoms_file_path_encrypted(string $path): bool
{
    $stream = fopen($path, 'rb');
    if ($stream === false) throw new RuntimeException('Cannot inspect stored document.');
    try {
        return fpoms_file_encrypted((string)fread($stream, strlen(FPOMS_FILE_MAGIC)));
    } finally {
        fclose($stream);
    }
}
