<?php
declare(strict_types=1);

/** Versioned authenticated binary envelope: magic | nonce | GCM tag | ciphertext. */
const FPOMS_FILE_MAGIC = 'FPOMSENC1';

function fpoms_file_encrypted(string $data): bool
{
    return str_starts_with($data, FPOMS_FILE_MAGIC);
}

function fpoms_file_encrypt(string $plaintext): string
{
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext, 'aes-256-gcm', fpoms_master_key(), OPENSSL_RAW_DATA,
        $nonce, $tag, FPOMS_FILE_MAGIC, 16
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Document encryption failed.');
    }
    return FPOMS_FILE_MAGIC . $nonce . $tag . $ciphertext;
}

function fpoms_file_decrypt(string $data): string
{
    if (!fpoms_file_encrypted($data)) {
        // Old documents remain readable until explicitly migrated with backups.
        return $data;
    }
    if (strlen($data) < strlen(FPOMS_FILE_MAGIC) + 28) {
        throw new RuntimeException('Encrypted document is incomplete.');
    }
    $offset = strlen(FPOMS_FILE_MAGIC);
    $plaintext = openssl_decrypt(
        substr($data, $offset + 28), 'aes-256-gcm', fpoms_master_key(),
        OPENSSL_RAW_DATA, substr($data, $offset, 12),
        substr($data, $offset + 12, 16), FPOMS_FILE_MAGIC
    );
    if ($plaintext === false) {
        throw new RuntimeException('Document integrity verification failed.');
    }
    return $plaintext;
}

function fpoms_file_read(string $path): string
{
    $data = @file_get_contents($path);
    if ($data === false) throw new RuntimeException('Cannot read protected document.');
    return fpoms_file_decrypt($data);
}

function fpoms_file_plaintext_sha256(string $path): string
{
    return hash('sha256', fpoms_file_read($path));
}

function fpoms_file_plaintext_size(string $path): int
{
    return strlen(fpoms_file_read($path));
}

/** Atomic ciphertext write, never a plaintext temporary file inside storage. */
function fpoms_file_write_encrypted_bytes(string $plaintext, string $destination): void
{
    $ciphertext = fpoms_file_encrypt($plaintext);
    $temporary = $destination . '.' . bin2hex(random_bytes(12)) . '.tmp';
    try {
        if (file_put_contents($temporary, $ciphertext, LOCK_EX) !== strlen($ciphertext)
            || !@chmod($temporary, 0600)
            || !@rename($temporary, $destination)) {
            throw new RuntimeException('Unable to securely save encrypted document.');
        }
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
}

function fpoms_file_store_upload(string $source, string $destination): void
{
    if (!is_uploaded_file($source)) throw new RuntimeException('Invalid uploaded document.');
    $data = file_get_contents($source);
    if ($data === false) throw new RuntimeException('Cannot read uploaded document.');
    fpoms_file_write_encrypted_bytes($data, $destination);
}

/** Existing staged encrypted document -> permanent encrypted file with new nonce. */
function fpoms_file_copy_encrypted(string $source, string $destination): void
{
    fpoms_file_write_encrypted_bytes(fpoms_file_read($source), $destination);
}

function fpoms_file_path_encrypted(string $path): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Stored document cannot be inspected.');
    try {
        return fread($handle, strlen(FPOMS_FILE_MAGIC)) === FPOMS_FILE_MAGIC;
    } finally {
        fclose($handle);
    }
}
