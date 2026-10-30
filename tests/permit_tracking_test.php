<?php
declare(strict_types=1);

// Database-free tests for applicant/staff tracking, destination rules, and
// permission-aware integration. Real MySQL/role/browser tests still required.
require_once __DIR__ . '/../app/permit_tracking.php';
function tracking_assert(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('FP-OMS tracking: '.$message);
}
$submitted = permit_tracking_state(['status'=>'submitted','received_at'=>null]);
tracking_assert($submitted['office']==='LPDD-FUS (Regional Office)', 'submitted in LPDD-FUS');
tracking_assert(str_contains($submitted['next_action'],'Acknowledge receipt'), 'pending receipt');
tracking_assert(!str_contains($submitted['assignment'],'Named'), 'no fabricated individual assignment');
$received = permit_tracking_state(['status'=>'submitted','received_at'=>'2026-10-30 11:00:00']);
tracking_assert(str_contains($received['next_action'],'initial documentary review'), 'after receipt next is review');
$correction = permit_tracking_state(['status'=>'corrections_required']);
tracking_assert($correction['office']==='Applicant / Client', 'returned to applicant');
tracking_assert($correction['next_role']==='Applicant', 'applicant acts on correction');
$reverted = permit_tracking_state(['status'=>'reverted']);
tracking_assert($reverted['next_role']==='Applicant', 'reversion returns to applicant');
$forwarded = permit_tracking_state(['status'=>'forwarded_to_ro']);
tracking_assert($forwarded['office']==='RO Receiving/Releasing (Regional Office)', 'forwarded to RO');
tracking_assert(str_contains($forwarded['next_action'],'not yet implemented'), 'no fake step 3 receipt');
tracking_assert(permit_tracking_event_destination('forwarded_to_ro')==='RO Receiving/Releasing', 'destination');
tracking_assert(permit_tracking_event_destination('reverted')==='Applicant / Client', 'reversion destination');
tracking_assert(permit_tracking_event_label('documents_received')==='Documents Received by LPDD-FUS', 'receipt event');

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
}
function url(string $page,array $query=[]): string {
    return $page . ($query ? '?' . http_build_query($query) : '');
}
function step2_status_badge(string $status): string {
    return '<span>'.e($status).'</span>';
}
function step2_events(int $appId): array {
    return [
        [
            'action'=>'documents_received','actor_user_id'=>15,
            'staff_actor'=>'Review <script>alert(1)</script>',
            'staff_office'=>'LPDD/FUS','revision'=>1,'remarks'=>'Received <img src=x>',
            'occurred_at'=>'2026-10-30 10:15:00'
        ],
        [
            'action'=>'submitted','actor_client_id'=>20,'applicant_actor'=>'Client',
            'revision'=>1,'remarks'=>null,
            'occurred_at'=>'2026-10-30 10:00:00'
        ],
        [
            'action'=>'resubmitted','actor_client_id'=>20,'applicant_actor'=>'Client',
            'revision'=>2,'remarks'=>null,
            'occurred_at'=>'2026-10-30 10:25:00'
        ],
        [
            'action'=>'returned_for_correction','actor_user_id'=>15,
            'staff_actor'=>'Reviewer','revision'=>2,'remarks'=>'Please correct the attachments.',
            'occurred_at'=>'2026-10-30 10:30:00'
        ],
        [
            'action'=>'forwarded_to_ro','actor_user_id'=>15,
            'staff_actor'=>'Reviewer','revision'=>2,'remarks'=>null,
            'occurred_at'=>'2026-10-30 10:40:00'
        ],
        [
            'action'=>'reverted','actor_client_id'=>20,'applicant_actor'=>'Client',
            'revision'=>2,'remarks'=>'Reverted before DENR receipt.',
            'occurred_at'=>'2026-10-30 10:45:00'
        ]
    ];
}
require_once __DIR__ . '/../app/permit_tracking_views.php';
$summary = permit_tracking_summary_html([
    'status'=>'submitted','project_title'=>'sample',
    'received_at'=>'2026-10-30 10:15:00'
]);
tracking_assert(str_contains($summary,'Current Office / Location'), 'current office rendered');
tracking_assert(str_contains($summary,'Next to Act'), 'next actor role rendered');
$timeline = permit_tracking_timeline_html(20);
tracking_assert(str_contains($timeline,'Documents Received by LPDD-FUS'), 'receipt timeline');
tracking_assert(str_contains($timeline,'Routed to:'), 'destination timeline');
tracking_assert(str_contains($timeline,'LPDD/FUS'), 'actor office timeline');
tracking_assert(!str_contains($timeline,'<script>'), 'escape actor name');
tracking_assert(!str_contains($timeline,'<img src=x>'), 'escape remarks');
tracking_assert(str_contains($timeline,'&lt;script&gt;'), 'HTML escaped');

$router=(string)file_get_contents(__DIR__.'/../public/index.php');
$view=(string)file_get_contents(__DIR__.'/../app/views.php');
$detail=(string)file_get_contents(__DIR__.'/../app/permit_step2_views.php');
$service=(string)file_get_contents(__DIR__.'/../app/permit_step2.php');
$bootstrap=(string)file_get_contents(__DIR__.'/../app/bootstrap.php');
tracking_assert(str_contains($router,"case 'my_actions':"), 'staff history route');
tracking_assert(str_contains($router,'view_staff_action_history();'), 'route handler');
tracking_assert(str_contains($view,"shell_nav_item('my_actions'"), 'staff sidebar');
tracking_assert(str_contains($view,'permit_tracking_staff_recent_html('), 'staff dashboard recent');
tracking_assert(str_contains($view,'permit_tracking_client_dashboard_html('), 'applicant dashboard tracking');
tracking_assert(str_contains($detail,'permit_tracking_summary_html($app)'), 'detail case tracking summary');
tracking_assert(str_contains($detail,'permit_tracking_timeline_html($applicationId)'), 'timeline integrated');
tracking_assert(str_contains($detail,'step2_files_modal_html($documents)'), 'dedicated files modal rendered');
tracking_assert(str_contains($detail,'permit_tracking_audit_modal_html($applicationId)'), 'audit timeline moved into modal');
tracking_assert(str_contains($detail,'data-bs-target="#fp-files-modal"'), 'files launcher button');
tracking_assert(str_contains($detail,'data-bs-target="#fp-audit-trail-modal"'), 'audit launcher button');
tracking_assert(!str_contains($detail,'. step2_history_html($applicationId)'), 'audit timeline not inline');
tracking_assert(str_contains($detail,"'view'=>'files'") && str_contains($detail,"'view'=>'audit'"),
    'distinct client list links to case modals');
tracking_assert(str_contains($service,'permit_tracking_historical_staff_access('), 'historical read access');
tracking_assert(str_contains($bootstrap,"require_once __DIR__ . '/permit_tracking.php';"), 'model registered');
tracking_assert(str_contains($bootstrap,"require_once __DIR__ . '/permit_tracking_views.php';"), 'views registered');


/* Verify each milestone retains its distinct color/icon/text indicator. */
$expectedVisuals = [
    'submitted'=>['submitted','send-check-fill','Submitted'],
    'documents_received'=>['received','inbox-fill','Received'],
    'resubmitted'=>['resubmitted','arrow-repeat','Resubmitted'],
    'returned_for_correction'=>['correction','exclamation-triangle-fill','Correction Needed'],
    'forwarded_to_ro'=>['forwarded','check-circle-fill','Forwarded'],
    'reverted'=>['reverted','arrow-counterclockwise','Reverted']
];
foreach ($expectedVisuals as $action => $expected) {
    $visual = permit_tracking_action_visual($action);
    tracking_assert(
        $visual['tone'] === $expected[0]
        && $visual['icon'] === $expected[1]
        && $visual['category'] === $expected[2],
        'visual classification for '.$action
    );
}
tracking_assert(permit_tracking_action_visual('unknown')['tone'] === 'other',
    'unknown actions use neutral visual fallback');
$stylesheet = (string)file_get_contents(__DIR__.'/../public/assets/style.css');
foreach ($expectedVisuals as $expected) {
    tracking_assert(str_contains($stylesheet,'.fp-audit-event--'.$expected[0]),
        'CSS indicator for '.$expected[0]);
    tracking_assert(str_contains($timeline,'fp-audit-event--'.$expected[0]),
        'rendered color indicator for '.$expected[0]);
    tracking_assert(str_contains($timeline,'bi-'.$expected[1]),
        'rendered icon for '.$expected[0]);
    tracking_assert(str_contains($timeline,'>'.$expected[2].'</span>'),
        'visible status label for '.$expected[0]);
}
tracking_assert(substr_count($timeline,'class="fp-audit-event fp-audit-event--') === 6,
    'one distinct card for each recorded event');
tracking_assert(str_contains($stylesheet,'.fp-audit-icon')
    && str_contains($stylesheet,'.fp-audit-action-label')
    && str_contains($stylesheet,'border-left:5px solid var(--fp-audit-accent)'),
    'color indicator has border, icon and visible label');
tracking_assert(str_contains((string)file_get_contents(__DIR__.'/../app/views.php'),
        'assets/style.css?v=32'), 'new stylesheet cache version');
tracking_assert(str_contains($timeline,'fp-audit-event--submitted')
    && str_contains($timeline,'fp-audit-event--received')
    && str_contains($timeline,'fp-audit-icon')
    && str_contains($timeline,'fp-audit-action-label'),
    'timeline HTML includes status-specific icon and color indicator');
tracking_assert(!str_contains($timeline, 'style='), 'no CSP-blocked inline styles');

$auditModal = permit_tracking_audit_modal_html(20);
tracking_assert(str_contains($auditModal,'id="fp-audit-trail-modal"'), 'audit has its own modal ID');
tracking_assert(str_contains($auditModal,'modal-dialog-scrollable'), 'audit modal is scrollable');
tracking_assert(str_contains($auditModal,'fp-audit-event--received'), 'colored timeline is inside audit modal');
tracking_assert(str_contains($auditModal,'data-bs-dismiss="modal"'), 'audit modal has close control');
$filesView = (string)file_get_contents(__DIR__.'/../app/permit_step2_views.php');
tracking_assert(str_contains($filesView,'function step2_document_list_html('), 'file list extracted');
tracking_assert(str_contains($filesView,'function step2_document_preview_modal_html('), 'preview remains separate');
tracking_assert(str_contains($filesView,'function step2_files_modal_html('), 'files modal separate from audit');
tracking_assert(str_contains($filesView,'id="fp-files-modal"'), 'files modal ID');
tracking_assert(str_contains($filesView,'step2_document_list_html($docs)'), 'files modal contains revision list');
tracking_assert(str_contains($filesView,'step2_document_preview_modal_html()'), 'PDF viewer preserved');
tracking_assert(str_contains($filesView,'modal-dialog-scrollable'), 'file list supports scrolling');
$trackingViews = (string)file_get_contents(__DIR__.'/../app/permit_tracking_views.php');
tracking_assert(str_contains($trackingViews,"'view'=>'audit'"), 'staff action has audit link');
tracking_assert(str_contains($trackingViews,"'view'=>'files'"), 'staff action has file link');
tracking_assert(str_contains((string)file_get_contents(__DIR__.'/../app/views.php'),
    'permit-case-modals.js?v=1'), 'modal transition script loaded');
$modalScript = (string)file_get_contents(__DIR__.'/../public/assets/permit-case-modals.js');
tracking_assert(str_contains($modalScript,"hidden.bs.modal")
    && str_contains($modalScript,"returnToFileList")
    && str_contains($modalScript,"new URLSearchParams")
    && str_contains($modalScript,"getOrCreateInstance"),
    'preview switches modal rather than nesting and direct actions auto-open');


/** Dashboard and full-page history use one tabular three-dot action menu. */
function status_label(string $status): string { return ucwords(str_replace('_',' ',$status)); }
$fpAction = [
    'type'=>'fp','id'=>28,'label'=>'Forwarded to RO','revision'=>2,
    'occurred_at'=>'2026-10-30 12:45:00',
    'title'=>'Forest study <script>alert(1)</script>','person'=>'Applicant <img src=x>',
    'case_status'=>'forwarded_to_ro'
];
$regAction = [
    'type'=>'registration','id'=>25,'label'=>'Registration Approved','revision'=>1,
    'occurred_at'=>'2026-10-30 10:00:00',
    'title'=>'Organization','person'=>'Test User','case_status'=>'active'
];
$fpMenu = permit_tracking_staff_action_menu_html($fpAction);
$regMenu = permit_tracking_staff_action_menu_html($regAction);
tracking_assert(substr_count($fpMenu,'data-bs-toggle="dropdown"') === 1,
    'FP action has one three-dot Bootstrap dropdown');
tracking_assert(str_contains($fpMenu,'bi-three-dots-vertical'),
    'FP dropdown has visible three-dot icon');
tracking_assert(str_contains($fpMenu,'aria-expanded="false"')
    && str_contains($fpMenu,'aria-label="Actions for FP Application #28"'),
    'action menu is keyboard and screen-reader accessible');
tracking_assert(str_contains($fpMenu,'View Files')
    && str_contains($fpMenu,'View Audit Trail')
    && str_contains($fpMenu,'permit_application?id=28&amp;view=files')
    && str_contains($fpMenu,'permit_application?id=28&amp;view=audit'),
    'FP actions point to their separate authorized modals');
tracking_assert(str_contains($regMenu,'review?id=25&amp;view=files')
    && str_contains($regMenu,'review?id=25&amp;view=audit'),
    'registration actions preserve LPDD-FUS review route');
$historyTable = permit_tracking_staff_action_table_html([$fpAction,$regAction]);
tracking_assert(str_contains($historyTable,'<table')
    && str_contains($historyTable,'Current Office')
    && str_contains($historyTable,'Next to Act'),
    'My Recent Actions renders a table with routing columns');
tracking_assert(substr_count($historyTable,'bi-three-dots-vertical') === 2,
    'one three-dot action menu per row');
tracking_assert(substr_count($historyTable,'View Files') === 2
    && substr_count($historyTable,'View Audit Trail') === 2,
    'each action row has precisely the two requested options');
tracking_assert(!str_contains($historyTable,'<script>')
    && !str_contains($historyTable,'<img src=x>'),
    'history table escapes user-provided project and applicant names');
tracking_assert(str_contains($historyTable,'&lt;script&gt;'),
    'history table displays escaped source text');
$registrationAudit = permit_tracking_registration_audit_modal_html(25, [
    [
        'decision'=>'needs_correction','reviewed_at'=>'2026-10-30 09:10:00',
        'reviewer'=>'Reviewer <script>alert(1)</script>',
        'revision'=>1,'remarks'=>'Attach <img src=x> documents'
    ],
    [
        'decision'=>'approved','reviewed_at'=>'2026-10-30 09:30:00',
        'reviewer'=>'LPDD FUS','revision'=>2,'remarks'=>'Complete'
    ]
]);
tracking_assert(str_contains($registrationAudit,'id="registration-action-audit-modal"')
    && str_contains($registrationAudit,'modal-dialog-scrollable')
    && str_contains($registrationAudit,'fp-audit-event--correction')
    && str_contains($registrationAudit,'fp-audit-event--forwarded'),
    'registration review audit modal displays both outcomes');
tracking_assert(!str_contains($registrationAudit,'<script>')
    && !str_contains($registrationAudit,'<img src=x>')
    && str_contains($registrationAudit,'&lt;script&gt;'),
    'registration review audit escapes user-entered values');
$viewSource = (string)file_get_contents(__DIR__.'/../app/views.php');
tracking_assert(str_contains($viewSource,'permit_tracking_registration_audit_modal_html($id, $history)')
    && str_contains($viewSource,'registration-action-audit.js?v=1'),
    'registration review renders its audited events and loads selected-modal script');
tracking_assert(str_contains($trackingViews,'permit_tracking_staff_action_table_html($actions)'),
    'shared table used for DENR dashboard and recent actions route');
tracking_assert(str_contains($trackingViews,'permit_tracking_staff_action_menu_html($item)'),
    'table uses action dropdown rather than separate action buttons');
tracking_assert(str_contains($stylesheet,'.fp-recent-actions-table-wrap')
    && str_contains($stylesheet,'.fp-recent-action-menu')
    && str_contains($stylesheet,'@media(max-width:767.98px)'),
    'responsive three-dot history table stylesheet is installed');

echo "Application tracking: routing, timeline, XSS and integration checks passed\n";
