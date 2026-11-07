<?php
declare(strict_types=1);

function step2_requirement_types(): array
{
    return [
        'letter_of_intent'=>'Letter of Intent',
        'annex_1a'=>'Duly accomplished and notarized application form (Annex 1A)',
        'annex_1b'=>'Feasibility Study Proposal (Annex 1B)',
        'ecc_cnc'=>'Environmental Compliance Certificate (ECC) / Certificate of Non-Coverage (CNC)*',
        'ncip_clearance'=>'NCIP Clearance or proof of application for NCIP Clearance, stamped received by NCIP**',
        'doe_esc_coa'=>'Energy Service Contract (ESC) or Certificate of Authority (COA) from DOE',
    ];
}
function step4_allowed_assessments(): array
{
    return ['complete','incomplete','insufficient','not_applicable'];
}
function step4_assessment_label(string $assessment): string
{
    return match ($assessment) {
        'complete'=>'Complete',
        'incomplete'=>'Lacking',
        'insufficient'=>'Inappropriate',
        'not_applicable'=>'Not Applicable',
        default=>'Not Reviewed'
    };
}

// Load only the helper source through lightweight extraction to avoid app bootstrap/database dependencies.
$source=file_get_contents(__DIR__.'/../app/deficiency_notice.php');
if (!is_string($source)) throw new RuntimeException('Cannot read deficiency_notice.php.');
$start=strpos($source,'function fpoms_notice_clean_remark');
$end=strpos($source,'function fpoms_notice_prepare');
if ($start===false || $end===false || $end<=$start) {
    throw new RuntimeException('Cannot locate deficiency notice row helpers.');
}
eval(substr($source,$start,$end-$start));

$items=[
    'letter_of_intent'=>['assessment'=>'complete','remarks'=>'Complete'],
    'annex_1a'=>['assessment'=>'complete','remarks'=>''],
    'annex_1b'=>['assessment'=>'incomplete','remarks'=>'Lacking Lacking'],
    'ecc_cnc'=>['assessment'=>'insufficient','remarks'=>'Inappropriate Wrong document uploaded'],
    'ncip_clearance'=>['assessment'=>'not_applicable','remarks'=>'Not Applicable'],
    'doe_esc_coa'=>['assessment'=>'complete','remarks'=>'Verified by reviewer'],
];
$rows=fpoms_notice_rows($items);
if (count($rows)!==6) throw new RuntimeException('Return notice must include all six assessed requirements.');
$remarks=array_column($rows,'remarks');
$expected=[
    'Complete',
    'Complete',
    'Lacking',
    'Inappropriate – Wrong document uploaded',
    'Not Applicable',
    'Complete – Verified by reviewer',
];
if ($remarks!==$expected) {
    throw new RuntimeException('Unexpected notice remarks: '.json_encode($remarks));
}
if (!fpoms_notice_has_deficiency($items)) {
    throw new RuntimeException('Deficiency detection failed.');
}
if (fpoms_notice_has_deficiency([
    ['assessment'=>'complete'],['assessment'=>'not_applicable']
])) {
    throw new RuntimeException('Complete/NA rows must not count as deficiencies.');
}

$pdfSource=file_get_contents(__DIR__.'/../app/notice_pdf.php');
if (!str_contains((string)$pdfSource,'assessment status of each documentary requirement')
    || !str_contains((string)$pdfSource,'marked Lacking or Inappropriate')) {
    throw new RuntimeException('Notice explanatory wording was not updated.');
}

echo "Deficiency notice assessment/remarks mapping checks passed.".PHP_EOL;
