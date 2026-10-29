<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/registration_requirements.php';

function entity_check(bool $yes,string $label): void {
    if (!$yes) throw new RuntimeException('Registration entity requirements failed: '.$label);
}
$orgs = registration_organization_types();
$choices = registration_requirement_choices();
entity_check(count($orgs)===3,'three entity groups offered');
entity_check(isset($choices['certificate_registration'],$choices['secretary_certification'],
    $choices['head_authorization'],$choices['other']),'required type keys available');
$corporate = [
    ['document_type'=>'valid_id','requirement_type'=>'other'],
    ['document_type'=>'supporting_document','requirement_type'=>'certificate_registration'],
    ['document_type'=>'supporting_document','requirement_type'=>'secretary_certification'],
    ['document_type'=>'supporting_document','requirement_type'=>'secretary_certification'],
];
entity_check(registration_requirement_errors('corporation',$corporate)===[],
    'multiple files in the same corporate category are accepted');
entity_check(registration_requirement_errors('government',$corporate)!==[],
    'corporation documents do not satisfy government authorization');
$government = [['document_type'=>'supporting_document','requirement_type'=>'head_authorization']];
entity_check(registration_requirement_errors('government',$government)===[],
    'head of agency authorization satisfies government requirements');
entity_check(count(registration_requirement_errors('corporation',$government))===2,
    'corporation must provide both distinct mandatory categories');
entity_check(registration_requirement_errors('individual',[])===[],
    'individual applicant has no new mandated supporting category');
entity_check(registration_requirement_errors('forged',[])!==[],
    'unrecognized entity type is rejected');
entity_check(registration_requirement_errors('corporation',[
    ['document_type'=>'valid_id','requirement_type'=>'certificate_registration']
])!==[],'Valid IDs cannot masquerade as supporting documents');
entity_check(registration_requirement_label('head_authorization')
    ==='Authorization to Apply for the FP from the Head of Agency',
    'review labels are accurate');
echo "PASS: Registration entity category requirements and multi-upload rules.\n";
