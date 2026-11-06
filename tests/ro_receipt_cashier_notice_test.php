<?php
declare(strict_types=1);

// DB-free source-contract checks. Run: php tests/ro_receipt_cashier_notice_test.php
$step3=file_get_contents(__DIR__.'/../app/permit_step3.php');
$flow=file_get_contents(__DIR__.'/../app/permit_flow.php');
$payment=file_get_contents(__DIR__.'/../app/permit_payment.php');
if (!is_string($step3) || !is_string($flow) || !is_string($payment)) {
    throw new RuntimeException('Cannot load RO receipt payment notice source files.');
}
function cashier_notice_assert(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('RO receipt Cashier notice: '.$message);
}
cashier_notice_assert(
    str_contains($step3,"$newFlowReceipt=$action==='receive' && permit_flow_is_new($app);"),
    'notice trigger must be restricted to the new Annex A RO receive action');
cashier_notice_assert(
    str_contains($step3,"'Notice to Pay Application Fee'") &&
    str_contains($step3,"permit_payment_instructions($applicationId)"),
    'client receives payment instructions in bell notification');
cashier_notice_assert(
    str_contains($step3,"'Notice to Pay – FP Application #'.$applicationId") &&
    str_contains($step3,"if ($newFlowReceipt)"),
    'client receives a Cashier email on official receipt');
cashier_notice_assert(
    str_contains($step3,"'awaiting_payment',NOW()") &&
    !str_contains($step3,"status='verified'"),
    'receipt creates payment tracking without claiming payment verified');
cashier_notice_assert(
    str_contains($step3,'if (permit_flow_is_new($app) && !permit_payment_schema_ready())') &&
    str_contains($step3,'$pdo->commit();'),
    'payment table preflight occurs before RO receipt commit');
cashier_notice_assert(
    !str_contains($flow,"'Notice to Pay Application Fee'") &&
    str_contains($flow,'Field Validation Schedule – FP #'),
    'Step 4 provides field schedule, not a duplicate Cashier payment notice');
cashier_notice_assert(
    str_contains($payment,'permit_payment_verify(') &&
    str_contains($payment,"'verified'"),
    'LPDD-FUS receipt verification remains separate');
echo "RO receipt Cashier notice checks passed.\n";
