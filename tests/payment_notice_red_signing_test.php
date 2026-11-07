<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$step3=file_get_contents($root.'/app/permit_step3.php');
$notice=file_get_contents($root.'/app/payment_notice.php');
$pdf=file_get_contents($root.'/app/payment_notice_pdf.php');
$views=file_get_contents($root.'/app/views.php');
$router=file_get_contents($root.'/public/index.php');
$checks=[
 [$step3,'fpoms_payment_notice_prepare_on_ro_receipt','RO receipt creates Template No. 2 draft'],
 [$step3,'Template No. 2 for RED Signature','RED is notified instead of applicant payment email'],
 [$notice,"status='released'",'finalization releases only signed notice'],
 [$notice,'red_signed_payment_notice','signed PDF stored as separate workflow artifact'],
 [$notice,'fpoms_payment_notice_red_upload_signed','physical signed upload path'],
 [$notice,'fpoms_payment_notice_red_approve','electronic RED signing path'],
 [$notice,'fpoms_payment_notice_send_email','signed PDF email path'],
 [$pdf,'application is found complete and meritorious','Template No. 2 complete/meritorious language'],
 [$pdf,'PHP 10,000.00','Template No. 2 fee amount'],
 [$pdf,'PENRO-________________ and CENRO-________________','Template No. 2 PENRO/CENRO placeholders'],
 [$views,'RED – Payment Notice Approvals','RED sidebar queue'],
 [$router,"case 'payment_notice_upload_signed':",'signed upload route'],
 [$router,"case 'payment_notice_approve':",'RED sign/finalize route']
];
foreach($checks as [$haystack,$needle,$label]) {
 if(!str_contains((string)$haystack,$needle)) throw new RuntimeException('Template No. 2 regression failed: '.$label);
}
echo "Template No. 2 RED-signing workflow checks passed.\n";
