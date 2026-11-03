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
step4_assert(step4_assessment_label('insufficient')==='Insufficient',
    'assessment labels remain stable');

$migration=file_get_contents(__DIR__.'/../database/027_step4_documentary_evaluation.sql');
$step2=file_get_contents(__DIR__.'/../app/permit_step2.php');
$views=file_get_contents(__DIR__.'/../app/permit_step4_views.php');
$routes=file_get_contents(__DIR__.'/../public/index.php');
$tracking=file_get_contents(__DIR__.'/../app/permit_tracking.php');
$nav=file_get_contents(__DIR__.'/../app/views.php');
$reviewJs=file_get_contents(__DIR__.'/../public/assets/permit-step4-review.js');

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
    && str_contains($views,'data-document-preview')
    && str_contains($views,'View File'),
    'Step 4 reviewers can open submitted files from the evaluation checklist');
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
step4_assert(str_contains($views,'data-step4-progress-count')
    && str_contains($views,'data-step4-progress-meter')
    && str_contains($views,'data-step4-step-label'),
    'wizard exposes live requirement progress and current step');
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
step4_assert(str_contains($tracking,"'step4_complete' => [")
    && str_contains($tracking,"'next_role'=>'CENRO Technical Working Group'"),
    'tracking hands complete Step 4 cases toward CENRO Step 5 preparation');
step4_assert(str_contains($nav,'Step 4 Documentary Evaluation')
    && !str_contains($nav,"['clipboard-data','Document Evaluation']"),
    'Step 4 is active navigation instead of a future placeholder');
step4_assert(str_contains($reviewJs,'updateProgress')
    && str_contains($reviewJs,'renderStep')
    && str_contains($reviewJs,'validateRequirementStep')
    && str_contains($reviewJs,'updateSummary')
    && str_contains($reviewJs,"completeButton.disabled = completed < total"),
    'Step 4 wizard validates each section, preserves inputs, updates summary and gates completion');

echo "PASS: Step 4 documentary evaluation, compliance loop, tracking and routing contracts.\n";
