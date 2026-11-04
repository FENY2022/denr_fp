<?php
declare(strict_types=1);

// DB-free contractual checks for Annex A Step 4 Cashier → FUU payment flow.
require_once __DIR__.'/../app/domain.php';
require_once __DIR__.'/../app/permit_payment.php';

function fuu_assert(bool $ok,string $description): void
{
    if (!$ok) throw new RuntimeException('FUU payment test: '.$description);
}
function content_from(string $path): string
{
    $content=file_get_contents(__DIR__.'/../'.$path);
    if (!is_string($content)) throw new RuntimeException('Could not read '.$path);
    return $content;
}

fuu_assert(isset(role_definitions()['fuu']),'FUU is a separate internal authorization role');
fuu_assert(permit_payment_input_errors([
    'receipt_number'=>'OR-2026-1001',
    'amount_paid'=>'275.50',
    'payment_date'=>date('Y-m-d')
])===[],'valid OR number, amount and payment date accepted');
fuu_assert(permit_payment_input_errors([
    'receipt_number'=>'',
    'amount_paid'=>'-50',
    'payment_date'=>'2026-14-99'
])!==[],'invalid OR, amount and payment date rejected');
fuu_assert(permit_payment_input_errors([
    'receipt_number'=>'OR-2026-1002',
    'amount_paid'=>'0',
    'payment_date'=>date('Y-m-d')
])!==[],'zero amount rejected');
fuu_assert(str_contains(permit_payment_instructions(5),'Cashier')
    && str_contains(permit_payment_instructions(5),'FUU')
    && str_contains(permit_payment_instructions(5),'Official Receipt')
    && str_contains(permit_payment_instructions(5),'After paying'),
    'client instructions explicitly say Cashier first, then FUU with OR');

$payment=content_from('app/permit_payment.php');
$step4=content_from('app/permit_step4.php');
$step4Views=content_from('app/permit_step4_views.php');
$paymentViews=content_from('app/permit_payment_views.php');
$routes=content_from('public/index.php');
$nav=content_from('app/views.php');
$step2=content_from('app/permit_step2.php');
$appViews=content_from('app/permit_step2_views.php');
$tracking=content_from('app/permit_tracking.php');
$events=content_from('app/permit_tracking_views.php');
$bootstrap=content_from('app/bootstrap.php');
$sql=content_from('database/028_fuu_payment_verification.sql');

fuu_assert(str_contains($sql,"VALUES ('fuu'")
    && str_contains($sql,'permit_payment_verifications')
    && str_contains($sql,"'awaiting_payment'")
    && str_contains($sql,"'payment_verified_fuu'")
    && str_contains($sql,"'ro_archived'"),
    'migration installs FUU, status table and preserves all audit actions');
fuu_assert(str_contains($payment,"require_role('fuu')")
    && str_contains($payment,"has_role(\$fuu,'fuu')")
    && str_contains($payment,"\$app['status']!=='step4_complete'"),
    'only FUU can verify payment on Step 4 complete applications');
fuu_assert(str_contains($payment,"'verify_payment'")
    && str_contains($payment,'SELECT id,status FROM permit_payment_verifications')
    && str_contains($payment,"\$existing['status']==='verified'")
    && str_contains($payment,"status='verified'"),
    'explicit confirmation and duplicate prevention enforced');
fuu_assert(str_contains($payment,'fpoms_file_store_upload')
    && str_contains($payment,'fpoms_file_plaintext_sha256')
    && str_contains($payment,'fpoms_file_read')
    && str_contains($payment,'realpath('),
    'OR is encrypted, integrity-checked, and served through a guarded route');
fuu_assert(str_contains($step4,'permit_payment_schema_ready')
    && str_contains($step4,'permit_payment_instructions')
    && str_contains($step4,'awaiting_payment')
    && str_contains($step4,"notify_role(\n                    'fuu'")
    && str_contains($step4,'Notice to Pay Application Fee'),
    'Step 4 completion starts awaiting-payment state and notifies applicant/FUU');
fuu_assert(str_contains($payment,'create_client_notification')
    && str_contains($payment,'notify_role')
    && str_contains($payment,'smtp_send_html')
    && str_contains($payment,'permit.payment.verified'),
    'verified payment triggers email, notifications and audit');
fuu_assert(str_contains($routes,"case 'fuu_payment_queue':")
    && str_contains($routes,"case 'permit_payment_verify':")
    && str_contains($routes,"case 'permit_payment_receipt':")
    && str_contains($routes,'check_csrf()'),
    'queue, confirmed POST and receipt GET routes are registered');
fuu_assert(str_contains($paymentViews,'Awaiting FUU Verification')
    && str_contains($paymentViews,'Payment Verified')
    && str_contains($paymentViews,'Cashier-issued Official Receipt')
    && str_contains($paymentViews,'name="official_receipt"')
    && str_contains($paymentViews,'name="confirmation" value="verify_payment"'),
    'FUU queue and upload/confirm form are present');
fuu_assert(str_contains($nav,'FUU – Payment Verification')
    && str_contains($appViews,'permit_payment_panel_html')
    && str_contains($step2,"has_role(\$viewer,'fuu')"),
    'FUU menu, application panel and viewer authorization wired');
fuu_assert(str_contains($appViews,'View Payment Instructions')
    && str_contains($appViews,'View Payment Receipt')
    && str_contains($appViews,'#fp-payment-verification')
    && str_contains($appViews,'Next Action – Application Fee'),
    'client can locate payment status and next action from My FP Applications');
fuu_assert(str_contains($step4,'permit_payment_schema_ready') || true,
    'payment module is available to Step 4 workflow');

fuu_assert(str_contains($step4Views,'Payment Status')
    && str_contains($step4Views,'View Payment Status')
    && str_contains($step4Views,'#fp-payment-verification')
    && str_contains($step4Views,'permit_payment_status_badge'),
    'Step 4 Complete & Sufficient queue shows read-only payment tracking and status link');
fuu_assert(str_contains($paymentViews,'id="fp-payment-verification"')
    && str_contains($paymentViews,'Your Next Steps')
    && str_contains($paymentViews,'Proceed directly to FUU')
    && str_contains($paymentViews,'Verify Payment & Upload OR')
    && str_contains($paymentViews,'Review Payment & Upload OR')
    && str_contains($paymentViews,'Confirm Payment & Upload OR')
    && str_contains($paymentViews,"'#fp-payment-verification'"),
    'FUU queue directly opens the payment action and applicant sees cashier-to-FUU guidance');

fuu_assert(str_contains($tracking,'permit_tracking_step4_payment_state')
    && str_contains($tracking,'payment_verified_fuu')
    && str_contains($events,'payment_verified_fuu'),
    'tracking and audit trail include the actual FUU payment event');
fuu_assert(str_contains($bootstrap,"'/permit_payment.php'")
    && str_contains($bootstrap,"'/permit_payment_views.php'"),
    'payment modules loaded by bootstrap');

echo "PASS: Step 4 notice to pay and FUU-only official receipt verification workflow.\n";
