<?php
declare(strict_types=1);

// No MySQL, active session or real files required. These are pure tests for
// Annex A Step 2 input rules, upload shape validation, and state labels.
require_once __DIR__ . '/../app/domain.php';
require_once __DIR__ . '/../app/permit_step2.php';
require_once __DIR__ . '/../app/permit_step2_views.php';

function step2_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Step 2 test failed: ' . $message);
}

$valid = [
    'project_title'=>'Community Forestry Study',
    'project_location'=>'Barangay Sample, Caraga Region',
    'purpose'=>'Initial field assessment request'
];
step2_assert(step2_validate_input($valid) === [], 'valid application identifiers');
step2_assert(step2_validate_input([
    'project_title'=>'A',
    'project_location'=>'Barangay Sample, Caraga Region',
    'purpose'=>''
]) !== [], 'short project title rejected');
step2_assert(step2_validate_input([
    'project_title'=>'Community Forestry Study',
    'project_location'=>'X',
    'purpose'=>''
]) !== [], 'short location rejected');
step2_assert(step2_validate_input([
    'project_title'=>'Community Forestry Study',
    'project_location'=>'Barangay Sample, Caraga Region',
    'purpose'=>str_repeat('X',2001)
]) !== [], 'overlong description rejected');
step2_assert(count(step2_uploads([],true)['errors']) === 1, 'initial submission requires file');
step2_assert(step2_uploads([],false)['errors'] === [], 'correction can retain previously uploaded docs');
step2_assert(step2_uploads(['application_files'=>'invalid'],true)['errors'] !== [], 'malformed file data rejected');
step2_assert(step2_status_label('submitted') === 'Pending LPDD-FUS Initial Review', 'submitted state display');
step2_assert(step2_status_label('corrections_required') === 'Corrections Required', 'correction state display');
step2_assert(step2_status_label('forwarded_to_ro') === 'Forwarded to RO Receiving', 'handoff state display');

echo "Step 2 pure PHP checks passed (10 assertions). Database/SMTP/browser testing still required.\n";
