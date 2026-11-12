<?php
declare(strict_types=1);

// Annex 1 revised checklist – pure input checks plus integration contracts.
// Does not need DB/SMTP; execute after migration 030 is installed in staging.
require_once __DIR__.'/../app/permit_step2_requirements.php';
require_once __DIR__.'/../app/permit_step2.php';
require_once __DIR__.'/../app/registration_requirements.php';
require_once __DIR__.'/../app/permit_step4.php';

function annex_check(bool $value,string $description): void
{
    if (!$value) throw new RuntimeException('Annex 1 revision: '.$description);
}
function annex_text(string $file): string
{
    $text=file_get_contents(__DIR__.'/../'.$file);
    if (!is_string($text)) throw new RuntimeException('Missing '.$file);
    return $text;
}

annex_check(isset(registration_organization_types()['lgu']), 'LGU included in applicant type');
annex_check(registration_requirement_errors('lgu',[])===[], 'no invented LGU documentary requirement');
annex_check(count(step2_requirement_types())===6, 'six original Step 4 upload groups remain');
annex_check(step2_requirement_valid('other')
    && !array_key_exists('other',step2_requirement_types()),
    'optional Other upload is allowed without a seventh mandatory assessment');
annex_check(step2_project_doe_exempt('solar') && step2_project_doe_exempt('biomass'),
    'Solar/Biomass exempt from DOE item 10');
annex_check(step2_project_requires_doe('other') && !step2_project_requires_doe('solar'),
    'DOE item 10 required only for Other project types');
annex_check(str_contains(step2_requirement_note('ecc_cnc'),'six (6) months')
    && str_contains(step2_requirement_note('ncip_clearance'),'one (1) year'),
    'post-issuance deadlines in source notes');

$base=['project_title'=>'Sample Energy Project','project_location'=>'Barangay Sample, Caraga Region',
    'project_area_hectares'=>'15.25','purpose'=>'','project_type'=>'solar','project_type_other'=>''];
annex_check(step2_validate_input($base)===[], 'Solar selected without custom description');
annex_check(step2_validate_input($base+['project_type_other'=>''])===[], 'solar remains valid');
$other=$base;
$other['project_type']='other';
annex_check(step2_validate_input($other)!==[], 'Other requires a specified type');
$other['project_type_other']='Wind generation';
annex_check(step2_validate_input($other)===[], 'Other accepts custom project type');
$bad=$base;
$bad['project_type']='unknown';
annex_check(step2_validate_input($bad)!==[], 'unknown project type rejected');

$submit=annex_text('app/permit_step2.php');
$draft=annex_text('app/permit_step2_persistent.php');
$form=annex_text('app/permit_step2_views.php');
$script=annex_text('public/assets/permit-project-type.js');
$autosave=annex_text('public/assets/permit-step2-drafts.js');
$step4=annex_text('app/permit_step4.php');
$step4Views=annex_text('app/permit_step4_views.php');
$reviewJs=annex_text('public/assets/permit-step4-review.js');
$migration=annex_text('database/030_annex1_project_types.sql');

annex_check(str_contains($migration,'ADD COLUMN project_type VARCHAR(20)')
    && str_contains($migration,'ADD COLUMN project_type_other VARCHAR(160)')
    && str_contains($migration,'permit_step2_form_drafts'),
    'both permanent applications and authenticated drafts persist project type');
annex_check(str_contains($submit,'step2_project_type_schema_ready')
    && str_contains($submit,'Other project types must upload Annex 1 requirement #10')
    && str_contains($submit,'$hasPriorDoe') && str_contains($submit,'$hasNewDoe'),
    'final submission validates DOE item 10 for Other including saved documents');
annex_check(str_contains($draft,'project_type_other') && str_contains($autosave,"'project_type_other'"),
    'custom type is retained in server and browser drafts');
annex_check(str_contains($form,'data-fp-project-type')
    && str_contains($form,'data-fp-project-type-picker')
    && str_contains($form,'data-fp-project-type-option')
    && str_contains($form,'data-fp-other-project')
    && str_contains($form,'step2_upload_categories()'),
    'accessible project type picker, Other input and optional document category are shown');
annex_check(str_contains($script,'data-step2-doe-upload')
    && str_contains($script,'option[value="doe_esc_coa"]')
    && str_contains($script,'ArrowRight')
    && str_contains($script,'data-fp-other-project'),
    'keyboard project picker and DOE controls toggle for Solar/Biomass versus Other');
annex_check(str_contains($step4,'step2_project_doe_exempt')
    && str_contains($step4,'step2_project_requires_doe')
    && str_contains($step4,"'not_applicable'"),
    'Step 4 server verifies project type instead of trusting the posted assessment');
annex_check(step4_assessment_label('incomplete')==='Lacking'
    && step4_assessment_label('insufficient')==='Inappropriate',
    'old assessment enums preserve history while showing new labels');
annex_check(str_contains($step4Views,"'incomplete'=>'Lacking'")
    && str_contains($step4Views,"'insufficient'=>'Inappropriate'")
    && str_contains($step4Views,'data-step4-project-type'),
    'new labels and project type are visible in Step 4');
annex_check(str_contains($reviewJs,"incomplete: ['Lacking'")
    && str_contains($reviewJs,"insufficient: ['Inappropriate'"),
    'Step 4 wizard summaries use updated terminology');

echo "PASS: Revised Annex 1 project types, LGU, optional documents and Step 4 assessments.\n";
