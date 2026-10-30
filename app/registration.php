<?php
declare(strict_types=1);

function upload_details(?array $file, bool $required): array
{
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $required ? ['error' => 'A valid ID attachment is required.'] : ['none' => true];
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        return ['error' => 'File upload did not complete. Please try again.'];
    }
    $max = max(1, (int)env_value('MAX_UPLOAD_BYTES', '5242880'));
    if ((int)$file['size'] < 1 || (int)$file['size'] > $max) {
        return ['error' => 'Each file must be between 1 byte and ' . round($max / 1048576, 1) . ' MB.'];
    }
    $type = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowed[$type])) return ['error' => 'Only PDF, JPG and PNG files are permitted.'];
    if ($type === 'application/pdf') {
        $handle = fopen((string)$file['tmp_name'], 'rb');
        $magic = $handle ? fread($handle, 5) : '';
        if ($handle) fclose($handle);
        if ($magic !== '%PDF-') return ['error' => 'The PDF attachment has an invalid header.'];
    } elseif (@getimagesize((string)$file['tmp_name']) === false) {
        return ['error' => 'The image attachment is invalid.'];
    }
    return [
        'file' => $file, 'mime' => $type, 'extension' => $allowed[$type],
        'name' => substr(basename(str_replace('\\', '/', (string)$file['name'])), 0, 220),
        'size' => (int)$file['size']
    ];
}

function store_reg_document(PDO $pdo, int $registrationId, int $userId, int $revision, string $type, array $info, ?string $idType = null, string $requirementType = 'other'): string
{
    $relative = 'registrations/' . $userId . '/' . bin2hex(random_bytes(18)) . '.' . $info['extension'];
    $absolute = PROJECT_ROOT . '/storage/' . $relative;
    if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0700, true) && !is_dir(dirname($absolute))) {
        throw new RuntimeException('Storage directory could not be created.');
    }
    if (isset($info['draft_path'])) {
        $safePath = realpath((string)$info['draft_path']);
        $draftRoot = realpath(PROJECT_ROOT . '/storage/registration_drafts');
        if (!$safePath || !$draftRoot
            || !str_starts_with($safePath, $draftRoot . DIRECTORY_SEPARATOR)
            || !is_file($safePath) || !is_readable($safePath)) {
            throw new RuntimeException('Saved registration attachment could not be copied.');
        }
        fpoms_file_copy_encrypted($safePath, $absolute);
    } else {
        fpoms_file_store_upload((string)$info['file']['tmp_name'], $absolute);
    }
    chmod($absolute, 0600);
    $digest = fpoms_file_plaintext_sha256($absolute);
    try {
        $stmt = $pdo->prepare('INSERT INTO registration_documents (registration_id,document_type,id_type,requirement_type,original_name,stored_path,mime_type,file_size,sha256,revision) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$registrationId,$type,$idType,$requirementType,$info['name'],$relative,$info['mime'],$info['size'],$digest,$revision]);
        return $absolute;
    } catch (Throwable $error) {
        @unlink($absolute);
        throw $error;
    }
}

function registration_input(): array
{
    return [
        'representative_name' => safe_post('representative_name', 180),
        'representative_position' => safe_post('representative_position', 150),
        'company_name' => safe_post('company_name', 220),
        'organization_type' => safe_post('organization_type', 32),
        'company_address' => safe_post('company_address', 2000),
        'contact_number' => safe_post('contact_number', 40)
    ];
}

function register_applicant(array $values, string $email, string $password, string $confirmation, array $files, array $attachmentInput = []): array
{
    $errors = registration_validation($values);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $errors[] = 'Enter a valid email address.';
    if ($message = check_password_strength($password)) $errors[] = $message;
    if ($password !== $confirmation) $errors[] = 'Passwords do not match.';
    // When AJAX draft files exist, use ONLY those validated, session-owned files.
    // Reject pending unsaved inputs rather than silently ignoring new file selections.
    $hasDrafts = count(registration_draft_list()) > 0;
    if ($hasDrafts) {
        $attachments = registration_draft_submission();
        if (registration_draft_has_unstaged_files($files)) {
            $errors[] = 'One selected attachment was not saved yet. Upload it before submitting.';
        }
    } else {
        // Graceful fallback for browsers without JavaScript.
        $attachments = registration_attachment_batch($attachmentInput, $files, true);
    }
    $errors = array_merge($errors, $attachments['errors'],
        registration_requirement_errors((string)($values['organization_type'] ?? ''),
            $attachments['items']));
    if ($errors) return $errors;

    // Explain missing SQL 011/013 instead of masking it as a generic DB failure.
    try {
        $missing = registration_schema_issues();
    } catch (Throwable $error) {
        $ref = registration_failure_reference('schema-preflight', $error);
        return ['Registration service is temporarily unavailable. '
            . 'Please contact the administrator with reference ' . $ref . '.'];
    }
    if ($missing) {
        $ref = registration_failure_reference('migrations-011-013', null, $missing);
        return ['Registration database setup is incomplete. The system administrator must '
            . 'check SQL migrations 011, 013 and 018. Reference: ' . $ref . '.'];
    }
    $pdo = db();
    $stored = [];
    $initialOtp = null;
    $stage = 'begin-transaction';
    try {
        $pdo->beginTransaction();
        if (email_assigned_to_other_account(strtolower(trim($email)), 'applicant')) {
            $pdo->rollBack();
            return ['This email is already registered. Use your existing account or choose another email.'];
        }
        $stage = 'client-account';
        $stmt = $pdo->prepare("INSERT INTO user_client (name,email,password_hash,user_kind,status) VALUES (?,?,?,'applicant','pending_email_verification')");
        $stmt->execute([$values['representative_name'],strtolower(trim($email)),password_hash($password, PASSWORD_DEFAULT)]);
        $uid = (int)$pdo->lastInsertId();
        $stage = 'client-profile';
        initialize_profile_account_fields($uid, 'applicant');
        // Client identity is inferred from user_client, not DENR user_roles.
        $stage = 'applicant-registration';
        $stmt = $pdo->prepare('INSERT INTO applicant_registrations (client_id,company_name,organization_type,company_address,representative_name,representative_position,contact_number) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$uid,$values['company_name'],$values['organization_type'],$values['company_address'],$values['representative_name'],$values['representative_position'] ?: null,$values['contact_number']]);
        $regId = (int)$pdo->lastInsertId();
        $stage = 'registration-documents';
        foreach ($attachments['items'] as $attachment) {
            $stored[] = store_reg_document(
                $pdo, $regId, $uid, 1, $attachment['document_type'],
                $attachment['info'], $attachment['id_type'],
                (string)($attachment['requirement_type'] ?? 'other')
            );
        }
        $stage = 'verification-link-create';
        $initialOtp = registration_otp_create_initial($pdo, $uid);
        $stage = 'client-audit';
        audit_client($uid,'registration.submitted','registration',$regId,[
            'revision'=>1,'email_verification'=>'pending'
        ]);
        // FUU review and acknowledgment are deferred until the client confirms the email link.
        $stage = 'commit-registration';
        $pdo->commit();
        // Only after DB commit: purge temporary upload originals, not permanent copies.
        if ($hasDrafts) {
            try {
                registration_draft_consume();
            } catch (Throwable $draftError) {
                error_log('Registration draft cleanup after commit: ' . $draftError->getMessage());
            }
        }
        // This marker allows resending a verification link only: it does not log in the client.
        session_regenerate_id(true);
        unset($_SESSION['user_id'], $_SESSION['client_id']);
        $_SESSION['registration_pending_client_id'] = $uid;
        registration_otp_deliver(
            $uid, strtolower(trim($email)),
            (string)$values['representative_name'], (string)$initialOtp
        );
        return [];
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($stored as $path) @unlink($path);
        $ref = registration_failure_reference($stage, $ex);
        // SQLSTATE 23000 also covers foreign-key failures; only MySQL 1062
        // actually means duplicate key.
        if ((int)($ex->errorInfo[1] ?? 0) === 1062) {
            return ['An account or registration using this information already exists. '
                . 'Please sign in or contact the administrator. Reference: ' . $ref . '.'];
        }
        return ['Registration could not be saved. Please contact the system administrator '
            . 'with reference ' . $ref . '.'];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($stored as $path) @unlink($path);
        $ref = registration_failure_reference($stage, $ex);
        return ['Registration could not be saved. Please contact the system administrator '
            . 'with reference ' . $ref . '.'];
    }
}

function registration_for_user(int $uid): ?array
{
    return query_one('SELECT * FROM applicant_registrations WHERE client_id=?', [$uid]);
}

function latest_review(int $registrationId): ?array
{
    return query_one('SELECT rr.*, u.name AS reviewer_name FROM registration_reviews rr JOIN users u ON u.id=rr.reviewer_user_id WHERE rr.registration_id=? ORDER BY rr.id DESC LIMIT 1',[$registrationId]);
}

function registration_documents(int $registrationId): array
{
    // Show every uploaded file, not only the latest one per document type.
    // Immutable earlier revisions remain available to applicants and LPDD-FUS.
    return query_all('SELECT * FROM registration_documents WHERE registration_id=? ORDER BY revision DESC, id DESC', [$registrationId]);
}

function revise_registration(array $user, array $values, array $files, array $attachmentInput = []): array
{
    $errors = registration_validation($values);
    $attachments = registration_attachment_batch($attachmentInput, $files, false);
    $errors = array_merge($errors, $attachments['errors']);
    if ($errors) return $errors;
    $pdo = db();
    $stored = [];
    try {
        $pdo->beginTransaction();
        $locked = query_one('SELECT * FROM user_client WHERE id=? FOR UPDATE', [(int)$user['id']]);
        if (!$locked || $locked['status'] !== 'needs_correction') {
            $pdo->rollBack();
            return ['Registration may be resubmitted only when corrections are required.'];
        }
        $reg = registration_for_user((int)$user['id']);
        if (!$reg) throw new RuntimeException('Missing registration record.');
        $existingDocs = registration_documents((int)$reg['id']);
        $missingDocs = registration_requirement_errors(
            (string)$values['organization_type'],array_merge($existingDocs,$attachments['items'])
        );
        if ($missingDocs) {
            $pdo->rollBack();
            return $missingDocs;
        }
        $revision = (int)$reg['revision'] + 1;
        $pdo->prepare('UPDATE applicant_registrations SET company_name=?,organization_type=?,company_address=?,representative_name=?,representative_position=?,contact_number=?,revision=?,submitted_at=NOW() WHERE id=?')
            ->execute([$values['company_name'],$values['organization_type'],$values['company_address'],$values['representative_name'],$values['representative_position'] ?: null,$values['contact_number'],$revision,(int)$reg['id']]);
        $pdo->prepare("UPDATE user_client SET name=?,status='pending_review' WHERE id=?")->execute([$values['representative_name'],(int)$user['id']]);
        foreach ($attachments['items'] as $attachment) {
            $stored[] = store_reg_document(
                $pdo, (int)$reg['id'], (int)$user['id'], $revision,
                $attachment['document_type'], $attachment['info'], $attachment['id_type'],
                (string)($attachment['requirement_type'] ?? 'other')
            );
        }
        audit_client((int)$user['id'],'registration.resubmitted','registration',(int)$reg['id'],['revision'=>$revision]);
        notify_role(
            'lpdd_fus',
            'Registration Resubmitted',
            $values['representative_name'] . ' submitted registration corrections for ' . $values['company_name'] . '.',
            'arrow-repeat',
            'warning',
            'review_queue'
        );
        $pdo->commit();
        return [];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($stored as $path) @unlink($path);
        error_log('Resubmission failure: ' . $ex->getMessage());
        return ['Corrections could not be submitted. Please contact the system administrator.'];
    }
}

function decide_registration(array $reviewer, int $registrationId, string $decision, string $remarks): array
{
    if (!in_array($decision, ['approved','needs_correction'], true)) return ['Invalid decision.'];
    if ($decision === 'needs_correction' && trim($remarks) === '') return ['Explain the corrections required before returning the registration.'];
    if (strlen($remarks) > 5000) return ['Remarks must be shorter than 5,000 characters.'];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $reg = query_one('SELECT ar.*,u.status,u.email FROM applicant_registrations ar JOIN user_client u ON u.id=ar.client_id WHERE ar.id=? FOR UPDATE', [$registrationId]);
        if (!$reg || $reg['status'] !== 'pending_review') {
            $pdo->rollBack();
            return ['This registration is no longer pending review. Reload the queue.'];
        }
        $docs = registration_documents($registrationId);
        if (!in_array('valid_id', array_column($docs,'document_type'), true)) {
            $pdo->rollBack();
            return ['A valid ID document must exist before approval.'];
        }
        if ($decision === 'approved') {
            $missing = registration_requirement_errors(
                (string)($reg['organization_type'] ?? 'individual'),$docs
            );
            if ($missing) {
                $pdo->rollBack();
                return $missing;
            }
        }
        $pdo->prepare('INSERT INTO registration_reviews (registration_id,reviewer_user_id,revision,decision,remarks) VALUES (?,?,?,?,?)')
            ->execute([$registrationId,(int)$reviewer['id'],(int)$reg['revision'],$decision,$remarks ?: null]);
        $pdo->prepare('UPDATE applicant_registrations SET last_reviewed_at=NOW() WHERE id=?')->execute([$registrationId]);
        if ($decision === 'approved') {
            $pdo->prepare("UPDATE user_client SET status='active',activated_at=NOW() WHERE id=?")->execute([(int)$reg['client_id']]);
        } else {
            $pdo->prepare("UPDATE user_client SET status='needs_correction' WHERE id=?")->execute([(int)$reg['client_id']]);
        }
        if ($decision === 'approved') {
            create_client_notification(
                (int)$reg['client_id'],
                'Registration Approved',
                'Your FP-OMS registration has been approved and your account is now active.',
                'check-circle',
                'success',
                'my_registration'
            );
        } else {
            create_client_notification(
                (int)$reg['client_id'],
                'Registration Corrections Required',
                'LPDD-FUS returned your registration for correction. Open My Registration to review the remarks.',
                'exclamation-circle',
                'warning',
                'my_registration'
            );
        }
        audit((int)$reviewer['id'],'registration.' . $decision,'registration',$registrationId,['revision'=>(int)$reg['revision']]);
        $pdo->commit();
        // The review decision is committed before sending the applicant status email.
        try {
            $sent = send_registration_decision_email(
                (string)$reg['email'],
                (string)$reg['representative_name'],
                $decision,
                $remarks
            );
            audit((int)$reviewer['id'], 'registration.email_decision', 'registration', $registrationId, [
                'decision' => $decision,
                'delivery' => $sent ? 'smtp' : 'not_sent'
            ]);
        } catch (Throwable $mailError) {
            error_log('Registration decision email failed after commit: ' . $mailError->getMessage());
        }
        return [];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Review failure: ' . $ex->getMessage());
        return ['Review could not be saved. Please try again.'];
    }
}

function send_registration_document(array $viewer, int $documentId): never
{
    $doc = query_one('SELECT rd.*,ar.client_id FROM registration_documents rd JOIN applicant_registrations ar ON ar.id=rd.registration_id WHERE rd.id=?',[$documentId]);
    if (!$doc || (!(($viewer['user_kind'] ?? '') === 'applicant'
            && (int)$doc['client_id'] === (int)$viewer['id']) &&
            !has_role($viewer,'lpdd_fus') && !has_role($viewer,'super_admin'))) {
        show_error('Document not found or access not permitted.', 404);
    }
    $root = realpath(PROJECT_ROOT . '/storage');
    $path = realpath(PROJECT_ROOT . '/storage/' . $doc['stored_path']);
    if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        show_error('Document not available.', 404);
    }
    $preview = (string)($_GET['preview'] ?? '') === '1';
    $allowedPreview = in_array((string)$doc['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true);
    if ($preview && !$allowedPreview) show_error('Preview is not supported for this file.', 415);
    if ($preview) {
        header('X-Frame-Options: SAMEORIGIN');
        header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'self\'; sandbox');
    }
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Length: ' . fpoms_file_plaintext_size($path));
    header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="document"; filename*=UTF-8\'\'' . rawurlencode($doc['original_name']));
    echo fpoms_file_read($path);
    exit;
}
