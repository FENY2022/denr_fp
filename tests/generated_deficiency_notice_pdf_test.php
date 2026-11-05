<?php
declare(strict_types=1);

require_once __DIR__.'/../app/notice_pdf.php';

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
$payload=[
    'area_hectares'=>'25.75',
    'notice_rows'=>[
        ['label'=>'Letter of Intent','remarks'=>'Missing authorized signature'],
        ['label'=>'Feasibility Study Proposal','remarks'=>'Incomplete location description']
    ]
];
$draft=FpomsNoticePdf::render($app,$payload,$config);
assert(str_starts_with($draft,'%PDF-1.4'));
assert(str_contains($draft,'Sample FP Project'));
assert(str_contains($draft,'Letter of Intent'));
assert(str_contains($draft,'Incomplete location description'));
assert(str_contains($draft,'DRAFT - NOT SIGNED / NOT FOR RELEASE'));
assert(str_contains($draft,'xref'));
assert(str_contains($draft,'%%EOF'));
assert(preg_match('/\/Type \/Page\b/',$draft)===1);

echo "Generated A4 deficiency notice PDF checks passed.\n";
