<?php
declare(strict_types=1);

if (!defined('PROJECT_ROOT')) define('PROJECT_ROOT',dirname(__DIR__));
require_once PROJECT_ROOT.'/app/fp_location_jurisdiction.php';

$dataset=fp_location_dataset();
if (($dataset['snapshot']??'')!=='2026-Q2') {
    throw new RuntimeException('Unexpected Caraga PSGC snapshot.');
}
$areas=$dataset['areas']??[];
$jurisdictions=$dataset['jurisdictions']??[];
if (count($areas)!==6) {
    throw new RuntimeException('Caraga location dataset must contain five provinces plus Butuan City.');
}

$cityCount=0;
foreach ($areas as $area) {
    foreach (($area['cities']??[]) as $city) {
        $cityCount++;
        $code=(string)($city['code']??'');
        if (!isset($jurisdictions[$code])) {
            throw new RuntimeException('Missing DENR jurisdiction for '.$code.' '.($city['name']??''));
        }
        $barangays=$city['barangays']??[];
        if (!$barangays) throw new RuntimeException('No barangays for '.$code);
        $resolved=fp_location_resolve(
            (string)$area['code'],$code,(string)$barangays[0][0]
        );
        if (!$resolved || trim((string)$resolved['concerned_penro'])==='') {
            throw new RuntimeException('Server resolver failed for '.$code);
        }
    }
}
if ($cityCount!==73 || count($jurisdictions)!==73) {
    throw new RuntimeException('Expected complete 73-city/municipality Caraga jurisdiction coverage.');
}

function fp_test_resolve_city(string $cityCode): array
{
    foreach (fp_location_areas() as $area) {
        foreach (($area['cities']??[]) as $city) {
            if ((string)($city['code']??'')!==$cityCode) continue;
            $barangay=$city['barangays'][0]??null;
            if (!is_array($barangay)) throw new RuntimeException('Barangay fixture missing.');
            $resolved=fp_location_resolve((string)$area['code'],$cityCode,(string)$barangay[0]);
            if (!$resolved) throw new RuntimeException('Unable to resolve '.$cityCode);
            return $resolved;
        }
    }
    throw new RuntimeException('Unknown test city '.$cityCode);
}

$checks=[
    '1630400000'=>['PENRO Agusan del Norte','CENRO Nasipit'],
    '1600203000'=>['PENRO Agusan del Norte','CENRO Tubay'],
    '1600306000'=>['PENRO Agusan del Sur','CENRO Bayugan'],
    '1600307000'=>['PENRO Agusan del Sur','CENRO Bunawan'],
    '1600305000'=>['PENRO Agusan del Sur','CENRO Loreto'],
    '1600311000'=>['PENRO Agusan del Sur','CENRO Talacogon'],
    '1606819000'=>['PENRO Surigao del Sur','CENRO Cantilan'],
    '1606801000'=>['PENRO Surigao del Sur','CENRO Lianga'],
    '1606803000'=>['PENRO Surigao del Sur','CENRO Bislig'],
    '1606724000'=>['PENRO Surigao del Norte','CENRO Tubod'],
    '1608506000'=>['PENRO Dinagat Islands',''],
];
foreach ($checks as $code=>[$penro,$cenro]) {
    $resolved=fp_test_resolve_city($code);
    if ($resolved['concerned_penro']!==$penro || $resolved['concerned_cenro']!==$cenro) {
        throw new RuntimeException('Unexpected DENR jurisdiction for '.$code);
    }
}

$butuan=fp_test_resolve_city('1630400000');
$butuan['project_barangay_name']='Anticala';
$address=fp_location_format_address('Purok 3',$butuan);
if ($address!=='Purok 3, Brgy. Anticala, City of Butuan') {
    throw new RuntimeException('Independent-city address formatting failed: '.$address);
}

$buenavista=fp_test_resolve_city('1600201000');
$buenavista['project_barangay_name']='Talo-ao';
$address=fp_location_format_address('Purok 2',$buenavista);
if ($address!=='Purok 2, Brgy. Talo-ao, Buenavista, Agusan del Norte') {
    throw new RuntimeException('Province address formatting failed: '.$address);
}

$step2=file_get_contents(PROJECT_ROOT.'/app/permit_step2.php');
$step2Views=file_get_contents(PROJECT_ROOT.'/app/permit_step2_views.php');
$draftJs=file_get_contents(PROJECT_ROOT.'/public/assets/permit-step2-drafts.js');
$addressJs=file_get_contents(PROJECT_ROOT.'/public/assets/permit-location-address.js');
$paymentPdf=file_get_contents(PROJECT_ROOT.'/app/payment_notice_pdf.php');
$migration=file_get_contents(PROJECT_ROOT.'/database/037_structured_project_location_jurisdiction.sql');

$staticChecks=[
    [$step2,'fp_location_normalize_submission','final submit revalidates structured address'],
    [$step2,'concerned_penro=?,concerned_cenro=?','application stores detected offices'],
    [$step2Views,'Province / Independent City','cascading province UI'],
    [$step2Views,'Concerned PENRO','detected PENRO display'],
    [$draftJs,"'project_province_code'","draft autosaves structured address"],
    [$addressJs,'caraga-psgc-2026-q2.json','browser loads bundled PSGC snapshot'],
    [$paymentPdf,"concerned_penro",'Template No. 2 uses detected PENRO'],
    [$paymentPdf,"concerned_cenro",'Template No. 2 uses detected CENRO'],
    [$migration,'project_barangay_code','migration adds structured location columns'],
];
foreach ($staticChecks as [$haystack,$needle,$label]) {
    if (!str_contains((string)$haystack,$needle)) {
        throw new RuntimeException('Structured project location regression failed: '.$label);
    }
}

echo "Structured Caraga project location and PENRO/CENRO routing checks passed.\n";
