<?php
declare(strict_types=1);

/** Persistent authenticated FP draft state (requires additive migration 016). */
function step2_persistent_ready(): bool
{
    if (!step2_drafts_schema_ready()) return false;
    try {
        db()->query('SELECT 1 FROM permit_step2_form_drafts LIMIT 0');
        return true;
    } catch (Throwable $error) {
        error_log('FP-OMS persistent draft schema not installed: '.$error->getMessage());
        return false;
    }
}

function step2_persistent_form(int $clientId, int $scope): ?array
{
    if (!step2_persistent_ready()) return null;
    $select = step2_location_schema_ready()
        ? 'project_title,project_location,project_latitude,project_longitude,purpose'
        : 'project_title,project_location,purpose';
    return query_one(
        'SELECT '.$select.' FROM permit_step2_form_drafts
         WHERE client_id=? AND application_id=?',
        [$clientId,$scope]
    );
}

function step2_persistent_save_fields(array $client, int $scope, array $fields): array
{
    if (!step2_persistent_ready()) return ['error'=>'Apply database migration 016 to save your draft.'];
    if (!step2_draft_scope_allowed($client,$scope)) return ['error'=>'Draft is no longer editable.'];
    $title = (string)($fields['project_title'] ?? '');
    $location = (string)($fields['project_location'] ?? '');
    $purpose = (string)($fields['purpose'] ?? '');
    $lat = (string)($fields['project_latitude'] ?? '');
    $lon = (string)($fields['project_longitude'] ?? '');
    if (strlen($lat)>32 || strlen($lon)>32) {
        return ['error'=>'Coordinate inputs must be at most 32 characters each.'];
    }
    if (strlen($title)>180 || strlen($location)>300 || strlen($purpose)>2000) {
        return ['error'=>'One or more fields exceed the allowed length.'];
    }
    try {
        if (step2_location_schema_ready()) {
            // Drafts intentionally accept partial coordinate typing. Strict validation
            // is applied only at final submission, not at each autosave keystroke.
            db()->prepare(
                'INSERT INTO permit_step2_form_drafts
                 (client_id,application_id,project_title,project_location,
                  project_latitude,project_longitude,purpose)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE project_title=VALUES(project_title),
                 project_location=VALUES(project_location),
                 project_latitude=VALUES(project_latitude),
                 project_longitude=VALUES(project_longitude),purpose=VALUES(purpose)'
            )->execute([(int)$client['id'],$scope,$title,$location,$lat,$lon,$purpose]);
        } else {
            db()->prepare(
                'INSERT INTO permit_step2_form_drafts
                 (client_id,application_id,project_title,project_location,purpose)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE project_title=VALUES(project_title),
                 project_location=VALUES(project_location),purpose=VALUES(purpose)'
            )->execute([(int)$client['id'],$scope,$title,$location,$purpose]);
        }
        return ['saved'=>true];
    } catch (Throwable $error) {
        error_log('FP-OMS persistent draft save error: '.$error->getMessage());
        return ['error'=>'Unable to save draft fields. Please try again.'];
    }
}

/**
 * Explicit Clear Form is the only pre-submission operation that discards the
 * entire applicant draft. Permanent submitted permit documents are untouched.
 */
function step2_persistent_clear(array $client, int $scope): array
{
    if (!step2_persistent_ready()) return ['error'=>'Apply migration 016 first.'];
    if (!step2_draft_scope_allowed($client,$scope)) return ['error'=>'Draft cannot be cleared.'];
    $clientId = (int)$client['id'];
    $pdo = db();
    $paths = [];
    try {
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM user_client WHERE id=? FOR UPDATE')->execute([$clientId]);
        $stmt = $pdo->prepare(
            'SELECT stored_path FROM permit_step2_draft_documents
             WHERE client_id=? AND application_id=? FOR UPDATE'
        );
        $stmt->execute([$clientId,$scope]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $paths[] = (string)$row['stored_path'];
        }
        $pdo->prepare('DELETE FROM permit_step2_draft_documents
                       WHERE client_id=? AND application_id=?')->execute([$clientId,$scope]);
        $pdo->prepare('DELETE FROM permit_step2_form_drafts
                       WHERE client_id=? AND application_id=?')->execute([$clientId,$scope]);
        // For correction screens, an empty draft overrides the pre-populated
        // last-submitted application values after an explicit Clear Form.
        if ($scope > 0) {
            $pdo->prepare(
                'INSERT INTO permit_step2_form_drafts
                 (client_id,application_id,project_title,project_location,purpose)
                 VALUES (?,?,?,?,?)'
            )->execute([$clientId,$scope,'','','']);
        }
        $pdo->commit();
        foreach ($paths as $relative) {
            $file = step2_draft_real_path($relative);
            if ($file !== null) @unlink($file);
        }
        return ['cleared'=>true,'documents'=>[]];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS persistent draft clear error: '.$error->getMessage());
        return ['error'=>'Could not clear the draft. Nothing was intentionally discarded.'];
    }
}

/** Find only PDF draft files belonging to the authenticated applicant/scope. */
function step2_persistent_pdf_row(array $client, int $scope, int $id, bool $lock=false): ?array
{
    if (!step2_persistent_ready() || $id < 1
        || !step2_draft_scope_allowed($client,$scope)) return null;
    return query_one(
        'SELECT id,original_name,stored_path,sha256,file_size,mime_type
         FROM permit_step2_draft_documents WHERE id=? AND client_id=?
         AND application_id=? AND mime_type=?'
        . ($lock ? ' FOR UPDATE' : ''),
        [$id,(int)$client['id'],$scope,'application/pdf']
    );
}

function step2_persistent_send_pdf(array $client, int $scope, int $id): never
{
    $row = step2_persistent_pdf_row($client,$scope,$id);
    $path = $row ? step2_draft_real_path((string)$row['stored_path']) : null;
    if (!$path || !is_file($path)) show_error('Saved PDF not found.',404);
    $actual = fpoms_file_plaintext_sha256($path);
    if (!hash_equals((string)$row['sha256'],$actual)) {
        show_error('Saved PDF failed verification.',409);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="fp-draft-'.$id.'.pdf"');
    header('Content-Length: '.fpoms_file_plaintext_size($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo fpoms_file_read($path);
    exit;
}

/** Replaces only an unsubmitted PDF draft; original remains if the update fails. */
function step2_persistent_replace_pdf(array $client,int $scope,int $id,array $files): array
{
    if (!step2_persistent_ready()) return ['error'=>'Migration 016 is required.'];
    if ($id<1 || !step2_draft_scope_allowed($client,$scope)) {
        return ['error'=>'This draft PDF is not editable.'];
    }
    $info = upload_details($files['attachment'] ?? null,true);
    if (isset($info['error'])) return ['error'=>$info['error']];
    if (($info['mime'] ?? '') !== 'application/pdf') {
        return ['error'=>'Only PDF files can replace an existing PDF draft.'];
    }
    $clientId = (int)$client['id'];
    $pdo = db();
    $newPath = null;
    try {
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM user_client WHERE id=? FOR UPDATE')->execute([$clientId]);
        $old = step2_persistent_pdf_row($client,$scope,$id,true);
        if (!$old) {
            $pdo->rollBack();
            return ['error'=>'Saved PDF not found. Refresh your draft.'];
        }
        $folder = 'fp_step2_drafts/'.$clientId;
        $absoluteFolder = PROJECT_ROOT.'/storage/'.$folder;
        if (!is_dir($absoluteFolder) &&
            !mkdir($absoluteFolder,0700,true) && !is_dir($absoluteFolder)) {
            throw new RuntimeException('Private PDF draft storage not available.');
        }
        $relative = $folder.'/'.bin2hex(random_bytes(20)).'.pdf';
        $newPath = PROJECT_ROOT.'/storage/'.$relative;
        fpoms_file_store_upload((string)$info['file']['tmp_name'],$newPath);
        @chmod($newPath,0600);
        $hash = fpoms_file_plaintext_sha256($newPath);
        $pdo->prepare(
            'UPDATE permit_step2_draft_documents
             SET stored_path=?,mime_type=?,file_size=?,sha256=?
             WHERE id=? AND client_id=? AND application_id=?'
        )->execute([$relative,'application/pdf',(int)$info['size'],$hash,$id,$clientId,$scope]);
        $pdo->commit();
        $oldPath = step2_draft_real_path((string)$old['stored_path']);
        if ($oldPath !== null) @unlink($oldPath);
        return ['documents'=>step2_draft_public_list($clientId,$scope)];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($newPath && is_file($newPath)) @unlink($newPath);
        error_log('FP-OMS draft PDF replacement failed: '.$error->getMessage());
        return ['error'=>'Unable to save rearranged PDF. Your previous saved PDF was preserved.'];
    }
}
