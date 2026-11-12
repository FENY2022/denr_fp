<?php
declare(strict_types=1);

if (!defined('PROJECT_ROOT')) define('PROJECT_ROOT',dirname(__DIR__));
require_once PROJECT_ROOT.'/app/permit_area_photos.php';

$valid=step2_area_photo_coordinate_pair('8.9471234','125.5423456');
if (!$valid || $valid['latitude']!=='8.9471234' || $valid['longitude']!=='125.5423456') {
    throw new RuntimeException('Valid area-photo coordinate pair was not accepted.');
}
foreach ([
    ['91','125'],['8','181'],['','125'],['8',''],['not-a-number','125']
] as [$lat,$lon]) {
    if (step2_area_photo_coordinate_pair($lat,$lon)!==null) {
        throw new RuntimeException('Invalid area-photo coordinate pair was accepted.');
    }
}

if (step2_area_photo_group(1)!==1 || step2_area_photo_group('10')!==10
    || step2_area_photo_group(0)!==null || step2_area_photo_group(11)!==null) {
    throw new RuntimeException('Area Photo Documentation group validation failed.');
}

$module=file_get_contents(PROJECT_ROOT.'/app/permit_area_photos.php');
$migration=file_get_contents(PROJECT_ROOT.'/database/038_geotagged_area_photos.sql');
$groupMigration=file_get_contents(PROJECT_ROOT.'/database/039_multiple_area_photo_documentation.sql');
$locationMigration=file_get_contents(PROJECT_ROOT.'/database/040_area_photo_documentation_locations.sql');
$step2=file_get_contents(PROJECT_ROOT.'/app/permit_step2.php');
$persistent=file_get_contents(PROJECT_ROOT.'/app/permit_step2_persistent.php');
$views=file_get_contents(PROJECT_ROOT.'/app/permit_step2_views.php');
$router=file_get_contents(PROJECT_ROOT.'/public/index.php');
$mapJs=file_get_contents(PROJECT_ROOT.'/public/assets/permit-location-map.js');
$photoJs=file_get_contents(PROJECT_ROOT.'/public/assets/permit-area-photos.js');
$bootstrap=file_get_contents(PROJECT_ROOT.'/app/bootstrap.php');

$checks=[
    [$migration,'permit_step2_area_photo_drafts','migration creates draft photo storage'],
    [$migration,'permit_step2_area_photos','migration creates submitted photo storage'],
    [$groupMigration,'documentation_group','migration 039 adds documentation grouping'],
    [$locationMigration,'permit_step2_area_photo_group_drafts','migration 040 stores editable documentation pins'],
    [$locationMigration,'permit_step2_area_photo_groups','migration 040 stores submitted documentation pins'],
    [$module,'fpoms_file_store_upload','draft photos are encrypted on upload'],
    [$module,'fpoms_file_copy_encrypted','submitted photos stay encrypted'],
    [$module,"['image/jpeg','image/png']",'server restricts photo MIME types'],
    [$module,'Maximum 20 area photos','server limits draft photo count'],
    [$module,'step2_area_photo_group','server validates documentation group 1-10'],
    [$module,'documentation_group,original_name','draft upload persists documentation group'],
    [$module,'revision,documentation_group,original_name','submitted photo preserves documentation group'],
    [$module,'step2_area_photo_group_save','documentation group pin can be persisted before photos'],
    [$module,'step2_area_photo_group_point','photo upload resolves the server-authoritative group pin'],
    [$module,'permit_step2_area_photo_groups','submitted revision preserves group pinpoint location'],
    [$module,'step2_area_photo_send_submitted','submitted photo access endpoint exists'],
    [$step2,'step2_area_photo_draft_submission','final submit locks and validates photo drafts'],
    [$step2,'step2_area_photo_copy_submission','final submit copies photos into immutable application storage'],
    [$step2,"'area_photos_added'","area photo additions are recorded in client audit metadata"],
    [$persistent,'step2_area_photo_clear_drafts','Clear Form removes unsubmitted photo drafts'],
    [$views,'Add Another Area Photo Documentation','client can add another documentation block'],
    [$views,'[1],array_keys($photoGroups),$locationGroups','Documentation 1 is the only default editor block'],
    [$views,'data-area-photo-group','server renders numbered documentation groups'],
    [$views,'Pinpoint Project Location / Area Photo Documentation','combined pinpoint/photo documentation heading exists'],
    [$views,'Pinpoint Project Location','every documentation block includes its own pinpoint section'],
    [$views,'data-area-photo-group-map','every documentation block renders its own mini-map'],
    [$views,'Use My Current Location','every documentation block exposes GPS selection'],
    [$views,'Geotagged Area Photo Documentation','submitted grouped photo gallery exists'],
    [$views,'permit-area-photos.js?v=5','on-demand documentation script is loaded'],
    [$views,"(int)$group!==1","only Documentation 1 is visibly rendered initially"],
    [$router,"case 'permit_area_photo_group_save':",'documentation group pin save route exists'],
    [$router,"case 'permit_area_photo_upload':",'area photo upload route exists'],
    [$router,"documentation_group",'upload route forwards documentation group'],
    [$router,"case 'permit_area_photo_update':",'area photo coordinate update route exists'],
    [$router,"case 'permit_area_photo_delete':",'area photo delete route exists'],
    [$router,"case 'permit_area_photo':",'secure photo preview route exists'],
    [$mapJs,'fpoms:location-map-ready','project map exposes a safe integration hook'],
    [$photoJs,"post('permit-area-photo-upload'","browser uploads photos through protected endpoint"],
    [$photoJs,"data-fp-add-area-photo-documentation","browser supports adding documentation blocks"],
    [$photoJs,"if (!editable || group===1) visibleGroups.add(group)","editable form initially reveals only Documentation 1"],
    [$photoJs,"Reveal the next saved group when one already exists","Add Another reveals saved data without duplicating it"],
    [$photoJs,"permit-area-photo-group-save","browser persists an empty/new documentation block and its pin"],
    [$photoJs,"data-area-photo-group-map","browser initializes independent documentation maps"],
    [$photoJs,"data-area-group-gps","each documentation map supports current-device GPS"],
    [$photoJs,"documentation_group","browser sends and preserves documentation group"],
    [$photoJs,"post('permit-area-photo-update'","dragged photo marker saves coordinates"],
    [$photoJs,"data-area-photo-use-group","photo can return to its own documentation pin"],
    [$photoJs,'navigator.geolocation', ''],
    [$bootstrap,"permit_area_photos.php",'area photo module is loaded'],
];
foreach ($checks as [$haystack,$needle,$label]) {
    if ($needle==='navigator.geolocation') continue;
    if (!str_contains((string)$haystack,$needle)) {
        throw new RuntimeException('Geotagged area photo regression failed: '.$label);
    }
}
if (str_contains((string)$photoJs,'google.maps') || str_contains((string)$mapJs,'google.maps')) {
    throw new RuntimeException('Google Maps dependency was introduced; free Leaflet/OSM flow must remain.');
}
if (!str_contains((string)$mapJs,'navigator.geolocation')
    || !str_contains((string)$photoJs,'navigator.geolocation')) {
    throw new RuntimeException('Current-location browser geolocation support is missing.');
}
if (!str_contains((string)$module,
        '$point=step2_area_photo_group_point($clientId,$applicationId,$group,true)')) {
    throw new RuntimeException('Photo upload is not bound to the server-saved documentation pin.');
}
if (!str_contains((string)$photoJs,"event.stopImmediatePropagation()")) {
    throw new RuntimeException('Final submission is not blocked during active area-photo work.');
}

if (str_contains((string)$views,'step2_location_editor_html($latitude,$longitude)')
    || str_contains((string)$views,'data-fp-location-map="edit"')) {
    throw new RuntimeException('Standalone Pinpoint Project Location editor returned to the applicant form.');
}
if (str_contains((string)$views,'Use Main Project Pin')
    || str_contains((string)$photoJs,'Use Main Project Pin')
    || str_contains((string)$photoJs,'data-area-group-use-project')) {
    throw new RuntimeException('Documentation maps must remain independent of a legacy main project pin.');
}

if (str_contains((string)$views,
        'array_keys($photoGroups),array_keys($locationIndex)')) {
    throw new RuntimeException('Empty additional Area Photo Documentation groups must not auto-render on reload.');
}

if (str_contains((string)$photoJs,"for (const photo of photosState) visibleGroups.add(photo.documentation_group);")
    && str_contains((string)$photoJs,"for (const group of groupState.keys()) visibleGroups.add(group);")) {
    throw new RuntimeException('Editable form must not auto-reveal saved Documentation 2+ groups.');
}
if (str_contains((string)$photoJs,"const payload=await saveGroup(next,'','');")) {
    throw new RuntimeException('Add Another must not blank-save or overwrite a hidden saved documentation pin.');
}

echo "Geotagged area photo and OpenStreetMap integration checks passed.\n";
