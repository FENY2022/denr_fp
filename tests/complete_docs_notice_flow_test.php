<?php
declare(strict_types=1);
require_once __DIR__.'/../app/complete_notice_pdf.php';
require_once __DIR__.'/../app/notice_template.php';
$app=[
 'applicant_name'=>'Sample Proponent','representative_position'=>'Authorized Representative',
 'company_name'=>'Sample Company','company_address'=>'Butuan City',
 'project_title'=>'Sample Project','project_location'=>'Agusan del Norte'
];
$payload=['area_hectares'=>'25.75','penro_name'=>'','cenro_name'=>''];
$config=[
 'signatory_name'=>'MA. THERESA J. ALLEN','signatory_title'=>'OIC, Regional Executive Director',
 'contact_emails'=>'r13@denr.gov.ph; lasangutilizationlpdd@gmail.com',
 'footer_text'=>'DENR-XIII, Ambago, Butuan City, Philippines | Hotline No. 0981-017-3551 | E-Mail: r13@denr.gov.ph',
 'letterhead_images'=>fpoms_notice_builtin_branding()
];
$pdf=FpomsCompleteNoticePdf::render($app,$payload,$config,null,'November 07, 2026');
$checks=[
 'PDF header'=>str_starts_with($pdf,'%PDF-1.4'),
 'one page'=>str_contains($pdf,'/Type /Pages /Count 1'),
 'complete meritorious'=>str_contains($pdf,'complete and meritorious'),
 'application fee'=>str_contains($pdf,'PHP 10,000.00'),
 'PENRO/CENRO'=>str_contains($pdf,'PENRO-') && str_contains($pdf,'CENRO-'),
 'RED signatory'=>str_contains($pdf,'MA. THERESA J. ALLEN'),
 'draft watermark'=>str_contains($pdf,'DRAFT - NOT SIGNED / NOT FOR RELEASE'),
 'EOF'=>str_ends_with($pdf,"%%EOF\n"),
];
foreach($checks as $label=>$passed) if(!$passed) throw new RuntimeException('Template No. 2 PDF test failed: '.$label);
$step3=file_get_contents(__DIR__.'/../app/permit_step3.php');
$complete=file_get_contents(__DIR__.'/../app/complete_notice.php');
$flow=file_get_contents(__DIR__.'/../app/permit_flow.php');
$router=file_get_contents(__DIR__.'/../public/index.php');
$views=file_get_contents(__DIR__.'/../app/views.php');
$integration=[
 'RO receipt prepares notice'=>str_contains((string)$step3,'fpoms_complete_notice_prepare_in_transaction'),
 'generic receipt payment email removed'=>!str_contains((string)$step3,"permit_flow_email($app,'Notice to Pay – FP Application #"),
 'RED e-sign path'=>str_contains((string)$complete,'function fpoms_complete_notice_red_approve'),
 'physical upload path'=>str_contains((string)$complete,'function fpoms_complete_notice_red_upload_signed'),
 'final release activates payment'=>str_contains((string)$complete,"status,notified_at) VALUES (?,'awaiting_payment',NOW())"),
 'final release verifies signed artifact'=>str_contains((string)$complete,'failed integrity verification. Final release was blocked'),
 'Step 4 waits for release'=>str_contains((string)$flow,'fpoms_complete_notice_released'),
 'RED queue route'=>str_contains((string)$router,"case 'complete_notice_queue':"),
 'RED queue menu'=>str_contains((string)$views,"'RED – Template No. 2 Approvals'"),
];
foreach($integration as $label=>$passed) if(!$passed) throw new RuntimeException('Template No. 2 integration test failed: '.$label);
echo "RED-signed Template No. 2 notice flow checks passed.\n";
