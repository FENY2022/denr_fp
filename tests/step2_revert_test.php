<?php
declare(strict_types=1);

// DB-free eligibility and integration safety checks for Migration 020.
require_once __DIR__ . '/../app/permit_step2_revert.php';
function revert_assert(bool $condition,string $message): void
{
    if (!$condition) throw new RuntimeException('FP Step 2 revert: '.$message);
}

$pending = [
    'id'=>28, 'status'=>'submitted', 'client_id'=>6,
    'received_at'=>null, 'received_by_user_id'=>null,
    'reviewed_at'=>null, 'forwarded_at'=>null, 'reviewer_user_id'=>null
];
revert_assert(step2_revert_eligibility($pending,true,1800)[0],
    'available immediately after submission');
revert_assert(step2_revert_eligibility($pending,true,1)[0],
    'available with one second remaining');
revert_assert(!step2_revert_eligibility($pending,true,0)[0],
    'blocked at 30-minute boundary');
revert_assert(!step2_revert_eligibility($pending,true,-1)[0],
    'blocked after 30-minute boundary');
revert_assert(!step2_revert_eligibility($pending,false,1800)[0],
    'migration 020 required');
$applicant = ['id'=>6,'user_kind'=>'applicant','status'=>'active'];
revert_assert(step2_revert_application($applicant,28,'short') ===
    ['Explain why you are reverting the application (minimum 10 characters).'],
    'revert remarks require a meaningful explanation');
revert_assert(step2_revert_application($applicant,28,str_repeat('a',3001)) ===
    ['Revert remarks must be 3,000 characters or fewer.'],
    'revert remarks enforce the storage limit');
foreach (['received_at'=>'2026-10-30 08:51:00',
          'received_by_user_id'=>10,
          'reviewed_at'=>'2026-10-30 08:51:00',
          'forwarded_at'=>'2026-10-30 08:51:00',
          'reviewer_user_id'=>10] as $field=>$value) {
    $modified=$pending;
    $modified[$field]=$value;
    revert_assert(!step2_revert_eligibility($modified,true,1300)[0],
        'DENR action '.$field.' blocks revert');
}
foreach (['reverted','corrections_required','forwarded_to_ro'] as $status) {
    $modified=$pending;
    $modified['status']=$status;
    revert_assert(!step2_revert_eligibility($modified,true,1200)[0],
        'non-pending '.$status.' blocks revert');
}

$backend = (string)file_get_contents(__DIR__.'/../app/permit_step2_revert.php');
$step2 = (string)file_get_contents(__DIR__.'/../app/permit_step2.php');
$drafts = (string)file_get_contents(__DIR__.'/../app/permit_step2_drafts.php');
$router = (string)file_get_contents(__DIR__.'/../public/index.php');
$view = (string)file_get_contents(__DIR__.'/../app/permit_step2_views.php');
$migration = (string)file_get_contents(__DIR__.'/../database/020_step2_revert_and_receipt.sql');
$script = (string)file_get_contents(__DIR__.'/../public/assets/permit-revert.js');

revert_assert(substr_count($backend,'FOR UPDATE')>=2,
    'receipt and revert lock the same application row');
revert_assert(str_contains($backend,"AND NOW()<DATE_ADD(last_submitted_at,INTERVAL 30 MINUTE)"),
    'UPDATE rechecks the DB-authoritative 30-minute deadline');
revert_assert(str_contains($backend,'a.id=? AND a.client_id=? FOR UPDATE'),
    'revert locks only the authenticated applicant owned record');
revert_assert(str_contains($backend,'step2_revert_eligibility($app,true,$remaining)'),
    'eligibility rechecked after locking the row');
revert_assert(str_contains($backend,'revert_confirm')
    && str_contains($backend,'receive_confirm'),
    'both modal forms require explicit confirmation markers');
revert_assert(str_contains($backend,'name="remarks"')
    && str_contains($backend,'minlength="10"')
    && str_contains($router,"(string)(\$_POST['remarks'] ?? '')"),
    'revert modal requires remarks and passes them to the backend');
revert_assert(str_contains($router,"case 'permit_revert':")
    && str_contains($router,"case 'permit_receive':")
    && substr_count($router,'check_csrf();')>=4,
    'two POST + CSRF protected router actions exist');
revert_assert(str_contains($backend,"'permit.step2.reverted'")
    && str_contains($backend,"'permit.step2.documents_received'"),
    'both actions record immutable audit entries');
revert_assert(str_contains($backend,"action,revision,remarks")
    && str_contains($backend,"'documents_received'")
    && str_contains($backend,"'reverted'")
    && str_contains($backend,'(int)$app[\'revision\'],$remarks'),
    'both actions have event history');
revert_assert(str_contains($backend,'create_client_notification(')
    && str_contains($backend,'create_notification(')
    && str_contains($backend,"step2_staff_recipients('lpdd_fus')")
    && str_contains($backend,'smtp_send_html('),
    'applicant/LPDD-FUS bell and email delivery included');
revert_assert(str_contains($backend,'$pdo->commit();')
    && str_contains($backend,'step2_notify_revert($app,$applicationId)'),
    'notifications sent only after successful revert transaction commit');
revert_assert(str_contains($drafts,"'corrections_required','reverted'")
    && str_contains($step2,"['corrections_required','reverted']"),
    'reverted applicant is allowed to edit and resubmit');
revert_assert(substr_count($step2,'received_at=NULL,received_by_user_id=NULL')===2,
    'corrected submissions clear DENR receipt state for new revision');
revert_assert(str_contains($view,'step2_revert_client_html($app)')
    && str_contains($view,'step2_receive_documents_html($app)'),
    'client and LPDD-FUS see appropriate modal/action components');
revert_assert(str_contains($view,'documents_received')
    && str_contains($view,"'reverted' => 'Reverted"),
    'receipt and revert appear in statuses and history');
revert_assert(str_contains($script,'data-step2-revert-countdown')
    && str_contains($script,'trigger.disabled = true')
    && str_contains($script,'confirm.disabled = true'),
    'browser timer disables expired button and confirmation');
revert_assert(str_contains($migration,"'reverted'")
    && str_contains($migration,"'documents_received'")
    && str_contains($migration,'received_at DATETIME'),
    'new status, history enums and receipt columns exist');
echo "PASS: 30-minute revert, DENR receipt barrier, atomic ownership and LPDD-FUS notices.\n";
