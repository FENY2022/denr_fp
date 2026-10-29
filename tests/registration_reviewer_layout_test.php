<?php
declare(strict_types=1);

// Database-free reviewer layout checks, using the same existing registration
// definitions and uploaded-file renderer as LPDD-FUS /review?id=N.
require_once __DIR__ . '/../app/registration_requirements.php';
require_once __DIR__ . '/../app/registration_attachments.php';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function url(string $path, array $args = []): string
{
    return $path . ($args ? '?' . http_build_query($args) : '');
}
function status_badge(string $status): string
{
    return '<span class="badge">' . e($status) . '</span>';
}
function reviewer_layout_check(bool $okay,string $description): void
{
    if (!$okay) throw new RuntimeException('Reviewer layout: '.$description);
}

$guide = registration_reviewer_requirement_guide_html('corporation');
reviewer_layout_check(str_contains($guide,'Documentary Requirements by Applicant Type')
    && str_contains($guide,'For Corporations, Cooperatives and Associations')
    && str_contains($guide,'For National Government Agencies')
    && str_contains($guide,'For Individual / Sole Proprietorship'),
    'reviewer sees every rule from the applicant type requirements');
reviewer_layout_check(substr_count($guide,'Selected Type') === 1
    && str_contains($guide,'Certified True Copy of Certificate of Registration')
    && str_contains($guide,'Secretary’s Certification'),
    'corporation selected type has exact mandatory document names');
$govGuide = registration_reviewer_requirement_guide_html('government');
reviewer_layout_check(substr_count($govGuide,'Selected Type') === 1
    && str_contains($govGuide,'Authorization to Apply for the FP from the Head of Agency'),
    'government selected type carries authorization requirement');
$indGuide = registration_reviewer_requirement_guide_html('individual');
reviewer_layout_check(str_contains($indGuide,'Valid ID apply')
    && substr_count($indGuide,'Selected Type')===1,
    'individual registration retains existing ID requirements');

$current = [
    ['id'=>85,'document_type'=>'supporting_document','requirement_type'=>'secretary_certification',
     'id_type'=>null,'revision'=>3,'original_name'=>'secretary.pdf',
     'file_size'=>142000,'mime_type'=>'application/pdf'],
    ['id'=>84,'document_type'=>'supporting_document','requirement_type'=>'certificate_registration',
     'id_type'=>null,'revision'=>3,'original_name'=>'certificate.pdf',
     'file_size'=>220000,'mime_type'=>'application/pdf'],
];
$old = [
    ['id'=>64,'document_type'=>'valid_id','requirement_type'=>'other','id_type'=>'drivers_license',
     'revision'=>2,'original_name'=>'license.png','file_size'=>34000,'mime_type'=>'image/png'],
    ['id'=>53,'document_type'=>'supporting_document','requirement_type'=>'other','id_type'=>null,
     'revision'=>1,'original_name'=>'prior.pdf','file_size'=>100000,'mime_type'=>'application/pdf'],
];
$all = array_merge($current,$old);
$currentHtml = registration_reviewer_documents_html($current,'supporting_document',false,'current',3);
$pastHtml = registration_reviewer_documents_html([$old[1]],'supporting_document',false,'previous',3);
$oldIdHtml = registration_reviewer_documents_html([$old[0]],'valid_id',false,'previous',3);
reviewer_layout_check(substr_count($currentHtml,'data-submitted-document-id=') === 2,
    'new supporting documents are separately rendered');
reviewer_layout_check(str_contains($currentHtml,'secretary.pdf')
    && str_contains($currentHtml,'certificate.pdf'),
    'both current filenames preserved');
reviewer_layout_check(str_contains($pastHtml,'prior.pdf')
    && !str_contains($pastHtml,'Latest submission'),
    'historical file remains available and not mislabeled latest');
reviewer_layout_check(str_contains($oldIdHtml,'license.png')
    && str_contains($oldIdHtml,'Driver'),
    'older Valid ID can be reviewed separately');
reviewer_layout_check(!str_contains($currentHtml,'id="registration-document-modal"')
    && !str_contains($pastHtml,'id="registration-document-modal"')
    && !str_contains($oldIdHtml,'id="registration-document-modal"'),
    'groups do not create duplicate document modals');
$modal=registration_reviewer_document_modal_html();
reviewer_layout_check(substr_count($modal,'id="registration-document-modal"')===1
    && str_contains($modal,'data-document-next disabled')
    && str_contains($modal,'data-document-counter'),
    'one reusable Previous/Next file preview modal retained');

$summary = registration_reviewer_verification_summary_html(
    'corporation',$all,3,'2026-10-29 10:00:00','pending_review');
reviewer_layout_check(str_contains($summary,'Verified')
    && str_contains($summary,'2026-10-29 10:00:00'),
    'email verification reflects actual stored timestamp');
reviewer_layout_check(str_contains($summary,'Files in Current Revision</dt><dd class="col-sm-7">2')
    && str_contains($summary,'Files from Earlier Revisions</dt><dd class="col-sm-7">2'),
    'current and older file counts are not conflated');
reviewer_layout_check(str_contains($summary,'Valid IDs across submissions</dt><dd class="col-sm-7">1')
    && str_contains($summary,'LPDD-FUS must still examine'),
    'retained IDs count but metadata presence is not content approval');

$missing=registration_reviewer_verification_summary_html('corporation',[
    ['document_type'=>'supporting_document','requirement_type'=>'other','revision'=>1]
],2,null,'pending_review');
reviewer_layout_check(str_contains($missing,'Not yet verified')
    && str_contains($missing,'Missing or unclassified')
    && str_contains($missing,'No saved Valid Identification Card.'),
    'reviewer sees missing categories, ID and unverified email without guessing');

$views=(string)file_get_contents(__DIR__.'/../app/views.php');
$reviewer = substr($views,strpos($views,'function view_review_case('),
    strpos($views,'function view_staff(')-strpos($views,'function view_review_case('));
$steps=[
    "registration_section_heading_html(1,'Applicant / Organization Type')",
    "registration_section_heading_html(2,'Documentary Requirements by Applicant Type')",
    "registration_reviewer_requirement_guide_html(\$organizationKey)",
    "registration_section_heading_html(3,'Applicant Information')",
    "registration_section_heading_html(4,'Valid Identification Cards')",
    "registration_section_heading_html(5,'Registration Verification Summary')",
];
$offset=-1;
foreach ($steps as $step) {
    $next=strpos($reviewer,$step);
    reviewer_layout_check($next!==false && $next>$offset,'ordered reviewer section: '.$step);
    $offset=$next;
}
reviewer_layout_check(str_contains($reviewer,'u.email_verified_at')
    && str_contains($reviewer,'registration_reviewer_verification_summary_html('),
    'email verification read from database and shown in consolidated summary');
reviewer_layout_check(str_contains($reviewer,"data-reviewer-step=\"1\"")
    && str_contains($reviewer,"data-reviewer-step=\"5\""),
    'five numbered read-only reviewer sections included');
reviewer_layout_check(str_contains($reviewer,'data-reviewer-previous-supporting')
    && str_contains($reviewer,'data-reviewer-previous-ids'),
    'old revisions remain accessible in collapsible audit details');
reviewer_layout_check(str_contains($reviewer,'data-confirm-decision="approved"')
    && str_contains($reviewer,'data-confirm-decision="needs_correction"')
    && str_contains($reviewer,'Previous Review Decisions'),
    'existing LPDD-FUS decision controls and audit trail are preserved');
reviewer_layout_check(str_contains($reviewer,'registration_reviewer_document_modal_html()'),
    'only one document preview modal embedded with all current and older files');
echo "PASS: LPDD-FUS mirrored type, documentary requirements, profile, ID and verification sections.\n";
