<?php
declare(strict_types=1);

// DB-free FP Step 2 requirement catalog, upload UI and persistence checks.
// The * and ** markers are retained; suitability is evaluated by LPDD-FUS.
require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/permit_step2_requirements.php';
function fp_category_check(bool $yes,string $label): void
{
    if (!$yes) throw new RuntimeException('FP document categories: '.$label);
}
$requirements = step2_requirement_types();
fp_category_check(count($requirements)===6,'six named document groups');
$checks = [
    'letter_of_intent'=>'Letter of Intent',
    'annex_1a'=>'Duly accomplished and notarized application form (Annex 1A)',
    'annex_1b'=>'Feasibility Study Proposal (Annex 1B)',
    'ecc_cnc'=>'Environmental Compliance Certificate (ECC) / Certificate of Non-Coverage (CNC)*',
    'ncip_clearance'=>'NCIP Clearance or proof of application for NCIP Clearance, stamped received by NCIP**',
    'doe_esc_coa'=>'Energy Service Contract (ESC) or Certificate of Authority (COA) from DOE'
];
fp_category_check($requirements===$checks,'exact supplied names and original order');
foreach (array_keys($checks) as $key) fp_category_check(step2_requirement_valid($key),
    'valid requirement '.$key);
fp_category_check(!step2_requirement_valid('other'),'other is not a category for new uploads');
fp_category_check(!step2_requirement_valid(''),'empty category rejected');
$sample=[
    ['requirement_type'=>'annex_1a'],
    ['requirement_type'=>'annex_1a'],
    ['requirement_type'=>'letter_of_intent'],
    ['requirement_type'=>'other'],
];
$render=step2_requirement_checklist_html($sample);
fp_category_check(str_contains($render,'Uploaded: 2')&&str_contains($render,'Uploaded: 1'),
    'multiple saved uploads counted by actual category');
fp_category_check(str_contains($render,'earlier/unclassified document(s)'),
    'historical files not automatically classified');
fp_category_check(substr_count($render,'Not uploaded')===4,
    'missing categories displayed, not falsely marked submitted');
$view=(string)file_get_contents(__DIR__.'/../app/permit_step2_views.php');
$upload=(string)file_get_contents(__DIR__.'/../app/permit_step2_drafts.php');
$submit=(string)file_get_contents(__DIR__.'/../app/permit_step2.php');
$script=(string)file_get_contents(__DIR__.'/../public/assets/permit-step2-drafts.js');
$router=(string)file_get_contents(__DIR__.'/../public/index.php');
$migration=(string)file_get_contents(__DIR__.'/../database/019_step2_document_requirements.sql');
fp_category_check(str_contains($view,'data-fp-requirement-guide')
    && str_contains($view,'data-step2-pick-requirement')
    && str_contains($view,'data-step2-category-select')
    && str_contains($view,'data-step2-requirement-files'),
    'six choices, upload buttons and matching file areas rendered');
fp_category_check(substr_count($view,'data-step2-form-section=')===4
    && str_contains($view,"step2_form_section_heading_html(4,'Review and Submit'"),
    'permit form is organized into four numbered sections');
fp_category_check(str_contains($view,'step2_requirement_checklist_html('),
    'LPDD-FUS and applicant display saved requirement categories');
fp_category_check(str_contains($view,'permit-step2-drafts.js?v=9'),
    'new browser uploader script loaded');
fp_category_check(str_contains($script,'placeFileCard')
    && str_contains($script,'step2RequirementType'),
    'saved and newly selected files appear under their matching requirement');
fp_category_check(str_contains($script,"data.set('document_category',category)")
    && str_contains($router,"(string)(" . '$_POST' . "['document_category']"),
    'AJAX upload includes selected requirement in POST');
fp_category_check(str_contains($script,'application_file_categories[]')
    && str_contains($submit,"application_file_categories"),
    'direct upload stores per-file categories, even across multiple file picker batches');
fp_category_check(str_contains($upload,'step2_requirement_valid($category)')
    && str_contains($upload,'requirement_type,original_name'),
    'category checked before private file upload and stored in draft');
fp_category_check(substr_count($submit,'revision,requirement_type,original_name')===2,
    'both direct and draft submissions save categories permanently');
fp_category_check(str_contains($submit,'step2_category_schema_ready()')
    && str_contains($upload,'step2_category_schema_ready()'),
    'category migration is checked before final submission and staging');
fp_category_check(substr_count($migration,'ADD COLUMN requirement_type')===2,
    'additive two-column database migration');
echo "PASS: Six FP document types, 019 schema, secure category upload and review display.\n";
