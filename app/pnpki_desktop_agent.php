<?php
declare(strict_types=1);

/**
 * Opt-in RED workstation signing pilot.
 * The .p12 certificate and its passphrase MUST remain on the RED workstation.
 * A paired, expiring token only authorizes access to that RED's pending jobs.
 * No unauthenticated signer, automatic signature, or Records release exists here.
 */
function pnpki_agent_schema_ready(): bool
{
    try {
        db()->query('SELECT enabled FROM fpoms_desktop_agent_settings LIMIT 0');
        db()->query('SELECT token_sha256 FROM fpoms_desktop_agent_tokens LIMIT 0');
        db()->query('SELECT draft_sha256,status FROM fpoms_desktop_agent_jobs LIMIT 0');
        return true;
    } catch (Throwable $e) { return false; }
}

function pnpki_agent_enabled(): bool
{
    if (!pnpki_agent_schema_ready()) return false;
    return (int)(query_one('SELECT enabled FROM fpoms_desktop_agent_settings WHERE id=1')['enabled']??0)===1;
}

function pnpki_agent_secure_request(): bool
{
    $https=strtolower((string)($_SERVER['HTTPS']??''));
    if ($https==='on' || $https==='1') return true;
    if (strtolower((string)($_SERVER['REQUEST_SCHEME']??''))==='https') return true;
    if (env_value('FPOMS_DESKTOP_AGENT_ALLOW_LOCAL_HTTP','false')!=='true') return false;
    $host=strtolower(trim((string)($_SERVER['HTTP_HOST']??'')));
    return in_array($host,['localhost','127.0.0.1','localhost:80','127.0.0.1:80',
        'localhost:8080','127.0.0.1:8080'],true);
}

function pnpki_agent_update_enabled(array $admin,bool $enabled): array
{
    if (!has_role($admin,'super_admin')) return ['Super Administrator required.'];
    if (!pnpki_agent_schema_ready()) return ['Apply migration 046 first.'];
    try {
        $pdo=db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE fpoms_desktop_agent_settings SET enabled=?,updated_by=? WHERE id=1')
            ->execute([$enabled?1:0,(int)$admin['id']]);
        if (!$enabled) {
            // Disable immediately invalidates every active workstation session.
            $pdo->exec('DELETE FROM fpoms_desktop_agent_tokens');
            $pdo->exec("UPDATE fpoms_desktop_agent_jobs SET status='cancelled' WHERE status='pending'");
        }
        audit((int)$admin['id'],$enabled?'permit.pnpki_agent.enabled':'permit.pnpki_agent.disabled',
            'settings',1);
        $pdo->commit();
        return [];
    } catch(Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('PNPKI desktop agent switch failed: '.$e->getMessage());
        return ['Unable to change Desktop PNPKI Agent settings.'];
    }
}

function pnpki_agent_issue_token(array $red): array
{
    if (!pnpki_authorized_red($red) || !pnpki_authorization_for((int)$red['id']))
        return ['error'=>'Authorize your RED PNPKI_2 account first.'];
    if (!pnpki_agent_enabled()) return ['error'=>'Desktop PNPKI Signing is disabled by Super Admin.'];
    if (!pnpki_agent_secure_request()) return ['error'=>'Pair only over HTTPS (or explicitly enabled localhost testing).'];
    $secret=bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO fpoms_desktop_agent_tokens(user_id,token_sha256,expires_at)
        VALUES (?,?,DATE_ADD(NOW(), INTERVAL 8 HOUR))
        ON DUPLICATE KEY UPDATE token_sha256=VALUES(token_sha256),
        expires_at=VALUES(expires_at),issued_at=CURRENT_TIMESTAMP')
        ->execute([(int)$red['id'],hash('sha256',$secret)]);
    audit((int)$red['id'],'permit.pnpki_agent.paired','user',(int)$red['id']);
    return ['token'=>$secret];
}

function pnpki_agent_revoke_token(array $red): void
{
    if (!pnpki_authorized_red($red)) return;
    db()->prepare('DELETE FROM fpoms_desktop_agent_tokens WHERE user_id=?')
        ->execute([(int)$red['id']]);
    audit((int)$red['id'],'permit.pnpki_agent.unpaired','user',(int)$red['id']);
}

function pnpki_agent_red_from_bearer(): ?array
{
    if (!pnpki_agent_enabled() || !pnpki_agent_secure_request()) return null;
    $authorization=(string)($_SERVER['HTTP_AUTHORIZATION']
        ??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$match)) return null;
    $row=query_one('SELECT user_id FROM fpoms_desktop_agent_tokens
        WHERE token_sha256=? AND expires_at>NOW()', [hash('sha256',$match[1])]);
    if (!$row) return null;
    $user=user_by_id((int)$row['user_id']);
    return $user && pnpki_authorized_red($user)
        && pnpki_authorization_for((int)$user['id']) ? $user : null;
}

/**
 * Exact unsigned signing copy. Always compute and compare the live draft hash
 * so schedule edits, revisions, and already signed documents cannot be signed.
 */
function pnpki_agent_draft(string $kind,int $id,int $revision): ?string
{
    $app=fpoms_payment_notice_app($id);
    if (!$app || (int)$app['revision']!==$revision || !permit_flow_is_new($app)) return null;
    if ($kind==='cenro_memo') {
        if (!cenro_memo_active_case($id)) return null;
        $memo=cenro_memo_record($id,$revision);
        if (!$memo || (string)$memo['stage']!=='pending_red'
            || !empty($memo['signed_path']) || (int)($memo['remote_doc_id']??0)>0) return null;
        return cenro_memo_safe_pdf((string)$memo['draft_path'],(string)$memo['draft_sha256']);
    }
    if ($kind==='payment_notice') {
        $notice=fpoms_payment_notice_record($id,$revision);
        if (!$notice || (string)$notice['status']!=='pending_red'
            || !in_array((string)$app['status'],['ro_received','forwarded_to_lpdd','step4_complete'],true)
            || pnpki_bridge_link($id,$revision) || pnpki_pending_signed_upload($id,$revision)) return null;
        $noticeDate=date('F d, Y',strtotime((string)$notice['created_at']));
        return FpomsPaymentNoticePdf::render($app,fpoms_payment_notice_config(),null,$noticeDate,true);
    }
    return null;
}

function pnpki_agent_queue(array $red,string $kind,int $id): array
{
    if (!pnpki_authorized_red($red) || !pnpki_authorization_for((int)$red['id']))
        return ['Only a connected RED account may queue signing jobs.'];
    if (!pnpki_agent_enabled()) return ['Desktop PNPKI Signing is OFF.'];
    if (!in_array($kind,['cenro_memo','payment_notice'],true)) return ['Unsupported signing document.'];
    $app=fpoms_payment_notice_app($id);
    if (!$app) return ['Application not found.'];
    $revision=(int)$app['revision'];
    $draft=pnpki_agent_draft($kind,$id,$revision);
    if (!is_string($draft) || strlen($draft)<100 || strlen($draft)>10485760)
        return ['Signing PDF unavailable. Check the current document status and requirements.'];
    $sha=hash('sha256',$draft);
    try {
        $pdo=db();$pdo->beginTransaction();
        $existing=query_one('SELECT * FROM fpoms_desktop_agent_jobs
            WHERE application_id=? AND revision=? AND document_kind=? FOR UPDATE',
            [$id,$revision,$kind]);
        if ($existing && $existing['status']==='completed') {
            $pdo->rollBack(); return ['This document has already been submitted by the desktop agent.'];
        }
        if ($existing && $existing['status']==='pending'
            && ((int)$existing['user_id']!==(int)$red['id']
                || !hash_equals((string)$existing['draft_sha256'],$sha))) {
            $pdo->rollBack(); return ['A different RED workstation or document revision is queued. Revoke or cancel first.'];
        }
        if ($existing) {
            $pdo->prepare("UPDATE fpoms_desktop_agent_jobs SET
                user_id=?,draft_sha256=?,status='pending',completed_at=NULL,created_at=NOW()
                WHERE id=?")->execute([(int)$red['id'],$sha,(int)$existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO fpoms_desktop_agent_jobs
                (application_id,revision,document_kind,user_id,draft_sha256)
                VALUES (?,?,?,?,?)")->execute([$id,$revision,$kind,(int)$red['id'],$sha]);
        }
        audit((int)$red['id'],'permit.pnpki_agent.job_queued',
            'permit_step2_application',$id,['revision'=>$revision,'kind'=>$kind]);
        $pdo->commit();
        return [];
    } catch(Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('PNPKI desktop queue: '.$e->getMessage());
        return ['Unable to queue the signing request.'];
    }
}

function pnpki_agent_cancel(array $red,int $id,string $kind): array
{
    if (!pnpki_authorized_red($red)) return ['RED account required.'];
    db()->prepare("UPDATE fpoms_desktop_agent_jobs SET status='cancelled'
        WHERE application_id=? AND document_kind=? AND user_id=? AND status='pending'")
        ->execute([$id,$kind,(int)$red['id']]);
    audit((int)$red['id'],'permit.pnpki_agent.job_cancelled',
        'permit_step2_application',$id,['kind'=>$kind]);
    return [];
}

function pnpki_agent_current_job(array $red,int $jobId): ?array
{
    if ($jobId<1) return null;
    return query_one("SELECT * FROM fpoms_desktop_agent_jobs
        WHERE id=? AND user_id=? AND status='pending' AND created_at>DATE_SUB(NOW(),INTERVAL 24 HOUR)",
        [$jobId,(int)$red['id']]);
}

function pnpki_agent_job_draft(array $job): ?string
{
    $draft=pnpki_agent_draft((string)$job['document_kind'],
        (int)$job['application_id'],(int)$job['revision']);
    return is_string($draft) && hash_equals((string)$job['draft_sha256'],hash('sha256',$draft))
        ? $draft : null;
}

/**
 * Reject replaced PDFs. pyHanko signs the exact unsigned PDF by appending a
 * PDF incremental update. This content check does NOT replace cryptographic
 * verification at PNPKI_2 before Records release.
 */
function pnpki_agent_signed_pdf_matches(string $unsigned,string $signed): bool
{
    return strlen($signed)>strlen($unsigned)+200
        && strlen($signed)<=10485760
        && strncmp($signed,$unsigned,strlen($unsigned))===0
        && str_contains($signed,'/ByteRange')
        && str_contains($signed,'/Contents')
        && preg_match('/%%EOF\s*$/D',substr($signed,-2048))===1;
}

function pnpki_agent_json(array $data,int $code=200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data,JSON_THROW_ON_ERROR);exit;
}

function pnpki_agent_api(string $method,string $action): never
{
    header('Cache-Control: no-store');
    if (!pnpki_agent_secure_request()) pnpki_agent_json(['error'=>'HTTPS required.'],403);
    $red=pnpki_agent_red_from_bearer();
    if (!$red) pnpki_agent_json(['error'=>'Invalid, expired or revoked workstation token.'],401);
    if ($method==='GET' && $action==='next') {
        $rows=query_all("SELECT * FROM fpoms_desktop_agent_jobs
            WHERE user_id=? AND status='pending'
            AND created_at>DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY created_at ASC LIMIT 10",[(int)$red['id']]);
        foreach ($rows as $job) {
            if (pnpki_agent_job_draft($job)===null) continue;
            pnpki_agent_json(['job'=>[
                'id'=>(int)$job['id'],'application_id'=>(int)$job['application_id'],
                'kind'=>(string)$job['document_kind'],
                'sha256'=>(string)$job['draft_sha256'],
                'description'=>($job['document_kind']==='cenro_memo'
                    ? 'Template No. 3 - Memo to CENRO' : 'Template No. 2 - Letter Applicant Complete')
            ]]);
        }
        pnpki_agent_json(['job'=>null]);
    }
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $job=$id?pnpki_agent_current_job($red,(int)$id):null;
    if (!$job) pnpki_agent_json(['error'=>'Signing job not found or no longer pending.'],404);
    $draft=pnpki_agent_job_draft($job);
    if (!is_string($draft)) pnpki_agent_json(['error'=>'Signing document changed; requeue current version.'],409);
    if ($method==='GET' && $action==='draft') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="PNPKI-signing-job-'.(int)$id.'.pdf"');
        header('X-Document-SHA256: '.hash('sha256',$draft));
        header('Content-Length: '.strlen($draft));
        echo $draft;exit;
    }
    if ($method!=='POST' || $action!=='submit') pnpki_agent_json(['error'=>'Unsupported operation.'],405);
    if (strtolower((string)($_SERVER['CONTENT_TYPE']??''))!=='application/pdf')
        pnpki_agent_json(['error'=>'Expected a signed application/pdf payload.'],415);
    $stream=fopen('php://input','rb');
    $signed=$stream?stream_get_contents($stream,10485761):false;
    if (is_resource($stream)) fclose($stream);
    if (!is_string($signed) || !pnpki_agent_signed_pdf_matches($draft,$signed))
        pnpki_agent_json(['error'=>'Signed PDF must preserve the exact unsigned document and contain a PDF digital signature.'],422);
    // Re-check the kill switch after potentially slow PDF transfer.
    if (!pnpki_agent_enabled() || !pnpki_agent_current_job($red,(int)$job['id'])
        || !pnpki_agent_red_from_bearer()) {
        pnpki_agent_json(['error'=>'Workstation authorization or pending job was revoked before submission.'],409);
    }
    if ($job['document_kind']==='cenro_memo') {
        $errors=cenro_memo_upload($red,(int)$job['application_id'],
            [],(string)$job['draft_sha256'],$signed);
    } elseif ($job['document_kind']==='payment_notice') {
        $errors=pnpki_upload_signed_pdf($red,(int)$job['application_id'],[],$signed);
    } else $errors=['Unsupported document kind.'];
    if ($errors) pnpki_agent_json(['error'=>implode(' ',array_map('strval',$errors))],422);
    db()->prepare("UPDATE fpoms_desktop_agent_jobs SET status='completed',completed_at=NOW()
        WHERE id=? AND user_id=? AND status='pending'")->execute([(int)$job['id'],(int)$red['id']]);
    audit((int)$red['id'],'permit.pnpki_agent.signed_forwarded',
        'permit_step2_application',(int)$job['application_id'],
        ['kind'=>$job['document_kind'],'signed_sha256'=>hash('sha256',$signed)]);
    pnpki_agent_json(['ok'=>true,'message'=>'Signed PDF forwarded to PNPKI_2 Records; official release is pending.']);
}

function pnpki_agent_controls_html(array $red,int $id,string $kind): string
{
    if (!pnpki_agent_enabled() || !pnpki_authorized_red($red)
        || !pnpki_authorization_for((int)$red['id'])) return '';
    $app=step2_application($id);
    if (!$app) return '';
    $job=query_one("SELECT status,user_id FROM fpoms_desktop_agent_jobs
        WHERE application_id=? AND revision=? AND document_kind=?",
        [$id,(int)$app['revision'],$kind]);
    if ($job && (string)$job['status']==='pending') {
        return '<div class="alert alert-info small">Desktop signing request is pending. '
            .'Open your paired RED workstation agent and personally approve the signature. '
            .'<form class="d-inline" method="post" action="'.e(url('pnpki_desktop_agent')).'">'
            .csrf_field().'<input type="hidden" name="action" value="cancel">'
            .'<input type="hidden" name="id" value="'.$id.'">'
            .'<input type="hidden" name="kind" value="'.e($kind).'">'
            .'<button class="btn btn-outline-secondary btn-sm ms-2"'
            .((int)$job['user_id']===(int)$red['id']?'':' disabled')
            .'>Cancel Agent Request</button></form></div>';
    }
    if (pnpki_agent_draft($kind,$id,(int)$app['revision'])===null) return '';
    return '<div class="border rounded p-2 my-3">'
        .'<p class="small mb-2"><strong>Optional: Sign without manual download/upload</strong> '
        .'(Desktop Agent pilot). The RED must approve each signature locally.</p>'
        .'<form method="post" action="'.e(url('pnpki_desktop_agent')).'">'
        .csrf_field().'<input type="hidden" name="action" value="queue">'
        .'<input type="hidden" name="id" value="'.$id.'">'
        .'<input type="hidden" name="kind" value="'.e($kind).'">'
        .'<button class="btn btn-outline-success btn-sm">Queue for Desktop PNPKI Signing</button> '
        .'<a href="'.e(url('pnpki_desktop_agent')).'">Pair RED Workstation</a>'
        .'</form></div>';
}

function pnpki_agent_settings_view(array $user): void
{
    $admin=has_role($user,'super_admin');
    $red=pnpki_authorized_red($user);
    if (!$admin && !$red) show_error('Authorized RED or Super Administrator required.',403);
    $ready=pnpki_agent_schema_ready();
    $enabled=$ready && pnpki_agent_enabled();
    $body='<h2 class="h5">Desktop PNPKI Signing Agent – Reversible Pilot</h2>'
        .'<p class="small">OFF by default. The existing manual PDF signing and Records release remain available. '
        .'No RED certificate or password is stored in FP-OMS.</p>';
    if (!$ready) $body.='<div class="alert alert-warning">Apply migration '
        .'<code>database/046_pnpki_desktop_agent.sql</code> first. No existing application records are changed.</div>';
    if ($admin && $ready) {
        $body.='<form method="post" action="'.e(url('pnpki_desktop_agent')).'">'
            .csrf_field().'<input type="hidden" name="action" value="setting">'
            .'<label class="form-check form-switch"><input type="checkbox" class="form-check-input" '
            .'name="enabled" value="1" '.($enabled?'checked':'').'>'
            .'<span class="form-check-label">Enable Desktop PNPKI Agent (pilot)</span></label>'
            .'<button class="btn btn-primary btn-sm mt-2">Save Pilot Setting</button></form>'
            .'<p class="small text-secondary">Turning OFF immediately revokes all device tokens and cancels '
            .'pending agent jobs. Already signed PDFs and audit logs remain untouched.</p>';
    }
    if ($red && $enabled) {
        $token=(string)($_SESSION['fpoms_agent_token_once']??'');
        unset($_SESSION['fpoms_agent_token_once']);
        if ($token!=='') {
            $body.='<div class="alert alert-warning" role="status"><strong>One-time RED workstation token '
                .'(visible only now):</strong><code class="d-block text-break user-select-all">'
                .e($token).'</code><p class="small mb-0">Copy this token to the RED workstation agent. '
                .'Expires in 8 hours. Never share or place in source code.</p></div>';
        }
        $body.='<form class="d-inline-block me-2" method="post" action="'.e(url('pnpki_desktop_agent')).'">'
            .csrf_field().'<input type="hidden" name="action" value="pair">'
            .'<button class="btn btn-primary btn-sm">Issue / Rotate Workstation Token</button></form>'
            .'<form class="d-inline-block" method="post" action="'.e(url('pnpki_desktop_agent')).'">'
            .csrf_field().'<input type="hidden" name="action" value="revoke">'
            .'<button class="btn btn-outline-danger btn-sm">Disconnect Workstation</button></form>';
    }
    $body.='<p class="small mt-4">Install the workstation script '
        .'<code>desktop_agent/agent.py</code> with <code>pyHanko</code>. '
        .'Use HTTPS for production. Pair an authorized RED account. '
        .'The RED must manually confirm and enter the P12 certificate passphrase for every PDF. '
        .'Once the signed PDF returns, PNPKI_2 Records must still verify and release it.</p>';
    render_page('Desktop PNPKI Agent',$body);
}
