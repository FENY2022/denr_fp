<?php
declare(strict_types=1);

/**
 * FP-OMS Step 2 application tracking.
 *
 * All case milestones come from the existing permit_step2_events
 * records; do not synthesize a receipt, employee assignment or Step 3 event.
 * Registration history continues to come from registration_reviews.
 */

/** @return array{office:string,next_role:string,next_action:string,assignment:string} */
function permit_tracking_state(array $app): array
{
    $status = (string)($app['status'] ?? '');
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
            'next_action'=>'Await formal Step 3 receipt (not yet implemented)',
            'assignment'=>'No individual assignee is recorded for this step'
        ],
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

    if (step2_schema_ready()) {
        $rows = query_all(
            'SELECT ev.id AS event_id,ev.application_id,ev.action,ev.revision,
                    ev.occurred_at,a.project_title,a.status AS case_status,
                    c.name AS applicant_name
             FROM permit_step2_events ev
             INNER JOIN permit_step2_applications a ON a.id=ev.application_id
             INNER JOIN user_client c ON c.id=a.client_id
             WHERE ev.actor_user_id=?
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
         AND action IN (\'documents_received\',\'returned_for_correction\',\'forwarded_to_ro\')
         LIMIT 1',
        [$applicationId,(int)$staff['id']]
    ) !== null;
}
