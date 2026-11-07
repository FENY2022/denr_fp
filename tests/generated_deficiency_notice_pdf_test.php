<?php
declare(strict_types=1);

require_once __DIR__.'/../app/notice_pdf.php';
require_once __DIR__.'/../app/notice_template.php';
require_once __DIR__.'/../app/deficiency_notice.php';

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
$printCopy=FpomsNoticePdf::render($app,$payload,$config,null,null,true);
$signatureImage=imagecreatetruecolor(80,24);
imagefill($signatureImage,0,0,imagecolorallocate($signatureImage,255,255,255));
imageline($signatureImage,5,17,74,5,imagecolorallocate($signatureImage,20,40,60));
ob_start();
imagejpeg($signatureImage,null,85);
$signature=(string)ob_get_clean();
imagedestroy($signatureImage);
$signed=fpoms_notice_render_red_signed_preview($app,$payload,$config,$signature);
$noticeController=file_get_contents(__DIR__.'/../app/deficiency_notice.php');
if (!is_string($noticeController)) {
    throw new RuntimeException('Cannot read deficiency notice controller.');
}
$checks=[
    'PDF 1.4 header'=>str_starts_with($draft,'%PDF-1.4'),
    'Project text'=>str_contains($draft,'Sample FP Project'),
    'Requirement row'=>str_contains($draft,'Letter of Intent'),
    'Reviewer remarks'=>str_contains($draft,'Incomplete location description'),
    'Unsigned watermark'=>str_contains($draft,'DRAFT - NOT SIGNED / NOT FOR RELEASE'),
    'Manual signing copy is clean and one page'=>
        !str_contains($printCopy,'DRAFT - NOT SIGNED / NOT FOR RELEASE')
        && str_contains($printCopy,'/Type /Pages /Count 1')
        && !str_contains($printCopy,'/Im_signature'),
    'Official DENR letterhead'=>str_contains($draft,'/Im_denr'),
    'Official SOCOTEC footer'=>str_contains($draft,'/Im_socotec'),
    'Justified body paragraphs'=>preg_match('/\d+\.\d{3} Tw/',$draft)===1,
    'Word spacing resets outside body text'=>str_contains($draft,'0 Tw'),
    'PDF object xref'=>str_contains($draft,'xref'),
    'PDF trailer'=>str_contains($draft,'%%EOF'),
    'Exactly one A4 page'=>str_contains($draft,'/Type /Pages /Count 1'),
    'A4 page object'=>preg_match('/\\/Type \\/Page\\b/',$draft)===1,
    'RED signature appears in preview'=>str_contains($signed,'/Im_signature')
        && !str_contains($signed,'DRAFT - NOT SIGNED / NOT FOR RELEASE')
        && str_contains($signed,'/Type /Pages /Count 1'),
    'RED queue previews e-signed PDF'=>str_contains($noticeController,
        "'embed'=>1]")
        && str_contains($noticeController,"'signed_preview'=>1")
        && str_contains($noticeController,'Preview with RED e-Signature'),
];
foreach ($checks as $label=>$passed) {
    if (!$passed) throw new RuntimeException('Notice PDF smoke test failed: '.$label);
}

echo "Generated A4 deficiency notice PDF checks passed.\n";
