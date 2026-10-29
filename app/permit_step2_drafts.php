<?php
declare(strict_types=1);

/** Private FP supporting-document drafts, distinct from registration drafts. */
function step2_drafts_schema_ready(): bool
{
    try {
        db()->query('SELECT 1 FROM permit_step2_draft_documents LIMIT 0');
        return true;
    } catch (Throwable $error) {
        error_log('FP Step 2 draft schema unavailable: '.$error->getMessage());
        return false;
    }
}

function step2_draft_hash(bool $create = true): ?string
{
    $token = $_SESSION['permit_step2_draft_token'] ?? null;
    if ((!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) && $create) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['permit_step2_draft_token'] = $token;
    }
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/D',$token)
        ? hash('sha256',$token) : null;
}

function step2_draft_scope_allowed(array $client, int $applicationId): bool
{
    if (($client['user_kind'] ?? '') !== 'applicant'
        || ($client['status'] ?? '') !== 'active' || $applicationId < 0) return false;
    if ($applicationId === 0) return true;
    return query_one(
        'SELECT id FROM permit_step2_applications
         WHERE id=? AND client_id=? AND status=?',
        [$applicationId,(int)$client['id'],'corrections_required']
    ) !== null;
}

function step2_draft_list(int $clientId, int $applicationId, bool $lock = false): array
{
    $persistent = step2_persistent_ready();
    $hash = $persistent ? null : step2_draft_hash(false);
    if (!$persistent && $hash === null) return [];
    $stmt = db()->prepare(
        'SELECT id,client_id,application_id,'
        . (step2_category_schema_ready() ? 'requirement_type,' : "'other' AS requirement_type,")
        . 'original_name,stored_path,mime_type,
                file_size,sha256,expires_at FROM permit_step2_draft_documents
         WHERE client_id=? AND application_id=?'
        . ($persistent ? '' : ' AND draft_hash=? AND expires_at>NOW()')
        . ' ORDER BY id ASC' . ($lock ? ' FOR UPDATE' : '')
    );
    $params = [$clientId,$applicationId];
    if (!$persistent) $params[] = $hash;
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function step2_draft_public_list(int $clientId, int $applicationId): array
{
    return array_map(static fn(array $d): array => [
        'id'=>(int)$d['id'],'original_name'=>(string)$d['original_name'],
        'size'=>(int)$d['file_size'],'mime_type'=>(string)$d['mime_type'],
        'requirement_type'=>(string)($d['requirement_type'] ?? 'other'),
        'requirement_label'=>step2_requirement_label((string)($d['requirement_type'] ?? 'other'))
    ],step2_draft_list($clientId,$applicationId));
}

/** Only allow randomized file names inside the intended private staging directory. */
function step2_draft_real_path(string $relative): ?string
{
    if (!preg_match('#^fp_step2_drafts/[0-9]+/[a-f0-9]{40}\.(pdf|jpg|png)$#D',$relative)) return null;
    $base = realpath(PROJECT_ROOT.'/storage/fp_step2_drafts');
    $path = realpath(PROJECT_ROOT.'/storage/'.$relative);
    return $base && $path && str_starts_with($path,$base.DIRECTORY_SEPARATOR)
        && is_file($path) ? $path : null;
}

/** A visible list even before migration 015, without falsely claiming files were saved. */
function step2_direct_selected_files_html(): string
{
    return '<section class="registration-selected-files" id="step2-selected-files" aria-live="polite">'
        . '<div class="selected-files-heading"><span>SELECTED FILES</span>'
        . '<span class="selected-files-total" data-step2-count>0</span></div>'
        . '<p class="selected-files-guidance">Selected files will be uploaded when you submit the '
        . 'application. They are not yet saved and will be lost if you refresh or leave this page. '
        . 'For refresh-safe saved uploads, the ICT administrator must install migration 015.</p>'
        . '<div class="selected-upload-queue" data-step2-queue></div>'
        . '<ul class="selected-file-list" data-step2-items></ul>'
        . '<p class="selected-files-empty" data-step2-empty>'
        . 'No files selected yet. Choose files above to add them.</p></section>';
}

/** Server-rendered queue allows a page refresh to restore saved-file cards. */
function step2_draft_list_html(array $docs, bool $persistent=false): string
{
    $html = '<section class="registration-selected-files" id="step2-selected-files" aria-live="polite">'
        . '<div class="selected-files-heading"><span>SELECTED FILES</span>'
        . '<span class="selected-files-total" data-step2-count>'.count($docs).'</span></div>'
        . '<p class="selected-files-guidance">'
        . ($persistent
            ? 'Saved documents remain in your applicant draft across logins until you submit '
                . 'or explicitly clear/remove them.'
            : 'Saved files stay available after refresh in the same browser session. '
                . 'Unsubmitted uploads expire in 24 hours.')
        . '</p>'
        . '<div class="selected-upload-queue" data-step2-queue></div>'
        . '<ul class="selected-file-list" data-step2-items>';
    foreach ($docs as $doc) {
        $name = e((string)$doc['original_name']);
        $html .= '<li class="selected-file-card is-saved" data-step2-draft-id="'.(int)$doc['id'].'">'
            . '<span class="selected-file-icon" aria-hidden="true"><i class="bi bi-file-earmark-text-fill"></i></span>'
            . '<div class="selected-file-info"><strong class="selected-file-name">'.$name.'</strong>'
            . '<span class="selected-file-status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> '
            . 'Saved — ready for submission · '
            . e(step2_requirement_label((string)($doc['requirement_type'] ?? 'other')))
            . '</span></div>'
            . ($persistent && (string)($doc['mime_type'] ?? '') === 'application/pdf'
                ? '<button class="btn btn-sm btn-outline-primary me-1" type="button" '
                    . 'data-step2-rearrange aria-label="Rearrange pages again in '
                    . $name . '"><i class="bi bi-files me-1" aria-hidden="true"></i>'
                    . 'Rearrange Pages</button>'
                : '')
            . '<button class="selected-file-remove" type="button" data-step2-remove '
            . 'aria-label="Remove saved attachment: '.$name.'" title="Remove file">'
            . '<i class="bi bi-x-lg" aria-hidden="true"></i></button>'
            . '<progress class="selected-file-progress" max="100" value="100" '
            . 'aria-label="Upload complete">100%</progress></li>';
    }
    return $html.'</ul><p class="selected-files-empty" data-step2-empty'
        . ($docs ? ' hidden' : '')
        . '>No saved files yet. Select files above to begin uploading.</p></section>';
}

function step2_draft_upload(array $client, int $applicationId, array $files,
    string $category = ''): array
{
    if (!step2_category_schema_ready()) {
        return ['error'=>'Please ask the ICT administrator to install Step 2 document migration 019.'];
    }
    if (!step2_requirement_valid($category)) {
        return ['error'=>'Select the correct FP documentary requirement before uploading.'];
    }
    if (!step2_drafts_schema_ready()) return ['error'=>'FP draft storage unavailable; apply migration 015.'];
    if (!step2_draft_scope_allowed($client,$applicationId)) return ['error'=>'Application not available for upload.'];
    $info = upload_details($files['attachment'] ?? null,true);
    if (isset($info['error'])) return ['error'=>$info['error']];
    $persistent = step2_persistent_ready();
    $hash = step2_draft_hash();
    if ($hash === null) return ['error'=>'Upload session expired. Refresh the page.'];
    $clientId = (int)$client['id'];
    $pdo = db();
    $stored = null;
    try {
        $pdo->beginTransaction();
        // Serialize uploads for this applicant to enforce the ten-file limit.
        $pdo->prepare('SELECT id FROM user_client WHERE id=? FOR UPDATE')->execute([$clientId]);
        if (count(step2_draft_list($clientId,$applicationId,true)) >= 10) {
            $pdo->rollBack();
            return ['error'=>'Maximum 10 saved supporting documents. Remove a file first.'];
        }
        $dirRelative = 'fp_step2_drafts/'.$clientId;
        $dir = PROJECT_ROOT.'/storage/'.$dirRelative;
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create private draft directory.');
        }
        $relative = $dirRelative.'/'.bin2hex(random_bytes(20)).'.'.$info['extension'];
        $stored = PROJECT_ROOT.'/storage/'.$relative;
        if (!move_uploaded_file((string)$info['file']['tmp_name'],$stored)) {
            throw new RuntimeException('Could not store uploaded FP draft.');
        }
        @chmod($stored,0600);
        $sha = hash_file('sha256',$stored);
        if ($sha === false) throw new RuntimeException('Could not hash draft file.');
        $pdo->prepare(
            'INSERT INTO permit_step2_draft_documents
            (client_id,draft_hash,application_id,requirement_type,original_name,stored_path,mime_type,file_size,sha256,expires_at)
            VALUES (?,?,?,?,?,?,?,?,?,' . ($persistent ? 'NULL' : 'DATE_ADD(NOW(),INTERVAL 24 HOUR)') . ')'
        )->execute([
            $clientId,$hash,$applicationId,$category,$info['name'],$relative,
            $info['mime'],$info['size'],$sha
        ]);
        $pdo->commit();
        return ['documents'=>step2_draft_public_list($clientId,$applicationId)];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($stored !== null && is_file($stored)) @unlink($stored);
        error_log('FP Step 2 draft upload failed: '.$error->getMessage());
        return ['error'=>'Could not securely save the selected file. Try again.'];
    }
}

function step2_draft_delete(array $client, int $applicationId, int $id): array
{
    if (!step2_drafts_schema_ready()) return ['error'=>'FP draft storage unavailable.'];
    if ($id < 1 || !step2_draft_scope_allowed($client,$applicationId)) {
        return ['error'=>'Saved attachment not found.'];
    }
    $persistent = step2_persistent_ready();
    $hash = $persistent ? null : step2_draft_hash(false);
    if (!$persistent && $hash === null) return ['error'=>'Saved attachment not found.'];
    $clientId = (int)$client['id'];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT stored_path FROM permit_step2_draft_documents WHERE
             id=? AND client_id=? AND application_id=?'
            . ($persistent ? '' : ' AND draft_hash=? AND expires_at>NOW()')
            . ' FOR UPDATE'
        );
        $params = [$id,$clientId,$applicationId];
        if (!$persistent) $params[] = $hash;
        $stmt->execute($params);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            $pdo->rollBack();
            return ['error'=>'Saved attachment not found.'];
        }
        $pdo->prepare('DELETE FROM permit_step2_draft_documents WHERE id=?')->execute([$id]);
        $pdo->commit();
        $path = step2_draft_real_path((string)$doc['stored_path']);
        if ($path) @unlink($path);
        return ['documents'=>step2_draft_public_list($clientId,$applicationId)];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP Step 2 draft deletion failed: '.$error->getMessage());
        return ['error'=>'Could not remove this saved attachment.'];
    }
}

/** Lock and verify staged files as part of the final submission transaction. */
function step2_draft_submission(int $clientId, int $applicationId): array
{
    $docs = step2_draft_list($clientId,$applicationId,true);
    $errors = [];
    if ($applicationId === 0 && !$docs) $errors[] = 'Upload at least one FP supporting document.';
    if (count($docs) > 10) $errors[] = 'Maximum 10 supporting files.';
    foreach ($docs as $doc) {
        $path = step2_draft_real_path((string)$doc['stored_path']);
        if (!$path
            || !in_array((string)$doc['mime_type'],['application/pdf','image/jpeg','image/png'],true)
            || filesize($path) !== (int)$doc['file_size']
            || !hash_equals((string)$doc['sha256'],(string)hash_file('sha256',$path))) {
            $errors[] = 'A saved supporting document is unavailable or modified. Remove it and upload again.';
        }
    }
    return ['rows'=>$docs,'errors'=>$errors];
}

function step2_draft_json(array $response, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($response,JSON_THROW_ON_ERROR);
    exit;
}
