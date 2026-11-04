<?php
declare(strict_types=1);

// DB-free contractual checks for Annex A Step 4 Cashier → LPDD-FUS payment flow.
require_once __DIR__.'/../app/domain.php';
require_once __DIR__.'/../app/permit_payment.php';

function payment_assert(bool $ok,string $description): void
{
    if (!$ok) throw new RuntimeException('LPDD-FUS payment test: '.$description);
}
function payment_content(string $path): string
{
    $content=file_get_contents(__DIR__.'/../'.$path);
    if (!is_string($content)) throw new RuntimeException('Could not read '.$path);
    return $content;
}

$roles=role_definitions();
$legacyRoleCode='f'.'uu';
payment_assert(isset($roles['lpdd_fus']),'LPDD-FUS role exists');
payment_assert(!isset($roles[$legacyRoleCode]),'legacy separate payment role is no longer exposed');
payment_assert(permit_payment_input_errors([
    'receipt_number'=>'OR-2026-1001',
    'amount_paid'=>'275.50',
    'payment_date'=>date('Y-m-d')
])===[],'valid OR number, amount and payment date accepted');
payment_assert(permit_payment_input_errors([
    'receipt_number'=>'',
    'amount_paid'=>'-50',
    'payment_date'=>'2026-14-99'
])!==[],'invalid OR, amount and payment date rejected');

$payment=payment_content('app/permit_payment.php');
$step4=payment_content('app/permit_step4.php');
$step4Views=payment_content('app/permit_step4_views.php');
$paymentViews=payment_content('app/permit_payment_views.php');
$routes=payment_content('public/index.php');
$nav=payment_content('app/views.php');
$step2=payment_content('app/permit_step2.php');
$appViews=payment_content('app/permit_step2_views.php');
$tracking=payment_content('app/permit_tracking.php');
$events=payment_content('app/permit_tracking_views.php');
$bootstrap=payment_content('app/bootstrap.php');
$sql=payment_content('database/028_lpdd_fus_payment_verification.sql');
$upgrade=payment_content('database/029_consolidate_legacy_payment_role_into_lpdd_fus.sql');

payment_assert(str_contains(permit_payment_instructions(5),'Cashier')
    && str_contains(permit_payment_instructions(5),'LPDD-FUS')
    && str_contains(permit_payment_instructions(5),'Official Receipt'),
    'client instructions say Cashier first, then LPDD-FUS with OR');
payment_assert(str_contains($sql,'permit_payment_verifications')
    && str_contains($sql,"'awaiting_payment'")
    && str_contains($sql,"'payment_verified_lpdd_fus'")
    && !str_contains($sql,"VALUES ('".$legacyRoleCode."'"),
    'fresh migration uses LPDD-FUS payment terminology only');
payment_assert(str_contains($upgrade,"legacy.code='".$legacyRoleCode."'")
    && str_contains($upgrade,"DELETE FROM roles WHERE code='".$legacyRoleCode."'")
    && str_contains($upgrade,"SET action='payment_verified_lpdd_fus'"),
    'upgrade migration transfers legacy role access and historical audit events');
payment_assert(str_contains($payment,"require_role('lpdd_fus')")
    && str_contains($payment,"has_role(\$lpddFus,'lpdd_fus')")
    && str_contains($payment,"\$app['status']!=='step4_complete'"),
    'only active LPDD-FUS accounts can verify eligible payments');
payment_assert(str_contains($payment,"'verify_payment'")
    && str_contains($payment,'SELECT id,status FROM permit_payment_verifications')
    && str_contains($payment,"\$existing['status']==='verified'")
    && str_contains($payment,"status='verified'"),
    'explicit confirmation and duplicate prevention enforced');
payment_assert(str_contains($payment,'fpoms_file_store_upload')
    && str_contains($payment,'fpoms_file_plaintext_sha256')
    && str_contains($payment,'fpoms_file_read'),
    'OR remains encrypted and integrity checked');
payment_assert(str_contains($step4,"notify_role(\n                    'lpdd_fus'")
    && str_contains($step4,'lpdd_fus_payment_queue')
    && str_contains($step4,'Notice to Pay Application Fee'),
    'Step 4 completion notifies LPDD-FUS payment queue');
payment_assert(str_contains($routes,"case 'lpdd_fus_payment_queue':")
    && str_contains($routes,'permit_payment_require_lpdd_fus')
    && str_contains($routes,"case 'permit_payment_verify':"),
    'LPDD-FUS payment routes are registered');
payment_assert(str_contains($paymentViews,'Awaiting LPDD-FUS Verification')
    && str_contains($paymentViews,'LPDD-FUS – Verify Payment & Upload Official Receipt')
    && str_contains($paymentViews,'Verify Payment & Upload OR')
    && str_contains($paymentViews,'name="official_receipt"'),
    'LPDD-FUS queue and receipt upload controls are present');
payment_assert(str_contains($nav,'lpdd_fus_payment_queue')
    && str_contains($nav,'Payment Verification')
    && !str_contains($nav,"in_array('".$legacyRoleCode."'"),
    'payment verification is part of the LPDD-FUS sidebar only');
payment_assert(!str_contains($step2,"has_role(\$viewer,'".$legacyRoleCode."')")
    && str_contains($appViews,'View Payment Instructions')
    && str_contains($appViews,'View Payment Receipt'),
    'separate legacy payment viewer path removed');
payment_assert(str_contains($step4Views,'Payment Status')
    && str_contains($step4Views,'View Payment Status'),
    'Step 4 complete queue retains read-only payment tracking');
payment_assert(str_contains($tracking,'payment_verified_lpdd_fus')
    && str_contains($events,'payment_verified_lpdd_fus'),
    'tracking and audit trail use LPDD-FUS payment event');
payment_assert(str_contains($bootstrap,"'/permit_payment.php'")
    && str_contains($bootstrap,"'/permit_payment_views.php'"),
    'payment modules loaded by bootstrap');

foreach ([
    'app/domain.php'=>$roles ? payment_content('app/domain.php') : '',
    'app/permit_payment.php'=>$payment,
    'app/permit_payment_views.php'=>$paymentViews,
    'app/permit_step4.php'=>$step4,
    'app/permit_step2.php'=>$step2,
    'app/permit_step2_views.php'=>$appViews,
    'app/permit_tracking.php'=>$tracking,
    'app/permit_tracking_views.php'=>$events,
    'app/views.php'=>$nav,
    'public/index.php'=>$routes,
] as $path=>$content) {
    payment_assert(!str_contains(strtolower($content),$legacyRoleCode),
        $path.' contains no runtime legacy payment-role terminology');
}

echo "PASS: LPDD-FUS owns Cashier payment verification and Official Receipt upload.\n";
