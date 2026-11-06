<?php
declare(strict_types=1);

// Source-level guard for RED finalization authorization and immutable snapshot.
$step4=file_get_contents(__DIR__.'/../app/permit_step4.php');
$notice=file_get_contents(__DIR__.'/../app/deficiency_notice.php');
if (!is_string($step4) || !is_string($notice)) {
    throw new RuntimeException('Unable to read FP-OMS notice source files.');
}
function finalization_check(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('RED finalization regression: '.$message);
}
finalization_check(str_contains($notice,'step4_decide($originalReviewer,$applicationId,$payload,$red)'),
    'only RED approval handler passes an authenticated RED actor');
finalization_check(str_contains($step4,'$finalizingRed===null')
    && str_contains($step4,'return fpoms_notice_prepare('),
    'normal LPDD-FUS submissions cannot bypass notice preparation using _red_finalize');
finalization_check(str_contains($step4,"has_role($finalizingRed,'red')"),
    'RED actor authorization is checked');
finalization_check(str_contains($step4,"($approved['decision']??'')!=='return'"),
    'server-stored frozen return decision must be valid');
finalization_check(!str_contains($step4,"The RED-approved notice snapshot cannot be changed."),
    'parser-array strict comparison does not block approved notice finalization');
finalization_check(str_contains($step4,'permit_flow_notice_email_attachments(')
    && str_contains($step4,"'step4_corrections_required'"),
    'signed PDF integrity and returned status are retained');
finalization_check(str_contains($step4,'$actionActor=$finalizingRed ?? $reviewer'),
    'RED audit actor attribution is retained');
echo "RED deficiency notice finalization source checks passed.\n";
