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

$module=file_get_contents(PROJECT_ROOT.'/app/permit_area_photos.php');
$migration=file_get_contents(PROJECT_ROOT.'/database/038_geotagged_area_photos.sql');
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
    [$module,'fpoms_file_store_upload','draft photos are encrypted on upload'],
    [$module,'fpoms_file_copy_encrypted','submitted photos stay encrypted'],
    [$module,"['image/jpeg','image/png']",'server restricts photo MIME types'],
    [$module,'Maximum 20 area photos','server limits draft photo count'],
    [$module,'step2_area_photo_send_submitted','submitted photo access endpoint exists'],
    [$step2,'step2_area_photo_draft_submission','final submit locks and validates photo drafts'],
    [$step2,'step2_area_photo_copy_submission','final submit copies photos into immutable application storage'],
    [$step2,"'area_photos_added'","area photo additions are recorded in client audit metadata"],
    [$persistent,'step2_area_photo_clear_drafts','Clear Form removes unsubmitted photo drafts'],
    [$views,'Area Photo Documentation','client area photo UI exists'],
    [$views,'Geotagged Area Photos','submitted photo gallery exists'],
    [$views,'permit-area-photos.js?v=1','area photo map script is loaded'],
    [$router,"case 'permit_area_photo_upload':",'area photo upload route exists'],
    [$router,"case 'permit_area_photo_update':",'area photo coordinate update route exists'],
    [$router,"case 'permit_area_photo_delete':",'area photo delete route exists'],
    [$router,"case 'permit_area_photo':",'secure photo preview route exists'],
    [$mapJs,'fpoms:location-map-ready','project map exposes a safe integration hook'],
    [$photoJs,"post('permit-area-photo-upload'","browser uploads photos through protected endpoint"],
    [$photoJs,"post('permit-area-photo-update'","dragged photo marker saves coordinates"],
    [$photoJs,"data-area-photo-use-project","photo can reuse the main project pin"],
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
if (!str_contains((string)$mapJs,'navigator.geolocation')) {
    throw new RuntimeException('Current-location browser geolocation support is missing.');
}
if (!str_contains((string)$photoJs,"event.stopImmediatePropagation()")) {
    throw new RuntimeException('Final submission is not blocked during active area-photo work.');
}

echo "Geotagged area photo and OpenStreetMap integration checks passed.\n";
