<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/registration_attachments.php';
require_once __DIR__ . '/../app/registration_requirements.php';

// Test upload metadata mapping without making real HTTP requests or storing IDs.
function upload_details(?array $file, bool $required): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $required ? ['error' => 'Required file missing'] : ['none' => true];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) return ['error' => 'Bad upload'];
    return ['name' => $file['name'], 'file' => $file, 'mime' => 'image/png', 'size' => $file['size'], 'extension' => 'png'];
}

function env_value(string $key, string $fallback = ''): string
{
    return $fallback;
}

function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $page, array $args = []): string
{
    return $page . '?' . http_build_query($args);
}

function assertion(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
}

function file_group(array $names): array
{
    $attributes = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($names as $index => $name) {
        $attributes['name'][$index] = $name;
        $attributes['type'][$index] = 'image/png';
        $attributes['tmp_name'][$index] = '/tmp/example-' . $index;
        $attributes['error'][$index] = $name === '' ? UPLOAD_ERR_NO_FILE : UPLOAD_ERR_OK;
        $attributes['size'][$index] = $name === '' ? 0 : 100;
    }
    return $attributes;
}

$types = registration_id_type_choices();
assertion(count($types) >= 6, 'ID options are available');
assertion(registration_id_type_label('passport') === 'Philippine Passport', 'Type mapping');
assertion(str_contains(registration_id_type_label(null), 'not recorded'), 'Historical ID fallback');

$case = registration_attachment_batch(
    ['id_types' => [0 => 'national_id', 3 => 'passport']],
    ['id_files' => file_group([0 => 'national.png', 3 => 'passport.png']),
     'supporting_files' => file_group([0 => 'extra.png', 4 => 'extra2.png'])],
    true
);
assertion($case['errors'] === [], 'Two IDs and two supporting files validate');
assertion(count($case['items']) === 4, 'All four files preserved');
assertion($case['items'][0]['id_type'] === 'national_id', 'National ID classified');
assertion($case['items'][1]['id_type'] === 'passport', 'Passport classified');
assertion($case['items'][2]['document_type'] === 'supporting_document', 'Supporting type classified');

$missing = registration_attachment_batch(['id_types' => [0 => '']], ['id_files' => file_group([0 => ''])], true);
assertion($missing['errors'] !== [], 'Initial registration requires an ID');

$incorrect = registration_attachment_batch(['id_types' => [0 => 'fake_id']], ['id_files' => file_group([0 => 'fake.png'])], true);
assertion($incorrect['errors'] !== [], 'Unknown ID type is rejected');

$noType = registration_attachment_batch(['id_types' => [0 => '']], ['id_files' => file_group([0 => 'id.png'])], true);
assertion($noType['errors'] !== [], 'File without ID type is rejected');

$noFile = registration_attachment_batch(['id_types' => [0 => 'passport']], ['id_files' => file_group([0 => ''])], true);
assertion($noFile['errors'] !== [], 'Type without file is rejected');

$tooMany = registration_attachment_batch(
    ['id_types' => array_fill(0, 6, 'passport')],
    ['id_files' => file_group(array_fill(0, 6, 'id.png'))],
    true
);
assertion($tooMany['errors'] !== [], 'More than five IDs are rejected');

$emptyCorrection = registration_attachment_batch(
    ['id_types' => [0 => '']],
    ['id_files' => file_group([0 => '']), 'supporting_files' => file_group([0 => ''])],
    false
);
assertion($emptyCorrection['errors'] === [] && $emptyCorrection['items'] === [], 'Corrections may contain no new uploads');

$older = registration_attachment_batch(
    [],
    ['valid_id' => ['name'=>'legacy.png','tmp_name'=>'/tmp/legacy','error'=>UPLOAD_ERR_OK,'size'=>100]],
    true
);
assertion(count($older['items']) === 1 && $older['items'][0]['id_type'] === null, 'Legacy single ID remains supported');

$markup = registration_attachment_form_fields(true);
assertion(str_contains($markup, 'name="id_types[0]"'), 'Initial form has ID type selector');
assertion(str_contains($markup, 'name="id_files[0]"'), 'Initial form has ID file input');
assertion(str_contains($markup, 'name="supporting_files[0]"'), 'Supporting upload is present');
assertion(str_contains($markup, 'name="supporting_requirements[0]"'),
    'Supporting upload category is editable');
assertion(str_contains($markup, 'data-max-supporting="10"'),
    'Supporting files may contain ten uploads');

$corp = registration_attachment_batch(
    ['id_types'=>[0=>'passport'],
     'supporting_requirements'=>[0=>'certificate_registration',1=>'secretary_certification']],
    ['id_files'=>file_group([0=>'id.png']),
     'supporting_files'=>file_group([0=>'certificate.png',1=>'secretary.png'])], true
);
assertion($corp['errors']===[],'Categorized corporation files are valid uploads');
assertion(registration_requirement_errors('corporation',$corp['items'])===[],
    'Corporation has both registration certificate and Secretary’s Certification');
assertion(count($corp['items'])===3,'Valid ID plus two supporting documents preserved');
$gov = registration_attachment_batch(
    ['id_types'=>[0=>'passport'],'supporting_requirements'=>[0=>'head_authorization']],
    ['id_files'=>file_group([0=>'id.png']),
     'supporting_files'=>file_group([0=>'authorization.png'])],true
);
assertion($gov['errors']===[],'GOCC authorization document accepted');
assertion(registration_requirement_errors('government',$gov['items'])===[],
    'Head of Agency authorization is mandatory and present');
$invalidCategory = registration_attachment_batch(
    ['id_types'=>[0=>'passport'],'supporting_requirements'=>[0=>'fake']],
    ['id_files'=>file_group([0=>'id.png']),
     'supporting_files'=>file_group([0=>'fake.png'])],true
);
assertion($invalidCategory['errors']!==[],'Forged document category rejected');
$many = registration_attachment_batch(
    ['id_types'=>[0=>'passport'],
     'supporting_requirements'=>array_fill(0,6,'other')],
    ['id_files'=>file_group([0=>'id.png']),
     'supporting_files'=>file_group(array_fill(0,6,'additional.png'))],true
);
assertion($many['errors']===[] && count($many['items'])===7,
    'Expanded supporting-documents cap accepts six supporting files');

$markup = registration_documents_html([
    ['id' => 8, 'document_type' => 'valid_id', 'id_type' => 'passport', 'revision' => 2, 'original_name' => '<test>.png'],
    ['id' => 7, 'document_type' => 'supporting_document', 'revision' => 1, 'original_name' => 'other.png'],
]);
assertion(str_contains($markup, 'Philippine Passport'), 'Stored ID type is shown');
assertion(str_contains($markup, 'Submission revision 2'), 'Revision history is shown');
assertion(!str_contains($markup, '<test>'), 'Uploaded names are escaped');
assertion(substr_count($markup, 'list-group-item') === 2, 'Both files are visible');

echo "PASS: Multiple registration IDs, validation, revision listing, and legacy upload assertions.\n";
