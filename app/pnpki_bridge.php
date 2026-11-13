<?php
declare(strict_types=1);

/**
 * FP-OMS side of the PNPKI_2 signing/release bridge.
 * Never exposes PNPKI certificates or signs PDF files.
 * A notice is sent to the client ONLY after PNPKI_2 verifies:
 * RED forwarding, Records release, a trusted RED digital signature,
 * and the completed final PDF.
 */

function pnpki_bridge_schema_ready(): bool
{
    try {
        db()->query('SELECT application_id,revision,remote_doc_id FROM fpoms_pnpki_links LIMIT 0');
        db()->query('SELECT nonce FROM fpoms_pnpki_nonces LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function pnpki_bridge_link(int $appId,int $revision): ?array
{
    if (!pnpki_bridge_schema_ready()) return null;
    return query_one('SELECT * FROM fpoms_pnpki_links WHERE application_id=? AND revision=?',
        [$appId,$revision]);
}

function pnpki_bridge_configured(): bool
{
    $config=fpoms_pnpki_effective_config();
    $secret=(string)$config['secret'];
    $url=(string)$config['url'];
    if (strlen($secret)<32 || $url==='' || !function_exists('curl_init')) return false;
    $parts=parse_url($url);
    if (!is_array($parts) || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass'])) return false;
    if (strtolower((string)($parts['scheme']??''))==='https') return true;
    $local=in_array(strtolower((string)$parts['host']),['localhost','127.0.0.1','::1'],true);
    return strtolower((string)($parts['scheme']??''))==='http'
        && $local && !empty($config['allow_local_http']);
}

/** Open the linked PNPKI_2 document in the user's authenticated PNPKI_2 session. */
function pnpki_bridge_document_url(int $docId): string
{
    if ($docId<1 || !pnpki_bridge_configured()) return '';
    $base=(string)fpoms_pnpki_effective_config()['url'];
    $slash=strrpos($base,'/');
    if ($slash===false) return '';
    return substr($base,0,$slash+1).'view_document.php?id='.$docId;
}

function pnpki_bridge_signature(string $body,string $timestamp,string $nonce): string
{
    return hash_hmac('sha256',$timestamp."\n".$nonce."\n".hash('sha256',$body),
        (string)fpoms_pnpki_effective_config()['secret']);
}

/** Safe, user-facing bridge errors; never echo remote server text or SQL. */
function pnpki_bridge_error_message(string $code): string
{
    return match($code) {
        'VERIFICATION_PAUSED'=>'PNPKI_2 PDF verification is OFF in Admin Settings. Turn it ON before retrying signed-PDF forwarding.',
        'PDFSIG_UNAVAILABLE'=>'PNPKI_2 cannot execute Poppler pdfsig.exe. Admin: install/configure the verifier and run Test PDF Signature Verification.',
        'RED_CERTIFICATE_NOT_CONFIGURED'=>'PNPKI_2 RED certificate Common Name is missing. Admin: save the exact certificate Common Name.',
        'SIGNATURE_INVALID'=>'The RED PDF cryptographic signature is invalid or could not be verified. Test this signed PDF in PNPKI_2 Admin.',
        'CERTIFICATE_UNTRUSTED'=>'The RED digital certificate chain is not trusted by PNPKI_2. Admin: verify the trusted PNPKI certificate chain.',
        'RED_IDENTITY_MISMATCH'=>'The PDF signer certificate Common Name does not match the configured RED certificate identity.',
        'RED_AUTHORIZATION_FAILED'=>'RED PNPKI_2 authorization is invalid or expired. Reauthorize the configured RED account in FP-OMS.',
        'SCHEMA_NOT_READY'=>'PNPKI_2 integration database setup is incomplete. Admin: verify migrations 001 and 002.',
        'DOCUMENT_CONFLICT'=>'PNPKI_2 already has a different signed document for this application. Contact RICTU before retrying.',
        'PDF_VERIFICATION_FAILED'=>'PNPKI_2 could not validate this signed PDF. Run the signed-PDF test in PNPKI_2 Admin.',
        'PNPKI_SERVER_ERROR'=>'PNPKI_2 encountered an internal server/database error. Ask RICTU to inspect the PNPKI_2 server logs.',
        'AUTHENTICATION_FAILED'=>'PNPKI_2 rejected the connection credentials. Admin: verify the same shared secret and server time in both systems.',
        'CONNECTION_FAILED'=>'Cannot reach the PNPKI_2 server. Check PNPKI_2 service status and configured integration URL.',
        default=>'PNPKI_2 rejected the request. Ask RICTU to inspect the PNPKI_2 integration logs.'
    };
}

function pnpki_bridge_request(array $payload): array
{
    if (!pnpki_bridge_configured()) {
        throw new RuntimeException('Configure PNPKI_BRIDGE_URL and the shared secret in FP-OMS .env.');
    }
    $json=json_encode($payload,JSON_THROW_ON_ERROR);
    $time=(string)time();$nonce=bin2hex(random_bytes(16));
    $headers=[
        'Content-Type: application/json',
        'Accept: application/json',
        'X-FPOMS-Timestamp: '.$time,
        'X-FPOMS-Nonce: '.$nonce,
        'X-FPOMS-Signature: '.pnpki_bridge_signature($json,$time,$nonce),
    ];
    $handle=curl_init((string)fpoms_pnpki_effective_config()['url']);
    curl_setopt_array($handle,[
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_POSTFIELDS=>$json,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_TIMEOUT=>25,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    $body=curl_exec($handle);
    $http=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
    $failure=curl_error($handle);
    curl_close($handle);
    if (!is_string($body) || $http!==200) {
        $received=is_string($body)?json_decode($body,true):null;
        $code=is_array($received)?(string)($received['error_code']??''):'';
        $known=['VERIFICATION_PAUSED','PDFSIG_UNAVAILABLE',
            'RED_CERTIFICATE_NOT_CONFIGURED','SIGNATURE_INVALID',
            'CERTIFICATE_UNTRUSTED','RED_IDENTITY_MISMATCH',
            'RED_AUTHORIZATION_FAILED','SCHEMA_NOT_READY',
            'DOCUMENT_CONFLICT','PDF_VERIFICATION_FAILED','PNPKI_SERVER_ERROR'];
        if (!in_array($code,$known,true)) {
            $code=$http===401?'AUTHENTICATION_FAILED'
                :($http===0?'CONNECTION_FAILED':'PNPKI_SERVER_ERROR');
        }
        // Server log contains only the HTTP status/code and transport failure,
        // never the remote response body, secrets, or applicant PDF.
        error_log('FP-OMS PNPKI_2 HTTP '.$http.' code='.$code
            .' transport='.substr($failure,0,120));
        throw new RuntimeException(pnpki_bridge_error_message($code));
    }
    $value=json_decode($body,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($value) || ($value['ok']??false)!==true) {
        throw new RuntimeException('PNPKI_2 returned an invalid response.');
    }
    return $value;
}

function pnpki_bridge_send(array $red,int $id): array
{
    if (($red['user_kind']??'')!=='internal' || ($red['status']??'')!=='active'
        || !has_role($red,'red')) return ['Only authorized RED may forward a notice to PNPKI_2.'];
    if (!pnpki_bridge_schema_ready()) return ['Apply database/041_pnpki_2_bridge.sql to denr_fp_oms.'];
    if (!pnpki_bridge_configured()) return ['PNPKI_2 server connection is not configured.'];
    $app=fpoms_payment_notice_app($id);
    if (!$app || !permit_flow_is_new($app)) return ['FP application not found.'];
    $revision=(int)$app['revision'];
    $notice=fpoms_payment_notice_record($id,$revision);
    if (!$notice || (string)$notice['status']!=='pending_red') {
        return ['Only a pending unsigned Template No. 2 can be forwarded.'];
    }
    if (!in_array((string)$app['status'],['ro_received','forwarded_to_lpdd','step4_complete'],true)) {
        return ['Records must receive the complete FP documents before PNPKI_2 signing.'];
    }
    if (pnpki_bridge_link($id,$revision)) return ['Already forwarded. Use Check PNPKI Status.'];
    $source=hash('sha256','denr_fp_oms:payment_notice:'.$id.':'.$revision);
    try {
        // Send an unsigned clean copy for signing. The stored draft contains
        // a DRAFT watermark and MUST NOT be cryptographically signed.
        // Stable date preserves idempotent imports if the response is lost
        // and RED retries on a later day.
        $noticeDate=date('F d, Y',strtotime((string)$notice['created_at']));
        $pdf=FpomsPaymentNoticePdf::render(
            $app,fpoms_payment_notice_config(),null,$noticeDate,true);
        if (strlen($pdf)<100 || strlen($pdf)>10485760) {
            return ['Generated Template No. 2 is not a valid signing payload.'];
        }
        $result=pnpki_bridge_request([
            'action'=>'import','source_key'=>$source,'sha256'=>hash('sha256',$pdf),
            'pdf_base64'=>base64_encode($pdf),
        ]);
        $remote=(int)($result['doc_id']??0);
        if ($remote<1) return ['PNPKI_2 did not return a document reference.'];
        db()->prepare("INSERT INTO fpoms_pnpki_links
            (application_id,revision,notice_id,source_key,remote_doc_id,requested_by_user_id)
            VALUES (?,?,?,?,?,?)")->execute([
                $id,$revision,(int)$notice['id'],$source,$remote,(int)$red['id']
            ]);
        audit((int)$red['id'],'permit.pnpki.forwarded','permit_step2_application',$id,[
            'revision'=>$revision,'remote_doc_id'=>$remote
        ]);
        return [];
    } catch (Throwable $e) {
        error_log('FP-OMS PNPKI_2 import error: '.$e->getMessage());
        return [$e instanceof RuntimeException?$e->getMessage():
            'Unable to save the PNPKI_2 link. Verify schema and retry; remote import is idempotent.'];
    }
}

/**
 * Pull authoritative status and signed PDF from PNPKI_2.
 * Safe to retry after callback failure: DB locks and finalization are idempotent.
 */
function pnpki_bridge_reconcile(int $id): array
{
    if (!pnpki_bridge_schema_ready()) return ['Apply database migration 041 first.'];
    $app=fpoms_payment_notice_app($id);
    if (!$app) return ['FP application not found.'];
    $revision=(int)$app['revision'];
    $link=pnpki_bridge_link($id,$revision);
    if (!$link) return ['This notice was not forwarded to PNPKI_2.'];
    $notice=fpoms_payment_notice_record($id,$revision);
    if (!$notice || (int)$notice['id']!==(int)$link['notice_id']) {
        return ['Linked payment notice is unavailable.'];
    }
    if ((string)$notice['status']==='released'
        && (string)$link['stage']==='released_verified') return [];
    try {
        $result=pnpki_bridge_request([
            'action'=>'status','source_key'=>(string)$link['source_key'],
            'doc_id'=>(int)$link['remote_doc_id']
        ]);
        if ((int)($result['doc_id']??0)!==(int)$link['remote_doc_id']) {
            return ['PNPKI_2 document reference mismatch.'];
        }
        $stage=(string)($result['status']??'');
        $verificationMode=(string)($result['verification_mode']??'automated_pdfsig');
        $manualRecords=$verificationMode==='records_manual_attestation';
        if (!in_array($stage,['awaiting_red','awaiting_records','verification_required','released_verified'],true)) {
            return ['Unexpected PNPKI_2 document status.'];
        }
        if ($stage!=='released_verified') {
            db()->prepare('UPDATE fpoms_pnpki_links SET stage=? WHERE application_id=? AND revision=?')
                ->execute([$stage,$id,$revision]);
            return [];
        }
        $pdf=base64_decode((string)($result['pdf_base64']??''),true);
        if (!is_string($pdf) || strlen($pdf)<100 || strlen($pdf)>10485760
            || !str_starts_with($pdf,'%PDF-')
            || !preg_match('/%%EOF\s*$/D',substr($pdf,-2048))
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($result['sha256']??''))
            || !hash_equals((string)$result['sha256'],hash('sha256',$pdf))) {
            return ['Returned PNPKI_2 signed PDF failed integrity checks.'];
        }
        $red=query_one("SELECT * FROM users WHERE id=? AND user_kind='internal'",
            [(int)$link['requested_by_user_id']]);
        if (!$red || !has_role($red,'red')) return ['Original FP-OMS RED authorization is no longer valid.'];

        $pdo=db();
        $pdo->beginTransaction();
        $path=null;
        $importedNow=false;
        try {
            $locked=query_one('SELECT * FROM fpoms_payment_notices WHERE id=? FOR UPDATE',
                [(int)$notice['id']]);
            $bridge=query_one('SELECT * FROM fpoms_pnpki_links
                WHERE application_id=? AND revision=? FOR UPDATE',[$id,$revision]);
            if (!$locked || !$bridge || (int)$bridge['remote_doc_id']!==(int)$link['remote_doc_id']
                || !in_array((string)$locked['status'],['pending_red','signed','released'],true)) {
                throw new RuntimeException('Payment notice changed during PNPKI verification.');
            }
            if ((string)$locked['status']==='pending_red') {
                [$relative,$path]=fpoms_payment_notice_path($id);
                fpoms_file_write_encrypted_bytes($pdf,$path);
                $filename='PNPKI2_RED_Signed_Released_Template_No_2_FP'.$id.'_R'.$revision.'.pdf';
                $pdo->prepare("INSERT INTO permit_flow_artifacts
                    (application_id,step_number,application_revision,category,
                    original_name,stored_path,mime_type,file_size,sha256,uploaded_by)
                    VALUES (?,3,?,'red_signed_payment_notice',?,?,'application/pdf',?,?,?)")
                    ->execute([$id,$revision,$filename,$relative,strlen($pdf),
                        hash('sha256',$pdf),(int)$red['id']]);
                $artifactId=(int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE fpoms_payment_notices SET status='signed',
                    artifact_id=?,approved_by=?,approved_at=NOW() WHERE id=?")
                    ->execute([$artifactId,(int)$red['id'],(int)$locked['id']]);
                permit_flow_record_event($pdo,$id,3,$red,
                    $manualRecords?'payment_notice_records_released':'payment_notice_pnpki_verified',
                    'PNPKI_2 document #'.(int)$link['remote_doc_id']
                    .($manualRecords
                        ? ' final signed PDF uploaded and manually attested/released by Records; automated pdfsig verification was disabled.'
                        : ' digitally signed by RED, cryptographically verified and released by Records.'));
                $importedNow=true;
            } elseif ((string)$bridge['stage']!=='released_verified') {
                throw new RuntimeException('This notice was signed outside the active PNPKI_2 handoff.');
            }
            $pdo->prepare("UPDATE fpoms_pnpki_links SET stage='released_verified'
                WHERE application_id=? AND revision=?")->execute([$id,$revision]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (is_string($path) && is_file($path)) @unlink($path);
            throw $e;
        }
        if ($importedNow) {
            try {
                notify_role('red','PNPKI_2 Released – FP #'.$id,
                    'PNPKI_2 document #'.(int)$link['remote_doc_id']
                    .($manualRecords
                        ? ' has a Records-uploaded final signed PDF and was officially released by Records using manual attestation. '
                        : ' has been digitally signed by RED, cryptographically verified and officially released by Records. ')
                    .'FP-OMS is processing the applicant notice to pay.',
                    'shield-check','success','payment_notice_queue');
            } catch (Throwable $notifyError) {
                error_log('FP-OMS PNPKI_2 RED notification: '.$notifyError->getMessage());
            }
        }
        $latest=fpoms_payment_notice_record($id,$revision);
        if ($latest && (string)$latest['status']==='released') return [];
        // Existing, audited finalization sends in-system notice + email and
        // preserves the signed PDF. It is only called after verified Records release.
        return fpoms_payment_notice_finalize($red,$id);
    } catch (Throwable $e) {
        error_log('FP-OMS PNPKI_2 reconciliation error: '.$e->getMessage());
        return [$e instanceof RuntimeException?$e->getMessage():
            'PNPKI_2 release could not be synchronized. Try Check PNPKI Status again.'];
    }
}

function pnpki_bridge_callback(): never
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
        http_response_code(405);echo '{"error":"POST required"}';exit;
    }
    if (!pnpki_bridge_configured() || !pnpki_bridge_schema_ready()) {
        http_response_code(503);echo '{"error":"Bridge not configured"}';exit;
    }
    $body=file_get_contents('php://input',false,null,0,4097);
    $stamp=(string)($_SERVER['HTTP_X_FPOMS_TIMESTAMP']??'');
    $nonce=(string)($_SERVER['HTTP_X_FPOMS_NONCE']??'');
    $signature=(string)($_SERVER['HTTP_X_FPOMS_SIGNATURE']??'');
    if (!is_string($body) || strlen($body)>4096
        || !preg_match('/^[0-9]{10,12}$/D',$stamp)
        || abs(time()-(int)$stamp)>300
        || !preg_match('/^[a-f0-9]{32}$/D',$nonce)
        || !preg_match('/^[a-f0-9]{64}$/D',$signature)
        || !hash_equals(pnpki_bridge_signature($body,$stamp,$nonce),$signature)) {
        http_response_code(401);echo '{"error":"Unauthorized callback"}';exit;
    }
    try {
        $pdo=db();
        $pdo->prepare('INSERT INTO fpoms_pnpki_nonces (nonce) VALUES (?)')->execute([$nonce]);
        $payload=json_decode($body,true,8,JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['event']??'')!=='released'
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($payload['source_key']??''))
            || (int)($payload['doc_id']??0)<1) {
            http_response_code(400);echo '{"error":"Invalid release event"}';exit;
        }
        $link=query_one('SELECT application_id FROM fpoms_pnpki_links
            WHERE source_key=? AND remote_doc_id=?',
            [(string)$payload['source_key'],(int)$payload['doc_id']]);
        if (!$link) {http_response_code(404);echo '{"error":"Link not found"}';exit;}
        $errors=pnpki_bridge_reconcile((int)$link['application_id']);
        if ($errors) {
            http_response_code(409);
            echo json_encode(['error'=>'Release pending verification or synchronization'],JSON_THROW_ON_ERROR);
        } else {
            echo '{"ok":true}';
        }
    } catch (Throwable $e) {
        error_log('FP-OMS PNPKI callback failed: '.$e->getMessage());
        http_response_code(503);echo '{"error":"Callback not processed"}';
    }
    exit;
}
