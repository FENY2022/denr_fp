<?php
declare(strict_types=1);

// No database or real credentials required. Tests business rule validation only.
require_once __DIR__ . '/../app/domain.php';
require_once __DIR__ . '/../app/user_management.php';

function must(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('User Management test failed: ' . $message);
}

$input = [
    'name'=>'Nelson Caranzo',
    'email'=>'nelson.caranzo@example.org',
    'contact_number'=>'0956-0845-321',
    'office_station'=>'Nasipit',
    'role'=>'cenro_twg',
    'status'=>'active'
];
$valid = user_management_data($input);
must(user_management_validate($valid) === [], 'Valid staff profile accepted');
must($valid['email'] === 'nelson.caranzo@example.org', 'Individual email normalized');
must(!isset(user_management_internal_roles()['applicant']), 'Applicant role cannot be assigned to internal staff');
must(isset(user_management_internal_roles()['gis_focal']), 'GIS Focal role available');
must(user_management_office_level('cenro_twg') === 'CENRO', 'CENRO office assignment');
must(user_management_office_level('penro_user') === 'PENRO', 'PENRO office assignment');
must(user_management_office_level('gis_focal') === 'Regional Office', 'Regional GIS assignment');

$bad = $valid;
$bad['email'] = 'invalid';
must(user_management_validate($bad) !== [], 'Reject invalid email');
$bad = $valid;
$bad['role'] = 'applicant';
must(user_management_validate($bad) !== [], 'Reject applicant role');
$bad = $valid;
$bad['role'] = 'unknown_role';
must(user_management_validate($bad) !== [], 'Reject arbitrary role');
$bad = $valid;
$bad['name'] = 'A';
must(user_management_validate($bad) !== [], 'Reject short employee name');
$bad = $valid;
$bad['office_station'] = '';
must(user_management_validate($bad) !== [], 'Reject missing office');
$bad = $valid;
$bad['contact_number'] = 'call me';
must(user_management_validate($bad) !== [], 'Reject invalid contact number');
$bad = $valid;
$bad['status'] = 'pending_review';
must(user_management_validate($bad) !== [], 'Reject inappropriate internal account status');

echo "PASS: User Management validation and role-permission domain tests.\n";
