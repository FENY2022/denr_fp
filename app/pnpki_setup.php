<?php
declare(strict_types=1);

/**
 * Super Administrator setup for the FP-OMS ↔ PNPKI_2 bridge.
 * Credentials are encrypted with an independent key derived from the
 * existing off-webroot FP-OMS master encryption key.
 */
function fpoms_pnpki_setup_tables_ready(): bool
{
    try {
        db()->query('SELECT application_id FROM fpoms_pnpki_links LIMIT 0');
        db()->query('SELECT nonce FROM fpoms_pnpki_nonces LIMIT 0');
        db()->query('SELECT fpoms_user_id FROM fpoms_pnpki_authorizations LIMIT 0');
        db()->query('SELECT application_id FROM fpoms_pnpki_signed_uploads LIMIT 0');
        db()->query('SELECT id FROM fpoms_pnpki_settings LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fpoms_pnpki_secret_key(): string
{
    return hash_hmac('sha256', 'fpoms-pnpki-hmac-secret-v1', fpoms_master_key(), true);
}

function fpoms_pnpki_encrypt_secret(string $secret): string
{
    $nonce=random_bytes(12);
    $tag='';
    $encrypted=openssl_encrypt($secret,'aes-256-gcm',fpoms_pnpki_secret_key(),
        OPENSSL_RAW_DATA,$nonce,$tag,'FPOMS-PNPKI-SETUP-V1',16);
    if (!is_string($encrypted) || strlen($tag)!==16) {
        throw new RuntimeException('Unable to securely encrypt PNPKI_2 settings.');
    }
    return base64_encode('FPOMSN1'.$nonce.$tag.$encrypted);
}

function fpoms_pnpki_decrypt_secret(string $stored): string
{
    $bytes=base64_decode($stored,true);
    if (!is_string($bytes) || !str_starts_with($bytes,'FPOMSN1')
        || strlen($bytes)<36) {
        throw new RuntimeException('Invalid PNPKI_2 secret storage.');
    }
    $secret=openssl_decrypt(substr($bytes,35),'aes-256-gcm',
        fpoms_pnpki_secret_key(),OPENSSL_RAW_DATA,substr($bytes,7,12),
        substr($bytes,19,16),'FPOMS-PNPKI-SETUP-V1');
    if (!is_string($secret) || strlen($secret)<32) {
        throw new RuntimeException('PNPKI_2 secret decryption failed.');
    }
    return $secret;
}

/**
 * Saved Super Admin settings override .env. A damaged saved secret
 * intentionally disables the bridge instead of silently using a legacy key.
 */
function fpoms_pnpki_effective_config(): array
{
    try {
        $row=query_one('SELECT * FROM fpoms_pnpki_settings WHERE id=1');
        if ($row) {
            try {
                $secret=fpoms_pnpki_decrypt_secret((string)$row['secret_ciphertext']);
            } catch(Throwable $e) {
                error_log('FP-OMS PNPKI_2 credential cannot be decrypted.');
                $secret='';
            }
            return [
                'url'=>(string)$row['bridge_url'],
                'secret'=>$secret,
                'allow_local_http'=>(int)$row['allow_local_http']===1,
                'source'=>'admin'
            ];
        }
    } catch(Throwable $e) {
        // Before setup, legacy .env remains available.
    }
    return [
        'url'=>env_value('PNPKI_BRIDGE_URL',''),
        'secret'=>env_value('PNPKI_BRIDGE_SECRET',''),
        'allow_local_http'=>env_value('PNPKI_BRIDGE_ALLOW_LOCAL_HTTP','false')==='true',
        'source'=>'environment'
    ];
}

function fpoms_pnpki_valid_url(string $url,bool $allowLocal): bool
{
    if (strlen($url)>500 || $url==='' || preg_match('/[\\x00-\\x20]/',$url)) return false;
    $parts=parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || isset($parts['user'])
        || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return false;
    }
    $scheme=strtolower((string)($parts['scheme']??''));
    $host=strtolower((string)$parts['host']);
    if (!str_ends_with((string)($parts['path']??''),'/fpoms_bridge.php')) return false;
    if ($scheme==='https') return true;
    return $allowLocal && $scheme==='http'
        && in_array($host,['localhost','127.0.0.1','::1'],true);
}

/** Explicit Super Admin action; never silently run DDL during a page load. */
function fpoms_pnpki_install_tables(array $admin): array
{
    if (!has_role($admin,'super_admin')) return ['Super Administrator required.'];
    if (fpoms_pnpki_setup_tables_ready()) return [];
    try {
        // The PNPKI link table depends on the already-defined Template No. 2
        // notice table. Create or verify it using the app's existing installer.
        if (!fpoms_payment_notice_schema_ready()) {
            throw new RuntimeException('Template No. 2 database storage must be installed first.');
        }
        foreach ([
            PROJECT_ROOT.'/database/041_pnpki_2_bridge.sql',
            PROJECT_ROOT.'/database/042_pnpki_authorized_upload.sql'
        ] as $file) {
            $sql=@file_get_contents($file);
            if (!is_string($sql) || $sql==='') {
                throw new RuntimeException('Missing database migration file.');
            }
            // Fixed bundled SQL, never user input. Remove SQL line comments
            // before splitting on semicolons; DDL remains idempotent.
            $sql=preg_replace('/^[ \\t]*--[^\\r\\n]*$/m','',$sql);
            if (!is_string($sql)) throw new RuntimeException('Cannot parse the migration file.');
            foreach (explode(';',$sql) as $statement) {
                if (trim($statement)!=='') db()->exec($statement);
            }
        }
        db()->exec("CREATE TABLE IF NOT EXISTS fpoms_pnpki_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            bridge_url VARCHAR(500) NOT NULL,
            secret_ciphertext TEXT NOT NULL,
            allow_local_http TINYINT(1) NOT NULL DEFAULT 0,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!fpoms_pnpki_setup_tables_ready()) {
            return ['One or more PNPKI_2 tables could not be verified.'];
        }
        audit((int)$admin['id'],'config.pnpki.schema_installed','user',(int)$admin['id']);
        return [];
    } catch(Throwable $e) {
        error_log('FP-OMS PNPKI_2 schema setup: '.$e->getMessage());
        return ['Database setup failed. Confirm CREATE TABLE permission and inspect the server log. '
            .'No existing applications were deleted.'];
    }
}

function fpoms_pnpki_save_settings(array $admin,array $post): array
{
    if (!has_role($admin,'super_admin')) return ['Super Administrator required.'];
    if (!fpoms_pnpki_setup_tables_ready()) return ['Install the required database tables first.'];
    $url=trim((string)($post['bridge_url']??''));
    $allow=(string)($post['allow_local_http']??'')==='1';
    $submitted=trim((string)($post['shared_secret']??''));
    if (!fpoms_pnpki_valid_url($url,$allow)) {
        return ['Enter a valid HTTPS PNPKI_2 endpoint. Local HTTP is allowed only on localhost for XAMPP testing.'];
    }
    try {
        $existing=query_one('SELECT secret_ciphertext FROM fpoms_pnpki_settings WHERE id=1');
        $secret=$submitted!==''?$submitted:($existing
            ?fpoms_pnpki_decrypt_secret((string)$existing['secret_ciphertext']):'');
        if (strlen($secret)<32 || strlen($secret)>512
            || preg_match('/[^\\x21-\\x7E]/',$secret)) {
            return ['Enter the same strong 32+ character secret shown in the PNPKI_2 Admin setup.'];
        }
        if (str_contains($secret,'REPLACE_WITH_') || str_contains($secret,'YOUR_')) {
            return ['Replace the example secret with a real randomly generated value.'];
        }
        $cipher=fpoms_pnpki_encrypt_secret($secret);
        db()->prepare('INSERT INTO fpoms_pnpki_settings
            (id,bridge_url,secret_ciphertext,allow_local_http,updated_by)
            VALUES (1,?,?,?,?)
            ON DUPLICATE KEY UPDATE bridge_url=VALUES(bridge_url),
            secret_ciphertext=VALUES(secret_ciphertext),
            allow_local_http=VALUES(allow_local_http),
            updated_by=VALUES(updated_by)')->execute([
                $url,$cipher,(int)$allow,(int)$admin['id']
            ]);
        audit((int)$admin['id'],'config.pnpki.settings_updated','user',(int)$admin['id']);
        return [];
    } catch(Throwable $e) {
        error_log('FP-OMS PNPKI_2 settings save: '.$e->getMessage());
        return ['Unable to save encrypted PNPKI_2 settings. Verify the private FP-OMS encryption key.'];
    }
}

function fpoms_pnpki_test_connection(array $admin): array
{
    if (!has_role($admin,'super_admin')) return ['Super Administrator required.'];
    if (!pnpki_bridge_configured()) return ['Set a valid bridge URL and shared secret first.'];
    try {
        $response=pnpki_bridge_request(['action'=>'health']);
        $schema=(bool)($response['schema_ready']??false);
        $identities=(bool)($response['accounts_ready']??false);
        $verifier=(bool)($response['signature_verifier_ready']??false);
        if (!$schema || !$identities || !$verifier) {
            return ['Connected to PNPKI_2 but its setup is incomplete: '
                .(!$schema?'database migrations; ':'')
                .(!$identities?'RED / Records / Initiator user accounts; ':'')
                .(!$verifier?'pdfsig and RED certificate settings; ':'')];
        }
        return [];
    } catch(Throwable $e) {
        error_log('FP-OMS PNPKI_2 connection test: '.$e->getMessage());
        return ['Cannot authenticate with PNPKI_2. Check the exact URL, shared secret, '
            .'PNPKI_2 migrations and clocks; confirm both sites are reachable.'];
    }
}

function fpoms_pnpki_setup_view(array $messages=[]): void
{
    $ready=fpoms_pnpki_setup_tables_ready();
    $cfg=fpoms_pnpki_effective_config();
    $url=e((string)$cfg['url']);
    $local=!empty($cfg['allow_local_http'])?' checked':'';
    $source=e((string)$cfg['source']);
    $state=$ready?'<span class="badge text-bg-success">Ready</span>'
        :'<span class="badge text-bg-warning">Migration Needed</span>';
    $msg='';
    foreach ($messages as $message) {
        $msg.='<div class="alert alert-danger">'.e((string)$message).'</div>';
    }
    $install='<form method="post" action="'.e(url('pnpki_settings')).'" class="mb-4">'
        .csrf_field().'<input type="hidden" name="operation" value="install">'
        .'<button class="btn btn-outline-primary" type="submit">'
        .'<i class="bi bi-database-check me-1"></i>Install / Verify FP-OMS Tables 041 &amp; 042</button>'
        .'<p class="small text-secondary mt-2">Creates only missing tables. Existing permit '
        .'applications, users and PNPKI documents are not deleted. Back up the database first.</p>'
        .'</form>';
    $html='<div class="panel"><h2 class="h5">PNPKI_2 Integration Setup</h2>'
        .'<p class="text-secondary">Configure the secure FP-OMS connection to PNPKI_2. '
        .'Only Super Administrators can edit these settings.</p>'.$msg
        .'<h3 class="h6">1. FP-OMS Database '.$state.'</h3>'.$install
        .'<h3 class="h6">2. Secure Connection</h3>'
        .'<form method="post" action="'.e(url('pnpki_settings')).'" class="border rounded p-3 mb-4">'
        .csrf_field().'<input type="hidden" name="operation" value="save">'
        .'<label class="form-label fw-semibold">PNPKI_2 API URL</label>'
        .'<input class="form-control mb-3" name="bridge_url" type="url" required '
        .'maxlength="500" placeholder="http://localhost/PNPKI_2/fpoms_bridge.php" '
        .'value="'.$url.'">'
        .'<label class="form-label fw-semibold">Shared Integration Secret</label>'
        .'<input class="form-control mb-2" name="shared_secret" type="password" '
        .'autocomplete="new-password" minlength="32" maxlength="512" '
        .'placeholder="Paste the secret generated in PNPKI_2">'
        .'<p class="small text-secondary">The secret is encrypted using the private FP-OMS '
        .'master key. Leave blank to retain an existing saved secret. Never paste a PNPKI password '
        .'or certificate private key here.</p>'
        .'<label class="d-flex gap-2 align-items-center mb-3">'
        .'<input type="checkbox" class="form-check-input" name="allow_local_http" value="1"'.$local.'>'
        .'<span>Allow HTTP only for PNPKI_2 on the same localhost XAMPP computer</span></label>'
        .'<p class="small text-secondary">Settings source: '.$source.'</p>'
        .'<button class="btn btn-primary" type="submit"'.(!$ready?' disabled':'').'>'
        .'<i class="bi bi-shield-lock me-1"></i>Save Secure Connection</button></form>'
        .'<h3 class="h6">3. Test &amp; Complete PNPKI_2 Setup</h3>'
        .'<p>Open <strong>PNPKI_2 Admin → FP-OMS Integration Setup</strong>. Install '
        .'PNPKI migrations 001/002, choose the real RED and Records accounts, '
        .'configure pdfsig/certificate trust, then copy the generated secret above.</p>'
        .'<form method="post" action="'.e(url('pnpki_settings')).'">'
        .csrf_field().'<input type="hidden" name="operation" value="test">'
        .'<button class="btn btn-outline-success" type="submit"'
        .(!pnpki_bridge_configured()?' disabled':'').'>'
        .'<i class="bi bi-activity me-1"></i>Test PNPKI_2 Connection</button></form>'
        .'<p class="small text-secondary mt-3">Signed PDF upload and applicant payment '
        .'notifications remain blocked until successful RED authorization and verified Records release.</p>'
        .'</div>';
    render_page('PNPKI_2 Integration Setup',$html);
}
