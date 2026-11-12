<?php
declare(strict_types=1);

/** Secure, geotagged project-area photo documentation for Annex A Step 2. */

function step2_area_photo_install_schema(): bool
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS permit_step2_area_photo_drafts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL,
            application_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            documentation_group SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            original_name VARCHAR(220) NOT NULL,
            stored_path VARCHAR(300) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(11,7) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_step2_area_photo_draft_path (stored_path),
            KEY idx_step2_area_photo_draft_owner (client_id,application_id,created_at),
            CONSTRAINT fk_step2_area_photo_draft_client FOREIGN KEY (client_id)
                REFERENCES user_client(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS permit_step2_area_photos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            application_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL,
            revision INT UNSIGNED NOT NULL,
            documentation_group SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            original_name VARCHAR(220) NOT NULL,
            stored_path VARCHAR(300) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(11,7) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_step2_area_photo_path (stored_path),
            KEY idx_step2_area_photo_app (application_id,revision,id),
            CONSTRAINT fk_step2_area_photo_app FOREIGN KEY (application_id)
                REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
            CONSTRAINT fk_step2_area_photo_client FOREIGN KEY (client_id)
                REFERENCES user_client(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("ALTER TABLE permit_step2_area_photo_drafts
            ADD COLUMN IF NOT EXISTS documentation_group SMALLINT UNSIGNED NOT NULL DEFAULT 1
            AFTER application_id");
        db()->exec("ALTER TABLE permit_step2_area_photos
            ADD COLUMN IF NOT EXISTS documentation_group SMALLINT UNSIGNED NOT NULL DEFAULT 1
            AFTER revision");
        db()->exec("CREATE TABLE IF NOT EXISTS permit_step2_area_photo_group_drafts (
            client_id BIGINT UNSIGNED NOT NULL,
            application_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            documentation_group SMALLINT UNSIGNED NOT NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(11,7) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (client_id,application_id,documentation_group),
            CONSTRAINT fk_step2_area_photo_group_draft_client FOREIGN KEY (client_id)
                REFERENCES user_client(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS permit_step2_area_photo_groups (
            application_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL,
            revision INT UNSIGNED NOT NULL,
            documentation_group SMALLINT UNSIGNED NOT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(11,7) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (application_id,revision,documentation_group),
            CONSTRAINT fk_step2_area_photo_group_app FOREIGN KEY (application_id)
                REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
            CONSTRAINT fk_step2_area_photo_group_client FOREIGN KEY (client_id)
                REFERENCES user_client(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return true;
    } catch (Throwable $e) {
        error_log('FP-OMS area photo schema initialization failed: '.$e->getMessage());
        return false;
    }
}

function step2_area_photo_schema_ready(): bool
{
    static $ready=null;
    if ($ready!==null) return $ready;
    $check=static function(): bool {
        try {
            db()->query('SELECT id,documentation_group,latitude,longitude FROM permit_step2_area_photo_drafts LIMIT 0');
            db()->query('SELECT id,revision,documentation_group,latitude,longitude FROM permit_step2_area_photos LIMIT 0');
            db()->query('SELECT documentation_group,latitude,longitude FROM permit_step2_area_photo_group_drafts LIMIT 0');
            db()->query('SELECT revision,documentation_group,latitude,longitude FROM permit_step2_area_photo_groups LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    };
    if ($check()) return $ready=true;
    if (!step2_area_photo_install_schema()) return $ready=false;
    return $ready=$check();
}

function step2_area_photo_group(mixed $value): ?int
{
    $group=filter_var($value,FILTER_VALIDATE_INT,[
        'options'=>['min_range'=>1,'max_range'=>10]
    ]);
    return $group===false?null:(int)$group;
}

function step2_area_photo_coordinate_pair(mixed $lat,mixed $lon): ?array
{
    $lat=trim((string)$lat);
    $lon=trim((string)$lon);
    if (!preg_match('/^[+-]?\d{1,3}(?:\.\d{1,7})?$/D',$lat)
        || !preg_match('/^[+-]?\d{1,3}(?:\.\d{1,7})?$/D',$lon)) return null;
    $latitude=(float)$lat;
    $longitude=(float)$lon;
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) return null;
    return [
        'latitude'=>number_format($latitude,7,'.',''),
        'longitude'=>number_format($longitude,7,'.','')
    ];
}

function step2_area_photo_draft_real_path(string $relative): ?string
{
    if (!preg_match('#^fp_area_photo_drafts/[0-9]+/[a-f0-9]{40}\.(jpg|png)$#D',$relative)) return null;
    $base=realpath(PROJECT_ROOT.'/storage/fp_area_photo_drafts');
    $path=realpath(PROJECT_ROOT.'/storage/'.$relative);
    return $base && $path && str_starts_with($path,$base.DIRECTORY_SEPARATOR)
        && is_file($path) ? $path : null;
}

function step2_area_photo_real_path(string $relative): ?string
{
    if (!preg_match('#^fp_area_photos/[0-9]+/[0-9]+/[a-f0-9]{36}\.(jpg|png)$#D',$relative)) return null;
    $base=realpath(PROJECT_ROOT.'/storage/fp_area_photos');
    $path=realpath(PROJECT_ROOT.'/storage/'.$relative);
    return $base && $path && str_starts_with($path,$base.DIRECTORY_SEPARATOR)
        && is_file($path) ? $path : null;
}

function step2_area_photo_drafts(int $clientId,int $applicationId,bool $lock=false): array
{
    if (!step2_area_photo_schema_ready()) return [];
    return query_all(
        'SELECT * FROM permit_step2_area_photo_drafts
         WHERE client_id=? AND application_id=?
         ORDER BY documentation_group ASC,id ASC'.($lock?' FOR UPDATE':''),
        [$clientId,$applicationId]
    );
}

function step2_area_photo_group_drafts(int $clientId,int $applicationId,bool $lock=false): array
{
    if (!step2_area_photo_schema_ready()) return [];
    return query_all(
        'SELECT * FROM permit_step2_area_photo_group_drafts
         WHERE client_id=? AND application_id=?
         ORDER BY documentation_group ASC'.($lock?' FOR UPDATE':''),
        [$clientId,$applicationId]
    );
}

function step2_area_photo_draft_public_list(int $clientId,int $applicationId): array
{
    return array_map(static fn(array $row): array=>[
        'id'=>(int)$row['id'],
        'documentation_group'=>(int)($row['documentation_group']??1),
        'original_name'=>(string)$row['original_name'],
        'mime_type'=>(string)$row['mime_type'],
        'size'=>(int)$row['file_size'],
        'latitude'=>(string)$row['latitude'],
        'longitude'=>(string)$row['longitude'],
        'preview_url'=>url('permit_area_photo',[
            'draft'=>1,'application_id'=>$applicationId,'id'=>(int)$row['id']
        ])
    ],step2_area_photo_drafts($clientId,$applicationId));
}

function step2_area_photo_group_draft_public_list(int $clientId,int $applicationId): array
{
    $groups=[];
    foreach (step2_area_photo_group_drafts($clientId,$applicationId) as $row) {
        $groups[]=[
            'documentation_group'=>(int)$row['documentation_group'],
            'latitude'=>$row['latitude']===null?'':(string)$row['latitude'],
            'longitude'=>$row['longitude']===null?'':(string)$row['longitude']
        ];
    }
    // Backward-compatible recovery for drafts created before migration 040:
    // infer a missing documentation pin from the first saved photo in that group.
    $known=array_fill_keys(array_map(
        static fn(array $row): int=>(int)$row['documentation_group'],$groups
    ),true);
    foreach (step2_area_photo_drafts($clientId,$applicationId) as $photo) {
        $group=(int)($photo['documentation_group']??1);
        if (isset($known[$group])) continue;
        $point=step2_area_photo_coordinate_pair($photo['latitude'],$photo['longitude']);
        if (!$point) continue;
        $groups[]=[
            'documentation_group'=>$group,
            'latitude'=>$point['latitude'],
            'longitude'=>$point['longitude']
        ];
        $known[$group]=true;
    }
    usort($groups,static fn(array $a,array $b): int=>
        (int)$a['documentation_group']<=>(int)$b['documentation_group']);
    return $groups;
}

function step2_area_photo_draft_state(int $clientId,int $applicationId): array
{
    return [
        'photos'=>step2_area_photo_draft_public_list($clientId,$applicationId),
        'groups'=>step2_area_photo_group_draft_public_list($clientId,$applicationId)
    ];
}

function step2_area_photos(int $applicationId): array
{
    if (!step2_area_photo_schema_ready()) return [];
    return query_all(
        'SELECT * FROM permit_step2_area_photos WHERE application_id=?
         ORDER BY revision ASC,documentation_group ASC,id ASC',[$applicationId]
    );
}

function step2_area_photo_groups(int $applicationId): array
{
    if (!step2_area_photo_schema_ready()) return [];
    $rows=query_all(
        'SELECT * FROM permit_step2_area_photo_groups WHERE application_id=?
         ORDER BY revision ASC,documentation_group ASC',[$applicationId]
    );
    if ($rows) return $rows;

    // Older submitted photos remain viewable even if migration 040 was applied later.
    $fallback=[];
    foreach (step2_area_photos($applicationId) as $photo) {
        $key=(int)$photo['revision'].':'.(int)($photo['documentation_group']??1);
        if (isset($fallback[$key])) continue;
        $point=step2_area_photo_coordinate_pair($photo['latitude'],$photo['longitude']);
        if (!$point) continue;
        $fallback[$key]=[
            'application_id'=>$applicationId,
            'client_id'=>(int)$photo['client_id'],
            'revision'=>(int)$photo['revision'],
            'documentation_group'=>(int)($photo['documentation_group']??1),
            'latitude'=>$point['latitude'],
            'longitude'=>$point['longitude']
        ];
    }
    return array_values($fallback);
}

function step2_area_photo_group_save(
    array $client,int $applicationId,mixed $documentationGroup,mixed $lat='',mixed $lon=''
): array {
    if (!step2_area_photo_schema_ready()) {
        return ['error'=>'Area photo location storage is unavailable; apply migrations 038–040.'];
    }
    if (!step2_draft_scope_allowed($client,$applicationId)) {
        return ['error'=>'Application is not available for Area Photo Documentation editing.'];
    }
    $group=step2_area_photo_group($documentationGroup);
    if ($group===null) return ['error'=>'Select a valid Area Photo Documentation group.'];

    $latText=trim((string)$lat);
    $lonText=trim((string)$lon);
    $point=null;
    if ($latText!=='' || $lonText!=='') {
        $point=step2_area_photo_coordinate_pair($latText,$lonText);
        if (!$point) return ['error'=>'Enter a valid documentation latitude and longitude.'];
    }

    $clientId=(int)$client['id'];
    try {
        db()->prepare(
            'INSERT INTO permit_step2_area_photo_group_drafts
             (client_id,application_id,documentation_group,latitude,longitude)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE latitude=VALUES(latitude),longitude=VALUES(longitude)'
        )->execute([
            $clientId,$applicationId,$group,
            $point['latitude']??null,$point['longitude']??null
        ]);
        return step2_area_photo_draft_state($clientId,$applicationId);
    } catch (Throwable $e) {
        error_log('FP-OMS documentation pin save failed: '.$e->getMessage());
        return ['error'=>'Could not save the Area Photo Documentation pin.'];
    }
}

function step2_area_photo_group_point(int $clientId,int $applicationId,int $group,bool $lock=false): ?array
{
    $row=query_one(
        'SELECT * FROM permit_step2_area_photo_group_drafts
         WHERE client_id=? AND application_id=? AND documentation_group=?'
        .($lock?' FOR UPDATE':''),
        [$clientId,$applicationId,$group]
    );
    if ($row) {
        $point=step2_area_photo_coordinate_pair($row['latitude'],$row['longitude']);
        if ($point) return $point;
    }
    // Old drafts may have photos before group-location metadata existed.
    $photo=query_one(
        'SELECT latitude,longitude FROM permit_step2_area_photo_drafts
         WHERE client_id=? AND application_id=? AND documentation_group=?
         ORDER BY id ASC LIMIT 1'.($lock?' FOR UPDATE':''),
        [$clientId,$applicationId,$group]
    );
    return $photo?step2_area_photo_coordinate_pair($photo['latitude'],$photo['longitude']):null;
}

function step2_area_photo_upload(
    array $client,int $applicationId,array $files,mixed $documentationGroup=1
): array
{
    if (!step2_area_photo_schema_ready()) {
        return ['error'=>'Area photo storage is unavailable; apply migrations 038–040.'];
    }
    if (!step2_draft_scope_allowed($client,$applicationId)) {
        return ['error'=>'Application is not available for area-photo upload.'];
    }
    $group=step2_area_photo_group($documentationGroup);
    if ($group===null) return ['error'=>'Select a valid Area Photo Documentation group.'];
    $info=upload_details($files['area_photo']??null,true);
    if (isset($info['error'])) return ['error'=>$info['error']];
    if (!in_array((string)($info['mime']??''),['image/jpeg','image/png'],true)) {
        return ['error'=>'Area photos must be JPG/JPEG or PNG images.'];
    }
    $clientId=(int)$client['id'];
    $pdo=db();
    $stored=null;
    try {
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM user_client WHERE id=? FOR UPDATE')->execute([$clientId]);
        $point=step2_area_photo_group_point($clientId,$applicationId,$group,true);
        if (!$point) {
            $pdo->rollBack();
            return ['error'=>'Pin Area Photo Documentation '.$group.' first, then upload its photos.'];
        }
        // Persist inferred pre-040 group locations on first new upload.
        $pdo->prepare(
            'INSERT INTO permit_step2_area_photo_group_drafts
             (client_id,application_id,documentation_group,latitude,longitude)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE latitude=COALESCE(latitude,VALUES(latitude)),
                 longitude=COALESCE(longitude,VALUES(longitude))'
        )->execute([$clientId,$applicationId,$group,$point['latitude'],$point['longitude']]);
        $count=query_one(
            'SELECT COUNT(*) AS n FROM permit_step2_area_photo_drafts
             WHERE client_id=? AND application_id=?',
            [$clientId,$applicationId]
        );
        if ((int)($count['n']??0)>=20) {
            $pdo->rollBack();
            return ['error'=>'Maximum 20 area photos per editable application draft.'];
        }
        $folder='fp_area_photo_drafts/'.$clientId;
        $absoluteFolder=PROJECT_ROOT.'/storage/'.$folder;
        if (!is_dir($absoluteFolder)
            && !mkdir($absoluteFolder,0700,true) && !is_dir($absoluteFolder)) {
            throw new RuntimeException('Private area-photo draft directory unavailable.');
        }
        $extension=(string)$info['mime']==='image/png'?'png':'jpg';
        $relative=$folder.'/'.bin2hex(random_bytes(20)).'.'.$extension;
        $stored=PROJECT_ROOT.'/storage/'.$relative;
        fpoms_file_store_upload((string)$info['file']['tmp_name'],$stored);
        @chmod($stored,0600);
        $sha=fpoms_file_plaintext_sha256($stored);
        $pdo->prepare(
            'INSERT INTO permit_step2_area_photo_drafts
             (client_id,application_id,documentation_group,original_name,stored_path,mime_type,file_size,sha256,latitude,longitude)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $clientId,$applicationId,$group,(string)$info['name'],$relative,(string)$info['mime'],
            (int)$info['size'],$sha,$point['latitude'],$point['longitude']
        ]);
        $pdo->commit();
        return step2_area_photo_draft_state($clientId,$applicationId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($stored && is_file($stored)) @unlink($stored);
        error_log('FP-OMS area photo upload failed: '.$e->getMessage());
        return ['error'=>'Could not securely save the area photo.'];
    }
}

function step2_area_photo_update(array $client,int $applicationId,int $id,mixed $lat,mixed $lon): array
{
    if (!step2_area_photo_schema_ready()) return ['error'=>'Area photo storage unavailable.'];
    if ($id<1 || !step2_draft_scope_allowed($client,$applicationId)) {
        return ['error'=>'Area photo not available for editing.'];
    }
    $point=step2_area_photo_coordinate_pair($lat,$lon);
    if (!$point) return ['error'=>'Select a valid location for this photo.'];
    $stmt=db()->prepare(
        'UPDATE permit_step2_area_photo_drafts
         SET latitude=?,longitude=? WHERE id=? AND client_id=? AND application_id=?'
    );
    $stmt->execute([
        $point['latitude'],$point['longitude'],$id,(int)$client['id'],$applicationId
    ]);
    if ($stmt->rowCount()<1 && !query_one(
        'SELECT id FROM permit_step2_area_photo_drafts WHERE id=? AND client_id=? AND application_id=?',
        [$id,(int)$client['id'],$applicationId]
    )) return ['error'=>'Area photo not found.'];
    return step2_area_photo_draft_state((int)$client['id'],$applicationId);
}

function step2_area_photo_delete(array $client,int $applicationId,int $id): array
{
    if (!step2_area_photo_schema_ready()) return ['error'=>'Area photo storage unavailable.'];
    if ($id<1 || !step2_draft_scope_allowed($client,$applicationId)) {
        return ['error'=>'Area photo not available for removal.'];
    }
    $pdo=db();
    try {
        $pdo->beginTransaction();
        $row=query_one(
            'SELECT stored_path FROM permit_step2_area_photo_drafts
             WHERE id=? AND client_id=? AND application_id=? FOR UPDATE',
            [$id,(int)$client['id'],$applicationId]
        );
        if (!$row) {
            $pdo->rollBack();
            return ['error'=>'Area photo not found.'];
        }
        $pdo->prepare('DELETE FROM permit_step2_area_photo_drafts WHERE id=?')->execute([$id]);
        $pdo->commit();
        $path=step2_area_photo_draft_real_path((string)$row['stored_path']);
        if ($path) @unlink($path);
        return step2_area_photo_draft_state((int)$client['id'],$applicationId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS area photo deletion failed: '.$e->getMessage());
        return ['error'=>'Could not remove the area photo.'];
    }
}

function step2_area_photo_group_delete(
    array $client,int $applicationId,mixed $documentationGroup
): array {
    if (!step2_area_photo_schema_ready()) {
        return ['error'=>'Area photo storage is unavailable; apply migrations 038–040.'];
    }
    if (!step2_draft_scope_allowed($client,$applicationId)) {
        return ['error'=>'Application is not available for Area Photo Documentation removal.'];
    }
    $group=step2_area_photo_group($documentationGroup);
    if ($group===null) return ['error'=>'Select a valid Area Photo Documentation group.'];

    $clientId=(int)$client['id'];
    $pdo=db();
    $storedPaths=[];
    try {
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM user_client WHERE id=? FOR UPDATE')->execute([$clientId]);

        $stmt=$pdo->prepare(
            'SELECT stored_path FROM permit_step2_area_photo_drafts
             WHERE client_id=? AND application_id=? AND documentation_group=? FOR UPDATE'
        );
        $stmt->execute([$clientId,$applicationId,$group]);
        $storedPaths=array_map(
            static fn(array $row): string=>(string)$row['stored_path'],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $pdo->prepare(
            'DELETE FROM permit_step2_area_photo_drafts
             WHERE client_id=? AND application_id=? AND documentation_group=?'
        )->execute([$clientId,$applicationId,$group]);
        $pdo->prepare(
            'DELETE FROM permit_step2_area_photo_group_drafts
             WHERE client_id=? AND application_id=? AND documentation_group=?'
        )->execute([$clientId,$applicationId,$group]);

        $pdo->commit();

        foreach ($storedPaths as $relative) {
            $path=step2_area_photo_draft_real_path($relative);
            if ($path) @unlink($path);
        }

        $state=step2_area_photo_draft_state($clientId,$applicationId);
        $state['removed_group']=$group;
        $state['group_one_reset']=$group===1;
        return $state;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS area photo documentation removal failed: '.$e->getMessage());
        return ['error'=>'Could not remove the Area Photo Documentation draft.'];
    }
}

function step2_area_photo_draft_submission(int $clientId,int $applicationId,bool $lock=true): array
{
    if (!step2_area_photo_schema_ready()) return ['rows'=>[],'groups'=>[],'errors'=>[]];
    $rows=step2_area_photo_drafts($clientId,$applicationId,$lock);
    $groups=step2_area_photo_group_drafts($clientId,$applicationId,$lock);
    $errors=[];
    if (count($rows)>20) $errors[]='Maximum 20 area photos per application draft.';

    $groupMap=[];
    foreach ($groups as $groupRow) {
        $point=step2_area_photo_coordinate_pair($groupRow['latitude'],$groupRow['longitude']);
        if ($point) $groupMap[(int)$groupRow['documentation_group']]=$point;
    }

    foreach ($rows as $row) {
        $group=(int)($row['documentation_group']??1);
        $path=step2_area_photo_draft_real_path((string)$row['stored_path']);
        $point=step2_area_photo_coordinate_pair($row['latitude'],$row['longitude']);
        if (!isset($groupMap[$group])) {
            $fallback=$point;
            if ($fallback) $groupMap[$group]=$fallback;
        }
        if (!$path || !$point
            || !in_array((string)$row['mime_type'],['image/jpeg','image/png'],true)
            || fpoms_file_plaintext_size($path)!==(int)$row['file_size']
            || !hash_equals((string)$row['sha256'],fpoms_file_plaintext_sha256($path))) {
            $errors[]='A saved area photo is missing, modified, or has an invalid map location.';
        }
        if (!isset($groupMap[$group])) {
            $errors[]='Area Photo Documentation '.$group.' has photos but no valid pinpoint location.';
        }
    }

    $normalizedGroups=[];
    foreach ($groupMap as $group=>$point) {
        $normalizedGroups[]=[
            'documentation_group'=>$group,
            'latitude'=>$point['latitude'],
            'longitude'=>$point['longitude']
        ];
    }
    usort($normalizedGroups,static fn(array $a,array $b): int=>
        (int)$a['documentation_group']<=>(int)$b['documentation_group']);
    return ['rows'=>$rows,'groups'=>$normalizedGroups,'errors'=>array_values(array_unique($errors))];
}

function step2_area_photo_copy_submission(
    PDO $pdo,array $rows,array $groups,int $clientId,int $draftScope,
    int $applicationId,int $revision,array &$createdFiles,array &$draftFilesToRemove
): int {
    $usedGroups=[];
    foreach ($rows as $row) $usedGroups[(int)($row['documentation_group']??1)]=true;
    foreach ($groups as $groupRow) {
        $group=(int)$groupRow['documentation_group'];
        if (!isset($usedGroups[$group])) continue;
        $point=step2_area_photo_coordinate_pair($groupRow['latitude'],$groupRow['longitude']);
        if (!$point) throw new RuntimeException('Area Photo Documentation '.$group.' has no valid pin.');
        $pdo->prepare(
            'INSERT INTO permit_step2_area_photo_groups
             (application_id,client_id,revision,documentation_group,latitude,longitude)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $applicationId,$clientId,$revision,$group,$point['latitude'],$point['longitude']
        ]);
    }

    $added=0;
    foreach ($rows as $row) {
        $source=step2_area_photo_draft_real_path((string)$row['stored_path']);
        if (!$source) throw new RuntimeException('A saved area photo is missing.');
        $extension=(string)$row['mime_type']==='image/png'?'png':'jpg';
        $folder='fp_area_photos/'.$clientId.'/'.$applicationId;
        $absoluteFolder=PROJECT_ROOT.'/storage/'.$folder;
        if (!is_dir($absoluteFolder)
            && !mkdir($absoluteFolder,0700,true) && !is_dir($absoluteFolder)) {
            throw new RuntimeException('Private submitted area-photo directory unavailable.');
        }
        $relative=$folder.'/'.bin2hex(random_bytes(18)).'.'.$extension;
        $absolute=PROJECT_ROOT.'/storage/'.$relative;
        fpoms_file_copy_encrypted($source,$absolute);
        @chmod($absolute,0600);
        $createdFiles[]=$absolute;
        $sha=fpoms_file_plaintext_sha256($absolute);
        if (!hash_equals((string)$row['sha256'],$sha)) {
            throw new RuntimeException('Area photo integrity verification failed.');
        }
        $pdo->prepare(
            'INSERT INTO permit_step2_area_photos
             (application_id,client_id,revision,documentation_group,original_name,stored_path,mime_type,file_size,sha256,latitude,longitude)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $applicationId,$clientId,$revision,(int)($row['documentation_group']??1),
            (string)$row['original_name'],$relative,
            (string)$row['mime_type'],(int)$row['file_size'],$sha,
            (string)$row['latitude'],(string)$row['longitude']
        ]);
        $draftFilesToRemove[]=$source;
        $added++;
    }
    if ($rows) {
        $pdo->prepare(
            'DELETE FROM permit_step2_area_photo_drafts WHERE client_id=? AND application_id=?'
        )->execute([$clientId,$draftScope]);
    }
    $pdo->prepare(
        'DELETE FROM permit_step2_area_photo_group_drafts WHERE client_id=? AND application_id=?'
    )->execute([$clientId,$draftScope]);
    return $added;
}

function step2_area_photo_clear_drafts(PDO $pdo,int $clientId,int $applicationId): array
{
    if (!step2_area_photo_schema_ready()) return [];
    $stmt=$pdo->prepare(
        'SELECT stored_path FROM permit_step2_area_photo_drafts
         WHERE client_id=? AND application_id=? FOR UPDATE'
    );
    $stmt->execute([$clientId,$applicationId]);
    $paths=array_map(static fn(array $row): string=>(string)$row['stored_path'],
        $stmt->fetchAll(PDO::FETCH_ASSOC));
    $pdo->prepare(
        'DELETE FROM permit_step2_area_photo_drafts WHERE client_id=? AND application_id=?'
    )->execute([$clientId,$applicationId]);
    $pdo->prepare(
        'DELETE FROM permit_step2_area_photo_group_drafts WHERE client_id=? AND application_id=?'
    )->execute([$clientId,$applicationId]);
    return $paths;
}

function step2_area_photo_send_draft(array $client,int $applicationId,int $id): never
{
    if (!step2_area_photo_schema_ready() || !step2_draft_scope_allowed($client,$applicationId)) {
        show_error('Area photo not found.',404);
    }
    $row=query_one(
        'SELECT * FROM permit_step2_area_photo_drafts WHERE id=? AND client_id=? AND application_id=?',
        [$id,(int)$client['id'],$applicationId]
    );
    $path=$row?step2_area_photo_draft_real_path((string)$row['stored_path']):null;
    if (!$row || !$path) show_error('Area photo not found.',404);
    step2_area_photo_stream($row,$path);
}

function step2_area_photo_send_submitted(array $viewer,int $applicationId,int $id): never
{
    step2_application_for_view($viewer,$applicationId);
    $row=query_one(
        'SELECT * FROM permit_step2_area_photos WHERE id=? AND application_id=?',
        [$id,$applicationId]
    );
    $path=$row?step2_area_photo_real_path((string)$row['stored_path']):null;
    if (!$row || !$path) show_error('Area photo not found.',404);
    step2_area_photo_stream($row,$path);
}

function step2_area_photo_stream(array $row,string $path): never
{
    if (!in_array((string)$row['mime_type'],['image/jpeg','image/png'],true)
        || !hash_equals((string)$row['sha256'],fpoms_file_plaintext_sha256($path))) {
        show_error('Area photo failed verification.',409);
    }
    header('Content-Type: '.(string)$row['mime_type']);
    header('Content-Length: '.fpoms_file_plaintext_size($path));
    header('Content-Disposition: inline; filename="area-photo"; filename*=UTF-8\'\''
        .rawurlencode((string)$row['original_name']));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo fpoms_file_read($path);
    exit;
}
