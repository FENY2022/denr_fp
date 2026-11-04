<?php
declare(strict_types=1);

/**
 * FP-OMS Step 2 application tracking.
 *
 * All case milestones come from the existing permit_step2_events
 * records; do not synthesize a receipt, employee assignment or Step 3 event.
 * Registration history continues to come from registration_reviews.
 */

/** The Step 4 payment action changes only after an actual LPDD-FUS verification. */
function permit_tracking_step4_payment_state(array $app): array
{
    $payment=permit_payment_record((int)($app['id']??0));
    if ($payment && (string)$payment['status']==='verified') {
        return [
            'office'=>'LPDD-FUS / CENRO Step 5 Preparation',
            'next_role'=>'CENRO Technical Working Group',
            'next_action'=>'Cashier payment verified by LPDD-FUS. Follow the field validation / assessment instructions and await Step 5 processing.',
            'assignment'=>'Step 5 formal processing is not yet implemented'
        ];
    }
    return [
        'office'=>'DENR CARAGA Regional Office – Cashier / LPDD-FUS',
        'next_role'=>'Applicant → Cashier → LPDD-FUS',
        'next_action'=>'Pay the official application fee at the Regional Office Cashier, then proceed directly to LPDD-FUS and present the Official Receipt for verification. Submit required hard copies.',
        'assignment'=>'Awaiting Cashier payment and authorized LPDD-FUS verification'
    ];
}

/** @return array{office:string,next_role:string,next_action:string,assignment:string} */
/** Updated Annex A stage tracking uses the authoritative workflow case record. */
function permit_flow_current_tracking(array $app): array
{
    $case=permit_flow_case((int)($app['id']??0));
    if (!$case) return permit_tracking_step4_payment_state($app);
    $step=(int)$case['current_step'];
    if ($step>=12) {
        return [
            'office'=>'RO Receiving/Releasing (Completed)',
            'next_role'=>'Applicant',
            'next_action'=>'Feasibility Permit issued; view the approved documents and recorded release.',
            'assignment'=>'Application completed'
        ];
    }
    $role=match ($step) {
        5=>'CENRO / TWG',6=>'SMD / CDD / RRDC Secretariat',
        7=>'LPDD-FUS',8=>'LPDD Chief',9=>'ARD-TS',
        10=>'RED',11=>'RO Receiving/Releasing',default=>'LPDD-FUS'
    };
    return [
        'office'=>$role.' – Step '.$step,
        'next_role'=>$role,
        'next_action'=>permit_flow_step_label($step).'. '
            .'Open the updated Annex A case to view required documents and recorded handoffs.',
        'assignment'=>'Step '.$step.' – Updated Annex A'
    ];
}

function permit_tracking_state(array $app): array
{
    $status = (string)($app['status'] ?? '');
    if (permit_flow_is_new($app)) {
        return match ($status) {
            'submitted','step4_under_evaluation','step4_resubmitted'=>[
                'office'=>'LPDD-FUS – Step 2',
                'next_role'=>'LPDD-FUS Reviewer',
                'next_action'=>'Determine completeness and sufficiency of submitted documents. '
                    .'Return Lacking/Inappropriate files with an actual RED-signed notice, '
                    .'or forward Complete & Sufficient to RO Receiving.',
                'assignment'=>'Step 2 documentary completeness/sufficiency decision'
            ],
            'step4_corrections_required','corrections_required'=>[
                'office'=>'Applicant / Client – Step 2',
                'next_role'=>'Applicant',
                'next_action'=>'Read the RED-signed deficiency notice, correct missing/inappropriate '
                    .'documents, and resubmit directly for LPDD-FUS Step 2 re-evaluation.',
                'assignment'=>'Applicant compliance'
            ],
            'forwarded_to_ro'=>[
                'office'=>'RO Receiving/Releasing – Step 3',
                'next_role'=>'RO Receiving/Releasing',
                'next_action'=>'Receive the electronic application documents formally.',
                'assignment'=>'Step 2 evaluation is already complete and sufficient'
            ],
            'ro_received'=>[
                'office'=>'RO Receiving/Releasing – Step 3',
                'next_role'=>'RO Receiving/Releasing',
                'next_action'=>'Forward the recorded electronic documents to LPDD-FUS Step 4 actions.',
                'assignment'=>'RO formal forwarding'
            ],
            'forwarded_to_lpdd'=>[
                'office'=>'LPDD-FUS – Step 4',
                'next_role'=>'LPDD-FUS',
                'next_action'=>'Send Cashier payment notice, communicate field assessment schedule, '
                    .'generate electronic notification and prepare memo instruction to CENRO.',
                'assignment'=>'After RO Receiving Step 3'
            ],
            'step4_complete'=>permit_flow_current_tracking($app),
            'reverted'=>[
                'office'=>'Applicant / Client',
                'next_role'=>'Applicant',
                'next_action'=>'Edit and resubmit the reverted application within the applicable rules.',
                'assignment'=>'Applicant'
            ],
            default=>[
                'office'=>'LPDD-FUS / Workflow Review',
                'next_role'=>'LPDD-FUS',
                'next_action'=>'Open the updated Annex A workflow and review the recorded case actions.',
                'assignment'=>'Updated Annex A case'
            ]
        };
    }
    return match ($status) {
        'submitted' => [
            'office'=>'LPDD-FUS (Regional Office)',
            'next_role'=>'LPDD-FUS Reviewing Personnel',
            'next_action'=>empty($app['received_at'])
                ? 'Acknowledge receipt of submitted documents'
                : 'Complete initial documentary review; forward or return for correction',
            'assignment'=>'No individual assignee is recorded for this step'
        ],
        'corrections_required' => [
            'office'=>'Applicant / Client',
            'next_role'=>'Applicant',
            'next_action'=>'Correct the deficiencies and resubmit the FP application',
            'assignment'=>'Application owner'
        ],
        'reverted' => [
            'office'=>'Applicant / Client',
            'next_role'=>'Applicant',
            'next_action'=>'Edit and resubmit the reverted FP application',
            'assignment'=>'Application owner'
        ],
        'forwarded_to_ro' => [
            'office'=>'RO Receiving/Releasing (Regional Office)',
            'next_role'=>'RO Receiving Personnel',
            'next_action'=>'Receive electronic application documents at RO Receiving (Step 3)',
            'assignment'=>'No individual assignee is recorded for this step'
        ],
        'ro_received' => [
            'office'=>'RO Receiving/Releasing (Regional Office)',
            'next_role'=>'RO Receiving Personnel',
            'next_action'=>'Forward the received application documents to LPDD-FUS for Step 4 documentary evaluation',
            'assignment'=>'No individual assignee is recorded for this step'
        ],
        'forwarded_to_lpdd' => [
            'office'=>'LPDD-FUS (Regional Office)',
            'next_role'=>'LPDD-FUS Documentary Evaluation Personnel',
            'next_action'=>'Start Step 4 formal documentary evaluation',
            'assignment'=>'No individual assignee is recorded for this step'
        ],
        'step4_under_evaluation' => [
            'office'=>'LPDD-FUS (Regional Office)',
            'next_role'=>'LPDD-FUS Documentary Evaluation Personnel',
            'next_action'=>'Evaluate completeness and sufficiency of every documentary requirement',
            'assignment'=>'Current Step 4 review record identifies the reviewer'
        ],
        'step4_corrections_required' => [
            'office'=>'Applicant / Client',
            'next_role'=>'Applicant',
            'next_action'=>'Correct deficiencies, complete missing documentary requirements, and resubmit for Step 4 re-evaluation',
            'assignment'=>'Application owner'
        ],
        'step4_resubmitted' => [
            'office'=>'LPDD-FUS (Regional Office)',
            'next_role'=>'LPDD-FUS Documentary Evaluation Personnel',
            'next_action'=>'Re-evaluate the applicant compliance submission under Step 4',
            'assignment'=>'No individual assignee is recorded until review starts'
        ],
        'step4_complete' => permit_tracking_step4_payment_state($app),
        default => [
            'office'=>'Unknown / workflow not implemented',
            'next_role'=>'Not determined',
            'next_action'=>'Contact the responsible DENR processing office',
            'assignment'=>'No individual assignee recorded'
        ]
    };
}

function permit_tracking_event_label(string $action): string
{
    return match ($action) {
        'submitted'=>'Application Submitted',
        'resubmitted'=>'Application Resubmitted',
        'documents_received'=>'Documents Received by LPDD-FUS',
        'returned_for_correction'=>'Returned for Correction',
        'forwarded_to_ro'=>'Forwarded to RO Receiving',
        'ro_documents_received'=>'Documents Received by RO Receiving',
        'forwarded_to_lpdd'=>'Forwarded to LPDD-FUS for Step 4',
        'step4_review_started'=>'Step 4 Documentary Evaluation Started',
        'step4_returned_for_compliance'=>'Step 4 Returned for Compliance',
        'step4_resubmitted'=>'Step 4 Compliance Resubmitted',
        'step4_complete'=>'Step 4 Complete & Sufficient',
        'payment_verified_lpdd_fus'=>'Cashier Payment Verified by LPDD-FUS',
        'ro_archived'=>'Records Action Archived',
        'reverted'=>'Reverted by Applicant',
        default=>ucwords(str_replace('_',' ', $action))
    };
}

function permit_tracking_event_destination(string $action): string
{
    return match ($action) {
        'submitted','resubmitted','documents_received'=>'LPDD-FUS (Regional Office)',
        'returned_for_correction','reverted'=>'Applicant / Client',
        'forwarded_to_ro'=>'RO Receiving/Releasing',
        'ro_documents_received'=>'RO Receiving/Releasing',
        'forwarded_to_lpdd','step4_review_started','step4_resubmitted'=>'LPDD-FUS (Regional Office)',
        'step4_returned_for_compliance'=>'Applicant / Client',
        'step4_complete'=>'LPDD-FUS / Cashier / LPDD-FUS',
        'payment_verified_lpdd_fus'=>'LPDD-FUS (Regional Office)',
        'ro_archived'=>'Records Archiving',
        default=>'Not recorded'
    };
}

/**
 * Staff history is filtered by the logged-in actor ID in BOTH sources.
 * No action is inferred from accessing a document or receiving a notification.
 *
 * @return array<int,array<string,mixed>>
 */
function permit_tracking_recent_actions(array $staff, int $limit = 100): array
{
    if (($staff['user_kind'] ?? '') !== 'internal'
        || ($staff['status'] ?? '') !== 'active'
        || (int)($staff['id'] ?? 0) < 1) return [];
    $limit = max(1, min(100, $limit));
    $staffId = (int)$staff['id'];
    $actions = [];
    // An archived case vanishes from Records My Recent Actions, while every
    // original event remains in permit_step2_events and the archive viewer.
    $hideArchivedRoActions = has_role($staff,'ro_receiving') && records_archive_schema_ready();

    if (step2_schema_ready()) {
        $rows = query_all(
            'SELECT ev.id AS event_id,ev.application_id,ev.action,ev.revision,
                    ev.occurred_at,a.project_title,a.status AS case_status,
                    c.name AS applicant_name
             FROM permit_step2_events ev
             INNER JOIN permit_step2_applications a ON a.id=ev.application_id
             INNER JOIN user_client c ON c.id=a.client_id
             WHERE ev.actor_user_id=?' . ($hideArchivedRoActions
                 ? " AND NOT EXISTS (SELECT 1 FROM permit_records_archives ra WHERE ra.application_id=ev.application_id)"
                 : '') . '
             ORDER BY ev.occurred_at DESC,ev.id DESC LIMIT ' . $limit,
            [$staffId]
        );
        foreach ($rows as $row) {
            $actions[] = [
                'type'=>'fp','id'=>(int)$row['application_id'],
                'event_id'=>(int)$row['event_id'],
                'action'=>(string)$row['action'],
                'label'=>permit_tracking_event_label((string)$row['action']),
                'occurred_at'=>(string)$row['occurred_at'],
                'title'=>(string)$row['project_title'],
                'person'=>(string)$row['applicant_name'],
                'case_status'=>(string)$row['case_status'],
                'revision'=>(int)$row['revision']
            ];
        }
    }

    // Registration review decisions are historical actions distinct from
    // outbound-email notifications, which are not processing decisions.
    if (has_role($staff,'lpdd_fus')) {
        $rows = query_all(
            'SELECT rr.id AS event_id,rr.registration_id,rr.decision,
                    rr.revision,rr.reviewed_at,
                    ar.company_name,ar.representative_name,
                    uc.status AS case_status
             FROM registration_reviews rr
             INNER JOIN applicant_registrations ar ON ar.id=rr.registration_id
             INNER JOIN user_client uc ON uc.id=ar.client_id
             WHERE rr.reviewer_user_id=?
             ORDER BY rr.reviewed_at DESC,rr.id DESC LIMIT ' . $limit,
            [$staffId]
        );
        foreach ($rows as $row) {
            $actions[] = [
                'type'=>'registration','id'=>(int)$row['registration_id'],
                'event_id'=>(int)$row['event_id'],
                'action'=>(string)$row['decision'],
                'label'=>match ((string)$row['decision']) {
                    'approved'=>'Registration Approved',
                    'needs_correction'=>'Registration Returned for Correction',
                    default=>'Registration Review'
                },
                'occurred_at'=>(string)$row['reviewed_at'],
                'title'=>(string)$row['company_name'],
                'person'=>(string)$row['representative_name'],
                'case_status'=>(string)$row['case_status'],
                'revision'=>(int)$row['revision']
            ];
        }
    }

    usort($actions, static function (array $a, array $b): int {
        $date = strcmp($b['occurred_at'], $a['occurred_at']);
        if ($date !== 0) return $date;
        $id = $b['event_id'] <=> $a['event_id'];
        return $id !== 0 ? $id : strcmp($a['type'], $b['type']);
    });
    return array_slice($actions, 0, $limit);
}

/** Read-only access to a case previously acted on, subject to current role. */
function permit_tracking_historical_staff_access(array $staff, int $applicationId): bool
{
    if (($staff['user_kind'] ?? '') !== 'internal'
        || ($staff['status'] ?? '') !== 'active'
        || $applicationId < 1
        || !(has_role($staff,'lpdd_fus') || has_role($staff,'ro_receiving'))) {
        return false;
    }
    return query_one(
        'SELECT id FROM permit_step2_events
         WHERE application_id=? AND actor_user_id=?
         AND action IN (
           \'documents_received\',\'returned_for_correction\',\'forwarded_to_ro\',
           \'ro_documents_received\',\'forwarded_to_lpdd\',\'step4_review_started\',
           \'step4_returned_for_compliance\',\'step4_resubmitted\',\'step4_complete\'
         )
         LIMIT 1',
        [$applicationId,(int)$staff['id']]
    ) !== null;
}
