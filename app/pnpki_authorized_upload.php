<?php
declare(strict_types=1);
/** RED authorizes PNPKI_2 once, then uploads the signed PDF inside FP-OMS. */

function pnpki_authorized_upload_ready(): bool
{
    if (!pnpki_bridge_schema_ready()) return false;
    try {
        db()->query('SELECT fpoms_user_id FROM fpoms_pnpki_authorizations LIMIT 0');
        db()->query('SELECT application_id FROM fpoms_pnpki_signed_uploads LIMIT 0');
        return true;
    } catch(Throwable $e) { return false; }
}
function pnpki_authorized_red(array $red): bool
{
    return ($red['user_kind']??'')==='internal'
        && ($red['status']??'')==='active'
        && has_role($red,'red');
}
function pnpki_authorization_for(int $staffId): ?array
{
    if (!pnpki_authorized_upload_ready() || $staffId<1) return null;
    return query_one('SELECT * FROM fpoms_pnpki_authorizations WHERE fpoms_user_id=?',[$staffId]);
}
function pnpki_authorize_url(array $red): string
{
    if (!pnpki_authorized_red($red) || !pnpki_bridge_configured()
        || !pnpki_authorized_upload_ready()) {
        throw new RuntimeException('Apply migrations 041 and 042, then configure the PNPKI_2 connection.');
    }
    $state=bin2hex(random_bytes(32));
    $_SESSION['pnpki_authorize_pending']=[
        'state'=>$state,'red_id'=>(int)$red['id'],'issued_at'=>time()
    ];
    $base=(string)fpoms_pnpki_effective_config()['url'];
    $at=strrpos($base,'/');
    if ($at===false) throw new RuntimeException('Invalid PNPKI_2 bridge endpoint.');
    return substr($base,0,$at+1).'fpoms_authorize.php?'.http_build_query([
        'state'=>$state,'fpoms_user'=>(int)$red['id']
    ],'','&',PHP_QUERY_RFC3986);
}
function pnpki_finish_authorization(array $red,string $code,string $state): array
{
    if (!pnpki_authorized_red($red) || !pnpki_authorized_upload_ready()) {
        return ['RED account or bridge is unavailable.'];
    }
    $pending=$_SESSION['pnpki_authorize_pending']??null;
    unset($_SESSION['pnpki_authorize_pending']);
    if (!is_array($pending)
        || (int)($pending['red_id']??0)!==(int)$red['id']
        || time()-(int)($pending['issued_at']??0)>300
        || !preg_match('/^[a-f0-9]{64}$/D',$code)
        || !preg_match('/^[a-f0-9]{64}$/D',$state)
        || !hash_equals((string)($pending['state']??''),$state)) {
        return ['Authorization expired or does not match this FP-OMS session. Try again.'];
    }
    try {
        $response=pnpki_bridge_request([
            'action'=>'redeem_authorization','code'=>$code,'state'=>$state,
            'fpoms_user_id'=>(int)$red['id']
        ]);
        $remote=(int)($response['remote_user_id']??0);
        if ($remote<1) return ['PNPKI_2 returned an invalid account.'];
        $previous=pnpki_authorization_for((int)$red['id']);
        if ($previous && (int)$previous['remote_user_id']!==$remote) {
            return ['Another PNPKI_2 account is already connected. Contact RICTU to update this link.'];
        }
        db()->prepare("INSERT INTO fpoms_pnpki_authorizations
            (fpoms_user_id,remote_user_id) VALUES (?,?)
            ON DUPLICATE KEY UPDATE authorized_at=NOW()")
            ->execute([(int)$red['id'],$remote]);
        audit((int)$red['id'],'permit.pnpki.account_authorized','user',(int)$red['id'],[
            'remote_user_id'=>$remote
        ]);
        return [];
    } catch(Throwable $e) {
        error_log('FP-OMS PNPKI authorization: '.$e->getMessage());
        return ['Unable to authorize PNPKI_2. Confirm account roles, secret and migrations.'];
    }
}
/** RED self-service disconnect. Revoke remotely first; never delete documents. */
function pnpki_revoke_authorization(array $red): array
{
    if (!pnpki_authorized_red($red) || !pnpki_authorized_upload_ready()) {
        return ['Only an active RED account can revoke its PNPKI_2 connection.'];
    }
    $userId=(int)$red['id'];
    $link=pnpki_authorization_for($userId);
    if (!$link) return ['No PNPKI_2 account is currently connected.'];
    try {
        $response=pnpki_bridge_request([
            'action'=>'revoke_authorization',
            'fpoms_user_id'=>$userId,
            'remote_user_id'=>(int)$link['remote_user_id']
        ]);
        if (($response['revoked']??false)!==true) {
            return ['PNPKI_2 could not confirm access revocation.'];
        }
        db()->prepare('DELETE FROM fpoms_pnpki_authorizations
            WHERE fpoms_user_id=? AND remote_user_id=?')
            ->execute([$userId,(int)$link['remote_user_id']]);
        unset($_SESSION['pnpki_authorize_pending']);
        audit($userId,'permit.pnpki.account_revoked','user',$userId,[
            'remote_user_id'=>(int)$link['remote_user_id']
        ]);
        return [];
    } catch(Throwable $e) {
        error_log('FP-OMS PNPKI revocation: '.$e->getMessage());
        return ['Unable to complete PNPKI_2 revocation. No existing documents were deleted. Retry or contact RICTU.'];
    }
}

function pnpki_pending_signed_upload(int $id,int $revision): ?array
{
    if (!pnpki_authorized_upload_ready()) return null;
    return query_one('SELECT * FROM fpoms_pnpki_signed_uploads
        WHERE application_id=? AND revision=?',[$id,$revision]);
}
function pnpki_upload_signed_pdf(array $red,int $id,array $upload,?string $agentBytes=null): array
{
    if (!pnpki_authorized_red($red)) return ['RED authorization required.'];
    if (!pnpki_authorized_upload_ready()) return ['Apply database migrations 041 and 042 first.'];
    if (!pnpki_authorization_for((int)$red['id'])) {
        return ['Authorize your PNPKI_2 RED account first.'];
    }
    $app=fpoms_payment_notice_app($id);
    if (!$app || !permit_flow_is_new($app)) return ['Application not found.'];
    $revision=(int)$app['revision'];
    $notice=fpoms_payment_notice_record($id,$revision);
    if (!$notice || $notice['status']!=='pending_red'
        || !in_array((string)$app['status'],
            ['ro_received','forwarded_to_lpdd','step4_complete'],true)) {
        return ['Only a pending RED Template No. 2 after RO receipt may be submitted.'];
    }
    if (pnpki_bridge_link($id,$revision)) return ['Document is already in PNPKI_2 Records.'];
    $existing=pnpki_pending_signed_upload($id,$revision);
    if ($existing && (int)$existing['uploaded_by']!==(int)$red['id']) {
        return ['Only the original RED uploader may replace a pending signed PDF.'];
    }
    if ($agentBytes===null && ((int)($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK
        || !is_uploaded_file((string)($upload['tmp_name']??'')))) {
        return ['Select the PNPKI-digitally-signed PDF first.'];
    }
    $size=$agentBytes!==null?strlen($agentBytes):(int)($upload['size']??0);
    $name=$agentBytes!==null?'PNPKI_Desktop_Signed_Template_No_2.pdf':(string)($upload['name']??'');
    if ($size<100 || $size>10485760 || strlen($name)>220
        || strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf') {
        return ['Valid PDF only, up to 10 MB.'];
    }
    $raw=$agentBytes??file_get_contents((string)$upload['tmp_name']);
    $mime=is_string($raw)?(new finfo(FILEINFO_MIME_TYPE))->buffer($raw):'';
    if (!is_string($raw) || strlen($raw)!==$size
        || !str_starts_with($raw,'%PDF-')
        || !preg_match('/%%EOF\s*$/D',substr($raw,-2048))
        || $mime!=='application/pdf') {
        return ['The selected file is not a valid PDF.'];
    }
    $folder=PROJECT_ROOT.'/storage/fp_pnpki_pending/'.$id;
    $relative='fp_pnpki_pending/'.$id.'/'.bin2hex(random_bytes(18)).'.pdf';
    $absolute=PROJECT_ROOT.'/storage/'.$relative;
    try {
        if (!is_dir($folder) && !mkdir($folder,0700,true) && !is_dir($folder)) {
            throw new RuntimeException('Private signed-PDF folder unavailable.');
        }
        fpoms_file_write_encrypted_bytes($raw,$absolute);
        try {
            if ($existing) {
                db()->prepare('UPDATE fpoms_pnpki_signed_uploads SET original_name=?,
                    stored_path=?,sha256=?,file_size=?,uploaded_at=NOW()
                    WHERE application_id=? AND revision=? AND uploaded_by=?')
                    ->execute([$name,$relative,hash('sha256',$raw),strlen($raw),
                        $id,$revision,(int)$red['id']]);
                $oldRoot=realpath(PROJECT_ROOT.'/storage/fp_pnpki_pending');
                $oldFile=realpath(PROJECT_ROOT.'/storage/'.(string)$existing['stored_path']);
                if ($oldRoot && $oldFile
                    && str_starts_with($oldFile,$oldRoot.DIRECTORY_SEPARATOR)) {
                    @unlink($oldFile);
                }
            } else {
                db()->prepare('INSERT INTO fpoms_pnpki_signed_uploads
                    (application_id,revision,uploaded_by,original_name,stored_path,sha256,file_size)
                    VALUES (?,?,?,?,?,?,?)')
                    ->execute([$id,$revision,(int)$red['id'],$name,$relative,
                        hash('sha256',$raw),strlen($raw)]);
            }
        } catch(Throwable $error) {
            @unlink($absolute);
            throw $error;
        }
        audit((int)$red['id'],'permit.pnpki.signed_uploaded','permit_step2_application',$id,[
            'revision'=>$revision,'sha256'=>hash('sha256',$raw)
        ]);
    } catch(Throwable $e) {
        error_log('FP-OMS secure PNPKI upload: '.$e->getMessage());
        return ['Could not securely save signed PDF. Check private storage/database permissions.'];
    }
    // Import into PNPKI_2 after the FP-OMS file is durable; failures can be retried.
    return pnpki_submit_saved_signed_pdf($red,$id);
}

function pnpki_submit_saved_signed_pdf(array $red,int $id): array
{
    if (!pnpki_authorized_red($red) || !pnpki_authorized_upload_ready()) {
        return ['RED or PNPKI_2 connection is unavailable.'];
    }
    $app=fpoms_payment_notice_app($id);
    if (!$app) return ['Application not found.'];
    $rev=(int)$app['revision'];
    $row=pnpki_pending_signed_upload($id,$rev);
    $auth=pnpki_authorization_for((int)$red['id']);
    if (!$row || !$auth || (int)$row['uploaded_by']!==(int)$red['id']) {
        return ['No matching authorized signed-PDF upload to submit.'];
    }
    if (pnpki_bridge_link($id,$rev)) return [];
    $base=realpath(PROJECT_ROOT.'/storage/fp_pnpki_pending');
    $path=realpath(PROJECT_ROOT.'/storage/'.(string)$row['stored_path']);
    if (!$base || !$path || !str_starts_with($path,$base.DIRECTORY_SEPARATOR)) {
        return ['Saved signed PDF was not found in private storage.'];
    }
    try {
        $raw=fpoms_file_read($path);
        if (!is_string($raw) || strlen($raw)!==(int)$row['file_size']
            || !hash_equals((string)$row['sha256'],hash('sha256',$raw))) {
            return ['Saved signed PDF failed integrity checks.'];
        }
        $source=hash('sha256','denr_fp_oms:payment_notice:'.$id.':'.$rev.':fpoms_signed');
        $response=pnpki_bridge_request([
            'action'=>'import_signed',
            'application_id'=>$id,
            'project_title'=>substr((string)($app['project_title']??''),0,130),
            'source_key'=>$source,
            'fpoms_user_id'=>(int)$red['id'],
            'remote_user_id'=>(int)$auth['remote_user_id'],
            'sha256'=>hash('sha256',$raw),
            'pdf_base64'=>base64_encode($raw)
        ]);
        $remote=(int)($response['doc_id']??0);
        if ($remote<1) return ['PNPKI_2 returned an invalid Records document reference.'];
        $notice=fpoms_payment_notice_record($id,$rev);
        if (!$notice) return ['Template No. 2 was not found.'];
        db()->prepare("INSERT INTO fpoms_pnpki_links
            (application_id,revision,notice_id,source_key,remote_doc_id,requested_by_user_id,stage)
            VALUES (?,?,?,?,?,?,'awaiting_records')")->execute([
                $id,$rev,(int)$notice['id'],$source,$remote,(int)$red['id']
            ]);
        audit((int)$red['id'],'permit.pnpki.signed_forwarded_to_records',
            'permit_step2_application',$id,[
                'revision'=>$rev,'remote_doc_id'=>$remote
            ]);
        return [];
    } catch(Throwable $e) {
        error_log('FP-OMS PNPKI signed import: '.$e->getMessage());
        // Only show the curated, safe diagnostics emitted by the bridge adapter.
        // Never reveal raw database exceptions or internal server file paths.
        if ($e instanceof RuntimeException) {
            $known=[
                'PNPKI_2 PDF verification is OFF',
                'PNPKI_2 cannot execute Poppler pdfsig.exe',
                'PNPKI_2 RED certificate Common Name is missing',
                'The RED PDF cryptographic signature is invalid',
                'The RED digital certificate chain is not trusted',
                'The PDF signer certificate Common Name does not match',
                'RED PNPKI_2 authorization is invalid',
                'PNPKI_2 integration database setup is incomplete',
                'PNPKI_2 already has a different signed document',
                'PNPKI_2 could not validate this signed PDF',
                'PNPKI_2 encountered an internal server/database error',
                'PNPKI_2 rejected the connection credentials',
                'Cannot reach the PNPKI_2 server',
                'PNPKI_2 rejected the request.'
            ];
            foreach ($known as $prefix) {
                if (str_starts_with($e->getMessage(),$prefix)) return [$e->getMessage()];
            }
        }
        return ['Unable to forward the saved signed PDF. Check PNPKI_2 integration diagnostics or ask RICTU to inspect the server logs. Your uploaded document is still saved; retry safely.'];
    }
}

function pnpki_authorized_sso_url(array $red,int $id): string
{
    if (!pnpki_authorized_red($red) || !pnpki_bridge_configured()) {
        throw new RuntimeException('RED PNPKI authorization required.');
    }
    $app=fpoms_payment_notice_app($id);
    $link=$app?pnpki_bridge_link($id,(int)$app['revision']):null;
    $auth=pnpki_authorization_for((int)$red['id']);
    if (!$link || !$auth || (int)$link['requested_by_user_id']!==(int)$red['id']) {
        throw new RuntimeException('No authorized PNPKI_2 linked document.');
    }
    $result=pnpki_bridge_request([
        'action'=>'sso_ticket',
        'fpoms_user_id'=>(int)$red['id'],
        'remote_user_id'=>(int)$auth['remote_user_id'],
        'doc_id'=>(int)$link['remote_doc_id']
    ]);
    $ticket=(string)($result['ticket']??'');
    if (!preg_match('/^[a-f0-9]{64}$/D',$ticket)) {
        throw new RuntimeException('PNPKI_2 did not return a valid one-time access ticket.');
    }
    $base=(string)fpoms_pnpki_effective_config()['url'];
    return substr($base,0,strrpos($base,'/')+1).'fpoms_sso.php?ticket='.$ticket;
}
