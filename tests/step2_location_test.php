<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/domain.php';
require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/permit_step2.php';
require_once __DIR__ . '/../app/permit_step2_views.php';

function location_assert(bool $ok,string $message): void
{
    if (!$ok) throw new RuntimeException('Project pinpoint: '.$message);
}
$base=['project_title'=>'Community Forestry Project',
    'project_location'=>'Barangay Example, Agusan del Norte','purpose'=>''];
location_assert(step2_validate_input($base)===[],'address only remains valid');
location_assert(step2_validate_input($base+[
    'project_latitude'=>'8.9471230','project_longitude'=>'125.5423450'
])===[],'valid WGS84 decimal coordinates');
location_assert(step2_validate_input($base+[
    'project_latitude'=>'0','project_longitude'=>'0'
])===[],'zero-zero pair syntactically allowed');
location_assert(step2_validate_input($base+[
    'project_latitude'=>'8.9','project_longitude'=>''
])!==[],'partial pair rejected');
location_assert(step2_validate_input($base+[
    'project_latitude'=>'91','project_longitude'=>'125'
])!==[],'latitude out of range');
location_assert(step2_validate_input($base+[
    'project_latitude'=>'8','project_longitude'=>'181'
])!==[],'longitude out of range');
location_assert(step2_validate_input($base+[
    'project_latitude'=>'8.12345678','project_longitude'=>'125.5'
])!==[],'precision >7 decimals rejected');
location_assert(step2_validate_input($base+[
    'project_latitude'=>'1e3','project_longitude'=>'125.5'
])!==[],'scientific notation rejected');
$editor = step2_location_editor_html('8.9471230','125.5423450');
location_assert(str_contains($editor,'name="project_latitude"'),'latitude field');
location_assert(str_contains($editor,'name="project_longitude"'),'longitude field');
location_assert(str_contains($editor,'data-fp-location-gps'),'explicit user-position button');
location_assert(str_contains($editor,'data-fp-location-map="edit"'),'map editor');
$preview = step2_location_preview_html([
    'project_latitude'=>'8.9471230','project_longitude'=>'125.5423450'
]);
location_assert(str_contains($preview,'data-fp-location-map="readonly"'),'review map');
location_assert(step2_location_preview_html([])==='','no map for older applications');
echo "FP project GPS validation and map UI checks passed.\n";
