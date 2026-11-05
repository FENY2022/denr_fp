<?php
declare(strict_types=1);

require_once __DIR__.'/../app/permit_step2_requirements.php';
require_once __DIR__.'/../app/permit_step4.php';

function step4_assert(bool $condition,string $label): void
{
    if (!$condition) throw new RuntimeException('Step 4 test failed: '.$label);
}

$valid=[];
foreach (step2_requirement_types() as $type=>$label) {
    $valid['assessment'][$type]='complete';
    $valid['requirement_remarks'][$type]='';
}
$parsed=step4_parse_requirement_assessments($valid);
$solar=step4_parse_requirement_assessments($valid,'solar');
$biomass=step4_parse_requirement_assessments($valid,'biomass');
step4_assert(($solar['items']['doe_esc_coa']['assessment']??'')==='not_applicable',
    'Solar automatically exempts DOE requirement #10');
step4_assert(($biomass['items']['doe_esc_coa']['assessment']??'')==='not_applicable',
    'Biomass automatically exempts DOE requirement #10');
step4_assert($parsed['errors']===[],'all required assessments accepted');
step4_assert(count($parsed['items'])===count(step2_requirement_types()),
    'all documentary requirements are represented');

$bad=$valid;
$first=array_key_first(step2_requirement_types());
$bad['assessment'][$first]='incomplete';
$bad['requirement_remarks'][$first]='';
step4_assert(step4_parse_requirement_assessments($bad)['errors']!==[],
    'deficiency requires a requirement-level note');

step4_assert(step4_parse_schedule('2026-11-10T09:30')==='2026-11-10 09:30:00',
    'valid datetime-local schedule normalized');
step4_assert(step4_parse_schedule('not-a-date')===null,
    'invalid schedule rejected');
step4_assert(step4_assessment_label('insufficient')==='Inappropriate'
    && step4_assessment_label('incomplete')==='Lacking',
    'assessment labels are updated for LPDD-FUS');

$migration=file_get_contents(__DIR__.'/../database/027_step4_documentary_evaluation.sql');
$step2=file_get_contents(__DIR__.'/../app/permit_step2.php');
$views=file_get_contents(__DIR__.'/../app/permit_step4_views.php');
$routes=file_get_contents(__DIR__.'/../public/index.php');
$tracking=file_get_contents(__DIR__.'/../app/permit_tracking.php');
$nav=file_get_contents(__DIR__.'/../app/views.php');
$reviewJs=file_get_contents(__DIR__.'/../public/assets/permit-step4-review.js');
$modalCss=file_get_contents(__DIR__.'/../public/assets/permit-step4-modal.css');

foreach ([
    'step4_under_evaluation','step4_corrections_required',
    'step4_resubmitted','step4_complete'
] as $status) {
    step4_assert(str_contains($migration,$status),'migration includes '.$status);
}
foreach ([
    'step4_review_started','step4_returned_for_compliance',
    'step4_resubmitted','step4_complete'
] as $action) {
    step4_assert(str_contains($migration,$action),'event enum includes '.$action);
}

step4_assert(str_contains($step2,"'step4_corrections_required'")
    && str_contains($step2,"status='step4_resubmitted'"),
    'Step 4 compliance resubmission returns directly to Step 4');
step4_assert(str_contains($routes,"case 'permit_step4_start':")
    && str_contains($routes,"case 'permit_step4_decision':"),
    'Step 4 start and decision routes exist');
step4_assert(str_contains($views,'Under Evaluation')
    && str_contains($views,'Returned for Compliance')
    && str_contains($views,'Complete & Sufficient'),
    'Step 4 queue tabs cover documentary evaluation flow');
step4_assert(str_contains($views,'View All Uploaded Files')
    && str_contains($views,'data-step4-file-link')
    && str_contains($views,'View File'),
    'Step 4 reviewers can preview submitted files within the full-screen evaluation modal');
step4_assert(str_contains($views,'Core Application Documents')
    && str_contains($views,'Environmental / Regulatory Clearances')
    && str_contains($views,'Overall Evaluation & Instructions')
    && str_contains($views,'Review & Final Decision'),
    'Step 4 review is organized into four guided wizard steps');
step4_assert(str_contains($views,'data-step4-wizard')
    && str_contains($views,'data-step4-wizard-step="1"')
    && str_contains($views,'data-step4-wizard-step="4"')
    && str_contains($views,'data-step4-next')
    && str_contains($views,'data-step4-prev'),
    'Step 4 uses Previous and Next wizard navigation');
step4_assert(str_contains($views,'id="step4-evaluation-modal"')
    && str_contains($views,'modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable')
    && !str_contains($views,'modal-dialog modal-fullscreen')
    && str_contains($views,'Open Documentary Evaluation')
    && str_contains($views,'data-step4-wizard-footer'),
    'Step 4 review opens as a large centered modal with persistent footer navigation');
step4_assert(str_contains($views,'data-step4-child-modal')
    && str_contains($views,'data-step4-child-close')
    && str_contains($views,'data-step4-child-files')
    && str_contains($views,'data-step4-child-preview')
    && str_contains($views,'data-step4-file-prev')
    && str_contains($views,'data-step4-back-files')
    && str_contains($views,'data-step4-fullscreen')
    && str_contains($views,'data-step4-fullscreen-label')
    && !str_contains($views,'id="step4-decision-modal"'),
    'View File opens a closable child modal with an optional full-screen viewer');
step4_assert(str_contains($views,'data-step4-progress-count')
    && str_contains($views,'data-step4-progress-meter')
    && str_contains($views,'data-step4-step-label')
    && str_contains($views,'data-step4-sticky-progress')
    && str_contains($views,'data-step4-progress-details')
    && str_contains($modalCss,'.step4-sticky-progress')
    && str_contains($modalCss,'position: sticky')
    && str_contains($modalCss,'.step4-progress-compact')
    && str_contains($reviewJs,'setProgressCompact')
    && str_contains($reviewJs,"delta > 6")
    && str_contains($reviewJs,"delta < -6"),
    'wizard collapses to a sticky progress bar on downward scroll and expands on upward scroll');
step4_assert(str_contains($views,'data-step4-summary-assessment')
    && str_contains($views,'data-step4-summary-overall')
    && str_contains($views,'data-step4-summary-schedule'),
    'final wizard step renders a review summary before decision');
step4_assert(!str_contains($views,'id="step4-document-sections"')
    && !str_contains($views,'accordion-collapse'),
    'Step 4 no longer depends on accordion navigation');
step4_assert(!str_contains($views,'style="width:'),
    'Step 4 progress does not rely on CSP-blocked inline styles');
step4_assert(str_contains($views,'name="assessment[')
    && str_contains($views,'field_validation_schedule'),
    'requirement-level assessments and field schedule are rendered');
step4_assert(str_contains($tracking,'permit_tracking_step4_payment_state')
    && str_contains($tracking,'permit_flow_current_tracking'),
    'tracking preserves the legacy payment stage and routes updated cases through Steps 5–11');
step4_assert(str_contains($nav,'Legacy Step 4 Reviews')
    && str_contains($nav,'Updated Annex A · Steps 4–11'),
    'legacy Step 4 evaluation remains accessible while new Annex A is active');
step4_assert(str_contains($reviewJs,'updateProgress')
    && str_contains($reviewJs,'renderStep')
    && str_contains($reviewJs,'validationForStep')
    && str_contains($reviewJs,'updateSummary')
    && str_contains($reviewJs,'openChild')
    && str_contains($reviewJs,'closeChild')
    && str_contains($reviewJs,'setChildFullscreen')
    && str_contains($reviewJs,"'Exit Full Screen'")
    && str_contains($reviewJs,'openFile')
    && str_contains($reviewJs,'form.requestSubmit()')
    && str_contains($reviewJs,"completeButton.disabled = completed < total"),
    'Step 4 wizard preserves inputs while a separate child document modal opens and closes');
step4_assert(str_contains($reviewJs,'markAssessmentError')
    && str_contains($reviewJs,"classList.add('is-invalid')")
    && str_contains($reviewJs,"classList.add('border-danger','border-2')")
    && str_contains($reviewJs,'scrollIntoView')
    && str_contains($reviewJs,"focus({preventScroll:true})")
    && str_contains($reviewJs,'step4AssessmentError')
    && str_contains($views,'permit-step4-review.js?v=10'),
    'missing assessments are highlighted red and the first invalid requirement is revealed and focused');

step4_assert(str_contains($modalCss,'#step4-evaluation-modal')
    && str_contains($modalCss,'.step4-child-modal')
    && str_contains($modalCss,'.step4-child-dialog')
    && str_contains($modalCss,'.step4-child-modal.step4-child-fullscreen')
    && str_contains($modalCss,'.step4-modal-pdf')
    && str_contains($nav,'permit-step4-modal.css'),
    'Large Step 4 modal and nested child document viewer styles are loaded on the evaluation page');

echo "PASS: Step 4 documentary evaluation, compliance loop, tracking and routing contracts.\n";
