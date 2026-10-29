<?php
declare(strict_types=1);

/**
 * Annex A - Step 2 only:
 * Active applicant submits FP documentary package -> LPDD/FUS Technical Staff
 * initial completeness check -> correction/resubmission OR forwarding to RO.
 *
 * No formal Step 4 documentary evaluation, payment, hardcopy or SLA starts here.
 * The Annex does not enumerate required FP document types; an approved official
 * checklist must be added before this stage is used in production.
 */

function step2_schema_ready(): bool
{
    // Avoid information_schema: some local XAMPP/MariaDB accounts are denied
    // metadata access even though they can read the application tables.
    // These fixed, trusted table names are not derived from user input.
    // SELECT ... LIMIT 0 succeeds even for empty tables and checks the SELECT
    // permission the Step 2 module actually needs.
    try {
        foreach ([
            'permit_step2_applications',
            'permit_step2_documents',
            'permit_step2_events'
        ] as $table) {
            db()->query('SELECT 1 FROM `' . $table . '` LIMIT 0');
        }
        return true;
    } catch (Throwable $error) {
        error_log('FP-OMS Step 2 schema preflight failed: ' . $error->getMessage());
        return false;
    }
}

function step2_require_schema(): void
{
    if (!step2_schema_ready()) {
        show_error('Step 2 cannot access its required database tables. Confirm that migration 014 created all three permit_step2 tables and that the FP-OMS database account has SELECT permission.', 503);
    }
}

function step2_require_applicant(): array
{
    $client = require_user();
    require_kind($client, 'applicant');
    if (($client['status'] ?? '') !== 'active') {
        show_error('FP applications can be submitted only after LPDD/FUS activates your applicant account.');
    }
    step2_require_schema();
    return $client;
}

function step2_require_reviewer(): array
{
    $reviewer = require_role('lpdd_fus');
    step2_require_schema();
    return $reviewer;
}

function step2_require_receiving(): array
{
    $staff = require_role('ro_receiving');
    step2_require_schema();
    return $staff;
}

function step2_application(int $applicationId): ?array
{
    return query_one(
        'SELECT a.*, c.name AS applicant_name, c.email AS applicant_email
         FROM permit_step2_applications a
         JOIN user_client c ON c.id=a.client_id WHERE a.id=?', [$applicationId]
    );
}

function step2_application_for_view(array $viewer, int $applicationId): array
{
    step2_require_schema();
    $app = step2_application($applicationId);
    if (!$app) show_error('Permit application not found.', 404);

    $mine = ($viewer['user_kind'] ?? '') === 'applicant'
        && (int)$viewer['id'] === (int)$app['client_id'];
    $reviewer = ($viewer['user_kind'] ?? '') === 'internal'
        && ($viewer['status'] ?? '') === 'active' && has_role($viewer, 'lpdd_fus');
    $receiving = ($viewer['user_kind'] ?? '') === 'internal'
        && ($viewer['status'] ?? '') === 'active' && has_role($viewer, 'ro_receiving')
        && $app['status'] === 'forwarded_to_ro';
    if (!$mine && !$reviewer && !$receiving) {
        show_error('You do not have permission to access this application.', 404);
    }
    return $app;
}

function step2_documents(int $applicationId): array
{
    return query_all(
        'SELECT * FROM permit_step2_documents WHERE application_id=?
         ORDER BY revision ASC, id ASC', [$applicationId]
    );
}

function step2_events(int $applicationId): array
{
    return query_all(
        'SELECT e.*, c.name AS applicant_actor, u.name AS staff_actor
         FROM permit_step2_events e
         LEFT JOIN user_client c ON c.id=e.actor_client_id
         LEFT JOIN users u ON u.id=e.actor_user_id
         WHERE e.application_id=? ORDER BY e.id DESC', [$applicationId]
    );
}

function step2_uploads(array $files, bool $required): array
{
    $group = $files['application_files'] ?? null;
    $errors = [];
    $items = [];
    if ($group === null) {
        return ['items'=>[], 'errors'=>$required ? ['Upload at least one FP supporting document.'] : []];
    }
    if (!is_array($group) || !isset($group['name'], $group['tmp_name'], $group['error'], $group['size'])
        || !is_array($group['name']) || !is_array($group['tmp_name'])
        || !is_array($group['error']) || !is_array($group['size'])) {
        return ['items'=>[], 'errors'=>['Document upload data is invalid.']];
    }

    $keys = array_unique(array_merge(
        array_keys($group['name']), array_keys($group['tmp_name']),
        array_keys($group['error']), array_keys($group['size'])
    ));
    if (count($keys) > 10) return ['items'=>[], 'errors'=>['Maximum 10 documents per submission.']];

    foreach ($keys as $key) {
        if (!array_key_exists($key, $group['name']) || !array_key_exists($key, $group['tmp_name'])
            || !array_key_exists($key, $group['error']) || !array_key_exists($key, $group['size'])) {
            $errors[] = 'One document upload is incomplete.';
            continue;
        }
        $file = [
            'name' => $group['name'][$key], 'tmp_name' => $group['tmp_name'][$key],
            'error' => $group['error'][$key], 'size' => $group['size'][$key]
        ];
        if (!is_string($file['name']) || !is_string($file['tmp_name'])
            || !is_int($file['error']) || !is_int($file['size'])) {
            $errors[] = 'One document upload is invalid.';
            continue;
        }
        if ($file['error'] === UPLOAD_ERR_NO_FILE) continue;
        $info = upload_details($file, false);
        if (isset($info['error'])) $errors[] = 'Document "' . substr($file['name'], 0, 80) . '": ' . $info['error'];
        elseif (!isset($info['none'])) $items[] = $info;
    }
    if ($required && !$items) $errors[] = 'Upload at least one FP supporting document.';
    return ['items'=>$items, 'errors'=>$errors];
}

/**
 * Migration 017 is optional for older deployments: absent columns never
 * cause an SQL error in the existing Step 2 form or reviewer pages.
 */
function step2_location_schema_ready(): bool
{
    static $available = null;
    if ($available !== null) return $available;
    try {
        db()->query('SELECT project_latitude,project_longitude FROM permit_step2_applications LIMIT 0');
        db()->query('SELECT project_latitude,project_longitude FROM permit_step2_form_drafts LIMIT 0');
        return $available = true;
    } catch (Throwable $error) {
        return $available = false;
    }
}

/** Verify both decimal coordinates were supplied, or neither; WGS84 degrees. */
function step2_coordinate_errors(array $data): array
{
    $lat = trim((string)($data['project_latitude'] ?? ''));
    $lon = trim((string)($data['project_longitude'] ?? ''));
    if ($lat === '' && $lon === '') return [];
    if ($lat === '' || $lon === '') {
        return ['Both Latitude and Longitude are required when pinning a project location.'];
    }
    $validNumber = static fn(string $number): bool =>
        (bool)preg_match('/^[+-]?\d{1,3}(?:\.\d{1,7})?$/D',$number);
    if (!$validNumber($lat) || !$validNumber($lon)
        || (float)$lat < -90 || (float)$lat > 90
        || (float)$lon < -180 || (float)$lon > 180) {
        return ['Enter valid WGS84 decimal coordinates: Latitude −90 to 90; '
            . 'Longitude −180 to 180 (up to 7 decimal places).'];
    }
    return [];
}

function step2_input(): array
{
    $data = [
        'project_title'=>safe_post('project_title',180),
        'project_location'=>safe_post('project_location',300),
        'purpose'=>safe_post('purpose',2000)
    ];
    if (step2_location_schema_ready()) {
        $data['project_latitude'] = safe_post('project_latitude',32);
        $data['project_longitude'] = safe_post('project_longitude',32);
    }
    return $data;
}

function step2_validate_input(array $data): array
{
    $errors = [];
    if (strlen(trim((string)($data['project_title'] ?? ''))) < 5
        || strlen((string)($data['project_title'] ?? '')) > 180) {
        $errors[] = 'Application / Project Title must be 5 to 180 characters.';
    }
    if (strlen(trim((string)($data['project_location'] ?? ''))) < 5
        || strlen((string)($data['project_location'] ?? '')) > 300) {
        $errors[] = 'Project Location must be 5 to 300 characters.';
    }
    if (strlen((string)($data['purpose'] ?? '')) > 2000) {
        $errors[] = 'Project Description must not exceed 2,000 characters.';
    }
    return array_merge($errors,step2_coordinate_errors($data));
}

/**
 * Submit once or resubmit an application returned for correction.
 * Uploaded files are immutable and kept outside the web root.
 */
function step2_submit(array $client, array $data, array $files, int $applicationId = 0): array
{
    if (($client['user_kind'] ?? '') !== 'applicant' || ($client['status'] ?? '') !== 'active') {
        return ['An activated applicant account is required.'];
    }
    if (!step2_schema_ready()) return ['Step 2 tables are missing or inaccessible. Verify migration 014 and SELECT permissions.'];
    $errors = step2_validate_input($data);
    $coordsReady = step2_location_schema_ready();
    $lat = trim((string)($data['project_latitude'] ?? ''));
    $lon = trim((string)($data['project_longitude'] ?? ''));
    $lat = $lat !== '' ? $lat : null;
    $lon = $lon !== '' ? $lon : null;
    // Preserve legacy direct upload only when the new draft migration is absent.
    // When installed, only server-staged files can be submitted.
    $draftMode = step2_drafts_schema_ready();
    $uploads = $draftMode ? ['items'=>[], 'errors'=>[]]
        : step2_uploads($files,$applicationId === 0);
    $errors = array_merge($errors,$uploads['errors']);
    if ($draftMode && $files) $errors[] = 'Use the secure Selected Files uploader.';
    if ($errors) return $errors;

    $pdo = db();
    $stored = [];
    $draftRows = [];
    $draftFilesToRemove = [];
    $draftScope = $applicationId;
    try {
        $pdo->beginTransaction();
        $clientId = (int)$client['id'];
        // Serialize final submission against a concurrent draft upload/clear.
        $pdo->prepare('SELECT id FROM user_client WHERE id=? FOR UPDATE')->execute([$clientId]);
        if ($draftMode) {
            $staged = step2_draft_submission($clientId,$draftScope);
            if ($staged['errors']) {
                $pdo->rollBack();
                return $staged['errors'];
            }
            $draftRows = $staged['rows'];
        }
        $revision = 1;
        if ($applicationId !== 0) {
            $locked = query_one(
                'SELECT * FROM permit_step2_applications WHERE id=? AND client_id=? FOR UPDATE',
                [$applicationId,$clientId]
            );
            if (!$locked || $locked['status'] !== 'corrections_required') {
                $pdo->rollBack();
                return ['This application is not currently available for corrections. Refresh the page.'];
            }
            $revision = (int)$locked['revision'] + 1;
            if ($coordsReady) {
                $pdo->prepare(
                    "UPDATE permit_step2_applications
                     SET project_title=?,project_location=?,project_latitude=?,
                         project_longitude=?,purpose=?,revision=?,
                         status='submitted',last_submitted_at=NOW(),reviewed_at=NULL,
                         reviewer_user_id=NULL,forwarded_at=NULL WHERE id=? AND client_id=?"
                )->execute([
                    $data['project_title'],$data['project_location'],$lat,$lon,
                    $data['purpose'] ?: null,$revision,$applicationId,$clientId
                ]);
            } else {
                $pdo->prepare(
                    "UPDATE permit_step2_applications
                     SET project_title=?,project_location=?,purpose=?,revision=?,
                         status='submitted',last_submitted_at=NOW(),reviewed_at=NULL,
                         reviewer_user_id=NULL,forwarded_at=NULL WHERE id=? AND client_id=?"
                )->execute([
                    $data['project_title'],$data['project_location'],$data['purpose'] ?: null,
                    $revision,$applicationId,$clientId
                ]);
            }
            $action = 'resubmitted';
        } else {
            if ($coordsReady) {
                $pdo->prepare(
                    "INSERT INTO permit_step2_applications
                        (client_id,project_title,project_location,project_latitude,
                         project_longitude,purpose,status)
                     VALUES (?,?,?,?,?,?,'submitted')"
                )->execute([
                    $clientId,$data['project_title'],$data['project_location'],
                    $lat,$lon,$data['purpose'] ?: null
                ]);
            } else {
                $pdo->prepare(
                    "INSERT INTO permit_step2_applications
                        (client_id,project_title,project_location,purpose,status)
                     VALUES (?,?,?,?,'submitted')"
                )->execute([
                    $clientId,$data['project_title'],$data['project_location'],
                    $data['purpose'] ?: null
                ]);
            }
            $applicationId = (int)$pdo->lastInsertId();
            $action = 'submitted';
        }

        foreach ($uploads['items'] as $info) {
            $relative = 'fp_step2/' . $clientId . '/' . $applicationId . '/'
                . bin2hex(random_bytes(18)) . '.' . $info['extension'];
            $absolute = PROJECT_ROOT . '/storage/' . $relative;
            $dir = dirname($absolute);
            if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
                throw new RuntimeException('Could not create private FP document directory.');
            }
            if (!move_uploaded_file((string)$info['file']['tmp_name'],$absolute)) {
                throw new RuntimeException('Could not save uploaded FP document.');
            }
            $stored[] = $absolute;
            chmod($absolute,0600);
            $hash = hash_file('sha256',$absolute);
            if ($hash === false) throw new RuntimeException('Could not verify uploaded FP document.');
            $pdo->prepare(
                'INSERT INTO permit_step2_documents
                    (application_id,client_id,revision,original_name,stored_path,mime_type,file_size,sha256)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $applicationId,$clientId,$revision,$info['name'],$relative,
                $info['mime'],$info['size'],$hash
            ]);
        }
        // Copies from validated staged files are part of this transaction.
        // Originals are deleted only after a successful DB commit.
        foreach ($draftRows as $draft) {
            $source = step2_draft_real_path((string)$draft['stored_path']);
            if ($source === null) throw new RuntimeException('A staged FP file is missing.');
            $extension = [
                'application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'
            ][(string)$draft['mime_type']] ?? null;
            if ($extension === null) throw new RuntimeException('Unsupported saved FP file.');
            $relative = 'fp_step2/'.$clientId.'/'.$applicationId.'/'
                . bin2hex(random_bytes(18)).'.'.$extension;
            $absolute = PROJECT_ROOT.'/storage/'.$relative;
            if (!is_dir(dirname($absolute))
                && !mkdir(dirname($absolute),0700,true) && !is_dir(dirname($absolute))) {
                throw new RuntimeException('Could not create FP document directory.');
            }
            if (!copy($source,$absolute)) {
                @unlink($absolute);
                throw new RuntimeException('Could not copy saved FP file.');
            }
            $stored[] = $absolute;
            @chmod($absolute,0600);
            $digest = hash_file('sha256',$absolute);
            if ($digest === false || !hash_equals((string)$draft['sha256'],$digest)) {
                throw new RuntimeException('FP document integrity check failed.');
            }
            $pdo->prepare(
                'INSERT INTO permit_step2_documents
                    (application_id,client_id,revision,original_name,stored_path,mime_type,file_size,sha256)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $applicationId,$clientId,$revision,(string)$draft['original_name'],$relative,
                (string)$draft['mime_type'],(int)$draft['file_size'],$digest
            ]);
            $draftFilesToRemove[] = $source;
        }
        if ($draftRows) {
            if (step2_persistent_ready()) {
                $pdo->prepare(
                    'DELETE FROM permit_step2_draft_documents
                     WHERE client_id=? AND application_id=?'
                )->execute([$clientId,$draftScope]);
            } else {
                $hash = step2_draft_hash(false);
                if ($hash === null) throw new RuntimeException('Draft session expired.');
                $pdo->prepare(
                    'DELETE FROM permit_step2_draft_documents
                     WHERE client_id=? AND draft_hash=? AND application_id=?'
                )->execute([$clientId,$hash,$draftScope]);
            }
        }
        // Only delete the server-side field draft as part of the successful
        // submission transaction; a rollback leaves it and its files intact.
        if (step2_persistent_ready()) {
            $pdo->prepare(
                'DELETE FROM permit_step2_form_drafts
                 WHERE client_id=? AND application_id=?'
            )->execute([$clientId,$draftScope]);
        }
        $pdo->prepare(
            'INSERT INTO permit_step2_events
                (application_id,actor_client_id,action,revision)
             VALUES (?,?,?,?)'
        )->execute([$applicationId,$clientId,$action,$revision]);
        audit_client($clientId, 'permit.step2.' . $action, 'permit_step2_application',
            $applicationId, ['revision'=>$revision,
                'documents_added'=>count($uploads['items'])+count($draftRows)]);
        $pdo->commit();
        foreach ($draftFilesToRemove as $source) @unlink($source);

        try {
            notify_role(
                'lpdd_fus',
                $action === 'submitted' ? 'New FP Application for Initial Review' : 'FP Application Resubmitted',
                'FP application #' . $applicationId . ' from ' . (string)$client['name']
                    . ' is awaiting LPDD-FUS initial review.',
                'file-earmark-text','info','permit_initial_queue'
            );
            step2_email_reviewer_alert($applicationId, (string)$client['name'], $action);
        } catch (Throwable $notifyError) {
            error_log('FP-OMS Step 2 staff alert failed: ' . $notifyError->getMessage());
        }
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($stored as $path) @unlink($path);
        error_log('FP-OMS Step 2 submission failure: ' . $error->getMessage());
        return ['Application could not be submitted. Please contact the ICT administrator.'];
    }
}

function step2_review(array $reviewer, int $applicationId, string $decision, string $remarks): array
{
    if (($reviewer['user_kind'] ?? '') !== 'internal' || ($reviewer['status'] ?? '') !== 'active'
        || !has_role($reviewer,'lpdd_fus')) return ['LPDD-FUS reviewer authorization is required.'];
    if (!step2_schema_ready()) return ['Step 2 tables are missing or inaccessible. Verify migration 014 and SELECT permissions.'];
    if (!in_array($decision, ['return','forward'],true)) return ['Invalid Step 2 decision.'];
    $remarks = trim($remarks);
    if (strlen($remarks) > 3000) return ['Remarks must be 3,000 characters or fewer.'];
    if ($decision === 'return' && strlen($remarks) < 10) {
        return ['Specify the lacking documentary requirements (minimum 10 characters).'];
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $app = query_one(
            "SELECT a.*, c.name AS applicant_name,c.email AS applicant_email
             FROM permit_step2_applications a
             JOIN user_client c ON c.id=a.client_id WHERE a.id=? FOR UPDATE",
            [$applicationId]
        );
        if (!$app || $app['status'] !== 'submitted') {
            $pdo->rollBack();
            return ['Application is no longer awaiting initial review. Refresh the queue.'];
        }
        $docCount = query_one(
            'SELECT COUNT(*) AS n FROM permit_step2_documents WHERE application_id=?',
            [$applicationId]
        );
        if ((int)($docCount['n'] ?? 0) < 1) {
            $pdo->rollBack();
            return ['At least one submitted supporting document must exist before review.'];
        }
        $forwarded = $decision === 'forward';
        $status = $forwarded ? 'forwarded_to_ro' : 'corrections_required';
        $action = $forwarded ? 'forwarded_to_ro' : 'returned_for_correction';
        $pdo->prepare(
            'UPDATE permit_step2_applications SET status=?,reviewed_at=NOW(),
                forwarded_at=IF(?=1,NOW(),NULL),reviewer_user_id=? WHERE id=?'
        )->execute([$status,$forwarded ? 1 : 0,(int)$reviewer['id'],$applicationId]);
        $pdo->prepare(
            'INSERT INTO permit_step2_events
                (application_id,actor_user_id,action,revision,remarks)
             VALUES (?,?,?,?,?)'
        )->execute([
            $applicationId,(int)$reviewer['id'],$action,(int)$app['revision'],
            $remarks !== '' ? $remarks : null
        ]);
        create_client_notification(
            (int)$app['client_id'],
            $forwarded ? 'FP Application Forwarded to RO Receiving' : 'FP Application Corrections Required',
            $forwarded
                ? 'Your FP application #' . $applicationId . ' passed LPDD-FUS initial review and was forwarded to RO Receiving.'
                : 'Your FP application #' . $applicationId . ' requires corrections. Open My FP Applications for details.',
            $forwarded ? 'check-circle' : 'exclamation-circle',
            $forwarded ? 'success' : 'warning',
            'permit_application',['id'=>$applicationId]
        );
        if ($forwarded) {
            notify_role(
                'ro_receiving','FP Application Forwarded for RO Receiving',
                'FP application #' . $applicationId . ' passed LPDD-FUS initial review and is ready for RO Receiving.',
                'inbox','info','permit_ro_inbox'
            );
        }
        audit((int)$reviewer['id'],'permit.step2.' . $action,'permit_step2_application',
            $applicationId,['revision'=>(int)$app['revision'],'remarks'=>$remarks]);
        $pdo->commit();

        try {
            step2_email_applicant_decision(
                (string)$app['applicant_email'],(string)$app['applicant_name'],
                $applicationId,$forwarded,$remarks
            );
            if ($forwarded) step2_email_ro_alert($applicationId);
        } catch (Throwable $emailError) {
            error_log('FP-OMS Step 2 post-review email failed: ' . $emailError->getMessage());
        }
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS Step 2 review failed: ' . $error->getMessage());
        return ['Review could not be saved. Please try again.'];
    }
}

/** Recipient addresses used for notification only; never authenticate shared mailboxes. */
function step2_staff_recipients(string $roleCode): array
{
    $role = $roleCode === 'ro_receiving' ? 'ro_receiving' : 'lpdd_fus';
    $members = query_all(
        "SELECT DISTINCT u.email,u.reference_email,u.office_station,u.status FROM users u
         JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id
         WHERE r.code=? AND u.user_kind='internal' AND u.status IN ('active','pending_setup')",
        [$role]
    );
    // Imported LPDD/FUS pending users may not have received role assignments yet.
    if ($role === 'lpdd_fus') {
        $members = array_merge($members, query_all(
            "SELECT email,reference_email,office_station,status FROM users
             WHERE user_kind='internal' AND status IN ('active','pending_setup')
               AND UPPER(TRIM(office_station))='LPDD/FUS'"
        ));
    }
    $emails = [];
    foreach ($members as $member) {
        if ($member['status'] === 'active') {
            $personal = strtolower(trim((string)($member['email'] ?? '')));
            if (filter_var($personal,FILTER_VALIDATE_EMAIL)) $emails[$personal] = $personal;
        }
        $office = strtolower(trim((string)($member['reference_email'] ?? '')));
        if (filter_var($office,FILTER_VALIDATE_EMAIL)) $emails[$office] = $office;
    }
    return array_values($emails);
}

function step2_email_base_url(): ?string
{
    // Reuse existing verified scheme/host handling used for applicant emails.
    return registration_applicant_login_url();
}

function step2_email_reviewer_alert(int $applicationId, string $applicantName, string $action): void
{
    $login = step2_email_base_url();
    $url = $login !== null ? $login . '?next=permit-initial-queue' : null;
    $safeUrl = $url === null ? '' : e($url);
    $safeName = e($applicantName);
    $subject = 'FP Application – LPDD-FUS Initial Review Required | DENR CARAGA FP-OMS';
    $body = '<p>FP application <strong>#' . $applicationId . '</strong> '
        . ($action === 'resubmitted' ? 'has been resubmitted' : 'has been submitted')
        . ' by <strong>' . $safeName . '</strong>.</p>'
        . '<p>Log in to FP-OMS and open <strong>Initial FP Review Queue</strong> to check documentary completeness.</p>'
        . ($url !== null ? '<p><a href="' . $safeUrl . '">Login to FP-OMS – Initial Review Queue</a></p>' : '');
    foreach (step2_staff_recipients('lpdd_fus') as $address) {
        try {
            smtp_send_html($address,$subject,
                registration_email_layout('FP Application for Initial Review','LPDD/FUS Reviewing Personnel',$body),
                "FP application #{$applicationId} from {$applicantName} awaits LPDD-FUS initial review.\n"
                    . ($url ?? 'Open the approved FP-OMS website to log in.'));
        } catch (Throwable $error) {
            error_log('FP-OMS Step 2 reviewer email failed: ' . $error->getMessage());
        }
    }
}

function step2_email_applicant_decision(
    string $email, string $name, int $applicationId, bool $forwarded, string $remarks
): void {
    $login = step2_email_base_url();
    $url = $login !== null ? $login . '?next=permit-applications' : null;
    $subject = $forwarded
        ? 'FP Application Forwarded to RO Receiving – DENR CARAGA'
        : 'Notice of Lacking Documentary Requirements – FP Application #' . $applicationId;
    $body = '<p><strong>Reference:</strong> FP Application #' . $applicationId
        . '<br><strong>Date:</strong> ' . e(date('F j, Y')) . '</p>'
        . '<p>' . ($forwarded
            ? 'Your FP application has passed LPDD-FUS Technical Staff initial review and '
                . 'has been forwarded to RO Receiving for the next processing step.'
            : 'This letter serves as formal notification that your FP application was found '
                . 'incomplete during the LPDD-FUS Technical Staff initial completeness review '
                . 'under Annex A Step 2.') . '</p>'
        . (!$forwarded
            ? '<p><strong>Lacking documentary requirements / reviewer remarks:</strong></p>'
                . '<div style="background:#fff8e7;border-left:4px solid #c68a20;padding:12px 15px">'
                . nl2br(e($remarks)) . '</div>'
                . '<p>Kindly correct the identified deficiencies, upload the required '
                . 'documents and resubmit through FP-OMS for revalidation.</p>'
            : '<p>This notice acknowledges the Step 2 handoff only; '
                . 'it does not indicate formal documentary approval or issuance of a permit.</p>')
        . ($url !== null
            ? '<p style="margin:22px 0"><a href="' . e($url)
                . '" style="background:#08783f;color:#ffffff;padding:12px 20px;'
                . 'text-decoration:none;border-radius:6px;font-weight:bold">'
                . 'Login to FP-OMS – View FP Application</a></p>'
                . '<p>Alternatively, open: <a href="' . e($url) . '">'
                . e($url) . '</a></p>'
            : '');
    if (filter_var($email,FILTER_VALIDATE_EMAIL)) {
        smtp_send_html($email,$subject,
            registration_email_layout($forwarded ? 'FP Application Forwarded' : 'FP Application Corrections Required',$name,$body),
            "FP application #{$applicationId}: " . ($forwarded ? 'Forwarded to RO Receiving.' : "Corrections required:\n{$remarks}")
                . "\n" . ($url ?? 'Sign in to your FP-OMS account.'));
    }
}

function step2_email_ro_alert(int $applicationId): void
{
    $login = step2_email_base_url();
    $url = $login !== null ? $login . '?next=permit-ro-inbox' : null;
    $subject = 'FP Application Ready for RO Receiving – DENR CARAGA FP-OMS';
    $body = '<p>FP application <strong>#' . $applicationId
        . '</strong> has passed the LPDD/FUS Step 2 initial check.</p>'
        . '<p>It has been forwarded to the RO Receiving inbox for the next step.</p>'
        . ($url !== null ? '<p><a href="' . e($url)
            . '">Login to FP-OMS – RO Receiving Inbox</a></p>' : '');
    foreach (step2_staff_recipients('ro_receiving') as $address) {
        try {
            smtp_send_html($address,$subject,
                registration_email_layout('FP Application for RO Receiving','RO Receiving/Releasing Personnel',$body),
                "FP application #{$applicationId} forwarded by LPDD/FUS.\n"
                . ($url ?? 'Open FP-OMS to view the receiving inbox.'));
        } catch (Throwable $error) {
            error_log('FP-OMS RO inbox email failed: ' . $error->getMessage());
        }
    }
}

function step2_send_document(array $viewer, int $documentId): never
{
    step2_require_schema();
    $doc = query_one(
        'SELECT d.*, a.status AS application_status, a.client_id AS owner_client_id
         FROM permit_step2_documents d
         JOIN permit_step2_applications a ON a.id=d.application_id WHERE d.id=?',
        [$documentId]
    );
    if (!$doc) show_error('Document not found.',404);
    $mine = ($viewer['user_kind'] ?? '') === 'applicant'
        && (int)$doc['owner_client_id'] === (int)$viewer['id'];
    $lpdd = ($viewer['user_kind'] ?? '') === 'internal'
        && ($viewer['status'] ?? '') === 'active' && has_role($viewer,'lpdd_fus');
    $ro = ($viewer['user_kind'] ?? '') === 'internal'
        && ($viewer['status'] ?? '') === 'active' && has_role($viewer,'ro_receiving')
        && $doc['application_status'] === 'forwarded_to_ro';
    if (!$mine && !$lpdd && !$ro) show_error('Document not found or access denied.',404);

    $storageRoot = realpath(PROJECT_ROOT . '/storage/fp_step2');
    $path = realpath(PROJECT_ROOT . '/storage/' . (string)$doc['stored_path']);
    if (!$storageRoot || !$path || !str_starts_with($path,$storageRoot . DIRECTORY_SEPARATOR)
        || !is_file($path)) show_error('Document not available.',404);
    $preview = (string)($_GET['preview'] ?? '') === '1';
    $mime = (string)$doc['mime_type'];
    if (!in_array($mime,['application/pdf','image/jpeg','image/png'],true)) {
        show_error('File type not supported.',415);
    }
    if ($preview) {
        header('X-Frame-Options: SAMEORIGIN');
        header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'self\'; sandbox');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment')
        . '; filename="fp-document"; filename*=UTF-8\'\'' . rawurlencode((string)$doc['original_name']));
    readfile($path);
    exit;
}
