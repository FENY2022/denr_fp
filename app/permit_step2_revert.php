<?php
declare(strict_types=1);

/**
 * Permit Step 2 applicant-initiated reversion.
 * The authoritative deadline is evaluated by MySQL, not PHP/browser clocks.
 */
function step2_revert_schema_ready(): bool
{
    try {
        db()->query('SELECT received_at,received_by_user_id FROM permit_step2_applications LIMIT 0');
        return true;
    } catch (Throwable $error) {
        error_log('FP-OMS revert migration 020 missing: ' . $error->getMessage());
        return false;
    }
}

function step2_revert_eligibility(array $application, bool $hasReceiptSchema, int $secondsRemaining): array
{
    if (!$hasReceiptSchema) return [false, 'The ICT administrator must apply database migration 020 first.'];
    if (($application['status'] ?? '') !== 'submitted') {
        return [false, 'This application is no longer pending LPDD-FUS initial review.'];
    }
    if (!empty($application['received_at']) || !empty($application['reviewed_at'])
        || !empty($application['forwarded_at']) || !empty($application['reviewer_user_id'])
        || !empty($application['received_by_user_id'])) {
        return [false, 'DENR has already received or acted on this submission. Revert is no longer allowed.'];
    }
    if ($secondsRemaining <= 0) {
        return [false, 'The 30-minute reversion period has already expired.'];
    }
    return [true, ''];
}

/** Return remaining time using the database server, not the HTTP/browser clock. */
function step2_revert_seconds(string $submittedAt): int
{
    $row = query_one(
        'SELECT GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),DATE_ADD(?, INTERVAL 30 MINUTE))) AS remaining',
        [$submittedAt]
    );
    return max(0,(int)($row['remaining'] ?? 0));
}

/** Read-only UI state. The POST always repeats these checks under row lock. */
function step2_revert_status(array $application): array
{
    $ready = step2_revert_schema_ready();
    $seconds = $ready ? step2_revert_seconds((string)$application['last_submitted_at']) : 0;
    [$allowed,$reason] = step2_revert_eligibility($application,$ready,$seconds);
    return ['allowed'=>$allowed,'reason'=>$reason,'seconds'=>$seconds];
}

function step2_revert_application(array $client, int $applicationId, string $remarks): array
{
    if (($client['user_kind'] ?? '') !== 'applicant' || ($client['status'] ?? '') !== 'active'
        || (int)($client['id'] ?? 0) < 1 || $applicationId < 1) {
        return ['Only an active applicant can revert their own application.'];
    }
    $remarks=trim($remarks);
    if (strlen($remarks) < 10) return ['Explain why you are reverting the application (minimum 10 characters).'];
    if (strlen($remarks) > 3000) return ['Revert remarks must be 3,000 characters or fewer.'];
    if (!step2_revert_schema_ready()) return ['Revert is unavailable until migration 020 is installed.'];
    $pdo=db();
    try {
        $pdo->beginTransaction();
        $app=query_one(
            'SELECT a.*,c.name AS applicant_name,c.email AS applicant_email
             FROM permit_step2_applications a
             JOIN user_client c ON c.id=a.client_id
             WHERE a.id=? AND a.client_id=? FOR UPDATE',
            [$applicationId,(int)$client['id']]
        );
        if (!$app) {
            $pdo->rollBack();
            return ['Application not found or you do not have permission to revert it.'];
        }
        // An LPDD-FUS Receive or Review action acquires the same FOR UPDATE lock.
        $remaining=step2_revert_seconds((string)$app['last_submitted_at']);
        [$allowed,$reason]=step2_revert_eligibility($app,true,$remaining);
        if (!$allowed) {
            $pdo->rollBack();
            return [$reason];
        }
        $stmt=$pdo->prepare(
            "UPDATE permit_step2_applications
             SET status='reverted'
             WHERE id=? AND client_id=? AND status='submitted'
             AND received_at IS NULL AND reviewed_at IS NULL
             AND forwarded_at IS NULL AND reviewer_user_id IS NULL
             AND received_by_user_id IS NULL
             AND NOW()<DATE_ADD(last_submitted_at,INTERVAL 30 MINUTE)"
        );
        $stmt->execute([$applicationId,(int)$client['id']]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return ['Revert window expired or DENR has already acted. Refresh your application.'];
        }
        $pdo->prepare(
            "INSERT INTO permit_step2_events
              (application_id,actor_client_id,action,revision,remarks)
             VALUES (?,?, 'reverted', ?, ?)"
        )->execute([$applicationId,(int)$client['id'],(int)$app['revision'],$remarks]);
        audit_client((int)$client['id'],'permit.step2.reverted','permit_step2_application',
            $applicationId,['revision'=>(int)$app['revision']]);
        $pdo->commit();

        // Notification failures must never roll back a successful state transition.
        try {
            step2_notify_revert($app,$applicationId);
        } catch (Throwable $noticeError) {
            error_log('FP-OMS revert committed but notification failed: '
                . $noticeError->getMessage());
        }
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS revert failed: '.$error->getMessage());
        return ['Could not revert the FP application. Please try again.'];
    }
}

/** "Received" is an explicit authenticated DENR action, not a read-only GET. */
function step2_receive_documents(array $reviewer, int $applicationId): array
{
    if (($reviewer['user_kind'] ?? '') !== 'internal'
        || ($reviewer['status'] ?? '') !== 'active'
        || !has_role($reviewer,'lpdd_fus') || $applicationId < 1) {
        return ['LPDD-FUS reviewer authorization is required.'];
    }
    if (!step2_revert_schema_ready()) return ['Apply migration 020 before acknowledging receipt.'];
    $pdo=db();
    try {
        $pdo->beginTransaction();
        $app=query_one(
            'SELECT * FROM permit_step2_applications WHERE id=? FOR UPDATE',[$applicationId]
        );
        if (!$app || $app['status'] !== 'submitted' || !empty($app['received_at'])) {
            $pdo->rollBack();
            return ['This application is no longer available to receive. Refresh the page.'];
        }
        $pdo->prepare(
            'UPDATE permit_step2_applications SET received_at=NOW(),received_by_user_id=?
             WHERE id=? AND status=? AND received_at IS NULL'
        )->execute([(int)$reviewer['id'],$applicationId,'submitted']);
        $pdo->prepare(
            "INSERT INTO permit_step2_events
             (application_id,actor_user_id,action,revision,remarks)
             VALUES (?,?,'documents_received',?,?)"
        )->execute([$applicationId,(int)$reviewer['id'],(int)$app['revision'],
            'LPDD-FUS acknowledged receipt of the submitted documents.']);
        audit((int)$reviewer['id'],'permit.step2.documents_received',
            'permit_step2_application',$applicationId,['revision'=>(int)$app['revision']]);
        $pdo->commit();
        try {
            create_client_notification((int)$app['client_id'],
                'DENR Received Your FP Documents',
                'LPDD-FUS acknowledged receipt of the documents for FP application #'
                . $applicationId . '. Applicant reversion is now unavailable.',
                'inbox','info','permit_application',['id'=>$applicationId]);
        } catch (Throwable $noticeError) {
            error_log('FP-OMS document receipt alert not delivered: '
                . $noticeError->getMessage());
        }
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS receive documents failed: '.$error->getMessage());
        return ['Could not acknowledge receipt of documents. Please try again.'];
    }
}

/**
 * Post-commit notifications: applicant gets confirmation; LPDD-FUS queue
 * reviewers get bell alerts and separate SMTP emails. Do not claim delivery
 * when SMTP is disabled/failed. Avoid attachment or ID contents in email.
 */
function step2_notify_revert(array $app, int $applicationId): void
{
    $title='FP Application Reverted by Applicant';
    $message='FP application #'.$applicationId
        .' was reverted by the applicant before initial DENR action. '
        .'Do not process this submission until it is resubmitted.';
    try {
        create_client_notification((int)$app['client_id'],
            'FP Application Reverted Successfully',
            'Your FP application #'.$applicationId.' is reverted and can now be edited and resubmitted.',
            'arrow-counterclockwise','info','permit_application',['id'=>$applicationId]);
    } catch (Throwable $error) {
        error_log('FP-OMS applicant revert bell alert failed: '.$error->getMessage());
    }
    try {
        // Role-assigned reviewers and active LPDD/FUS office assignees.
        // Deduplicate by user ID so mixed legacy role/office accounts receive one bell.
        $reviewers=query_all(
            "SELECT DISTINCT u.id FROM users u
             WHERE u.user_kind='internal' AND u.status='active'
               AND (UPPER(TRIM(u.office_station))='LPDD/FUS'
                OR EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
                           WHERE ur.user_id=u.id AND r.code='lpdd_fus'))"
        );
        foreach ($reviewers as $staff) {
            create_notification((int)$staff['id'],$title,$message,
                'arrow-counterclockwise','warning','permit_initial_queue');
        }
    } catch (Throwable $error) {
        error_log('FP-OMS LPDD-FUS revert bell alert failed: '.$error->getMessage());
    }
    $project=(string)($app['project_title'] ?? '');
    $name=(string)($app['applicant_name'] ?? '');
    $revertedAt='';
    try {
        $when=query_one(
            "SELECT occurred_at FROM permit_step2_events
             WHERE application_id=? AND action='reverted' ORDER BY id DESC LIMIT 1",
            [$applicationId]
        );
        $revertedAt=(string)($when['occurred_at'] ?? '');
    } catch (Throwable $error) {
        error_log('FP-OMS reverted time lookup failed: '.$error->getMessage());
    }
    $login=step2_email_base_url();
    $link=$login===null ? 'Open your approved FP-OMS address.'
        : $login.'?next=permit-initial-queue';
    $safeBody='<p><strong>FP Application #'.(int)$applicationId.'</strong> was '
        .'reverted by the applicant.</p><p><strong>Applicant:</strong> '.e($name)
        .'<br><strong>Project:</strong> '.e($project)
        .'<br><strong>Status:</strong> REVERTED'
        . ($revertedAt !== '' ? '<br><strong>Reverted at:</strong> '.e($revertedAt) : '')
        . '</p>'
        .'<p>Please stop initial review of this submission. '
        .'Wait for the applicant to resubmit it.</p>';
    $plain="FP application #{$applicationId} was reverted by {$name}.\n"
        ."Project: {$project}\nStatus: REVERTED. "
        . ($revertedAt !== '' ? "Reverted at: {$revertedAt}. " : '')
        . "Stop reviewing until resubmission.\n{$link}";
    try {
        foreach (step2_staff_recipients('lpdd_fus') as $address) {
            $sent=smtp_send_html($address,
                'FP Application Reverted by Applicant – Action Required',
                registration_email_layout('FP Application Reverted',
                    'LPDD-FUS Reviewing Personnel',
                    $safeBody.'<p>'.e($link).'</p>'),$plain);
            if (!$sent) error_log('FP-OMS revert SMTP alert not delivered to LPDD-FUS contact.');
        }
    } catch (Throwable $error) {
        error_log('FP-OMS revert LPDD-FUS email exception: '.$error->getMessage());
    }
    $clientEmail=(string)($app['applicant_email'] ?? '');
    if (filter_var($clientEmail,FILTER_VALIDATE_EMAIL)) {
        try {
            if (!smtp_send_html($clientEmail,
                'FP Application Reverted Successfully – DENR CARAGA FP-OMS',
                registration_email_layout('Your FP Application Was Reverted',$name,
                    '<p>Your FP application <strong>#'.(int)$applicationId
                    .'</strong> has been reverted successfully. You may sign in '
                    .'to update and resubmit the application and documents.</p>'),
                "Your FP application #{$applicationId} was reverted. "
                    .'Log in to FP-OMS to edit and resubmit it.')) {
                error_log('FP-OMS revert applicant email could not be delivered.');
            }
        } catch (Throwable $error) {
            error_log('FP-OMS revert applicant email exception: '.$error->getMessage());
        }
    }
}

/** Client-only revert action, server-derived countdown and Bootstrap confirmation. */
function step2_revert_client_html(array $application): string
{
    $id=(int)$application['id'];
    if (($application['status'] ?? '') === 'reverted') {
        return '<div class="panel soft mt-3" data-step2-reverted>'
            . '<h3 class="h6">Application Reverted</h3>'
            . '<p class="small">Your submitted application was reverted before DENR action. '
            . 'Previous uploaded documents remain in the history. '
            . 'You may edit and resubmit when ready.</p>'
            . '<a class="btn btn-primary" href="' . e(url('permit_apply',['id'=>$id]))
            . '"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>'
            . 'Edit / Resubmit Application</a></div>';
    }
    if (($application['status'] ?? '') !== 'submitted') return '';
    $status=step2_revert_status($application);
    $html='<div class="panel soft mt-3" data-step2-revert-panel>'
        . '<h3 class="h6"><i class="bi bi-arrow-counterclockwise me-2" '
        . 'aria-hidden="true"></i>Revert Submitted Application</h3>'
        . '<p class="small text-secondary">You may revert within 30 minutes of '
        . 'the latest submission, provided DENR has not received or acted on the documents.</p>';
    if (!$status['allowed']) {
        return $html . '<div class="alert alert-secondary small mb-0" role="status">'
            . e((string)$status['reason']) . '</div></div>';
    }
    $html .= '<p class="small mb-2">Time remaining: <strong data-step2-revert-countdown '
        . 'data-seconds="' . (int)$status['seconds'] . '" aria-live="polite">'
        . gmdate('i:s',(int)$status['seconds']) . '</strong></p>'
        . '<button class="btn btn-outline-danger" type="button" '
        . 'data-bs-toggle="modal" data-bs-target="#step2-revert-modal" '
        . 'data-step2-revert-trigger>'
        . '<i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>'
        . 'Revert Application</button>'
        . '<p class="small text-warning-emphasis mt-2 mb-0 d-none" '
        . 'data-step2-revert-expired role="alert">'
        . 'The 30-minute period has expired. Revert is unavailable.</p></div>'
        . '<div class="modal fade" id="step2-revert-modal" tabindex="-1" '
        . 'aria-labelledby="step2-revert-title" aria-hidden="true">'
        . '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h2 class="modal-title h5" id="step2-revert-title">'
        . 'Confirm Revert Application</h2>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" '
        . 'aria-label="Close"></button></div>'
         . '<form method="post" action="' . e(url('permit_revert',['id'=>$id])) . '">'
         . '<div class="modal-body"><p>Are you sure you want to revert '
         . '<strong>FP Application #' . $id . '</strong>?</p>'
         . '<p class="small text-secondary">The pending submission will '
         . 'be removed from the LPDD-FUS initial review queue. Previous documents '
         . 'and audit history are retained. You may edit and resubmit. '
         . 'This action cannot proceed once DENR acts or the 30 minutes expire.</p>'
         . '<label class="form-label" for="step2_revert_remarks">Remarks <span '
         . 'class="text-danger" aria-hidden="true">*</span></label>'
         . '<textarea class="form-control" id="step2_revert_remarks" name="remarks" '
         . 'rows="4" minlength="10" maxlength="3000" required '
         . 'autocomplete="off" aria-autocomplete="list" '
         . 'aria-controls="step2_revert_suggestions" aria-expanded="false" '
         . 'placeholder="Explain why you are reverting this application">'
         . e((string)($_POST['remarks'] ?? '')) . '</textarea>'
         . '<div class="list-group mt-1 d-none" id="step2_revert_suggestions" '
         . 'data-step2-revert-suggestions role="listbox" aria-label="Suggested remarks">'
         . '<button class="list-group-item list-group-item-action small" type="button" '
         . 'role="option">I need to correct information in the application.</button>'
         . '<button class="list-group-item list-group-item-action small" type="button" '
         . 'role="option">I need to replace or update the uploaded documents.</button>'
         . '<button class="list-group-item list-group-item-action small" type="button" '
         . 'role="option">I submitted the application before completing all requirements.</button>'
         . '<button class="list-group-item list-group-item-action small" type="button" '
         . 'role="option">I need to revise the project details.</button></div>'
         . '<div class="form-text">Required, 10 to 3,000 characters.</div></div>'
         . '<div class="modal-footer"><button class="btn btn-secondary" type="button" '
         . 'data-bs-dismiss="modal">Cancel</button>'
         . csrf_field()
         . '<input type="hidden" name="revert_confirm" value="1">'
         . '<button class="btn btn-danger" type="submit" data-step2-revert-submit>'
         . 'Confirm Revert</button></div></form>'
        . '</div></div></div>'
        . '<script src="assets/permit-revert.js?v=2" defer></script>';
    return $html;
}

/** Explicit receipt cannot be undone by the applicant; staff reviews continue. */
function step2_receive_documents_html(array $application): string
{
    $id=(int)$application['id'];
    if (!step2_revert_schema_ready()) {
        return '<div class="alert alert-warning small">Migration 020 must be applied '
            . 'before DENR can formally receive these documents.</div>';
    }
    if (!empty($application['received_at'])) {
        return '<div class="alert alert-success small">'
            . '<i class="bi bi-check-circle me-1" aria-hidden="true"></i>'
            . 'Documents received by DENR on ' . e((string)$application['received_at'])
            . '. The applicant can no longer revert this submission.</div>';
    }
    return '<div class="border rounded-3 p-3 mb-3">'
        . '<h4 class="h6">Formal Receipt of Documents</h4>'
        . '<p class="small text-secondary">Acknowledge receipt before processing. '
        . 'This will immediately end the applicant’s ability to revert '
        . 'this submission, even if the 30-minute window is still open.</p>'
        . '<button type="button" class="btn btn-outline-primary" '
        . 'data-bs-toggle="modal" data-bs-target="#step2-receive-modal">'
        . '<i class="bi bi-inbox me-1" aria-hidden="true"></i>Receive Documents</button>'
        . '</div><div class="modal fade" id="step2-receive-modal" tabindex="-1" '
        . 'aria-labelledby="step2-receive-title" aria-hidden="true">'
        . '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h2 id="step2-receive-title" class="modal-title h5">'
        . 'Confirm Receipt of Documents</h2><button type="button" class="btn-close" '
        . 'data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body">Confirm that LPDD-FUS has received the submitted '
        . 'documents for FP Application #' . $id . '. This action is audited and '
        . 'prevents the applicant from reverting this submission.</div>'
        . '<div class="modal-footer">'
        . '<button class="btn btn-secondary" type="button" '
        . 'data-bs-dismiss="modal">Cancel</button>'
        . '<form method="post" action="' . e(url('permit_receive',['id'=>$id])) . '">'
        . csrf_field()
        . '<input type="hidden" name="receive_confirm" value="1">'
        . '<button class="btn btn-primary" type="submit">Confirm Receipt</button>'
        . '</form></div></div></div></div>';
}
