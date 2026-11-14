<?php
declare(strict_types=1);

/**
 * Isolated Template No. 3 signing workflow. Payment Notice (Template No. 2)
 * remains in fpoms_pnpki_links; the two document types are never conflated.
 */
function cenro_memo_schema_ready(): bool
{
    static $ready=null;
    if ($ready!==null) return $ready;
    try {
        db()->query('SELECT source_key,stage,remote_doc_id FROM fpoms_cenro_memo_signings LIMIT 0');
        return $ready=true;
    } catch (Throwable $e) {
        return $ready=false;
    }
}
function cenro_memo_record(int $id,int $revision): ?array
{
    if (!cenro_memo_schema_ready()) return null;
    return query_one('SELECT * FROM fpoms_cenro_memo_signings WHERE application_id=? AND revision=?',[$id,$revision]);
}
function cenro_memo_release_required(int $id,int $revision): bool
{
    return cenro_memo_record($id,$revision)!==null;
}
function cenro_memo_is_released(int $id,int $revision): bool
{
    $memo=cenro_memo_record($id,$revision);
    return $memo && $memo['stage']==='released_verified' && (int)$memo['released_artifact_id']>0;
}
function cenro_memo_path(int $id): array
{
    $folder=PROJECT_ROOT.'/storage/fp_cenro_memos/'.$id;
    if (!is_dir($folder) && !mkdir($folder,0700,true) && !is_dir($folder)) {
        throw new RuntimeException('Private memorandum storage is unavailable.');
    }
    $relative='fp_cenro_memos/'.$id.'/'.bin2hex(random_bytes(20)).'.pdf';
    return [$relative,PROJECT_ROOT.'/storage/'.$relative];
}
function cenro_memo_safe_pdf(string $relative,string $digest): ?string
{
    if (!preg_match('#^fp_cenro_memos/[0-9]+/[a-f0-9]{40}\.pdf$#D',$relative)) return null;
    $root=realpath(PROJECT_ROOT.'/storage/fp_cenro_memos');
    $path=realpath(PROJECT_ROOT.'/storage/'.$relative);
    if (!$root || !$path || !str_starts_with($path,$root.DIRECTORY_SEPARATOR)) return null;
    try {
        $raw=fpoms_file_read($path);
        return is_string($raw) && hash_equals($digest,hash('sha256',$raw)) ? $raw : null;
    } catch (Throwable $e) {
        error_log('CENRO memorandum secure read failed: '.$e->getMessage());
        return null;
    }
}
function cenro_memo_active_case(int $id): bool
{
    $app=step2_application($id);
    $case=permit_flow_case($id);
    return $app && permit_flow_is_new($app) && $case && (int)$case['current_step']===4
        && $case['completed_at']===null && (string)$app['status']==='forwarded_to_lpdd';
}
/** Attach the latest communicated schedule to generated memorandum copies. */
function cenro_memo_with_schedule(array $app): array
{
    $schedule=permit_flow_schedule_details((int)$app['id']);
    if (!$schedule) return $app;
    $daily=json_decode((string)($schedule['field_validation_daily_times']??''),true);
    $summary=is_array($daily)?permit_flow_daily_schedule_summary($daily):'';
    if ($summary==='') {
        $summary=fpoms_display_datetime((string)$schedule['field_validation_schedule'])
            .(!empty($schedule['field_validation_schedule_end'])
                ? ' to '.fpoms_display_datetime((string)$schedule['field_validation_schedule_end']) : '');
    }
    $app['_field_schedule']=$summary;
    $app['_field_venue']=(string)$schedule['field_validation_location'];
    return $app;
}
function cenro_memo_start(array $staff,int $id): array
{
    if (!permit_flow_staff_can_act($staff,4)) return ['LPDD-FUS authorization required.'];
    if (!cenro_memo_schema_ready()) return ['Apply database migration 044 first.'];
    if (!pnpki_authorized_upload_ready() || !pnpki_bridge_configured()) {
        return ['Configure PNPKI_2 and migrations 041/042 before requesting digital signature.'];
    }
    if (!cenro_memo_active_case($id)) return ['Template No. 3 is available only at active Step 4.'];
    $app=fpoms_payment_notice_app($id);
    if (!$app) return ['Application details unavailable.'];
    if (!fpoms_payment_notice_is_released($id,(int)$app['revision'])
        || !permit_payment_is_verified($id)
        || !permit_flow_schedule_communicated($id)) {
        return ['Release Template No. 2, verify Cashier OR, and communicate the schedule first.'];
    }
    $revision=(int)$app['revision'];
    if (cenro_memo_record($id,$revision)) return [];
    $relative=null;$absolute=null;
    try {
        // Immutable clean PDF: RED signs this exact PDF, not the on-screen DRAFT.
        $pdf=FpomsCenroMemoPdf::render(cenro_memo_with_schedule($app),fpoms_payment_notice_config(),date('F d, Y'),true);
        if (strlen($pdf)<100 || strlen($pdf)>10485760) return ['Generated Template No. 3 PDF is invalid.'];
        [$relative,$absolute]=cenro_memo_path($id);
        fpoms_file_write_encrypted_bytes($pdf,$absolute);
        $source=hash('sha256','denr_fp_oms:cenro_memo:'.$id.':'.$revision.':fpoms_signed');
        db()->prepare("INSERT INTO fpoms_cenro_memo_signings
            (application_id,revision,prepared_by,draft_path,draft_sha256,source_key)
            VALUES (?,?,?,?,?,?)")->execute([
                $id,$revision,(int)$staff['id'],$relative,hash('sha256',$pdf),$source
            ]);
        audit((int)$staff['id'],'permit.cenro_memo.pnpki_requested','permit_step2_application',$id,
            ['revision'=>$revision]);
        notify_role('red','Template No. 3 – RED PNPKI Signature Required (FP #'.$id.')',
            'LPDD-FUS prepared the CENRO field-validation memorandum. Review and sign the Template No. 3 PDF in FP-OMS.',
            'file-signature','warning','permit_flow',['id'=>$id]);
        return [];
    } catch (Throwable $e) {
        if ($absolute && is_file($absolute) && !cenro_memo_record($id,$revision)) @unlink($absolute);
        error_log('FP-OMS CENRO memo prepare: '.$e->getMessage());
        return ['Unable to prepare Template No. 3. Verify migration 044 and storage permissions.'];
    }
}
function cenro_memo_preview(array $viewer,int $id,bool $clean=false): never
{
    $app=fpoms_payment_notice_app($id);
    if (!$app || !permit_flow_is_new($app) || !permit_flow_staff_can_view($viewer)) {
        show_error('Memorandum unavailable.',403);
    }
    $memo=cenro_memo_record($id,(int)$app['revision']);
    if ($clean && (!$memo || !pnpki_authorized_red($viewer))) {
        show_error('Only authorized RED may access the unsigned signing copy.',403);
    }
    $raw=$clean
        ? cenro_memo_safe_pdf((string)$memo['draft_path'],(string)$memo['draft_sha256'])
        : FpomsCenroMemoPdf::render(cenro_memo_with_schedule($app),fpoms_payment_notice_config());
    if (!is_string($raw)) show_error('Memorandum is unavailable.',404);
    $embedded=!$clean && (string)($_GET['embed']??'')==='1';
    if ($embedded) {
        header_remove('X-Frame-Options');
        header_remove('Content-Security-Policy');
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: '.($clean?'attachment':'inline').'; filename="FPOMS_Template_No_3_FP_'.$id.'.pdf"');
    header('Cache-Control: private,no-store,max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: '.strlen($raw));
    echo $raw;exit;
}
function cenro_memo_upload(array $red,int $id,array $upload,string $draftHash,?string $agentBytes=null): array
{
    if (!pnpki_authorized_red($red) || !pnpki_authorization_for((int)$red['id'])) {
        return ['Authorize your RED PNPKI_2 account first.'];
    }
    if (!cenro_memo_active_case($id)) return ['Application is no longer in Step 4.'];
    $app=step2_application($id);
    $memo=$app?cenro_memo_record($id,(int)$app['revision']):null;
    if (!$memo || $memo['stage']!=='pending_red' || (int)($memo['remote_doc_id']??0)>0) {
        return ['Template No. 3 must be pending RED signing; Records document already linked.'];
    }
    if (!hash_equals((string)$memo['draft_sha256'],$draftHash)) {
        return ['The memorandum changed. Refresh and download the latest copy before signing.'];
    }
    if ($agentBytes===null && ((int)($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK
        || !is_uploaded_file((string)($upload['tmp_name']??'')))) {
        return ['Select the RED PNPKI-signed Template No. 3 PDF.'];
    }
    $size=$agentBytes!==null?strlen($agentBytes):(int)($upload['size']??0);
    $name=$agentBytes!==null?'PNPKI_Desktop_Signed_Template_No_3.pdf':(string)($upload['name']??'');
    if ($size<100 || $size>10485760 || strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf') {
        return ['PDF only, maximum 10 MB.'];
    }
    $raw=$agentBytes??file_get_contents((string)$upload['tmp_name']);
    $mime=is_string($raw)?(new finfo(FILEINFO_MIME_TYPE))->buffer($raw):'';
    if (!is_string($raw) || strlen($raw)!==$size || $mime!=='application/pdf'
        || !str_starts_with($raw,'%PDF-') || !preg_match('/%%EOF\s*$/D',substr($raw,-2048))) {
        return ['Invalid signed PDF.'];
    }
    $absolute=null;
    try {
        [$relative,$absolute]=cenro_memo_path($id);
        fpoms_file_write_encrypted_bytes($raw,$absolute);
        db()->prepare("UPDATE fpoms_cenro_memo_signings SET
            signed_path=?,signed_sha256=?,signed_size=?,signed_by=?
            WHERE application_id=? AND revision=? AND stage='pending_red' AND remote_doc_id IS NULL AND draft_sha256=?")
            ->execute([$relative,hash('sha256',$raw),strlen($raw),(int)$red['id'],
                $id,(int)$app['revision'],$draftHash]);
        if ((int)db()->query('SELECT ROW_COUNT()')->fetchColumn()!==1) {
            @unlink($absolute);return ['Memo state changed; refresh before retrying.'];
        }
        audit((int)$red['id'],'permit.cenro_memo.signed_uploaded','permit_step2_application',$id,
            ['revision'=>(int)$app['revision'],'sha256'=>hash('sha256',$raw)]);
    } catch(Throwable $e) {
        if ($absolute && is_file($absolute)) @unlink($absolute);
        error_log('FP-OMS Template 3 signed upload: '.$e->getMessage());
        return ['Unable to save signed PDF securely.'];
    }
    return cenro_memo_submit($red,$id);
}
function cenro_memo_submit(array $red,int $id): array
{
    if (!pnpki_authorized_red($red)) return ['RED authorization required.'];
    $app=fpoms_payment_notice_app($id);
    $memo=$app?cenro_memo_record($id,(int)$app['revision']):null;
    $auth=pnpki_authorization_for((int)$red['id']);
    if (!$memo || !$auth || (int)($memo['signed_by']??0)!==(int)$red['id']
        || (string)$memo['stage']!=='pending_red') {
        return ['No matching saved RED-signed memorandum is awaiting submission.'];
    }
    if ((int)($memo['remote_doc_id']??0)>0) return [];
    $raw=cenro_memo_safe_pdf((string)($memo['signed_path']??''),(string)($memo['signed_sha256']??''));
    if (!is_string($raw) || strlen($raw)!==(int)$memo['signed_size']) {
        return ['Stored signed PDF failed integrity verification.'];
    }
    try {
        $response=pnpki_bridge_request([
            'action'=>'import_signed','document_kind'=>'cenro_memo',
            'application_id'=>$id,
            'project_title'=>substr((string)($app['project_title']??''),0,130),
            'source_key'=>(string)$memo['source_key'],
            'fpoms_user_id'=>(int)$red['id'],
            'remote_user_id'=>(int)$auth['remote_user_id'],
            'sha256'=>hash('sha256',$raw),'pdf_base64'=>base64_encode($raw)
        ]);
        $remote=(int)($response['doc_id']??0);
        if ($remote<1) return ['PNPKI_2 returned an invalid document reference.'];
        db()->prepare("UPDATE fpoms_cenro_memo_signings SET
            remote_doc_id=?,stage='awaiting_records'
            WHERE application_id=? AND revision=? AND remote_doc_id IS NULL")
            ->execute([$remote,$id,(int)$app['revision']]);
        audit((int)$red['id'],'permit.cenro_memo.forwarded_to_records','permit_step2_application',
            $id,['remote_doc_id'=>$remote]);
        return [];
    } catch (Throwable $e) {
        error_log('CENRO memo PNPKI submission: '.$e->getMessage());
        return ['Unable to forward signed Template No. 3. Your encrypted upload is saved; retry submission.'];
    }
}
function cenro_memo_reconcile(int $id): array
{
    if (!cenro_memo_schema_ready()) return ['Migration 044 required.'];
    $app=step2_application($id);
    $memo=$app?cenro_memo_record($id,(int)$app['revision']):null;
    if (!$memo || (int)($memo['remote_doc_id']??0)<1) return ['No PNPKI_2 document linked to this memorandum.'];
    if ($memo['stage']==='released_verified' && (int)$memo['released_artifact_id']>0) return [];
    try {
        $result=pnpki_bridge_request([
            'action'=>'status','source_key'=>(string)$memo['source_key'],
            'doc_id'=>(int)$memo['remote_doc_id']
        ]);
        if ((int)($result['doc_id']??0)!==(int)$memo['remote_doc_id']) {
            return ['PNPKI_2 document reference mismatch.'];
        }
        $stage=(string)($result['status']??'');
        if (!in_array($stage,['awaiting_red','awaiting_records','verification_required','released_verified'],true)) {
            return ['Unexpected PNPKI_2 release state.'];
        }
        if ($stage!=='released_verified') {
            db()->prepare('UPDATE fpoms_cenro_memo_signings SET stage=?
                WHERE application_id=? AND revision=? AND released_artifact_id IS NULL')
                ->execute([$stage,$id,(int)$app['revision']]);
            return [];
        }
        $pdf=base64_decode((string)($result['pdf_base64']??''),true);
        if (!is_string($pdf) || strlen($pdf)<100 || strlen($pdf)>10485760
            || !str_starts_with($pdf,'%PDF-') || !preg_match('/%%EOF\s*$/D',substr($pdf,-2048))
            || !hash_equals((string)($result['sha256']??''),hash('sha256',$pdf))) {
            return ['Released PDF failed SHA-256 and PDF checks.'];
        }
        $red=query_one("SELECT * FROM users WHERE id=? AND user_kind='internal'",
            [(int)$memo['signed_by']]);
        if (!$red || !has_role($red,'red')) return ['RED authorization is no longer valid.'];
        $pdo=db();
        $absolute=null;
        $pdo->beginTransaction();
        try {
            $locked=query_one('SELECT * FROM fpoms_cenro_memo_signings
                WHERE application_id=? AND revision=? FOR UPDATE',[$id,(int)$app['revision']]);
            if (!$locked || (int)$locked['remote_doc_id']!==(int)$memo['remote_doc_id']) {
                throw new RuntimeException('Memorandum state changed during release.');
            }
            if (!$locked['released_artifact_id']) {
                [$relative,$absolute]=cenro_memo_path($id);
                fpoms_file_write_encrypted_bytes($pdf,$absolute);
                $pdo->prepare("INSERT INTO permit_flow_artifacts
                    (application_id,step_number,application_revision,category,original_name,
                    stored_path,mime_type,file_size,sha256,uploaded_by)
                    VALUES (?,4,?,'cenro_memo',? ,?,'application/pdf',?,?,?)")
                    ->execute([$id,(int)$app['revision'],
                        'RED_PNPKI2_Records_Released_Template_No_3_FP'.$id.'.pdf',
                        $relative,strlen($pdf),hash('sha256',$pdf),(int)$red['id']]);
                $artifact=(int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE fpoms_cenro_memo_signings SET
                    stage='released_verified',released_artifact_id=?,released_at=NOW()
                    WHERE application_id=? AND revision=?")->execute([$artifact,$id,(int)$app['revision']]);
                permit_flow_record_event($pdo,$id,4,$red,'cenro_memo_records_released',
                    'RED PNPKI-signed Template No. 3 released by PNPKI_2 Records, document #'.(int)$memo['remote_doc_id']);
            }
            $pdo->commit();
        } catch(Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($absolute && is_file($absolute)) @unlink($absolute);
            throw $e;
        }
        $office=trim((string)($app['concerned_cenro']??''));
        if ($office==='') $office=trim((string)($app['concerned_penro']??''));
        foreach(['lpdd_fus','cenro_twg','penro_user'] as $role) {
            try {
                notify_role($role,'RED-Signed CENRO Memorandum Released – FP #'.$id,
                    'Official Template No. 3 for '.($office!==''?$office:'the concerned field office')
                    .' has been released by PNPKI_2 Records. Open the FP-OMS workflow to view the signed PDF.',
                    'file-earmark-check','success','permit_flow',['id'=>$id]);
            } catch(Throwable $e) {error_log('CENRO memo notification: '.$e->getMessage());}
        }
        return [];
    } catch(Throwable $e) {
        error_log('FP-OMS CENRO memo release reconciliation: '.$e->getMessage());
        return ['PNPKI_2 release could not be synchronized. Retry Check PNPKI Status.'];
    }
}
function cenro_memo_sso_url(array $red,int $id): string
{
    if (!pnpki_authorized_red($red)) throw new RuntimeException('RED authorization required.');
    $app=step2_application($id);
    $memo=$app?cenro_memo_record($id,(int)$app['revision']):null;
    $auth=pnpki_authorization_for((int)$red['id']);
    if (!$memo || !$auth || (int)$memo['signed_by']!==(int)$red['id']
        || (int)$memo['remote_doc_id']<1) throw new RuntimeException('No authorized memorandum link.');
    $result=pnpki_bridge_request([
        'action'=>'sso_ticket','fpoms_user_id'=>(int)$red['id'],
        'remote_user_id'=>(int)$auth['remote_user_id'],
        'doc_id'=>(int)$memo['remote_doc_id']
    ]);
    $ticket=(string)($result['ticket']??'');
    if (!preg_match('/^[a-f0-9]{64}$/D',$ticket)) throw new RuntimeException('Invalid PNPKI_2 access ticket.');
    $base=(string)fpoms_pnpki_effective_config()['url'];
    return substr($base,0,strrpos($base,'/')+1).'fpoms_sso.php?ticket='.$ticket;
}
function cenro_memo_panel_html(array $app,array $user): string
{
    $id=(int)$app['id'];$rev=(int)$app['revision'];
    $memo=cenro_memo_record($id,$rev);
    $canAct=permit_flow_staff_can_act($user,4);
    $isRed=pnpki_authorized_red($user);
    $previewModalId='cenro-memo-preview-'.$id;
    $previewTitleId=$previewModalId.'-title';
    $previewUrl=url('permit_cenro_memo_pdf',['id'=>$id,'embed'=>1]);
    $html='<section class="border rounded-3 p-3 mb-3">'
        .'<h4 class="h6"><i class="bi bi-file-earmark-text me-1"></i>Template No. 3 – Memo Instruction to CENRO</h4>'
        .'<p class="small text-secondary">Official DENR Caraga memorandum to PENRO (Attention: CENRO). '
        .'PNPKI signing is optional, but once requested, only the Records-released final PDF can complete Step 4.</p>'
        .'<button class="btn btn-outline-secondary btn-sm mb-3" type="button" '
        .'data-bs-toggle="modal" data-bs-target="#'.e($previewModalId).'">'
        .'<i class="bi bi-eye me-1"></i>Preview Official Template No. 3</button>'
        .'<div class="modal fade" id="'.e($previewModalId).'" tabindex="-1" '
        .'aria-labelledby="'.e($previewTitleId).'" aria-hidden="true">'
        .'<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">'
        .'<div class="modal-content"><div class="modal-header">'
        .'<h5 class="modal-title" id="'.e($previewTitleId).'">Official Template No. 3 Preview</h5>'
        .'<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close preview"></button>'
        .'</div><div class="modal-body p-0"><div class="ratio ratio-4x3 bg-body-secondary">'
        .'<iframe class="border-0" src="'.e($previewUrl).'" loading="lazy" '
        .'title="Official Template No. 3 PDF preview"></iframe></div></div>'
        .'<div class="modal-footer"><button type="button" class="btn btn-secondary" '
        .'data-bs-dismiss="modal">Close</button></div></div></div></div>';
    if (!cenro_memo_schema_ready()) {
        return $html.'<div class="alert alert-warning">Apply database migration 044 to enable RED PNPKI for this memo.</div></section>';
    }
    if (!$memo) {
        if ($canAct) {
            $requestIssues=[];
            $scheduleMissing=!permit_flow_schedule_communicated($id);
            if (!pnpki_bridge_configured() || !pnpki_authorized_upload_ready()) {
                $requestIssues[]='PNPKI_2 integration must be configured and available';
            }
            if (!fpoms_payment_notice_is_released($id,$rev)) {
                $requestIssues[]='Template No. 2 must be officially released';
            }
            if (!permit_payment_is_verified($id)) {
                $requestIssues[]='Cashier Official Receipt must be verified';
            }
            if ($scheduleMissing) {
                $requestIssues[]='field-validation schedule must be saved and communicated';
            }
            if ($requestIssues) {
                $html.='<div class="toast-container position-fixed top-0 end-0 p-3 mt-5">'
                    .'<div class="toast show border-warning" role="alert" aria-live="assertive" '
                    .'aria-atomic="true" data-bs-autohide="false" data-cenro-memo-prerequisite'
                    .($scheduleMissing?' data-scroll-target="field-validation-schedule-form"':'').'>'
                    .'<div class="toast-header text-bg-warning">'
                    .'<i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>'
                    .'<strong class="me-auto">RED PNPKI prerequisite</strong>'
                    .'<button type="button" class="btn-close" data-bs-dismiss="toast" '
                    .'aria-label="Close notification"></button></div>'
                    .'<div class="toast-body"><strong>Before requesting RED PNPKI:</strong> '
                    .e(implode('; ',$requestIssues)).'. The request action will recheck these requirements.'
                    .($scheduleMissing?'<div><button type="button" class="btn btn-warning btn-sm mt-2" '
                        .'data-scroll-to-prerequisite><i class="bi bi-arrow-down-circle me-1" aria-hidden="true"></i>'
                        .'Go to Schedule Form</button></div>':'')
                    .'</div></div></div>';
            }
            $html.='<form method="post" action="'.e(url('permit_cenro_memo',['id'=>$id])).'">'
                .csrf_field().'<input type="hidden" name="action" value="request">'
                .'<button type="submit" class="btn btn-primary btn-sm">'
                .'Request RED PNPKI Signature</button></form>';
        }
        return $html.'<div class="small text-secondary mt-2">Or use the existing manual memorandum PDF upload below.</div></section>';
    }
    $state=match((string)$memo['stage']){
        'pending_red'=>'Awaiting RED PNPKI-signed PDF',
        'awaiting_records'=>'Signed memo received by PNPKI_2 Records; awaiting official release',
        'verification_required'=>'PNPKI_2 requires signature verification or Records final upload',
        'released_verified'=>'RED-signed Template No. 3 officially released by Records',
        default=>'Pending'
    };
    $html.='<div class="alert '.(cenro_memo_is_released($id,$rev)?'alert-success':'alert-info').' small">'.e($state).'</div>';
    if (cenro_memo_is_released($id,$rev)) {
        return $html.'<p class="small">Signed PDF is available in Required Documents below. '
            .'LPDD-FUS may now complete Step 4 when the other requirements are satisfied.</p></section>';
    }
    if ($isRed && $memo['stage']==='pending_red') {
        $html.='<a class="btn btn-outline-primary btn-sm mb-3" href="'
            .e(url('permit_cenro_memo_pdf',['id'=>$id,'print_for_signing'=>1]))
            .'" download><i class="bi bi-download me-1"></i>Download Clean Template No. 3 for PNPKI Signing</a>';
        if (!pnpki_authorization_for((int)$user['id'])) {
            $html.='<div class="alert alert-warning small">Connect your RED PNPKI_2 account '
                .'to sign this memorandum. Your PNPKI password and private key remain outside FP-OMS.</div>'
                .'<form method="post" action="'.e(url('permit_cenro_memo',['id'=>$id])).'">'
                .csrf_field().'<input type="hidden" name="action" value="authorize">'
                .'<button class="btn btn-outline-primary btn-sm" type="submit">'
                .'Authorize PNPKI_2 RED Account</button></form>';
        } else {
            $html.='<form method="post" enctype="multipart/form-data" class="mb-3" '
                .'action="'.e(url('permit_cenro_memo',['id'=>$id])).'">'
                .csrf_field().'<input type="hidden" name="action" value="upload">'
                .'<input type="hidden" name="draft_sha256" value="'.e((string)$memo['draft_sha256']).'">'
                .'<p class="small text-warning">Always download and sign the latest copy after a schedule update.</p>'
                .'<label class="form-label fw-semibold">PNPKI Sign – Upload RED-Signed Template No. 3 PDF</label>'
                .'<input class="form-control mb-2" type="file" name="signed_pdf" accept=".pdf,application/pdf" required>'
                .'<button class="btn btn-success btn-sm" type="submit">Upload &amp; Forward to PNPKI_2 Records</button>'
                .'</form>';
            if (!empty($memo['signed_path'])) {
                $html.='<form method="post" action="'.e(url('permit_cenro_memo',['id'=>$id])).'">'
                    .csrf_field().'<input type="hidden" name="action" value="retry">'
                    .'<button class="btn btn-outline-primary btn-sm" type="submit">'
                    .'Retry Saved Signed PDF Submission</button></form>';
            }
        }
    }
    if ((int)($memo['remote_doc_id']??0)>0) {
        $html.='<p class="small">PNPKI_2 Document #'.(int)$memo['remote_doc_id'].'</p>';
        if ($isRed && (int)$memo['signed_by']===(int)$user['id']) {
            $html.='<form class="d-inline-block me-2" method="post" action="'
                .e(url('permit_cenro_memo',['id'=>$id])).'">'
                .csrf_field().'<input type="hidden" name="action" value="open">'
                .'<button class="btn btn-outline-primary btn-sm" type="submit">'
                .'Open PNPKI_2 (Authorized)</button></form>';
        }
        if ($canAct || $isRed) {
            $html.='<form class="d-inline-block" method="post" action="'
                .e(url('permit_cenro_memo',['id'=>$id])).'">'
                .csrf_field().'<input type="hidden" name="action" value="check">'
                .'<button class="btn btn-outline-success btn-sm" type="submit">'
                .'Check PNPKI Records Release</button></form>';
        }
    }
    return $html.'</section>';
}
