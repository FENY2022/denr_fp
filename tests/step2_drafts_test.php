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
$direct = step2_direct_selected_files_html();
check_step2_draft(str_contains($direct,'SELECTED FILES'),'fallback list exists without migration 015');
check_step2_draft(str_contains($direct,'data-step2-count>0'),'fallback count starts at zero');
check_step2_draft(str_contains($direct,'not yet saved'),'fallback never claims staged uploads');
check_step2_draft(str_contains($direct,'will be lost if you refresh'),'fallback explains no refresh persistence');
check_step2_draft(!str_contains($direct,'is-saved'),'fallback does not mark files saved');
$viewSource = (string)file_get_contents(__DIR__.'/../app/permit_step2_views.php');
check_step2_draft(str_contains($viewSource,"step2_direct_selected_files_html()"),'fallback is rendered');
check_step2_draft(str_contains($viewSource,'permit-step2-drafts.js?v=2'),'current script loads in both modes');
check_step2_draft(str_contains($viewSource,'data-step2-js-warning'),'JavaScript unavailable notice exists');
echo "FP supporting draft, fallback Selected Files and path safety checks passed.\n";
