<?php
declare(strict_types=1);

/** Shared location and next-action summary; display only real routing state. */
function permit_tracking_summary_html(array $application): string
{
    $state = permit_tracking_state($application);
    $receipt = !empty($application['received_at'])
        ? '<p class="small text-success mb-2"><i class="bi bi-check-circle me-1"></i>'
            . 'LPDD-FUS receipt acknowledged on '
            . e((string)$application['received_at']) . '</p>'
        : '';
    return '<section class="panel mb-3" aria-label="Application tracking overview">'
        . '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">'
        . '<h3 class="h6 mb-0"><i class="bi bi-signpost-split me-2"></i>Application Tracking</h3>'
        . step2_status_badge((string)($application['status'] ?? '')) . '</div>'
        . '<div class="row g-3">'
        . '<div class="col-md-6"><div class="border rounded-3 p-3 h-100">'
        . '<div class="small text-secondary mb-1">Current Office / Location</div>'
        . '<strong>' . e($state['office']) . '</strong></div></div>'
        . '<div class="col-md-6"><div class="border rounded-3 p-3 h-100">'
        . '<div class="small text-secondary mb-1">Next to Act</div>'
        . '<strong>' . e($state['next_role']) . '</strong>'
        . '<div class="small text-secondary mt-1">' . e($state['assignment']) . '</div>'
        . '</div></div></div>'
        . '<div class="mt-3"><div class="small text-secondary mb-1">Required Next Action</div>'
        . '<p class="mb-2">' . e($state['next_action']) . '</p>' . $receipt
        . '<p class="small text-secondary mb-0">Tracking shows recorded actions only. '
        . 'A named next assignee is not displayed unless an actual assignment exists. '
        . 'Formal RO Step 3 processing is not yet implemented.</p></div></section>';
}

/**
 * Safe, static visual vocabulary for the six authoritative Step 2 actions.
 * Unknown actions use a neutral fallback. Never interpolate an event action
 * from the database into a CSS class or icon name.
 *
 * @return array{tone:string,icon:string,category:string}
 */
function permit_tracking_action_visual(string $action): array
{
    return match ($action) {
        'submitted' => ['tone'=>'submitted','icon'=>'send-check-fill','category'=>'Submitted'],
        'documents_received' => ['tone'=>'received','icon'=>'inbox-fill','category'=>'Received'],
        'resubmitted' => ['tone'=>'resubmitted','icon'=>'arrow-repeat','category'=>'Resubmitted'],
        'returned_for_correction' => ['tone'=>'correction','icon'=>'exclamation-triangle-fill','category'=>'Correction Needed'],
        'forwarded_to_ro' => ['tone'=>'forwarded','icon'=>'check-circle-fill','category'=>'Forwarded'],
        'reverted' => ['tone'=>'reverted','icon'=>'arrow-counterclockwise','category'=>'Reverted'],
        default => ['tone'=>'other','icon'=>'clock-history','category'=>'Recorded']
    };
}

/** Every item comes from permit_step2_events. No editable event controls. */
function permit_tracking_timeline_html(int $applicationId): string
{
    $events = array_reverse(step2_events($applicationId));
    $html = '<section class="mt-4" aria-label="Chronological application audit trail">'
        . '<h3 class="h6"><i class="bi bi-clock-history me-2"></i>'
        . 'Application Audit Trail</h3>'
        . '<p class="small text-secondary">Recorded submissions, document receipt, '
        . 'review decisions, corrections, reversion and forwarding. '
        . 'Each event retains its original revision and timestamp.</p>';
    if (!$events) {
        return $html . '<p class="small text-secondary">No recorded application actions.</p></section>';
    }
    $html .= '<ol class="list-unstyled fp-audit-timeline">';
    foreach ($events as $event) {
        $action = (string)$event['action'];
        $visual = permit_tracking_action_visual($action);
        $actor = trim((string)($event['staff_actor'] ?? $event['applicant_actor'] ?? ''));
        if ($actor === '') {
            $actor = !empty($event['actor_user_id']) ? 'DENR personnel (historical account)'
                : (!empty($event['actor_client_id']) ? 'Applicant (historical account)' : 'System');
        }
        $who = !empty($event['actor_user_id']) ? 'DENR Personnel' :
            (!empty($event['actor_client_id']) ? 'Applicant' : 'System');
        $staffOffice = !empty($event['actor_user_id']) && trim((string)($event['staff_office'] ?? '')) !== ''
            ? ' · ' . e((string)$event['staff_office']) : '';
        $remarks = trim((string)($event['remarks'] ?? ''));
        $html .= '<li class="fp-audit-event fp-audit-event--' . $visual['tone'] . '">'
            . '<span class="fp-audit-icon" aria-hidden="true"><i class="bi bi-'
            . $visual['icon'] . '"></i></span>'
            . '<div class="fp-audit-event-content">'
            . '<div class="fp-audit-event-heading">'
            . '<div class="fp-audit-event-title">'
            . '<strong>' . e(permit_tracking_event_label($action)) . '</strong>'
            . '<span class="fp-audit-action-label">' . e($visual['category']) . '</span>'
            . '</div><time class="small text-secondary">'
            . e((string)$event['occurred_at']) . '</time></div>'
            . '<p class="small mb-1"><i class="bi bi-person-check me-1" aria-hidden="true"></i>'
            . e($actor) . ' · ' . e($who) . $staffOffice
            . ' · Revision ' . (int)$event['revision'] . '</p>'
            . '<p class="small text-secondary mb-0">Routed to: '
            . e(permit_tracking_event_destination($action)) . '</p>'
            . ($remarks !== '' ? '<p class="small fp-audit-remarks mt-2 mb-0">'
                . nl2br(e($remarks)) . '</p>' : '')
            . '</div></li>';
    }
    return $html . '</ol></section>';
}

/** Audit history is intentionally hidden until the authorized viewer opens it. */
function permit_tracking_audit_modal_html(int $applicationId): string
{
    return '<div class="modal fade" id="fp-audit-trail-modal" tabindex="-1" '
        . 'aria-labelledby="fp-audit-trail-title" aria-hidden="true">'
        . '<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">'
        . '<div class="modal-content"><div class="modal-header">'
        . '<h2 class="modal-title h5" id="fp-audit-trail-title">'
        . '<i class="bi bi-clock-history me-2" aria-hidden="true"></i>'
        . 'Application Audit Trail — FP #' . (int)$applicationId . '</h2>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
        . '</div><div class="modal-body">'
        . permit_tracking_timeline_html($applicationId)
        . '</div><div class="modal-footer">'
        . '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>'
        . '</div></div></div></div>';
}

/** Compact client dashboard link to each current application tracking page. */
function permit_tracking_client_dashboard_html(array $client): string
{
    if (($client['user_kind'] ?? '') !== 'applicant'
        || ($client['status'] ?? '') !== 'active'
        || !step2_schema_ready()) return '';
    $receiptField = step2_revert_schema_ready() ? 'received_at' : 'NULL AS received_at';
    $apps = query_all(
        'SELECT id,project_title,status,' . $receiptField . ',last_submitted_at
         FROM permit_step2_applications WHERE client_id=?
         ORDER BY last_submitted_at DESC,id DESC LIMIT 5', [(int)$client['id']]
    );
    $html = '<section class="panel mt-3"><div class="d-flex justify-content-between '
        . 'align-items-center gap-2 flex-wrap mb-3"><h2 class="h5 m-0">'
        . '<i class="bi bi-signpost-split me-2"></i>Track My FP Applications</h2>'
        . '<a class="btn btn-sm btn-outline-primary" href="'
        . e(url('permit_applications')) . '">View All</a></div>';
    if (!$apps) return $html . '<p class="small text-secondary mb-0">'
        . 'Your submitted FP applications will appear here.</p></section>';
    foreach ($apps as $app) {
        $state = permit_tracking_state($app);
        $html .= '<div class="border rounded-3 p-3 mb-2">'
            . '<div class="d-flex align-items-start justify-content-between flex-wrap gap-2">'
            . '<div><strong>FP #' . (int)$app['id'] . ' — '
            . e((string)$app['project_title']) . '</strong><div class="small text-secondary">'
            . 'Current: ' . e($state['office']) . '</div>'
            . '<div class="small text-secondary">Next: '
            . e($state['next_role']) . '</div></div>'
            . '<div class="d-flex flex-wrap gap-2">'
            . '<a class="btn btn-sm btn-outline-primary" href="'
            . e(url('permit_application',['id'=>(int)$app['id'],'view'=>'files']))
            . '"><i class="bi bi-folder2-open me-1" aria-hidden="true"></i>View Files</a>'
            . '<a class="btn btn-sm btn-outline-secondary" href="'
            . e(url('permit_application',['id'=>(int)$app['id'],'view'=>'audit']))
            . '"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>View Audit Trail</a>'
            . '</div></div></div>';
    }
    return $html . '</section>';
}

function permit_tracking_staff_recent_html(array $staff, int $limit = 5): string
{
    $actions = permit_tracking_recent_actions($staff, $limit);
    $html = '<section class="panel mt-3" aria-label="My recent DENR processing actions">'
        . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">'
        . '<h2 class="h5 m-0"><i class="bi bi-clock-history me-2"></i>'
        . 'My Recent Actions</h2>'
        . '<a class="btn btn-sm btn-outline-primary" href="'
        . e(url('my_actions')) . '">View Full Action History</a></div>'
        . '<p class="small text-secondary mt-2">Actions performed by your account. '
        . 'The application remains visible after forwarding, subject to access permissions.</p>';
    if (!$actions) return $html . '<p class="small text-secondary mb-0">'
        . 'No recorded review or processing actions yet.</p></section>';
    foreach ($actions as $item) {
        $current = $item['type'] === 'fp'
            ? permit_tracking_state(['status'=>$item['case_status']])['office']
            : status_label($item['case_status']);
        $html .= '<div class="border rounded-3 p-3 mb-2">'
            . '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">'
            . '<div><strong>' . e($item['label']) . '</strong>'
            . '<div class="small text-secondary">'
            . e(($item['type'] === 'fp' ? 'FP #' : 'Registration #')
                . (string)$item['id'] . ' — ' . $item['title']) . '</div>'
            . '<div class="small text-secondary">Current: ' . e($current) . '</div>'
            . '<div class="small text-secondary">' . e($item['occurred_at']) . '</div></div>'
            . ($item['type'] === 'fp'
                ? '<div class="d-flex flex-wrap gap-2">'
                    . '<a class="btn btn-sm btn-outline-primary" href="'
                    . e(url('permit_application',['id'=>$item['id'],'view'=>'files']))
                    . '">View Files</a>'
                    . '<a class="btn btn-sm btn-outline-secondary" href="'
                    . e(url('permit_application',['id'=>$item['id'],'view'=>'audit']))
                    . '">View Audit Trail</a></div>'
                : '<a class="btn btn-sm btn-outline-primary" href="'
                    . e(url('review',['id'=>$item['id']]))
                    . '">View Registration &amp; Files</a>') . '</div></div>';
    }
    return $html . '</section>';
}

/** Staff-only, read-only history; registration and FP actions are separated. */
function view_staff_action_history(): void
{
    $staff = require_user();
    if (($staff['user_kind'] ?? '') !== 'internal' || ($staff['status'] ?? '') !== 'active') {
        show_error('Only active DENR personnel can view this history.',403);
    }
    $filter = (string)($_GET['type'] ?? 'all');
    if (!in_array($filter, ['all','fp','registration'], true)) {
        show_error('Invalid action history filter.',400);
    }
    $actions = array_values(array_filter(permit_tracking_recent_actions($staff, 100),
        static fn(array $a): bool => $filter === 'all' || $a['type'] === $filter));
    $html = '<section class="panel"><div class="d-flex justify-content-between '
        . 'align-items-center flex-wrap gap-2"><div><h2 class="h5 mb-1">'
        . '<i class="bi bi-journal-check me-2"></i>My Action History</h2>'
        . '<p class="small text-secondary mb-0">Your own recorded DENR actions, '
        . 'including completed and forwarded cases.</p></div>'
        . '<a class="btn btn-sm btn-outline-secondary" href="'
        . e(url('dashboard')) . '">Dashboard</a></div>'
        . '<nav class="d-flex flex-wrap gap-2 my-3" aria-label="Filter action history">';
    foreach (['all'=>'All Actions','fp'=>'FP Applications','registration'=>'Registrations'] as $type=>$label) {
        $html .= '<a class="btn btn-sm ' . ($filter === $type
            ? 'btn-primary' : 'btn-outline-primary') . '" href="'
            . e(url('my_actions',['type'=>$type])) . '">' . e($label) . '</a>';
    }
    $html .= '</nav>';
    if (!$actions) {
        render_page('My Action History', $html
            . '<p class="text-secondary">No actions recorded for this filter.</p></section>');
        return;
    }
    $html .= '<div class="table-responsive"><table class="table table-hover align-middle">'
        . '<thead><tr><th>Date / Time</th><th>My Action</th><th>Case / Applicant</th>'
        . '<th>Current Location</th><th>Next to Act</th><th></th></tr></thead><tbody>';
    foreach ($actions as $item) {
        $isFp = $item['type'] === 'fp';
        $state = $isFp ? permit_tracking_state(['status'=>$item['case_status']]) : null;
        $current = $isFp ? $state['office'] : status_label($item['case_status']);
        $next = $isFp ? $state['next_role'] : (
            $item['case_status'] === 'needs_correction' ? 'Applicant' : 'No registration action pending'
        );
        $html .= '<tr><td class="small">' . e($item['occurred_at'])
            . '</td><td><strong>' . e($item['label']) . '</strong>'
            . '<div class="small text-secondary">Revision ' . (int)$item['revision'] . '</div></td>'
            . '<td>' . e(($isFp ? 'FP #' : 'Registration #') . (string)$item['id'])
            . '<div class="small">' . e($item['title']) . '</div>'
            . '<div class="small text-secondary">' . e($item['person']) . '</div></td>'
            . '<td>' . e($current) . '</td><td>' . e($next) . '</td>'
            . '<td>'
            . ($isFp
                ? '<div class="d-flex flex-wrap gap-2">'
                    . '<a class="btn btn-sm btn-outline-primary" href="'
                    . e(url('permit_application',['id'=>$item['id'],'view'=>'files']))
                    . '">View Files</a>'
                    . '<a class="btn btn-sm btn-outline-secondary" href="'
                    . e(url('permit_application',['id'=>$item['id'],'view'=>'audit']))
                    . '">View Audit Trail</a></div>'
                : '<a class="btn btn-sm btn-outline-primary" href="'
                    . e(url('review',['id'=>$item['id']]))
                    . '">View Registration &amp; Files</a>') . '</td></tr>';
    }
    render_page('My Action History', $html . '</tbody></table></div>'
        . '<p class="small text-secondary mb-0">Showing up to 100 of your latest '
        . 'recorded decisions. Document access remains controlled by your active role '
        . 'and case permissions.</p></section>');
}
