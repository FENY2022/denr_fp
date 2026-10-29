<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/permit_step2_drafts.php';

function check_step2_draft(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException('Step 2 draft test: '.$reason);
}
$empty = step2_draft_list_html([]);
check_step2_draft(str_contains($empty,'SELECTED FILES'),'heading');
check_step2_draft(str_contains($empty,'data-step2-count>0'),'empty counter');
check_step2_draft(str_contains($empty,'expire in 24 hours'),'expiry note');
$full = step2_draft_list_html([
    ['id'=>7,'original_name'=>'test.pdf'],
    ['id'=>8,'original_name'=>'<script>alert(1)</script>.png']
]);
check_step2_draft(str_contains($full,'data-step2-count>2'),'count');
check_step2_draft(substr_count($full,'data-step2-remove') === 2,'remove controls');
check_step2_draft(str_contains($full,'&lt;script&gt;'),'escape filename');
check_step2_draft(!str_contains($full,'<script>'),'avoid raw html injection');
check_step2_draft(step2_draft_real_path('../../.env') === null,'path traversal rejected');
check_step2_draft(step2_draft_real_path('fp_step2/1/some.pdf') === null,'permanent path rejected');
echo "FP supporting draft list and path safety checks passed.\n";
