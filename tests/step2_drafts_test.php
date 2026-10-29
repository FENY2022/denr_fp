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
check_step2_draft(str_contains($viewSource,'permit-step2-drafts.js?v=5'),'current script loads in both modes');
check_step2_draft(str_contains($viewSource,'data-step2-js-warning'),'JavaScript unavailable notice exists');
check_step2_draft(str_contains($viewSource,'data-draft-key="fpoms.permit.'),'form draft is scoped to applicant and application');
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
check_step2_draft(str_contains($uploadSource,'window.sessionStorage'),'permit fields persist through refresh');
check_step2_draft(str_contains($uploadSource,"['project_title','project_location','purpose']"),'permit draft includes all fields');
check_step2_draft(str_contains($bootstrapSource,"worker-src \\'self\\' blob:"),'PDF.js worker CSP');

$modern = step2_draft_list_html([
    ['id'=>19,'original_name'=>'document.pdf','mime_type'=>'application/pdf','size'=>1000]
],true);
check_step2_draft(str_contains($modern,'Rearrange Pages'),'saved PDF can be edited again');
check_step2_draft(str_contains($modern,'until you submit'),'persistent draft notice');
check_step2_draft(!str_contains($modern,'expire in 24 hours'),'modern draft does not expire');
$view = (string)file_get_contents(__DIR__.'/../app/permit_step2_views.php');
$backend = (string)file_get_contents(__DIR__.'/../app/permit_step2_persistent.php');
$submit = (string)file_get_contents(__DIR__.'/../app/permit_step2.php');
$router = (string)file_get_contents(__DIR__.'/../public/index.php');
$script = (string)file_get_contents(__DIR__.'/../public/assets/permit-step2-drafts.js');
$cleanup = (string)file_get_contents(__DIR__.'/../scripts/cleanup_step2_drafts.php');
check_step2_draft(str_contains($view,'step2_persistent_form('),'restores server fields');
check_step2_draft(str_contains($view,'data-step2-confirm-clear'),'confirm clear button');
check_step2_draft(str_contains($backend,'ON DUPLICATE KEY UPDATE'),'server autosave upsert');
check_step2_draft(str_contains($backend,'function step2_persistent_replace_pdf'),'secure PDF replacement');
check_step2_draft(str_contains($backend,'function step2_persistent_clear'),'server clear function');
check_step2_draft(str_contains($backend,'WHERE id=? AND client_id=?'),'PDF authorized by owner');
check_step2_draft(str_contains($router,"case 'permit_draft_save':"),'autosave endpoint');
check_step2_draft(str_contains($router,"case 'permit_draft_clear':"),'clear endpoint');
check_step2_draft(str_contains($router,"case 'permit_draft_replace':"),'replace endpoint');
check_step2_draft(str_contains($router,"case 'permit_draft_file':"),'PDF fetch endpoint');
check_step2_draft(str_contains($script,'permit-draft-replace'),'PDF reopen workflow');
check_step2_draft(str_contains($script,'form.requestSubmit(submit)'),'submit waits on field save');
check_step2_draft(str_contains($submit,'DELETE FROM permit_step2_form_drafts'),'draft deleted in commit');
check_step2_draft(str_contains($cleanup,'if (step2_persistent_ready())'),'expiration cleanup disabled');
echo "FP persistent draft and PDF organizer checks passed.\n";
