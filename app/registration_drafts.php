<?php
declare(strict_types=1);

/**
 * Anonymous registration attachments are staged outside /public before the final
 * registration is posted. A cryptographically random token exists only in the
 * server-side PHP session. The browser receives only opaque document IDs.
 */
function registration_draft_hash(bool $create = true): ?string
{
    $token = $_SESSION['registration_draft_token'] ?? null;
    if ((!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) && $create) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['registration_draft_token'] = $token;
    }
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)
        ? hash('sha256', $token) : null;
}

function registration_draft_list(): array
{
    $hash = registration_draft_hash(false);
    if ($hash === null) return [];
    $category = registration_requirements_schema_ready()
        ? 'requirement_type,' : '';
    return query_all(
        'SELECT id,document_type,id_type,'.$category.'original_name,mime_type,file_size,sha256,stored_path,expires_at
         FROM registration_draft_documents
         WHERE draft_hash=? AND expires_at>NOW()
         ORDER BY id ASC', [$hash]
    );
}

function registration_draft_count(array $documents, string $type): int
{
    return count(array_filter($documents,
        static fn(array $row): bool => ($row['document_type'] ?? '') === $type
    ));
}

function registration_draft_public_list(): array
{
    return array_map(static fn(array $row): array => [
        'id'=>(int)$row['id'],
        'document_type'=>(string)$row['document_type'],
        'id_type'=>$row['id_type'] === null ? null : (string)$row['id_type'],
        'requirement_type'=>(string)($row['requirement_type'] ?? 'other'),
        'id_label'=>($row['document_type'] === 'valid_id'
            ? registration_id_type_label((string)$row['id_type'])
            : registration_requirement_label((string)($row['requirement_type'] ?? 'other'))),
        'original_name'=>(string)$row['original_name'],
        'size'=>(int)$row['file_size'],
    ], registration_draft_list());
}

/**
 * Render one upload list immediately below its respective file input controls.
 * The ID and Supporting Document sections have independent progress queues and
 * counters but share the same server-side session-bound draft records.
 */
function registration_draft_list_html(string $kind = 'id'): string
{
    if (!in_array($kind, ['id','supporting'], true)) {
        throw new InvalidArgumentException('Unknown registration attachment category.');
    }
    $type = $kind === 'id' ? 'valid_id' : 'supporting_document';
    $docs = array_values(array_filter(
        registration_draft_public_list(),
        static fn(array $row): bool => $row['document_type'] === $type
    ));
    $id = $kind === 'id' ? 'registration-draft-list' : 'registration-supporting-draft-list';
    $html = '<section class="registration-selected-files" id="' . $id
        . '" data-draft-list-kind="' . $kind . '" aria-live="polite">'
        . '<div class="selected-files-heading"><span>SELECTED FILES</span>'
        . '<span class="selected-files-total" data-draft-count>' . count($docs) . '</span></div>'
        . '<p class="selected-files-guidance">Saved files stay available after refresh in the same '
        . 'browser session. Unsubmitted uploads expire in 24 hours.</p>'
        . '<div class="selected-upload-queue" data-upload-queue></div>'
        . '<ul class="selected-file-list" data-draft-items>';
    foreach ($docs as $doc) {
        $html .= '<li class="selected-file-card is-saved" data-draft-document data-draft-type="'
            . e($doc['document_type']) . '" data-draft-id="' . (int)$doc['id'] . '" data-draft-requirement="'
            . e((string)($doc['requirement_type'] ?? 'other')) . '">'
            . '<span class="selected-file-icon" aria-hidden="true"><i class="bi bi-file-earmark-text-fill"></i></span>'
            . '<div class="selected-file-info"><strong class="selected-file-name">'
            . e($doc['original_name']) . '</strong>'
            . '<span class="selected-file-status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> '
            . 'Saved — ready for submission · ' . e($doc['id_label']) . '</span></div>'
            . '<button class="selected-file-remove" type="button" data-remove-draft '
            . 'aria-label="Remove saved attachment: ' . e($doc['original_name']) . '" title="Remove file">'
            . '<i class="bi bi-x-lg" aria-hidden="true"></i></button>'
            . '<progress class="selected-file-progress" max="100" value="100" '
            . 'aria-label="Upload complete">100%</progress></li>';
    }
    return $html . '</ul>'
        . '<p class="selected-files-empty" data-draft-empty'
        . ($docs ? ' hidden' : '') . '>No saved files yet. Select a file above to begin uploading.</p>'
        . '</section>';
}

/** Never accept arbitrary paths from a browser or a forged SQL response. */
function registration_draft_real_path(string $relative): ?string
{
    if (!str_starts_with($relative, 'registration_drafts/')
        || str_contains($relative, '..') || str_contains($relative, '\\')) return null;
    $root = realpath(PROJECT_ROOT . '/storage/registration_drafts');
    $full = realpath(PROJECT_ROOT . '/storage/' . $relative);
    return $root && $full && str_starts_with($full, $root . DIRECTORY_SEPARATOR)
        && is_file($full) ? $full : null;
}

function registration_draft_upload(array $post, array $files): array
{
    if (!registration_requirements_schema_ready()) {
        return ['error'=>'Registration requirements need database migration 018. Contact the ICT administrator.'];
    }
    $kind = (string)($post['document_type'] ?? '');
    if (!in_array($kind, ['valid_id','supporting_document'], true)) {
        return ['error'=>'Choose the correct attachment category.'];
    }
    $idType = trim((string)($post['id_type'] ?? ''));
    if ($kind === 'valid_id' && !isset(registration_id_type_choices()[$idType])) {
        return ['error'=>'Select a valid ID type before uploading.'];
    }
    if ($kind === 'supporting_document') $idType = '';
    $category = (string)($post['requirement_type'] ?? 'other');
    if ($kind === 'supporting_document' && !isset(registration_requirement_choices()[$category])) {
        return ['error'=>'Select a valid Supporting Document category before uploading.'];
    }
    if ($kind === 'valid_id') $category = 'other';

    $info = upload_details($files['attachment'] ?? null, true);
    if (isset($info['error'])) return ['error'=>$info['error']];
    $hash = registration_draft_hash();
    if ($hash === null) return ['error'=>'Your registration session expired. Refresh and retry.'];

    $ipHash = hash('sha256', client_ip());
    $pdo = db();
    $stored = null;
    try {
        $pdo->beginTransaction();
        $docs = registration_draft_list();
        if (registration_draft_count($docs,$kind) >= ($kind === 'valid_id' ? 5 : 10) || count($docs) >= 15) {
            $pdo->rollBack();
            return ['error'=>'The maximum is 5 Valid IDs and 10 supporting documents. Remove a saved file first.'];
        }
        // Limit anonymous file storage abuse in addition to 5 MB/file and 10/session.
        $recent = query_one(
            'SELECT COUNT(*) AS n FROM registration_draft_documents
             WHERE ip_hash=? AND created_at>=DATE_SUB(NOW(), INTERVAL 1 DAY)', [$ipHash]
        );
        if ((int)($recent['n'] ?? 0) >= 100) {
            $pdo->rollBack();
            return ['error'=>'Upload limit reached for this connection. Try again later.'];
        }

        $folder = 'registration_drafts/' . substr($hash, 0, 12);
        $absoluteDir = PROJECT_ROOT . '/storage/' . $folder;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir,0700,true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Private draft storage is unavailable.');
        }
        $relative = $folder . '/' . bin2hex(random_bytes(22)) . '.' . $info['extension'];
        $stored = PROJECT_ROOT . '/storage/' . $relative;
        if (!move_uploaded_file((string)$info['file']['tmp_name'], $stored)) {
            throw new RuntimeException('The selected attachment could not be saved.');
        }
        @chmod($stored,0600);
        $digest = hash_file('sha256',$stored);
        $pdo->prepare(
            'INSERT INTO registration_draft_documents
              (draft_hash,document_type,id_type,requirement_type,original_name,stored_path,mime_type,file_size,sha256,ip_hash,expires_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 24 HOUR))'
        )->execute([
            $hash,$kind,$kind === 'valid_id' ? $idType : null,$category,$info['name'],
            $relative,$info['mime'],$info['size'],$digest,$ipHash
        ]);
        $pdo->commit();
        return ['documents'=>registration_draft_public_list()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($stored !== null && is_file($stored)) @unlink($stored);
        error_log('FP-OMS draft upload failure: ' . $e->getMessage());
        return ['error'=>'Attachment could not be saved. Try again.'];
    }
}

function registration_draft_delete(int $id): array
{
    $hash = registration_draft_hash(false);
    if ($hash === null || $id < 1) return ['error'=>'Saved attachment not found.'];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $row = query_one(
            'SELECT stored_path FROM registration_draft_documents WHERE id=? AND draft_hash=? FOR UPDATE',
            [$id,$hash]
        );
        if (!$row) {
            $pdo->rollBack();
            return ['error'=>'Saved attachment not found.'];
        }
        $pdo->prepare('DELETE FROM registration_draft_documents WHERE id=? AND draft_hash=?')
            ->execute([$id,$hash]);
        $pdo->commit();
        $path = registration_draft_real_path((string)$row['stored_path']);
        if ($path) @unlink($path);
        return ['documents'=>registration_draft_public_list()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS draft removal failure: '.$e->getMessage());
        return ['error'=>'Could not remove the saved attachment.'];
    }
}

/** Remove every staged attachment when the applicant explicitly clears the form. */
function registration_draft_clear(): array
{
    try {
        registration_draft_consume();
        return ['documents'=>[]];
    } catch (Throwable $e) {
        error_log('FP-OMS draft clear failure: '.$e->getMessage());
        return ['error'=>'Could not clear the registration form.'];
    }
}

/** Detect raw file inputs still pending AJAX upload at final submit time. */
function registration_draft_has_unstaged_files(array $files): bool
{
    foreach (['valid_id','supporting_document','id_files','supporting_files'] as $field) {
        if (!isset($files[$field]) || !is_array($files[$field])) continue;
        $errors = $files[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
        if (is_array($errors)) {
            foreach ($errors as $status) {
                if ((int)$status !== UPLOAD_ERR_NO_FILE) return true;
            }
        } elseif ((int)$errors !== UPLOAD_ERR_NO_FILE) {
            return true;
        }
    }
    return false;
}

/** Draft files have already passed the same MIME, size and ID-type checks. */
function registration_draft_submission(): array
{
    if (!registration_requirements_schema_ready()) {
        return ['items'=>[], 'errors'=>['Database migration 018 is needed before registration submission.']];
    }
    $items = [];
    $errors = [];
    $docs = registration_draft_list();
    if (registration_draft_count($docs, 'valid_id') < 1) {
        $errors[] = 'Upload at least one valid ID to complete registration.';
    }
    if (registration_draft_count($docs,'valid_id') > 5
        || registration_draft_count($docs,'supporting_document') > 10) {
        $errors[] = 'Too many saved registration attachments.';
    }
    $extensions = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];
    foreach ($docs as $doc) {
        $path = registration_draft_real_path((string)$doc['stored_path']);
        $mime = (string)$doc['mime_type'];
        if (!$path || !isset($extensions[$mime]) || filesize($path) !== (int)$doc['file_size']
            || !hash_equals((string)$doc['sha256'],(string)hash_file('sha256',$path))) {
            $errors[] = 'One saved attachment is unavailable or changed. Remove it and upload again.';
            continue;
        }
        if ($doc['document_type'] === 'valid_id'
            && !isset(registration_id_type_choices()[(string)$doc['id_type']])) {
            $errors[] = 'One saved Valid ID has an invalid ID Type.';
            continue;
        }
        $category = (string)($doc['requirement_type'] ?? 'other');
        if (!isset(registration_requirement_choices()[$category])) {
            $errors[] = 'One supporting document has an invalid category.';
            continue;
        }
        $items[] = [
            'document_type'=>(string)$doc['document_type'],
            'id_type'=>$doc['id_type'] === null ? null : (string)$doc['id_type'],
            'requirement_type'=>$category,
            'info'=>[
                'draft_path'=>$path,'extension'=>$extensions[$mime],
                'name'=>(string)$doc['original_name'],
                'mime'=>$mime,'size'=>(int)$doc['file_size']
            ]
        ];
    }
    return ['items'=>$items,'errors'=>$errors];
}

/** After COMMIT only: DB rows become permanent; draft originals are removed. */
function registration_draft_consume(): void
{
    $hash = registration_draft_hash(false);
    if ($hash === null) return;
    $rows = registration_draft_list();
    db()->prepare('DELETE FROM registration_draft_documents WHERE draft_hash=?')->execute([$hash]);
    unset($_SESSION['registration_draft_token']);
    foreach ($rows as $row) {
        $path = registration_draft_real_path((string)$row['stored_path']);
        if ($path) @unlink($path);
    }
}

/** Used by the HTTP router; generic errors never expose filesystem locations. */
function registration_draft_json(array $result, int $http = 200): never
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}
