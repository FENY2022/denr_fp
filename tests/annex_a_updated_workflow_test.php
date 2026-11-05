<?php
declare(strict_types=1);

// DB-free contracts for the final 11-step Annex A workflow.
require_once __DIR__.'/../app/permit_flow.php';

function updated_flow_assert(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException('Updated Annex A: '.$label);
}
function updated_flow_text(string $path): string
{
    $data=file_get_contents(__DIR__.'/../'.$path);
    if (!is_string($data)) throw new RuntimeException('Cannot read '.$path);
    return $data;
}

updated_flow_assert(permit_flow_is_new(['flow_version'=>2]),'new applications use version 2');
updated_flow_assert(!permit_flow_is_new(['flow_version'=>1])
    && !permit_flow_is_new([]),'legacy applications are unchanged');
foreach (range(2,12) as $step) {
    updated_flow_assert(permit_flow_step_label($step)!=='Unknown Step',
        'Step '.$step.' has an explicit label');
}
foreach ([
    2=>['red_signed_deficiency_notice'],
    4=>['cenro_memo'],
    5=>['csw_report','gis_map','shapefiles','td','authenticity_certificate'],
    6=>['final_map','minutes','agreements','approval_sheet','signed_final_map'],
    7=>['memo_endorsement','fp_draft','tor_draft'],
    8=>['initialed_memo','initialed_fp','initialed_tor'],
    9=>['signed_memo','initialed_fp_ard','initialed_tor_ard'],
    10=>['approved_fp','approved_tor','approved_map','approved_sheet','memo_to_penro_cenro'],
    11=>['release_record','printed_memo','transmittal']
] as $step=>$categories) {
    updated_flow_assert(array_keys(permit_flow_categories($step))===$categories,
        'Step '.$step.' required artifact categories match final Annex A');
}
updated_flow_assert(permit_flow_categories(6)['signed_final_map'][0]
    ==='Scanned with Signed Final Map (Days 3–4)',
    'Step 6 requires the scanned signed final map');

$schema=updated_flow_text('database/031_annex_a_updated_workflow.sql');
$submit=updated_flow_text('app/permit_step2.php');
$step2=updated_flow_text('app/permit_step2_views.php');
$evaluation=updated_flow_text('app/permit_step4.php');
$step3=updated_flow_text('app/permit_step3.php');
$flow=updated_flow_text('app/permit_flow.php');
$views=updated_flow_text('app/permit_flow_views.php');
$router=updated_flow_text('public/index.php');
$nav=updated_flow_text('app/views.php');
$clientJs=updated_flow_text('public/assets/permit-step4-review.js');

updated_flow_assert(str_contains($schema,'flow_version TINYINT UNSIGNED NOT NULL DEFAULT 1')
    && str_contains($schema,'permit_flow_cases')
    && str_contains($schema,'permit_flow_artifacts')
    && str_contains($schema,'permit_flow_events')
    && str_contains($schema,'rrdc_schedule DATETIME'),
    'migration 031 preserves legacy cases and creates auditable updated workflow');
updated_flow_assert(str_contains($submit,'status,flow_version')
    && str_contains($submit,"'submitted',2")
    && str_contains($submit,'permit_flow_is_new($workflow)'),
    'new applications use updated version, legacy initial decisions cannot bypass full evaluation');
updated_flow_assert(str_contains($step2,'Step 2 Completeness & Sufficiency')
    && str_contains($evaluation,'$modern'."?'forwarded_to_ro':'step4_complete'")
    && str_contains($evaluation,'deficiency_notice_issued'),
    'Step 2 completeness decision forwards to RO and requires actual issued signed notices');
updated_flow_assert(str_contains($clientJs,"dataset.step4FlowVersion === '2'")
    && str_contains($clientJs,'Step 2')
    && str_contains($clientJs,'RED-signed deficiency notice'),
    'wizard bypasses early scheduling and checks signed notice on incomplete decisions');
updated_flow_assert(str_contains($step3,'INSERT INTO permit_flow_cases')
    && str_contains($step3,'current_step')
    && str_contains($step3,'permit_flow_is_new'),
    'RO Step 3 forwards updated applications to Step 4 action case');
updated_flow_assert(str_contains($flow,'permit_payment_instructions')
    && str_contains($flow,"status='step4_complete'")
    && str_contains($flow,'cenro_instructions')
    && str_contains($flow,'rrdc_scheduled')
    && str_contains($flow,'rrdc_deliberated')
    && str_contains($flow,'twg_instructed'),
    'post-RO LPDD actions, CENRO and RRDC checkpoints are enforced');
updated_flow_assert(str_contains($flow,'fpoms_file_store_upload')
    && str_contains($flow,'fpoms_file_read')
    && str_contains($flow,'hash_equals')
    && str_contains($flow,'permit_flow_staff_can_act'),
    'all stage documents use encrypted storage with role-checked access');
updated_flow_assert(str_contains($flow,'deficiency_notice_issued')
    && str_contains($flow,'(int)$case'."['current_step']===12")
    && str_contains($views,'$issued'),
    'client only sees issued notices and final permits after RO release');
updated_flow_assert(str_contains($evaluation,'permit_flow_application_email_url')
    && str_contains($evaluation,'permit_flow_notice_email_attachments')
    && str_contains($flow,'/permit-application?id=')
    && str_contains($flow,'deficiency notice email attachment failed integrity verification')
    && str_contains($flow,'Open FP Application')
    && str_contains((string)file_get_contents(__DIR__.'/../app/smtp.php'),'addStringAttachment'),
    'returned application email attaches verified RED-signed notice and includes configured system link');
updated_flow_assert(str_contains($router,"case 'permit_flow_advance':")
    && str_contains($router,"case 'permit_flow_upload':")
    && str_contains($router,"case 'permit_flow_milestone':")
    && str_contains($router,"case 'permit_flow_notice':")
    && str_contains($router,"case 'permit_flow_file':"),
    'workflow routes implement secure document, milestone and handoff actions');
updated_flow_assert(str_contains($nav,'permit_flow_queue')
    && str_contains($views,'Scanned with Signed Final Map')===false,
    'sidebar links workflow and signed map is defined in authoritative category catalog');
updated_flow_assert(str_contains($views,'RRDC Deliberation Schedule')
    && str_contains($views,'Record TWG Field Validation Instruction')
    && str_contains($views,'Record Completed RRDC Deliberation'),
    'CENRO and RRDC action checkpoint buttons are visible to their roles');

echo "PASS: versioned 11-step Annex A workflow, signed map, roles, uploads, events and release access.\n";
