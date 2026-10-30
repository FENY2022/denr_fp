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
tracking_assert(str_contains($detail,'Track / View Files'), 'client case link');
tracking_assert(str_contains($service,'permit_tracking_historical_staff_access('), 'historical read access');
tracking_assert(str_contains($bootstrap,"require_once __DIR__ . '/permit_tracking.php';"), 'model registered');
tracking_assert(str_contains($bootstrap,"require_once __DIR__ . '/permit_tracking_views.php';"), 'views registered');

echo "Application tracking: routing, timeline, XSS and integration checks passed\n";
