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
check_step2_draft(str_contains($viewSource,'permit-step2-drafts.js?v=3'),'current script loads in both modes');
check_step2_draft(str_contains($viewSource,'data-step2-js-warning'),'JavaScript unavailable notice exists');
$modalSource = (string)file_get_contents(__DIR__.'/../app/permit_step2_views.php');
$editorSource = (string)file_get_contents(__DIR__.'/../public/assets/permit-pdf-organizer.js');
$uploadSource = (string)file_get_contents(__DIR__.'/../public/assets/permit-step2-drafts.js');
$bootstrapSource = (string)file_get_contents(__DIR__.'/../app/bootstrap.php');
check_step2_draft(str_contains($modalSource,'function step2_pdf_organizer_modal_html()'),'PDF organizer modal helper');
check_step2_draft(str_contains($modalSource,'step2_pdf_organizer_modal_html()'),'organizer modal included on FP apply');
check_step2_draft(str_contains($modalSource,'pdf-lib@1.17.1'),'pinned PDF editor library');
check_step2_draft(str_contains($modalSource,'permit-pdf-organizer.js?v=1'),'organizer loaded before uploader');
check_step2_draft(str_contains($editorSource,'copyPages(state.document,state.order)'),'saved PDF follows arranged page order');
check_step2_draft(str_contains($editorSource,"makeButton('duplicate'"),'duplicate page action');
check_step2_draft(str_contains($editorSource,"makeButton('remove'"),'remove page action');
check_step2_draft(str_contains($editorSource,"makeButton('preview'"),'page preview action');
check_step2_draft(str_contains($uploadSource,'window.FPOMSOrganizePdf(original,maxBytes'), 'all PDF files organized before upload');
check_step2_draft(str_contains($bootstrapSource,"worker-src \\'self\\' blob:"),'PDF.js worker CSP');
echo "FP draft Selected Files and PDF organizer checks passed.\n";
