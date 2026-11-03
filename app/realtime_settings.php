<?php
declare(strict_types=1);

/**
 * FP-OMS Realtime Settings: one protected row, encrypted with a key derived
 * from the existing off-webroot FP-OMS encryption master key.
 * No Pusher credentials are ever placed in Git or the public directory.
 */
function fpoms_realtime_table_ready(): bool
{
    try {
        // COUNT(*) returns one row even when the settings table is empty.
        // Existing deployments therefore need SELECT, not permanent CREATE.
        query_one('SELECT COUNT(*) AS n FROM fpoms_realtime_settings');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function fpoms_realtime_create_table(): void
{
    // Only bootstrap the table when it is genuinely missing/unavailable.
    if (fpoms_realtime_table_ready()) return;

    db()->exec("CREATE TABLE IF NOT EXISTS fpoms_realtime_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        app_id VARCHAR(32) NOT NULL DEFAULT '',
        app_key VARCHAR(128) NOT NULL DEFAULT '',
        secret_ciphertext TEXT NULL,
        cluster VARCHAR(32) NOT NULL DEFAULT 'ap1',
        last_tested_at DATETIME NULL,
        last_test_ok TINYINT(1) NULL,
        last_test_note VARCHAR(180) NULL,
        updated_by BIGINT UNSIGNED NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function fpoms_realtime_secret_key(): string
{
    // Separate cryptographic domain from protected upload files.
    return hash_hmac('sha256', 'fpoms-realtime-pusher-secret-v1', fpoms_master_key(), true);
}

function fpoms_realtime_encrypt_secret(string $secret): string
{
    if ($secret === '') throw new RuntimeException('A Pusher secret is required.');
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $secret, 'aes-256-gcm', fpoms_realtime_secret_key(),
        OPENSSL_RAW_DATA, $nonce, $tag, 'FPOMS-PUSHER-V1', 16
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Could not encrypt the realtime credential.');
    }
    return base64_encode('FPOMSP1' . $nonce . $tag . $ciphertext);
}

function fpoms_realtime_decrypt_secret(string $stored): string
{
    $raw = base64_decode($stored, true);
    if ($raw === false || !str_starts_with($raw, 'FPOMSP1')
        || strlen($raw) < 7 + 28 + 1) {
        throw new RuntimeException('Invalid encrypted realtime credential.');
    }
    $plain = openssl_decrypt(
        substr($raw, 35), 'aes-256-gcm', fpoms_realtime_secret_key(),
        OPENSSL_RAW_DATA, substr($raw, 7, 12),
        substr($raw, 19, 16), 'FPOMS-PUSHER-V1'
    );
    if ($plain === false || $plain === '') {
        throw new RuntimeException('Realtime credential integrity verification failed.');
    }
    return $plain;
}

/** A missing table (before first Super Admin setup) safely means no saved config. */
function fpoms_realtime_saved_row(): ?array
{
    try {
        return query_one('SELECT * FROM fpoms_realtime_settings WHERE id=1 LIMIT 1');
    } catch (PDOException $e) {
        // The UI creates the table on first authorized visit. No runtime DDL.
        return null;
    }
}

/** DB settings win over the legacy .env configuration, even when disabled. */
function fpoms_realtime_effective_config(): array
{
    $row = fpoms_realtime_saved_row();
    if ($row !== null) {
        try {
            $secret = !empty($row['secret_ciphertext'])
                ? fpoms_realtime_decrypt_secret((string)$row['secret_ciphertext']) : '';
        } catch (Throwable $e) {
            error_log('FP-OMS realtime settings cannot be decrypted; integration disabled.');
            $secret = '';
        }
        return [
            'enabled' => (int)$row['enabled'] === 1,
            'app_id' => (string)$row['app_id'],
            'key' => (string)$row['app_key'],
            'secret' => $secret,
            'cluster' => (string)$row['cluster'],
            'source' => 'admin',
        ];
    }
    return [
        'enabled' => env_value('PUSHER_ENABLED', 'false') === 'true',
        'app_id' => env_value('PUSHER_APP_ID'),
        'key' => env_value('PUSHER_KEY'),
        'secret' => env_value('PUSHER_SECRET'),
        'cluster' => env_value('PUSHER_CLUSTER', 'ap1'),
        'source' => 'environment',
    ];
}

function fpoms_realtime_validate(string $appId, string $key, string $cluster, string $secret, bool $enabled): array
{
    $errors = [];
    if ($appId !== '' && preg_match('/^[0-9]{1,18}$/D', $appId) !== 1) {
        $errors[] = 'Pusher App ID must contain digits only.';
    }
    if ($key !== '' && preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $key) !== 1) {
        $errors[] = 'Pusher App Key has an invalid format.';
    }
    if (preg_match('/^[a-z0-9-]{2,20}$/D', $cluster) !== 1) {
        $errors[] = 'Select a valid Pusher cluster.';
    }
    if ($secret !== '' && (strlen($secret) > 256
        || preg_match('/^[A-Za-z0-9_-]{12,256}$/D', $secret) !== 1)) {
        $errors[] = 'Pusher Secret must be 12–256 alphanumeric characters.';
    }
    if ($enabled && ($appId === '' || $key === '' || $secret === '')) {
        $errors[] = 'App ID, App Key and a saved App Secret are required before enabling realtime notifications.';
    }
    return $errors;
}

/** POST handler; never accepts keys or secrets from URL parameters. */
function fpoms_realtime_save(array $admin, array $post): array
{
    $appId = trim((string)($post['app_id'] ?? ''));
    $key = trim((string)($post['app_key'] ?? ''));
    $cluster = strtolower(trim((string)($post['cluster'] ?? 'ap1')));
    $newSecret = trim((string)($post['app_secret'] ?? ''));
    $enabled = (string)($post['enabled'] ?? '') === '1';
    try {
        fpoms_realtime_create_table();
        $previous = fpoms_realtime_saved_row();
        $secretAvailable = $newSecret !== '' || !empty($previous['secret_ciphertext']);
        $errors = fpoms_realtime_validate($appId, $key, $cluster,
            $secretAvailable ? 'a_valid_stored_secret' : '', $enabled);
        if ($newSecret !== '') {
            $errors = array_merge($errors, fpoms_realtime_validate($appId, $key, $cluster, $newSecret, false));
        }
        if ($enabled && !$secretAvailable) {
            $errors[] = 'Enter the new rotated Pusher App Secret before enabling.';
        }
        if ($errors) return array_values(array_unique($errors));

        // Never decrypt/re-encrypt an existing secret unless the admin replaces it.
        $ciphertext = $newSecret !== ''
            ? fpoms_realtime_encrypt_secret($newSecret)
            : (string)($previous['secret_ciphertext'] ?? '');
        db()->prepare(
            'INSERT INTO fpoms_realtime_settings
             (id,enabled,app_id,app_key,secret_ciphertext,cluster,updated_by,
              last_tested_at,last_test_ok,last_test_note)
             VALUES (1,?,?,?,?,?,?,NULL,NULL,NULL)
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),
                 app_id=VALUES(app_id),app_key=VALUES(app_key),
                 secret_ciphertext=VALUES(secret_ciphertext),cluster=VALUES(cluster),
                 updated_by=VALUES(updated_by),
                 last_tested_at=NULL,last_test_ok=NULL,last_test_note=NULL'
        )->execute([$enabled ? 1 : 0, $appId, $key, $ciphertext, $cluster, (int)$admin['id']]);
        audit((int)$admin['id'], 'settings.realtime_saved', 'settings', 1, [
            'enabled'=>$enabled, 'cluster'=>$cluster, 'secret_rotated'=>$newSecret !== ''
        ]);
        return [];
    } catch (Throwable $error) {
        error_log('FP-OMS realtime settings save failed (no credentials logged): ' . get_class($error));
        return ['Unable to save settings. Check database permissions and the private encryption key directory.'];
    }
}

/** Real API test: signed Pusher event to the requesting Super Admin's own channel. */
function fpoms_realtime_test_connection(array $admin): array
{
    try {
        fpoms_realtime_create_table();
        $row = fpoms_realtime_saved_row();
        if (!$row || empty($row['app_id']) || empty($row['app_key'])
            || empty($row['secret_ciphertext'])) {
            return ['success'=>false,'message'=>'Save the App ID, App Key and rotated App Secret first.'];
        }
        $config = fpoms_realtime_effective_config();
        if ($config['secret'] === '') return [
            'success'=>false,'message'=>'Saved secret cannot be decrypted. Restore the private key or save a new secret.'
        ];
        $result = fpoms_pusher_send($config, fpoms_pusher_channel('staff',(int)$admin['id']),
            'fpoms-connection-test', ['test'=>true]);
        $success = (bool)$result['success'];
        $message = $success
            ? 'Pusher API accepted the test event. Confirm browser subscription separately.'
            : (string)$result['message'];
        db()->prepare(
            'UPDATE fpoms_realtime_settings
             SET last_tested_at=NOW(),last_test_ok=?,last_test_note=? WHERE id=1'
        )->execute([$success ? 1 : 0, substr($message, 0, 180)]);
        audit((int)$admin['id'], 'settings.realtime_tested', 'settings', 1,
            ['success'=>$success]);
        return ['success'=>$success,'message'=>$message];
    } catch (Throwable $error) {
        error_log('FP-OMS Pusher connection test failed: '.get_class($error));
        return ['success'=>false,'message'=>'Connection test could not complete. Check network, cURL and private key.'];
    }
}

function view_realtime_settings(array $errors = [], string $notice = ''): void
{
    require_role('super_admin');
    $setupError = '';
    try {
        fpoms_realtime_create_table();
    } catch (Throwable $e) {
        $setupError = 'Realtime settings storage is unavailable. If the table is missing, apply database/024_realtime_settings.sql once with an administrator account.';
    }
    $row = fpoms_realtime_saved_row();
    $configured = $row !== null && !empty($row['secret_ciphertext'])
        && !empty($row['app_key']) && !empty($row['app_id']);
    $enabled = $row !== null ? (int)$row['enabled'] === 1 : false;
    $appId = (string)($row['app_id'] ?? '2208663');
    $appKey = (string)($row['app_key'] ?? '56a132864eec4a5608c1');
    $cluster = (string)($row['cluster'] ?? 'ap1');
    $testOk = $row['last_test_ok'] ?? null;
    $tested = (string)($row['last_tested_at'] ?? '');
    $html = '<div class="row g-4"><div class="col-xl-8">'
        . '<div class="panel">'
        . '<div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">'
        . '<div><h2 class="h5 mb-1"><i class="bi bi-broadcast me-2"></i>Realtime Notifications</h2>'
        . '<p class="small text-secondary mb-0">Configure Pusher Channels once in FP-OMS. '
        . 'No server .env editing required.</p></div>'
        . '<span class="badge text-bg-' . ($enabled && $configured ? 'success' : 'secondary') . '">'
        . ($enabled && $configured ? 'Enabled' : 'Not enabled') . '</span></div>'
        . ($setupError !== '' ? '<div class="alert alert-warning">' . e($setupError) . '</div>' : '')
        . errors_html($errors)
        . ($notice !== '' ? '<div class="alert alert-info" role="status">' . e($notice) . '</div>' : '')
        . '<div class="alert alert-warning small"><strong>Security:</strong> Rotate the App Secret previously '
        . 'shared in chat before saving it here. The secret is stored encrypted and never displayed again.</div>'
        . '<form method="post" action="' . e(url('realtime_settings')) . '" autocomplete="off">'
        . csrf_field()
        . '<input type="hidden" name="operation" value="save">'
        . '<div class="row g-3">'
        . '<div class="col-md-6"><label class="form-label" for="pusher_app_id">Pusher App ID</label>'
        . '<input class="form-control" id="pusher_app_id" name="app_id" inputmode="numeric" '
        . 'maxlength="18" required value="' . e($appId) . '"></div>'
        . '<div class="col-md-6"><label class="form-label" for="pusher_app_key">Pusher App Key</label>'
        . '<input class="form-control" id="pusher_app_key" name="app_key" '
        . 'maxlength="128" required value="' . e($appKey) . '"></div>'
        . '<div class="col-md-6"><label class="form-label" for="pusher_cluster">Cluster</label>'
        . '<select class="form-select" id="pusher_cluster" name="cluster">';
    foreach (['ap1'=>'Asia Pacific – Singapore (ap1)','ap2'=>'Asia Pacific – Mumbai (ap2)',
        'ap3'=>'Asia Pacific – Tokyo (ap3)','ap4'=>'Asia Pacific – Sydney (ap4)',
        'us2'=>'US East (us2)','us3'=>'US West (us3)','eu'=>'Europe (eu)','mt1'=>'Montreal (mt1)',
        'sa1'=>'South America (sa1)'] as $value=>$label) {
        $html .= '<option value="' . e($value) . '"'
            . ($cluster === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $html .= '</select></div><div class="col-md-6">'
        . '<label class="form-label" for="pusher_secret">Pusher App Secret</label>'
        . '<input type="password" class="form-control" id="pusher_secret" name="app_secret" '
        . 'autocomplete="new-password" maxlength="256" placeholder="'
        . ($configured ? 'Saved securely — leave blank to keep existing' : 'Paste your newly rotated secret')
        . '"><div class="form-text">'
        . ($configured ? 'A secret is saved; leave blank to retain it.' : 'Required to enable notifications.')
        . '</div></div></div>'
        . '<div class="form-check form-switch mt-3 mb-3">'
        . '<input class="form-check-input" type="checkbox" role="switch" id="realtime_enabled" '
        . 'name="enabled" value="1"' . ($enabled ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="realtime_enabled">'
        . 'Enable Realtime Notifications</label></div>'
        . '<button class="btn btn-success" type="submit"'
        . ($setupError !== '' ? ' disabled' : '') . '>'
        . '<i class="bi bi-floppy me-1"></i>Save Settings</button></form></div></div>'
        . '<div class="col-xl-4"><div class="panel">'
        . '<h3 class="h6"><i class="bi bi-activity me-2"></i>Connection Status</h3>'
        . '<p class="mb-2"><strong>Configuration:</strong> '
        . ($configured ? '<span class="text-success">Credentials saved</span>'
            : '<span class="text-secondary">Not yet configured</span>') . '</p>'
        . '<p class="mb-2"><strong>PHP cURL:</strong> '
        . (function_exists('curl_init') ? '<span class="text-success">Available</span>'
            : '<span class="text-danger">Missing</span>') . '</p>'
        . '<p class="mb-2"><strong>OpenSSL:</strong> '
        . (function_exists('openssl_encrypt') ? '<span class="text-success">Available</span>'
            : '<span class="text-danger">Missing</span>') . '</p>'
        . '<p class="mb-2"><strong>API test:</strong> ';
    if ($testOk === null) {
        $html .= '<span class="text-secondary">Not tested</span>';
    } else {
        $html .= $testOk ? '<span class="text-success">Passed</span>'
            : '<span class="text-danger">Failed</span>';
    }
    $html .= '</p><p class="small text-secondary">'
        . ($tested !== '' ? 'Last tested: ' . e($tested) : 'No test has been run.')
        . '</p><p class="small">' . e((string)($row['last_test_note'] ?? '')) . '</p>'
        . '<p class="small">Browser subscription: '
        . '<span id="realtime-browser-status">'
        . ($enabled && $configured ? 'Waiting for connection' : 'Disabled — enable and save settings first.')
        . '</span></p>'
        . '<form id="realtime-test-form" method="post" action="' . e(url('realtime_settings')) . '">'
        . csrf_field() . '<input type="hidden" name="operation" value="test">'
        . '<button class="btn btn-outline-primary w-100" type="submit"'
        . (!$configured ? ' disabled' : '') . '>'
        . '<i class="bi bi-plug me-1"></i>Test Connection</button></form>'
        . '<div id="realtime-test-result" class="small mt-2" role="status" aria-live="polite"></div>'
        . '<p class="small text-secondary mt-3 mb-0">The Pusher API test checks server credentials '
        . 'and outbound HTTPS. Browser subscription requires this feature to be enabled, '
        . 'a valid session and available WebSocket connectivity.</p></div></div></div>';
    render_page('Realtime Notifications',$html);
}
