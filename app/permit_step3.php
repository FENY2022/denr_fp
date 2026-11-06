<?php
declare(strict_types=1);

/** Annex A Step 3 – RO Receiving/Releasing. Step 4 payment is NOT covered. */
function step3_schema_ready(): bool
{
    try {
        db()->query('SELECT application_id,revision,received_at,forwarded_at FROM permit_step3_movements LIMIT 0');
        return true;
    } catch (Throwable $e) {
        error_log('FP-OMS Step 3 migration 022 missing: '.$e->getMessage());
        return false;
    }
}

function step3_require_schema(): void
{
    step2_require_schema();
    if (!step3_schema_ready()) show_error('Apply database migration 022 to enable RO Receiving Step 3.',503);
}
function step3_require_officer(): array
{
    $staff=require_role('ro_receiving');
    step3_require_schema();
    return $staff;
}
/** Valid transitions prevent duplicate and out-of-order processing. */
function step3_transition_allowed(string $status,string $action): bool
{
    return ($status==='forwarded_to_ro' && $action==='receive')
        || ($status==='ro_received' && $action==='forward');
}
function step3_movement(int $applicationId): ?array
{
    if (!step3_schema_ready()) return null;
    return query_one(
        'SELECT m.*,u.name AS receiver_name,v.name AS forwarder_name
         FROM permit_step3_movements m
         LEFT JOIN users u ON u.id=m.received_by_user_id
         LEFT JOIN users v ON v.id=m.forwarded_by_user_id WHERE m.application_id=?',
        [$applicationId]);
}

/** Formal RO receipt and forwarding are independent of Step 2 initial receipt. */
function step3_record_action(array $staff,int $applicationId,string $action,string $remarks): array
{
    if (($staff['user_kind'] ?? '')!=='internal' || ($staff['status'] ?? '')!=='active'
        || !has_role($staff,'ro_receiving')) return ['Only authorized RO Receiving personnel may process this application.'];
    if (!step2_schema_ready() || !step3_schema_ready()) return ['Migration 022 is required.'];
    if ($applicationId<1 || !in_array($action,['receive','forward'],true)) return ['Invalid action.'];
    $remarks=trim($remarks);
    if (strlen($remarks)>2000) return ['Remarks must not exceed 2,000 bytes.'];
    $pdo=db();
    try {
        $pdo->beginTransaction();
        $app=query_one('SELECT a.*,c.name AS applicant_name,c.email AS applicant_email
            FROM permit_step2_applications a JOIN user_client c ON c.id=a.client_id
            WHERE a.id=? FOR UPDATE',[$applicationId]);
        if (!$app || !step3_transition_allowed((string)$app['status'],$action)) {
            $pdo->rollBack();
            return ['Application status changed or this action was already recorded. Refresh the RO inbox.'];
        }
        if ($action==='receive') {
            if (permit_flow_is_new($app) && !permit_payment_schema_ready()) {
                $pdo->rollBack();
                return ['Apply payment migration 028 before receiving and issuing the applicant Cashier notice.'];
            }
            if (empty($app['forwarded_at'])) {
                $pdo->rollBack();
                return ['LPDD-FUS must record Step 2 forwarding before RO may receive this application.'];
            }
            // An unchanged document is still part of a corrected submission,
            // even when its original upload row belongs to an earlier revision.
            // Verify every effective file (including carried-forward files)
            // rather than requiring a duplicate row for the current revision.
            $documentError=step2_effective_documents_error($applicationId,(int)$app['revision']);
            if ($documentError!==null) {
                $pdo->rollBack();
                return [$documentError];
            }
            $pdo->prepare('INSERT INTO permit_step3_movements
                (application_id,revision,received_at,received_by_user_id,receipt_remarks)
                VALUES (?,?,NOW(),?,?)')->execute([
                    $applicationId,(int)$app['revision'],(int)$staff['id'],$remarks !== '' ? $remarks : null
                ]);
            if (permit_flow_is_new($app)) {
                // Create the payment-tracking record as part of the same RO
                // receipt transaction; receipt never implies payment verified.
                $pdo->prepare("INSERT INTO permit_payment_verifications
                    (application_id,status,notified_at)
                    VALUES (?,'awaiting_payment',NOW())
                    ON DUPLICATE KEY UPDATE
                      notified_at=COALESCE(notified_at,VALUES(notified_at))")
                    ->execute([$applicationId]);
            }
            $next='ro_received';
            $event='ro_documents_received';
        } else {
            $movement=query_one(
                'SELECT * FROM permit_step3_movements WHERE application_id=? FOR UPDATE',
                [$applicationId]);
            if (!$movement || empty($movement['received_at'])
                || !empty($movement['forwarded_at'])
                || (int)$movement['revision']!==(int)$app['revision']) {
                $pdo->rollBack();
                return ['Formal RO receipt must be recorded before forwarding.'];
            }
            $pdo->prepare('UPDATE permit_step3_movements
                SET forwarded_at=NOW(),forwarded_by_user_id=?,forwarding_remarks=?
                WHERE application_id=? AND forwarded_at IS NULL')->execute([
                    (int)$staff['id'],$remarks !== '' ? $remarks : null,$applicationId
                ]);
            $next='forwarded_to_lpdd';
            $event='forwarded_to_lpdd';
        }
        $update=$pdo->prepare('UPDATE permit_step2_applications SET status=?
            WHERE id=? AND status=?');
        $update->execute([$next,$applicationId,(string)$app['status']]);
        if ($update->rowCount()!==1) throw new RuntimeException('Concurrent update rejected');
        $pdo->prepare('INSERT INTO permit_step2_events
            (application_id,actor_user_id,action,revision,remarks) VALUES (?,?,?,?,?)')
            ->execute([$applicationId,(int)$staff['id'],$event,(int)$app['revision'],
                $remarks !== '' ? $remarks : null]);
        if ($action==='forward' && permit_flow_is_new($app)) {
            if (!permit_flow_ready()) throw new RuntimeException('Migration 031 required');
            $pdo->prepare('INSERT INTO permit_flow_cases (application_id,current_step)
                VALUES (?,4) ON DUPLICATE KEY UPDATE application_id=application_id')
                ->execute([$applicationId]);
        }
        audit((int)$staff['id'],'permit.step3.'.$event,'permit_step2_application',
            $applicationId,['revision'=>(int)$app['revision'],'remarks'=>$remarks]);
        $pdo->commit();

        // Delivery failures never roll back a recorded action.
        $newFlowReceipt=$action==='receive' && permit_flow_is_new($app);
        try {
            create_client_notification((int)$app['client_id'],
                $newFlowReceipt ? 'Notice to Pay Application Fee'
                    : ($action==='receive' ? 'FP Application Received by RO' : 'FP Application Forwarded to LPDD-FUS'),
                $newFlowReceipt ? permit_payment_instructions($applicationId)
                    : ($action==='receive' ? 'RO Receiving received FP application #'.$applicationId.'.'
                        : 'RO Receiving forwarded FP application #'.$applicationId.' to LPDD-FUS for Step 4 field validation schedule and CENRO instruction.'),
                $newFlowReceipt ? 'receipt' : ($action==='receive' ? 'inbox':'send'),
                'info','permit_application',['id'=>$applicationId]);
            if ($action==='forward') notify_role('lpdd_fus',
                permit_flow_is_new($app)
                    ? 'FP Application – Step 4 Notice & CENRO Instructions'
                    : 'FP Application for Documentary Evaluation',
                'FP application #'.$applicationId.' forwarded by RO Receiving; open the appropriate Step 4 queue.',
                'file-earmark-check','info',
                permit_flow_is_new($app)?'permit_flow_queue':'permit_step4_queue');
        } catch (Throwable $e) {
            error_log('FP-OMS Step 3 notification failed: '.$e->getMessage());
        }
        if ($newFlowReceipt) {
            // Only the successful RO receipt triggers the Cashier email.
            // Repeated receive actions are rejected by the status transition.
            if (!permit_flow_email($app,'Notice to Pay – FP Application #'.$applicationId,
                permit_payment_instructions($applicationId))) {
                error_log('FP-OMS Step 3 Cashier notice email failed for FP #'.$applicationId);
            }
        } else {
            step3_send_email_alerts($app,$applicationId,$action);
        }
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS Step 3 action failed: '.$e->getMessage());
        return ['RO Step 3 action could not be saved. Verify migration 022 and try again.'];
    }
}

function step3_send_email_alerts(array $app,int $id,string $action): void
{
    $forward=$action==='forward';
    $message=$forward ? (permit_flow_is_new($app)
        ? 'Forwarded to LPDD-FUS for Step 4 Payment Notice / CENRO Instruction'
        : 'Forwarded to LPDD-FUS for Step 4 Documentary Evaluation')
        : 'Received by RO Receiving';
    $email=(string)($app['applicant_email'] ?? '');
    if (filter_var($email,FILTER_VALIDATE_EMAIL)) {
        $body='<p>FP application <strong>#'.$id.'</strong>: '.e($message).'.</p>'
            .'<p>Log in to FP-OMS to view the recorded status and audit trail. '
            .'The application fee is not collected at Step 3.</p>';
        try {
            smtp_send_html($email,'FP Application #'.$id.' – '.$message,
                registration_email_layout('FP Application Status',
                    (string)$app['applicant_name'],$body),
                "FP application #{$id}: {$message}. Log in to FP-OMS for details.");
        } catch (Throwable $e) {
            error_log('FP-OMS Step 3 applicant email failed: '.$e->getMessage());
        }
    }
    if (!$forward) return;
    $base=step2_email_base_url();
    $link=$base !== null ? $base.'?next='
        .(permit_flow_is_new($app)?'permit-flow-queue':'permit-step4-queue') : null;
    $body='<p>FP application <strong>#'.$id.'</strong> has been forwarded by RO Receiving '
        .'for Annex A Step 4 '.(permit_flow_is_new($app)
            ? 'payment notice, field schedule and CENRO memo instructions.'
            : 'documentary evaluation.').'</p>'
        .($link!==null ? '<p><a href="'.e($link).'">Open incoming queue</a></p>':'');
    foreach (step2_staff_recipients('lpdd_fus') as $address) {
        try {
            smtp_send_html($address,'FP Application #'.$id.' – Step 4 Incoming',
                registration_email_layout('New Step 4 Incoming Application','LPDD-FUS Personnel',$body),
                "FP application #{$id} forwarded to LPDD-FUS."
                    .($link!==null ? "\n".$link:''));
        } catch (Throwable $e) {
            error_log('FP-OMS Step 3 LPDD email failed: '.$e->getMessage());
        }
    }
}
