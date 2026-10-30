<?php
declare(strict_types=1);

/**
 * Automatically provision a persistent 256-bit document key on the first upload.
 * NO key is placed in Git, MySQL, public/, or the application's storage tree.
 *
 * The directory MUST be outside the webroot. Deployment must still protect
 * the filesystem/Windows ACL and back up the key separately from the documents.
 */
function fpoms_path_normalize(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function fpoms_path_within(string $path, string $root): bool
{
    $path = strtolower(fpoms_path_normalize($path));
    $root = strtolower(fpoms_path_normalize($root));
    return $path === $root || str_starts_with($path, $root . '/');
}

function fpoms_key_directory(): string
{
    $project = realpath(PROJECT_ROOT);
    if ($project === false) throw new RuntimeException('Project root unavailable.');
    $project = fpoms_path_normalize($project);
    $override = env_value('FPOMS_KEY_DIR');
    if ($override !== '') {
        if (!str_starts_with($override, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $override)) {
            throw new RuntimeException('Encryption key directory must be an absolute path.');
        }
        $dir = fpoms_path_normalize($override);
    } else {
        // XAMPP: C:/xampp/htdocs/site -> C:/xampp/fpoms-private-keys-<site-id>
        // Typical Linux: /var/www/html/site -> /var/www/fpoms-private-keys-<site-id>
        $base = dirname($project);
        if (preg_match('#^(.+?)/(?:htdocs|public_html|www|html)(?:/|$)#i', $project, $m)) {
            $base = $m[1];
        }
        $dir = fpoms_path_normalize($base) . '/fpoms-private-keys-' .
            substr(hash('sha256', strtolower($project)), 0, 20);
    }
    $documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $documentRoot = $documentRoot !== '' ? realpath($documentRoot) : false;
    if (fpoms_path_within($dir, $project)
        || ($documentRoot !== false && fpoms_path_within($dir, $documentRoot))) {
        throw new RuntimeException(
            'Cannot create document encryption key inside the website. Server administrator must provide a private key directory.'
        );
    }
    return $dir;
}

/** A missing key may NEVER be silently replaced after encrypted files exist. */
function fpoms_existing_ciphertext(): bool
{
    foreach (['registrations', 'registration_drafts', 'fp_step2', 'fp_step2_drafts', 'profile_photos'] as $folder) {
        $root = PROJECT_ROOT . '/storage/' . $folder;
        if (!is_dir($root)) continue;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->isLink()) throw new RuntimeException('Symlink found in protected upload storage.');
            if (!$file->isFile()) continue;
            $stream = @fopen($file->getPathname(), 'rb');
            if ($stream === false) throw new RuntimeException('Cannot inspect existing file storage.');
            try {
                if (fread($stream, 9) === 'FPOMSENC1') return true;
            } finally {
                fclose($stream);
            }
        }
    }
    return false;
}

/** Returns a single stable 32-byte key; creation is serialized and fail-closed. */
function fpoms_master_key(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $dir = fpoms_key_directory();
    if (is_link($dir)) throw new RuntimeException('Encryption key directory must not be a symlink.');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Private encryption key directory cannot be created outside the website.');
    }
    @chmod($dir, 0700);
    $realDir = realpath($dir);
    $documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $documentRoot = $documentRoot !== '' ? realpath($documentRoot) : false;
    if ($realDir === false || fpoms_path_within($realDir, PROJECT_ROOT)
        || ($documentRoot !== false && fpoms_path_within($realDir, $documentRoot))) {
        throw new RuntimeException('Unsafe encryption key directory: key storage must be outside the webroot.');
    }
    if (DIRECTORY_SEPARATOR !== '\\' && (fileperms($realDir) & 0077) !== 0) {
        throw new RuntimeException('Encryption key directory permissions are too broad.');
    }
    $lockPath = $dir . '/setup.lock';
    if (is_link($lockPath)) throw new RuntimeException('Unsafe encryption key lock.');
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException('Cannot lock encryption setup.');
    @chmod($lockPath, 0600);
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Cannot serialize encryption key creation.');
        $path = $dir . '/master.key';
        $marker = PROJECT_ROOT . '/storage/.fpoms-key-initialized';
        if (is_link($path) || is_link($marker)) {
            throw new RuntimeException('Unsafe encryption key metadata.');
        }
        if (!is_file($path)) {
            if (file_exists($marker) || fpoms_existing_ciphertext()) {
                throw new RuntimeException('Encryption key missing: restore the original key backup. A replacement cannot decrypt existing files.');
            }
            $key = random_bytes(32);
            $handle = @fopen($path, 'x');
            if ($handle === false) throw new RuntimeException('Cannot create encryption key file.');
            try {
                if (fwrite($handle, base64_encode($key)) !== 44 || !fflush($handle)) {
                    throw new RuntimeException('Encryption key write failed.');
                }
            } finally {
                fclose($handle);
            }
            @chmod($path, 0600);
        }
        $raw = @file_get_contents($path);
        $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Stored encryption key is corrupt.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('Encryption key permissions are too broad.');
        }
        $fingerprint = hash('sha256', $key);
        if (is_file($marker)) {
            if (!hash_equals($fingerprint, trim((string)file_get_contents($marker)))) {
                throw new RuntimeException('Encryption key mismatch: restore correct key backup.');
            }
        } elseif (@file_put_contents($marker, $fingerprint, LOCK_EX) === false) {
            throw new RuntimeException('Cannot persist encryption key recovery marker.');
        }
        $cached = $key;
        return $key;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
