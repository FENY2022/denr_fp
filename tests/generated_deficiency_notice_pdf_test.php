<?php
declare(strict_types=1);

require_once __DIR__.'/../app/notice_pdf.php';
require_once __DIR__.'/../app/notice_template.php';

$app=[
    'applicant_name'=>'Sample Applicant',
    'representative_position'=>'Authorized Representative',
    'company_name'=>'Sample Company',
    'company_address'=>'Butuan City',
    'project_title'=>'Sample FP Project',
    'project_location'=>'Agusan del Norte'
];
$config=[
    'signatory_name'=>'Regional Executive Director',
    'signatory_title'=>'RED',
    'contact_emails'=>'r13@denr.gov.ph',
    'footer_text'=>'DENR Region XIII, Ambago, Butuan City'
];
$config['letterhead_images']=fpoms_notice_builtin_branding();
$payload=[
    'area_hectares'=>'25.75',
    'notice_rows'=>[
        ['label'=>'Letter of Intent','remarks'=>'Missing authorized signature'],
        ['label'=>'Feasibility Study Proposal','remarks'=>'Incomplete location description']
    ]
];
$draft=FpomsNoticePdf::render($app,$payload,$config);
$checks=[
    'PDF 1.4 header'=>str_starts_with($draft,'%PDF-1.4'),
    'Project text'=>str_contains($draft,'Sample FP Project'),
    'Requirement row'=>str_contains($draft,'Letter of Intent'),
    'Reviewer remarks'=>str_contains($draft,'Incomplete location description'),
    'Unsigned watermark'=>str_contains($draft,'DRAFT - NOT SIGNED / NOT FOR RELEASE'),
    'Official DENR letterhead'=>str_contains($draft,'/Im_denr'),
    'Official SOCOTEC footer'=>str_contains($draft,'/Im_socotec'),
    'PDF object xref'=>str_contains($draft,'xref'),
    'PDF trailer'=>str_contains($draft,'%%EOF'),
    'Exactly one A4 page'=>str_contains($draft,'/Type /Pages /Count 1'),
    'A4 page object'=>preg_match('/\\/Type \\/Page\\b/',$draft)===1,
];
foreach ($checks as $label=>$passed) {
    if (!$passed) throw new RuntimeException('Notice PDF smoke test failed: '.$label);
}

echo "Generated A4 deficiency notice PDF checks passed.\n";
