<?php
declare(strict_types=1);

/**
 * Structured Caraga project location and deterministic DENR field-office routing.
 * PSGC names/codes come from the bundled PSA-derived 2026-Q2 snapshot.
 * PENRO/CENRO assignments are server-authoritative; browser values are never trusted.
 */

function fp_location_dataset_path(): string
{
    return PROJECT_ROOT.'/public/assets/caraga-psgc-2026-q2.json';
}

function fp_location_dataset(): array
{
    static $dataset=null;
    if (is_array($dataset)) return $dataset;
    $raw=@file_get_contents(fp_location_dataset_path());
    if (!is_string($raw) || $raw==='') return $dataset=[];
    try {
        $decoded=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        error_log('FP-OMS Caraga PSGC dataset decode failed: '.$e->getMessage());
        return $dataset=[];
    }
    if (!is_array($decoded) || !is_array($decoded['areas']??null)
        || !is_array($decoded['jurisdictions']??null)) {
        return $dataset=[];
    }
    return $dataset=$decoded;
}

function fp_location_install_schema(): bool
{
    $applicationSql="ALTER TABLE permit_step2_applications
        ADD COLUMN IF NOT EXISTS project_province_code VARCHAR(10) NULL AFTER project_location,
        ADD COLUMN IF NOT EXISTS project_province_name VARCHAR(120) NULL AFTER project_province_code,
        ADD COLUMN IF NOT EXISTS project_city_municipality_code VARCHAR(10) NULL AFTER project_province_name,
        ADD COLUMN IF NOT EXISTS project_city_municipality_name VARCHAR(120) NULL AFTER project_city_municipality_code,
        ADD COLUMN IF NOT EXISTS project_barangay_code VARCHAR(10) NULL AFTER project_city_municipality_name,
        ADD COLUMN IF NOT EXISTS project_barangay_name VARCHAR(120) NULL AFTER project_barangay_code,
        ADD COLUMN IF NOT EXISTS project_street_purok VARCHAR(180) NULL AFTER project_barangay_name,
        ADD COLUMN IF NOT EXISTS concerned_penro VARCHAR(120) NULL AFTER project_street_purok,
        ADD COLUMN IF NOT EXISTS concerned_cenro VARCHAR(120) NULL AFTER concerned_penro";
    $draftSql="ALTER TABLE permit_step2_form_drafts
        ADD COLUMN IF NOT EXISTS project_province_code VARCHAR(10) NULL AFTER project_location,
        ADD COLUMN IF NOT EXISTS project_province_name VARCHAR(120) NULL AFTER project_province_code,
        ADD COLUMN IF NOT EXISTS project_city_municipality_code VARCHAR(10) NULL AFTER project_province_name,
        ADD COLUMN IF NOT EXISTS project_city_municipality_name VARCHAR(120) NULL AFTER project_city_municipality_code,
        ADD COLUMN IF NOT EXISTS project_barangay_code VARCHAR(10) NULL AFTER project_city_municipality_name,
        ADD COLUMN IF NOT EXISTS project_barangay_name VARCHAR(120) NULL AFTER project_barangay_code,
        ADD COLUMN IF NOT EXISTS project_street_purok VARCHAR(180) NULL AFTER project_barangay_name,
        ADD COLUMN IF NOT EXISTS concerned_penro VARCHAR(120) NULL AFTER project_street_purok,
        ADD COLUMN IF NOT EXISTS concerned_cenro VARCHAR(120) NULL AFTER concerned_penro";
    try {
        db()->exec($applicationSql);
        try {
            db()->query('SELECT 1 FROM permit_step2_form_drafts LIMIT 0');
            db()->exec($draftSql);
        } catch (Throwable $draftError) {
            // Migration 016 is optional. If its table exists but cannot be altered,
            // the readiness check below will keep persistent structured drafts disabled.
            error_log('FP-OMS structured draft location schema skipped/failed: '.$draftError->getMessage());
        }
        return true;
    } catch (Throwable $e) {
        error_log('FP-OMS structured project location schema initialization failed: '.$e->getMessage());
        return false;
    }
}

function fp_location_structured_schema_ready(): bool
{
    static $ready=null;
    if ($ready!==null) return $ready;
    $check=static function(): bool {
        try {
            db()->query('SELECT project_province_code,project_province_name,
                project_city_municipality_code,project_city_municipality_name,
                project_barangay_code,project_barangay_name,project_street_purok,
                concerned_penro,concerned_cenro
                FROM permit_step2_applications LIMIT 0');
        } catch (Throwable $e) {
            return false;
        }
        try {
            db()->query('SELECT 1 FROM permit_step2_form_drafts LIMIT 0');
        } catch (Throwable $e) {
            return true; // Persistent draft migration 016 is not installed.
        }
        try {
            db()->query('SELECT project_province_code,project_province_name,
                project_city_municipality_code,project_city_municipality_name,
                project_barangay_code,project_barangay_name,project_street_purok,
                concerned_penro,concerned_cenro
                FROM permit_step2_form_drafts LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    };
    if ($check()) return $ready=true;
    if (!fp_location_install_schema()) return $ready=false;
    return $ready=$check();
}

function fp_location_areas(): array
{
    $dataset=fp_location_dataset();
    return is_array($dataset['areas']??null)?$dataset['areas']:[];
}

function fp_location_resolve(
    string $areaCode,string $cityMunicipalityCode,string $barangayCode
): ?array {
    $areaCode=trim($areaCode);
    $cityMunicipalityCode=trim($cityMunicipalityCode);
    $barangayCode=trim($barangayCode);
    if (!preg_match('/^\d{10}$/D',$areaCode)
        || !preg_match('/^\d{10}$/D',$cityMunicipalityCode)
        || !preg_match('/^\d{10}$/D',$barangayCode)) return null;

    $dataset=fp_location_dataset();
    foreach (($dataset['areas']??[]) as $area) {
        if ((string)($area['code']??'')!==$areaCode) continue;
        foreach (($area['cities']??[]) as $city) {
            if ((string)($city['code']??'')!==$cityMunicipalityCode) continue;
            foreach (($city['barangays']??[]) as $barangay) {
                if (!is_array($barangay) || count($barangay)<2
                    || (string)$barangay[0]!==$barangayCode) continue;
                $office=$dataset['jurisdictions'][$cityMunicipalityCode]??null;
                if (!is_array($office) || trim((string)($office['penro']??''))==='') return null;
                return [
                    'project_province_code'=>$areaCode,
                    'project_province_name'=>(string)($area['name']??''),
                    'project_area_kind'=>(string)($area['kind']??'province'),
                    'project_city_municipality_code'=>$cityMunicipalityCode,
                    'project_city_municipality_name'=>(string)($city['name']??''),
                    'project_city_municipality_type'=>(string)($city['type']??'municipality'),
                    'project_barangay_code'=>$barangayCode,
                    'project_barangay_name'=>(string)$barangay[1],
                    'concerned_penro'=>trim((string)$office['penro']),
                    'concerned_cenro'=>trim((string)($office['cenro']??''))
                ];
            }
        }
    }
    return null;
}

function fp_location_format_address(string $streetPurok,array $resolved): string
{
    $street=trim((string)preg_replace('/\s+/u',' ',trim($streetPurok)));
    $parts=[];
    if ($street!=='') $parts[]=$street;
    $barangay=trim((string)($resolved['project_barangay_name']??''));
    if ($barangay!=='') $parts[]='Brgy. '.$barangay;
    $city=trim((string)($resolved['project_city_municipality_name']??''));
    if ($city!=='') $parts[]=$city;
    $area=trim((string)($resolved['project_province_name']??''));
    $kind=(string)($resolved['project_area_kind']??'province');
    if ($area!=='' && $kind!=='independent_city' && strcasecmp($area,$city)!==0) {
        $parts[]=$area;
    }
    return implode(', ',$parts);
}

/**
 * Final-submit validator. Derived names, complete address and DENR offices are
 * overwritten from the bundled dataset so posted hidden fields cannot spoof routing.
 */
function fp_location_normalize_submission(array &$data): array
{
    if (!fp_location_structured_schema_ready()) {
        return ['Project location requires database migration 037.'];
    }
    $street=trim((string)($data['project_street_purok']??''));
    $street=preg_replace('/\s+/u',' ',$street)??$street;
    if (mb_strlen($street)<2 || mb_strlen($street)>180) {
        return ['Enter the Street / Purok / Sitio of the project location (2–180 characters).'];
    }
    $resolved=fp_location_resolve(
        (string)($data['project_province_code']??''),
        (string)($data['project_city_municipality_code']??''),
        (string)($data['project_barangay_code']??'')
    );
    if (!$resolved) {
        return ['Select a valid Caraga Province/Independent City, Municipality/City, and Barangay.'];
    }
    foreach ($resolved as $key=>$value) {
        if ($key==='project_area_kind' || $key==='project_city_municipality_type') continue;
        $data[$key]=$value;
    }
    $data['project_street_purok']=$street;
    $data['project_location']=fp_location_format_address($street,$resolved);
    return [];
}

/** Draft fields are non-authoritative; when the full PSGC chain is valid, normalize it. */
function fp_location_normalize_draft(array $fields): array
{
    $out=[
        'project_province_code'=>substr(trim((string)($fields['project_province_code']??'')),0,10),
        'project_province_name'=>'',
        'project_city_municipality_code'=>substr(trim((string)($fields['project_city_municipality_code']??'')),0,10),
        'project_city_municipality_name'=>'',
        'project_barangay_code'=>substr(trim((string)($fields['project_barangay_code']??'')),0,10),
        'project_barangay_name'=>'',
        'project_street_purok'=>mb_substr(trim((string)($fields['project_street_purok']??'')),0,180),
        'concerned_penro'=>'',
        'concerned_cenro'=>'',
        'project_location'=>mb_substr(trim((string)($fields['project_location']??'')),0,300)
    ];
    $resolved=fp_location_resolve(
        $out['project_province_code'],$out['project_city_municipality_code'],$out['project_barangay_code']
    );
    if ($resolved) {
        foreach ([
            'project_province_name','project_city_municipality_name','project_barangay_name',
            'concerned_penro','concerned_cenro'
        ] as $key) $out[$key]=(string)$resolved[$key];
        if ($out['project_street_purok']!=='') {
            $out['project_location']=fp_location_format_address($out['project_street_purok'],$resolved);
        }
    }
    return $out;
}
