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

function store_reg_document(PDO $pdo, int $registrationId, int $userId, int $revision, string $type, array $info): string
{
    $relative = 'registrations/' . $userId . '/' . bin2hex(random_bytes(18)) . '.' . $info['extension'];
    $absolute = PROJECT_ROOT . '/storage/' . $relative;
    if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0700, true) && !is_dir(dirname($absolute))) {
        throw new RuntimeException('Storage directory could not be created.');
    }
    if (!move_uploaded_file((string)$info['file']['tmp_name'], $absolute)) {
        throw new RuntimeException('Upload could not be saved.');
    }
    chmod($absolute, 0600);
    $digest = hash_file('sha256', $absolute);
    try {
        $stmt = $pdo->prepare('INSERT INTO registration_documents (registration_id,document_type,original_name,stored_path,mime_type,file_size,sha256,revision) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$registrationId,$type,$info['name'],$relative,$info['mime'],$info['size'],$digest,$revision]);
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
        'company_address' => safe_post('company_address', 2000),
        'contact_number' => safe_post('contact_number', 40)
    ];
}

function register_applicant(array $values, string $email, string $password, string $confirmation, array $files): array
{
    $errors = registration_validation($values);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $errors[] = 'Enter a valid email address.';
    if ($message = check_password_strength($password)) $errors[] = $message;
    if ($password !== $confirmation) $errors[] = 'Passwords do not match.';
    $id = upload_details($files['valid_id'] ?? null, true);
    $extra = upload_details($files['supporting_document'] ?? null, false);
    if (isset($id['error'])) $errors[] = $id['error'];
    if (isset($extra['error'])) $errors[] = $extra['error'];
    if ($errors) return $errors;
    $pdo = db();
    $stored = [];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO users (name,email,password_hash,user_kind,status) VALUES (?,?,?,'applicant','pending_review')");
        $stmt->execute([$values['representative_name'],strtolower(trim($email)),password_hash($password, PASSWORD_DEFAULT)]);
        $uid = (int)$pdo->lastInsertId();
        initialize_profile_account_fields($uid, 'applicant');
        $role = query_one('SELECT id FROM roles WHERE code=?', ['applicant']);
        if (!$role) throw new RuntimeException('Roles have not been seeded.');
        $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')->execute([$uid,(int)$role['id']]);
        $stmt = $pdo->prepare('INSERT INTO applicant_registrations (user_id,company_name,company_address,representative_name,representative_position,contact_number) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$uid,$values['company_name'],$values['company_address'],$values['representative_name'],$values['representative_position'] ?: null,$values['contact_number']]);
        $regId = (int)$pdo->lastInsertId();
        $stored[] = store_reg_document($pdo,$regId,$uid,1,'valid_id',$id);
        if (!isset($extra['none'])) $stored[] = store_reg_document($pdo,$regId,$uid,1,'supporting_document',$extra);
        audit($uid,'registration.submitted','registration',$regId,['revision'=>1]);
        notify_role(
            'lpdd_fus',
            'New Applicant Registration',
            $values['representative_name'] . ' submitted a registration for ' . $values['company_name'] . '.',
            'person-plus',
            'info',
            'review_queue'
        );
        $pdo->commit();
        // The registration is durable before attempting SMTP; mail problems never undo it.
        try {
            $sent = send_registration_acknowledgment_email(strtolower(trim($email)), $values['representative_name']);
            audit($uid, 'registration.email_acknowledgment', 'registration', $regId, [
                'delivery' => $sent ? 'smtp' : 'not_sent'
            ]);
        } catch (Throwable $mailError) {
            error_log('Registration acknowledgment email failed after commit: ' . $mailError->getMessage());
        }
        return [];
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($stored as $path) @unlink($path);
        if ($ex->getCode() === '23000') return ['That email address is already registered.'];
        error_log('Registration database failure: ' . $ex->getMessage());
        return ['Registration could not be saved. Please contact the system administrator.'];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($stored as $path) @unlink($path);
        error_log('Registration failure: ' . $ex->getMessage());
        return ['Registration could not be saved. Please contact the system administrator.'];
    }
}

function registration_for_user(int $uid): ?array
{
    return query_one('SELECT * FROM applicant_registrations WHERE user_id=?', [$uid]);
}

function latest_review(int $registrationId): ?array
{
    return query_one('SELECT rr.*, u.name AS reviewer_name FROM registration_reviews rr JOIN users u ON u.id=rr.reviewer_user_id WHERE rr.registration_id=? ORDER BY rr.id DESC LIMIT 1',[$registrationId]);
}

function registration_documents(int $registrationId): array
{
    return query_all('SELECT rd.* FROM registration_documents rd INNER JOIN (SELECT document_type, MAX(id) AS max_id FROM registration_documents WHERE registration_id=? GROUP BY document_type) recent ON recent.max_id=rd.id ORDER BY rd.document_type', [$registrationId]);
}

function revise_registration(array $user, array $values, array $files): array
{
    $errors = registration_validation($values);
    $id = upload_details($files['valid_id'] ?? null, false);
    $extra = upload_details($files['supporting_document'] ?? null, false);
    if (isset($id['error'])) $errors[] = $id['error'];
    if (isset($extra['error'])) $errors[] = $extra['error'];
    if ($errors) return $errors;
    $pdo = db();
    $stored = [];
    try {
        $pdo->beginTransaction();
        $locked = query_one('SELECT * FROM users WHERE id=? FOR UPDATE', [(int)$user['id']]);
        if (!$locked || $locked['status'] !== 'needs_correction') {
            $pdo->rollBack();
            return ['Registration may be resubmitted only when corrections are required.'];
        }
        $reg = registration_for_user((int)$user['id']);
        if (!$reg) throw new RuntimeException('Missing registration record.');
        $revision = (int)$reg['revision'] + 1;
        $pdo->prepare('UPDATE applicant_registrations SET company_name=?,company_address=?,representative_name=?,representative_position=?,contact_number=?,revision=?,submitted_at=NOW() WHERE id=?')
            ->execute([$values['company_name'],$values['company_address'],$values['representative_name'],$values['representative_position'] ?: null,$values['contact_number'],$revision,(int)$reg['id']]);
        $pdo->prepare("UPDATE users SET name=?,status='pending_review' WHERE id=?")->execute([$values['representative_name'],(int)$user['id']]);
        if (!isset($id['none'])) $stored[] = store_reg_document($pdo,(int)$reg['id'],(int)$user['id'],$revision,'valid_id',$id);
        if (!isset($extra['none'])) $stored[] = store_reg_document($pdo,(int)$reg['id'],(int)$user['id'],$revision,'supporting_document',$extra);
        audit((int)$user['id'],'registration.resubmitted','registration',(int)$reg['id'],['revision'=>$revision]);
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
        $reg = query_one('SELECT ar.*,u.status,u.email FROM applicant_registrations ar JOIN users u ON u.id=ar.user_id WHERE ar.id=? FOR UPDATE', [$registrationId]);
        if (!$reg || $reg['status'] !== 'pending_review') {
            $pdo->rollBack();
            return ['This registration is no longer pending review. Reload the queue.'];
        }
        $docs = registration_documents($registrationId);
        if (!in_array('valid_id', array_column($docs,'document_type'), true)) {
            $pdo->rollBack();
            return ['A valid ID document must exist before approval.'];
        }
        $pdo->prepare('INSERT INTO registration_reviews (registration_id,reviewer_user_id,revision,decision,remarks) VALUES (?,?,?,?,?)')
            ->execute([$registrationId,(int)$reviewer['id'],(int)$reg['revision'],$decision,$remarks ?: null]);
        $pdo->prepare('UPDATE applicant_registrations SET last_reviewed_at=NOW() WHERE id=?')->execute([$registrationId]);
        if ($decision === 'approved') {
            $pdo->prepare("UPDATE users SET status='active',activated_at=NOW() WHERE id=?")->execute([(int)$reg['user_id']]);
        } else {
            $pdo->prepare("UPDATE users SET status='needs_correction' WHERE id=?")->execute([(int)$reg['user_id']]);
        }
        if ($decision === 'approved') {
            create_notification(
                (int)$reg['user_id'],
                'Registration Approved',
                'Your FP-OMS registration has been approved and your account is now active.',
                'check-circle',
                'success',
                'my_registration'
            );
        } else {
            create_notification(
                (int)$reg['user_id'],
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
    $doc = query_one('SELECT rd.*,ar.user_id FROM registration_documents rd JOIN applicant_registrations ar ON ar.id=rd.registration_id WHERE rd.id=?',[$documentId]);
    if (!$doc || ((int)$doc['user_id'] !== (int)$viewer['id'] &&
            !has_role($viewer,'lpdd_fus') && !has_role($viewer,'super_admin'))) {
        show_error('Document not found or access not permitted.', 404);
    }
    $root = realpath(PROJECT_ROOT . '/storage');
    $path = realpath(PROJECT_ROOT . '/storage/' . $doc['stored_path']);
    if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        show_error('Document not available.', 404);
    }
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="document"; filename*=UTF-8\'\'' . rawurlencode($doc['original_name']));
    readfile($path);
    exit;
}
