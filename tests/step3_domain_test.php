<?php
declare(strict_types=1);
// Static/domain tests; integration against MySQL must be performed after migration 022.
require_once __DIR__.'/../app/permit_step3.php';
function step3_assert(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('Step 3: '.$message);
}
step3_assert(step3_transition_allowed('forwarded_to_ro','receive'),'receipt allowed');
step3_assert(!step3_transition_allowed('forwarded_to_ro','forward'),'forward before receipt blocked');
step3_assert(step3_transition_allowed('ro_received','forward'),'forward after receipt allowed');
step3_assert(!step3_transition_allowed('ro_received','receive'),'repeat receipt blocked');
step3_assert(!step3_transition_allowed('forwarded_to_lpdd','forward'),'repeat forward blocked');
step3_assert(!step3_transition_allowed('submitted','receive'),'Step 2 pending blocked');
step3_assert(!step3_transition_allowed('reverted','receive'),'reverted application blocked');
$router=(string)file_get_contents(__DIR__.'/../public/index.php');
$bootstrap=(string)file_get_contents(__DIR__.'/../app/bootstrap.php');
$view=(string)file_get_contents(__DIR__.'/../app/permit_step2_views.php');
step3_assert(str_contains($router,"case 'permit_ro_receive':"),'RO receive route');
step3_assert(str_contains($router,"case 'permit_ro_forward':"),'RO forward route');
step3_assert(str_contains($router,"case 'permit_step4_queue':"),'Step 4 handoff');
step3_assert(str_contains($bootstrap,"require_once __DIR__ . '/permit_step3.php'"),'module registered');
step3_assert(str_contains($view,'step3_action_panel_html($app)'),'panel integrated');
echo "Step 3 domain tests passed.\n";
