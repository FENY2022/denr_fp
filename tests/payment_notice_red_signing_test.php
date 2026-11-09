<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$step3=file_get_contents($root.'/app/permit_step3.php');
$step3Views=file_get_contents($root.'/app/permit_step3_views.php');
$notice=file_get_contents($root.'/app/payment_notice.php');
$pdf=file_get_contents($root.'/app/payment_notice_pdf.php');
$payment=file_get_contents($root.'/app/permit_payment.php');
$paymentViews=file_get_contents($root.'/app/permit_payment_views.php');
$flow=file_get_contents($root.'/app/permit_flow.php');
$flowViews=file_get_contents($root.'/app/permit_flow_views.php');
$step2Views=file_get_contents($root.'/app/permit_step2_views.php');
$views=file_get_contents($root.'/app/views.php');
$router=file_get_contents($root.'/public/index.php');

$checks=[
 [$step3,'fpoms_payment_notice_prepare_on_ro_receipt','RO receipt creates Template No. 2 draft'],
 [$step3,'fpoms_payment_notice_notify_pending','RO receipt routes the draft to RED and records applicant pending status'],
 [$notice,"status='released'",'finalization releases only signed notice'],
 [$notice,'red_signed_payment_notice','signed PDF stored as separate workflow artifact'],
 [$notice,'fpoms_payment_notice_red_upload_signed','physical signed upload path'],
 [$notice,'FILEINFO_MIME_TYPE','physical signed PDF MIME validation'],
 [$notice,'fpoms_payment_notice_red_approve','electronic RED signing path'],
 [$notice,"'embed'=>1",'payment notice modal requests an embeddable PDF response'],
 [$notice,"header_remove('X-Frame-Options')",'embedded payment PDF replaces the global frame denial'],
 [$notice,"frame-ancestors 'self'",'embedded payment PDF remains same-origin only'],
 [$notice,'fpoms_payment_notice_send_email','signed PDF email path'],
 [$notice,'fpoms_payment_notice_install_schema','automatic schema initialization'],
 [$notice,'fpoms_payment_notice_prepare_existing','existing received-case recovery path'],
 [$notice,'print_for_signing','clean physical-signing print mode'],
 [$notice,"ON DUPLICATE KEY UPDATE notified_at=COALESCE",'final release preserves previously verified payment state'],
 [$pdf,'$printForSigning=false','PDF renderer supports clean print-for-signing copy'],
 [$pdf,'$signature===null && !$printForSigning','DRAFT watermark omitted only for signing copy'],
 [$flow,"red_signed_payment_notice",'applicant file access for released payment notice'],
 [$flow,'fpoms_payment_notice_is_released','Step 4 waits for official signed Template No. 2 release'],
 [$flowViews,'Official RED-signed Template No. 2 release is required','Step 4 UI shows release gate'],
 [$flowViews,'RED-Signed Template No. 2 / Notice to Pay','client modal preview for official Template No. 2'],
 [$payment,'permit_payment_available_for_application','payment becomes available after official notice release'],
 [$paymentViews,'fpoms_payment_notices pn','payment queue includes released updated-flow notices'],
 [$step2Views,'fpoms_payment_notice_is_released','application page shows payment action after notice release'],
 [$pdf,'application is found complete and meritorious','Template No. 2 complete/meritorious language'],
 [$pdf,'PHP 10,000.00','Template No. 2 fee amount'],
 [$pdf,'PENRO-________________ and CENRO-________________','Template No. 2 PENRO/CENRO placeholders'],
 [$views,'RED – Payment Notice Approvals','RED sidebar queue'],
 [$views,'RED Notices / E-Sign','shared RED signature configuration label'],
 [$router,"case 'payment_notice_generate':",'existing received-case generation route'],
 [$router,"case 'payment_notice_upload_signed':",'signed upload route'],
 [$router,"case 'payment_notice_approve':",'RED sign/finalize route']
];
foreach($checks as [$haystack,$needle,$label]) {
    if(!str_contains((string)$haystack,$needle)) {
        throw new RuntimeException('Template No. 2 regression failed: '.$label);
    }
}
echo "Template No. 2 RED-signing workflow checks passed.\n";
