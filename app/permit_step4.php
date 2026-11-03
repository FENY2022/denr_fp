<?php
declare(strict_types=1);

/** Annex A Step 4 – LPDD-FUS formal documentary evaluation. */
function step4_schema_ready(): bool
{
    try {
        db()->query('SELECT id,application_id,revision,review_status FROM permit_step4_reviews LIMIT 0');
        db()->query('SELECT id,review_id,requirement_type,assessment FROM permit_step4_requirement_reviews LIMIT 0');
        return true;
    } catch (Throwable $e) {
        error_log('FP-OMS Step 4 migration 027 missing: '.$e->getMessage());
        return false;
    }
}

function step4_require_schema(): void
{
    step3_require_schema();
    if (!step4_schema_ready()) {
        show_error('Apply database migration 027 to enable Step 4 Documentary Evaluation.',503);
    }
}

function step4_require_reviewer(): array
{
    $reviewer=require_role('lpdd_fus');
    step4_require_schema();
    return $reviewer;
}

function step4_review(int $applicationId,int $revision=0): ?array
{
    if (!step4_schema_ready()) return null;
    if ($revision>0) {
        return query_one(
            'SELECT r.*,u.name AS reviewer_name
             FROM permit_step4_reviews r
             LEFT JOIN users u ON u.id=r.reviewer_user_id
             WHERE r.application_id=? AND r.revision=?',
            [$applicationId,$revision]
        );
    }
    return query_one(
        'SELECT r.*,u.name AS reviewer_name
         FROM permit_step4_reviews r
         LEFT JOIN users u ON u.id=r.reviewer_user_id
         WHERE r.application_id=?
         ORDER BY r.revision DESC,r.id DESC LIMIT 1',
        [$applicationId]
    );
}

function step4_review_items(int $reviewId): array
{
    if ($reviewId<1 || !step4_schema_ready()) return [];
    $rows=query_all(
        'SELECT requirement_type,assessment,remarks
         FROM permit_step4_requirement_reviews
         WHERE review_id=? ORDER BY id ASC',[$reviewId]
    );
    $items=[];
    foreach ($rows as $row) $items[(string)$row['requirement_type']]=$row;
    return $items;
}

function step4_assessment_label(string $assessment): string
{
    return match ($assessment) {
        'complete'=>'Complete',
        'incomplete'=>'Incomplete / Missing',
        'insufficient'=>'Insufficient',
        'not_applicable'=>'Not Applicable',
        default=>'Not Reviewed'
    };
}

function step4_allowed_assessments(): array
{
    return ['complete','incomplete','insufficient','not_applicable'];
}

/** @return array{items:array<string,array{assessment:string,remarks:string}>,errors:list<string>} */
function step4_parse_requirement_assessments(array $input): array
{
    $assessments=is_array($input['assessment'] ?? null) ? $input['assessment'] : [];
    $remarks=is_array($input['requirement_remarks'] ?? null) ? $input['requirement_remarks'] : [];
    $items=[];
    $errors=[];
    foreach (step2_requirement_types() as $type=>$label) {
        $assessment=trim((string)($assessments[$type] ?? ''));
        $note=trim((string)($remarks[$type] ?? ''));
        if (!in_array($assessment,step4_allowed_assessments(),true)) {
            $errors[]='Select an assessment for '.$label.'.';
            continue;
        }
        if (strlen($note)>1500) {
            $errors[]='Remarks for '.$label.' must not exceed 1,500 characters.';
        }
        if (in_array($assessment,['incomplete','insufficient'],true) && strlen($note)<5) {
            $errors[]='Add a short deficiency note for '.$label.'.';
        }
        $items[$type]=['assessment'=>$assessment,'remarks'=>$note];
    }
    return ['items'=>$items,'errors'=>$errors];
}

function step4_parse_schedule(string $value): ?string
{
    $value=trim($value);
    if ($value==='') return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value);
    $errors=DateTimeImmutable::getLastErrors();
    if (!$date || ($errors!==false && ($errors['warning_count']>0 || $errors['error_count']>0))) return null;
    return $date->format('Y-m-d H:i:00');
}

/** Incoming or resubmitted cases become an active Step 4 review. */
function step4_start(array $reviewer,int $applicationId): array
{
    if (($reviewer['user_kind'] ?? '')!=='internal'
        || ($reviewer['status'] ?? '')!=='active'
        || !has_role($reviewer,'lpdd_fus')) {
        return ['LPDD-FUS reviewer authorization is required.'];
    }
    if (!step4_schema_ready()) return ['Migration 027 is required.'];

    $pdo=db();
    try {
        $pdo->beginTransaction();
        $app=query_one(
            "SELECT a.*,c.name AS applicant_name
             FROM permit_step2_applications a
             JOIN user_client c ON c.id=a.client_id
             WHERE a.id=? FOR UPDATE",[$applicationId]
        );
        if (!$app || !in_array((string)$app['status'],['forwarded_to_lpdd','step4_resubmitted'],true)) {
            $pdo->rollBack();
            return ['This application is not awaiting Step 4 evaluation. Refresh the queue.'];
        }
        $revision=(int)$app['revision'];
        $existing=query_one(
            'SELECT id,review_status FROM permit_step4_reviews
             WHERE application_id=? AND revision=? FOR UPDATE',
            [$applicationId,$revision]
        );
        if ($existing) {
            if ((string)$existing['review_status']!=='under_evaluation') {
                $pdo->rollBack();
                return ['This revision already has a completed Step 4 decision.'];
            }
            $reviewId=(int)$existing['id'];
            $pdo->prepare(
                'UPDATE permit_step4_reviews
                 SET reviewer_user_id=? WHERE id=?'
            )->execute([(int)$reviewer['id'],$reviewId]);
        } else {
            $pdo->prepare(
                "INSERT INTO permit_step4_reviews
                 (application_id,revision,reviewer_user_id,review_status)
                 VALUES (?,?,?,'under_evaluation')"
            )->execute([$applicationId,$revision,(int)$reviewer['id']]);
            $reviewId=(int)$pdo->lastInsertId();
        }

        $pdo->prepare(
            "UPDATE permit_step2_applications
             SET status='step4_under_evaluation' WHERE id=?"
        )->execute([$applicationId]);
        $pdo->prepare(
            'INSERT INTO permit_step2_events
             (application_id,actor_user_id,action,revision,remarks)
             VALUES (?,?,?,?,?)'
        )->execute([
            $applicationId,(int)$reviewer['id'],'step4_review_started',$revision,
            'Formal Step 4 documentary evaluation started.'
        ]);
        audit((int)$reviewer['id'],'permit.step4.review_started',
            'permit_step2_application',$applicationId,
            ['revision'=>$revision,'review_id'=>$reviewId]);
        $pdo->commit();

        try {
            create_client_notification(
                (int)$app['client_id'],
                'Step 4 Documentary Evaluation Started',
                'LPDD-FUS started the formal documentary evaluation of FP application #'.$applicationId.'.',
                'clipboard-check','info','permit_application',['id'=>$applicationId]
            );
        } catch (Throwable $e) {
            error_log('FP-OMS Step 4 start notification failed: '.$e->getMessage());
        }
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS Step 4 start failed: '.$e->getMessage());
        return ['Step 4 evaluation could not be started.'];
    }
}

/**
 * Formal Step 4 decision.
 * Return requires at least one incomplete/insufficient requirement.
 * Complete requires every requirement complete or not applicable and a field schedule.
 */
function step4_decide(array $reviewer,int $applicationId,array $input): array
{
    if (($reviewer['user_kind'] ?? '')!=='internal'
        || ($reviewer['status'] ?? '')!=='active'
        || !has_role($reviewer,'lpdd_fus')) {
        return ['LPDD-FUS reviewer authorization is required.'];
    }
    if (!step4_schema_ready()) return ['Migration 027 is required.'];

    $decision=trim((string)($input['decision'] ?? ''));
    if (!in_array($decision,['return','complete'],true)) return ['Invalid Step 4 decision.'];
    $parsed=step4_parse_requirement_assessments($input);
    $errors=$parsed['errors'];
    $overall=trim((string)($input['overall_remarks'] ?? ''));
    $instructions=trim((string)($input['applicant_instructions'] ?? ''));
    if (strlen($overall)>4000) $errors[]='Overall remarks must not exceed 4,000 characters.';
    if (strlen($instructions)>3000) $errors[]='Applicant instructions must not exceed 3,000 characters.';

    $hasDeficiency=false;
    $allSufficient=true;
    foreach ($parsed['items'] as $item) {
        if (in_array($item['assessment'],['incomplete','insufficient'],true)) {
            $hasDeficiency=true;
            $allSufficient=false;
        } elseif (!in_array($item['assessment'],['complete','not_applicable'],true)) {
            $allSufficient=false;
        }
    }
    if ($decision==='return') {
        if (!$hasDeficiency) $errors[]='Mark at least one requirement Incomplete / Missing or Insufficient before returning.';
        if (strlen($overall)<10) $errors[]='Provide an overall deficiency explanation of at least 10 characters.';
    }

    $schedule=null;
    if ($decision==='complete') {
        if (!$allSufficient || count($parsed['items'])!==count(step2_requirement_types())) {
            $errors[]='Every requirement must be Complete or Not Applicable before marking the documentary requirements complete and sufficient.';
        }
        $rawSchedule=trim((string)($input['field_validation_schedule'] ?? ''));
        $schedule=step4_parse_schedule($rawSchedule);
        if ($rawSchedule==='' || $schedule===null) {
            $errors[]='Set the field validation / assessment schedule before completing Step 4.';
        }
    }
    if ($errors) return $errors;

    $pdo=db();
    try {
        $pdo->beginTransaction();
        $app=query_one(
            "SELECT a.*,c.name AS applicant_name,c.email AS applicant_email
             FROM permit_step2_applications a
             JOIN user_client c ON c.id=a.client_id
             WHERE a.id=? FOR UPDATE",[$applicationId]
        );
        if (!$app || (string)$app['status']!=='step4_under_evaluation') {
            $pdo->rollBack();
            return ['Application is no longer under Step 4 evaluation. Refresh the page.'];
        }
        $review=query_one(
            "SELECT * FROM permit_step4_reviews
             WHERE application_id=? AND revision=? AND review_status='under_evaluation'
             FOR UPDATE",[$applicationId,(int)$app['revision']]
        );
        if (!$review) {
            $pdo->rollBack();
            return ['No active Step 4 review exists for this revision.'];
        }

        foreach ($parsed['items'] as $type=>$item) {
            $pdo->prepare(
                'INSERT INTO permit_step4_requirement_reviews
                 (review_id,requirement_type,assessment,remarks)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE assessment=VALUES(assessment),
                    remarks=VALUES(remarks),updated_at=CURRENT_TIMESTAMP'
            )->execute([
                (int)$review['id'],$type,$item['assessment'],
                $item['remarks']!=='' ? $item['remarks'] : null
            ]);
        }

        $complete=$decision==='complete';
        $newStatus=$complete ? 'step4_complete' : 'step4_corrections_required';
        $event=$complete ? 'step4_complete' : 'step4_returned_for_compliance';
        $reviewStatus=$complete ? 'complete' : 'returned';
        $pdo->prepare(
            'UPDATE permit_step4_reviews
             SET reviewer_user_id=?,review_status=?,overall_remarks=?,
                 applicant_instructions=?,field_validation_schedule=?,decided_at=NOW()
             WHERE id=?'
        )->execute([
            (int)$reviewer['id'],$reviewStatus,$overall!=='' ? $overall : null,
            $instructions!=='' ? $instructions : null,$complete ? $schedule : null,
            (int)$review['id']
        ]);
        $pdo->prepare(
            'UPDATE permit_step2_applications SET status=? WHERE id=?'
        )->execute([$newStatus,$applicationId]);
        $pdo->prepare(
            'INSERT INTO permit_step2_events
             (application_id,actor_user_id,action,revision,remarks)
             VALUES (?,?,?,?,?)'
        )->execute([
            $applicationId,(int)$reviewer['id'],$event,(int)$app['revision'],
            $overall!=='' ? $overall : null
        ]);
        audit((int)$reviewer['id'],'permit.step4.'.$event,
            'permit_step2_application',$applicationId,[
                'revision'=>(int)$app['revision'],
                'review_id'=>(int)$review['id'],
                'field_validation_schedule'=>$complete ? $schedule : null
            ]);
        $pdo->commit();
        fpoms_pusher_flush_pending();

        try {
            if ($complete) {
                $message='FP application #'.$applicationId.' completed Step 4 documentary evaluation. '
                    .'Submit the required hard copies and pay the application fee at the Regional Office. '
                    .'Field validation/assessment schedule: '.fpoms_display_datetime((string)$schedule).'.';
                create_client_notification(
                    (int)$app['client_id'],
                    'Documentary Requirements Complete & Sufficient',
                    $message,'check-circle','success','permit_application',['id'=>$applicationId]
                );
                notify_role(
                    'cenro_twg','FP Application Ready for Step 5 Preparation',
                    'FP application #'.$applicationId.' completed Step 4. '
                    .'Field validation/assessment is scheduled for '.fpoms_display_datetime((string)$schedule).'.',
                    'geo-alt','info','permit_application',['id'=>$applicationId]
                );
            } else {
                create_client_notification(
                    (int)$app['client_id'],
                    'Step 4 Compliance Required',
                    'LPDD-FUS found incomplete or insufficient documentary requirements for FP application #'.$applicationId.'. Correct the deficiencies and resubmit the required documents.',
                    'exclamation-triangle','warning','permit_application',['id'=>$applicationId]
                );
            }
        } catch (Throwable $e) {
            error_log('FP-OMS Step 4 notification failed: '.$e->getMessage());
        }

        step4_send_applicant_email($app,$applicationId,$complete,$overall,$instructions,$schedule);
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS Step 4 decision failed: '.$e->getMessage());
        return ['Step 4 decision could not be saved.'];
    }
}

function step4_send_applicant_email(
    array $app,int $applicationId,bool $complete,string $remarks,
    string $instructions,?string $schedule
): void {
    $email=trim((string)($app['applicant_email'] ?? ''));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) return;

    $subject=$complete
        ? 'FP Application #'.$applicationId.' – Documentary Requirements Complete & Sufficient'
        : 'FP Application #'.$applicationId.' – Step 4 Compliance Required';
    $body='<p><strong>Reference:</strong> FP Application #'.$applicationId.'</p>';
    if ($complete) {
        $body.='<p>LPDD-FUS completed the documentary evaluation and found the submitted documentary requirements complete and sufficient.</p>'
            .'<ul><li>Submit the required hard copies.</li>'
            .'<li>Pay the required application fee at the DENR Regional Office.</li>'
            .'<li>Field validation / assessment schedule: <strong>'
            .e(fpoms_display_datetime((string)$schedule)).'</strong></li></ul>';
    } else {
        $body.='<p>LPDD-FUS found incomplete or insufficient documentary requirements. '
            .'Please correct the deficiencies, complete the missing requirements, and resubmit through FP-OMS.</p>';
    }
    if ($remarks!=='') $body.='<p><strong>Review remarks:</strong><br>'.nl2br(e($remarks)).'</p>';
    if ($instructions!=='') $body.='<p><strong>Additional instructions:</strong><br>'.nl2br(e($instructions)).'</p>';
    try {
        smtp_send_html(
            $email,$subject,
            registration_email_layout('Step 4 Documentary Evaluation',
                (string)($app['applicant_name'] ?? 'Applicant'),$body),
            strip_tags(str_replace(['<br>','<br/>','<br />'],"\n",$body))
        );
    } catch (Throwable $e) {
        error_log('FP-OMS Step 4 applicant email failed: '.$e->getMessage());
    }
}
