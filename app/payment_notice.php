<?php
declare(strict_types=1);

function fpoms_payment_notice_schema_ready(): bool
{
    try { db()->query('SELECT id,status FROM fpoms_payment_notices LIMIT 0'); return true; }
    catch (Throwable $e) { return false; }
}
function fpoms_payment_notice_record_for_app(int $applicationId): ?array
{
    if (!fpoms_payment_notice_schema_ready()) return null;
    return query_one('SELECT * FROM fpoms_payment_notices WHERE application_id=? ORDER BY revision DESC,id DESC LIMIT 1',[$applicationId]);
}
function fpoms_payment_notice_record(int $applicationId,int $revision): ?array
{
    if (!fpoms_payment_notice_schema_ready()) return null;
    return query_one('SELECT * FROM fpoms_payment_notices WHERE application_id=? AND revision=?',[$applicationId,$revision]);
}
function fpoms_payment_notice_app(int $id): ?array
{
    return query_one('SELECT a.*,c.name AS applicant_name,c.email AS applicant_email,
        r.company_name,r.company_address,r.representative_position
        FROM permit_step2_applications a JOIN user_client c ON c.id=a.client_id
        LEFT JOIN applicant_registrations r ON r.client_id=a.client_id WHERE a.id=?',[$id]);
}
function fpoms_payment_notice_path(int $id): array
{
    $dir=PROJECT_ROOT.'/storage/fp_workflow/'.$id.'/3';
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Unable to prepare protected payment-notice storage.');
    $relative='fp_workflow/'.$id.'/3/'.bin2hex(random_bytes(20)).'.pdf';
    return [$relative,PROJECT_ROOT.'/storage/'.$relative];
}
function fpoms_payment_notice_config(): array
{
    $config=fpoms_notice_config();
    $config['letterhead_images']=fpoms_notice_template_branding($config);
    return $config;
}
function fpoms_payment_notice_prepare_on_ro_receipt(array $app,array $staff): array
{
    if (!permit_flow_is_new($app)) return [];
    if (!fpoms_payment_notice_schema_ready()) return ['Apply database migration 036.'];
    $id=(int)$app['id']; $revision=(int)$app['revision'];
    if (fpoms_payment_notice_record($id,$revision)) return [];
    $full=fpoms_payment_notice_app($id);
    if (!$full) return ['Application data is unavailable.'];
    try {
        $config=fpoms_payment_notice_config();
        $pdf=FpomsPaymentNoticePdf::render($full,$config,null,date('F d, Y'));
        [$relative,$absolute]=fpoms_payment_notice_path($id);
        fpoms_file_write_encrypted_bytes($pdf,$absolute);
        db()->prepare("INSERT INTO fpoms_payment_notices
            (application_id,revision,prepared_by,draft_path,draft_sha256,status)
            VALUES (?,?,?,?,?,'pending_red')")
            ->execute([$id,$revision,(int)$staff['id'],$relative,hash('sha256',$pdf)]);
        audit((int)$staff['id'],'fp.payment_notice.prepared','permit_step2_application',$id,['revision'=>$revision]);
        return [];
    } catch (Throwable $e) {
        if (isset($absolute) && is_file($absolute)) @unlink($absolute);
        error_log('FP-OMS Template No. 2 prepare failed: '.$e->getMessage());
        return ['Unable to generate Template No. 2.'];
    }
}
function fpoms_payment_notice_render_signed(array $app,string $signature): string
{
    return FpomsPaymentNoticePdf::render($app,fpoms_payment_notice_config(),$signature,date('F d, Y'));
}
function fpoms_payment_notice_red_approve(array $red,int $id,bool $signOnly=false): array
{
    if (($red['status']??'')!=='active' || !has_role($red,'red')) return ['Only RED may approve this notice.'];
    $app=fpoms_payment_notice_app($id);
    if (!$app || !permit_flow_is_new($app)) return ['FP application not found.'];
    $notice=fpoms_payment_notice_record($id,(int)$app['revision']);
    if (!$notice) return ['Template No. 2 has not been prepared by RO Receiving.'];
    $signed=(string)$notice['status']==='signed';
    if ($signOnly && $signed) return ['This notice is already signed.'];
    if (!$signOnly && !$signed) return ['Sign the notice first, then review and finalize it.'];
    if (!$signed) {
        $config=fpoms_notice_config();
        if (empty($config['signature_path']) || empty($config['signature_sha256'])) {
            return ['No RED e-sign JPEG configured. Use the physically signed PDF upload option.'];
        }
        try {
            $signature=fpoms_notice_safe_read((string)$config['signature_path'],(string)$config['signature_sha256']);
            $pdf=fpoms_payment_notice_render_signed($app,$signature);
            [$relative,$absolute]=fpoms_payment_notice_path($id);
            fpoms_file_write_encrypted_bytes($pdf,$absolute);
            $pdo=db(); $pdo->beginTransaction();
            $locked=query_one('SELECT * FROM fpoms_payment_notices WHERE application_id=? AND revision=? FOR UPDATE',[$id,(int)$app['revision']]);
            if (!$locked || (string)$locked['status']!=='pending_red') throw new RuntimeException('Notice changed; reload.');
            $filename='DENR_RED_Template_No_2_Complete_Docs_Advise_to_Pay_FP'.$id.'_R'.$app['revision'].'.pdf';
            $pdo->prepare('INSERT INTO permit_flow_artifacts
                (application_id,step_number,application_revision,category,original_name,stored_path,mime_type,file_size,sha256,uploaded_by)
                VALUES (?,3,?,"red_signed_payment_notice",?,?,"application/pdf",?,?,?)')
                ->execute([$id,(int)$app['revision'],$filename,$relative,strlen($pdf),hash('sha256',$pdf),(int)$red['id']]);
            $artifactId=(int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE fpoms_payment_notices SET status='signed',artifact_id=?,approved_by=?,approved_at=NOW()
                WHERE id=?")->execute([$artifactId,(int)$red['id'],(int)$locked['id']]);
            permit_flow_record_event($pdo,$id,3,$red,'payment_notice_red_signed','RED e-signed Template No. 2.');
            $pdo->commit();
            if ($signOnly) return [];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            if (isset($absolute) && is_file($absolute)) @unlink($absolute);
            error_log('FP-OMS Template No. 2 e-sign failed: '.$e->getMessage());
            return ['Unable to save RED e-signed Template No. 2.'];
        }
    }
    return fpoms_payment_notice_finalize($red,$id);
}
function fpoms_payment_notice_red_upload_signed(array $red,int $id,array $upload): array
{
    if (($red['status']??'')!=='active' || !has_role($red,'red')) return ['Only RED may upload a signed notice.'];
    if ((int)($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name']??''))) return ['Select the physically signed PDF.'];
    $size=(int)($upload['size']??0);
    if ($size<100 || $size>10485760 || strtolower(pathinfo((string)($upload['name']??''),PATHINFO_EXTENSION))!=='pdf') return ['Upload a valid PDF up to 10 MB.'];
    $pdf=file_get_contents((string)$upload['tmp_name']);
    if (!is_string($pdf) || !preg_match('/^%PDF-1\.[0-7]/',$pdf) || !preg_match('/%%EOF\s*$/',$pdf)) return ['The uploaded file is not a valid PDF.'];
    $app=fpoms_payment_notice_app($id); if (!$app) return ['Application not found.'];
    $notice=fpoms_payment_notice_record($id,(int)$app['revision']);
    if (!$notice || (string)$notice['status']!=='pending_red') return ['Only a pending Template No. 2 can receive a signed upload.'];
    try {
        [$relative,$absolute]=fpoms_payment_notice_path($id);
        fpoms_file_write_encrypted_bytes($pdf,$absolute);
        $pdo=db(); $pdo->beginTransaction();
        $locked=query_one('SELECT * FROM fpoms_payment_notices WHERE id=? FOR UPDATE',[(int)$notice['id']]);
        if (!$locked || (string)$locked['status']!=='pending_red') throw new RuntimeException('Notice changed; reload.');
        $filename='DENR_RED_Manually_Signed_Template_No_2_FP'.$id.'_R'.$app['revision'].'.pdf';
        $pdo->prepare('INSERT INTO permit_flow_artifacts
            (application_id,step_number,application_revision,category,original_name,stored_path,mime_type,file_size,sha256,uploaded_by)
            VALUES (?,3,?,"red_signed_payment_notice",?,?,"application/pdf",?,?,?)')
            ->execute([$id,(int)$app['revision'],$filename,$relative,$size,hash('sha256',$pdf),(int)$red['id']]);
        $artifactId=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE fpoms_payment_notices SET status='signed',artifact_id=?,approved_by=?,approved_at=NOW()
            WHERE id=?")->execute([$artifactId,(int)$red['id'],(int)$notice['id']]);
        permit_flow_record_event($pdo,$id,3,$red,'payment_notice_red_signed_upload','RED uploaded physically signed Template No. 2.');
        $pdo->commit(); return [];
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        if (isset($absolute) && is_file($absolute)) @unlink($absolute);
        return ['Unable to save signed Template No. 2.'];
    }
}
function fpoms_payment_notice_artifact(array $notice): ?array
{
    $artifactId=(int)($notice['artifact_id']??0);
    return $artifactId>0 ? query_one("SELECT * FROM permit_flow_artifacts WHERE id=? AND category='red_signed_payment_notice'",[$artifactId]) : null;
}
function fpoms_payment_notice_attachment(array $notice): array
{
    $artifact=fpoms_payment_notice_artifact($notice); if (!$artifact) return [];
    $path=PROJECT_ROOT.'/storage/'.(string)$artifact['stored_path'];
    if (!is_file($path)) return [];
    $data=fpoms_file_read($path);
    if (!hash_equals((string)$artifact['sha256'],hash('sha256',$data))) return [];
    return [['data'=>$data,'name'=>(string)$artifact['original_name'],'mime'=>'application/pdf']];
}
function fpoms_payment_notice_finalize(array $red,int $id): array
{
    $app=fpoms_payment_notice_app($id); if (!$app) return ['Application not found.'];
    $notice=fpoms_payment_notice_record($id,(int)$app['revision']);
    if (!$notice || (string)$notice['status']!=='signed') return ['A RED-signed Template No. 2 is required.'];
    $attachments=fpoms_payment_notice_attachment($notice);
    if (!$attachments) return ['Signed Template No. 2 is unavailable or failed integrity verification.'];
    try {
        $pdo=db(); $pdo->beginTransaction();
        $locked=query_one('SELECT * FROM fpoms_payment_notices WHERE id=? FOR UPDATE',[(int)$notice['id']]);
        if (!$locked || (string)$locked['status']!=='signed') throw new RuntimeException('Notice changed; reload.');
        $pdo->prepare("INSERT INTO permit_payment_verifications
            (application_id,status,notified_at) VALUES (?,'awaiting_payment',NOW())
            ON DUPLICATE KEY UPDATE status='awaiting_payment',notified_at=COALESCE(notified_at,VALUES(notified_at))")
            ->execute([$id]);
        $pdo->prepare("UPDATE fpoms_payment_notices SET status='released',released_by=?,released_at=NOW() WHERE id=?")
            ->execute([(int)$red['id'],(int)$notice['id']]);
        permit_flow_record_event($pdo,$id,3,$red,'payment_notice_released','Official RED-signed Template No. 2 released to applicant.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['Unable to finalize Template No. 2.'];
    }
    try {
        create_client_notification((int)$app['client_id'],'Official Notice to Pay Application Fee',
            'DENR CARAGA released the RED-signed Template No. 2 for FP application #'.$id.'. Pay the PHP 10,000.00 application fee at the Regional Office Cashier and submit the required hard copies.',
            'receipt','warning','permit_application',['id'=>$id]);
    } catch (Throwable $e) { error_log('Template No. 2 client notification failed: '.$e->getMessage()); }
    $sent=fpoms_payment_notice_send_email($app,$notice,$attachments);
    try {
        db()->prepare("UPDATE fpoms_payment_notices SET email_status=?,email_last_attempt_at=NOW(),
            email_sent_at=CASE WHEN ?='sent' THEN NOW() ELSE email_sent_at END WHERE id=?")
            ->execute([$sent?'sent':'failed',$sent?'sent':'failed',(int)$notice['id']]);
    } catch (Throwable $e) { error_log('Template No. 2 email status save failed: '.$e->getMessage()); }
    return [];
}
function fpoms_payment_notice_send_email(array $app,array $notice,array $attachments): bool
{
    $url=permit_flow_application_email_url((int)$app['id']);
    $message='DENR CARAGA has released the official RED-signed Template No. 2 confirming that your Feasibility Permit documentary requirements were found complete and meritorious. Please submit the required hard copies and pay the PHP 10,000.00 application fee at the DENR CARAGA Regional Office Cashier.';
    return permit_flow_email($app,'Official RED-Signed Notice to Pay – FP Application #'.$app['id'],
        $message,$url,$attachments,'Open FP Application');
}
function fpoms_payment_notice_retry_email(array $staff,int $id): array
{
    if (($staff['user_kind']??'')!=='internal' || ($staff['status']??'')!=='active') return ['Authorized DENR staff required.'];
    $app=fpoms_payment_notice_app($id); if (!$app) return ['Application not found.'];
    $notice=fpoms_payment_notice_record($id,(int)$app['revision']);
    if (!$notice || (string)$notice['status']!=='released') return ['Only a released notice can be emailed.'];
    $attachments=fpoms_payment_notice_attachment($notice);
    if (!$attachments) return ['Signed PDF attachment is unavailable.'];
    if (!fpoms_payment_notice_send_email($app,$notice,$attachments)) return ['Email delivery failed. Check SMTP settings.'];
    db()->prepare("UPDATE fpoms_payment_notices SET email_status='sent',email_last_attempt_at=NOW(),email_sent_at=NOW() WHERE id=?")->execute([(int)$notice['id']]);
    return [];
}
function fpoms_payment_notice_send_pdf(array $viewer,int $id): never
{
    $app=fpoms_payment_notice_app($id); if (!$app) show_error('Notice not found.',404);
    $notice=fpoms_payment_notice_record($id,(int)$app['revision']); if (!$notice) show_error('Notice not found.',404);
    $isRed=($viewer['user_kind']??'')==='internal' && has_role($viewer,'red');
    $mine=($viewer['user_kind']??'')==='applicant' && (int)$viewer['id']===(int)$app['client_id'];
    $signed=(string)$notice['status']!=='pending_red';
    if (!$isRed && !($mine && (string)$notice['status']==='released')) show_error('Notice not available.',403);
    $raw=null;
    if ($signed) {
        $attachment=fpoms_payment_notice_attachment($notice);
        $raw=$attachment[0]['data']??null;
    } else {
        $raw=fpoms_notice_safe_read((string)$notice['draft_path'],(string)$notice['draft_sha256']);
        if ($isRed && (string)($_GET['signed_preview']??'')==='1') {
            $config=fpoms_notice_config();
            if (!empty($config['signature_path']) && !empty($config['signature_sha256'])) {
                $signature=fpoms_notice_safe_read((string)$config['signature_path'],(string)$config['signature_sha256']);
                $raw=fpoms_payment_notice_render_signed($app,$signature);
            }
        }
    }
    if (!is_string($raw)) show_error('Notice PDF unavailable.',404);
    header('Content-Type: application/pdf'); header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="Template_No_2_FP'.$id.'.pdf"');
    echo $raw; exit;
}
function fpoms_payment_notice_queue_view(array $red): void
{
    if (!fpoms_payment_notice_schema_ready()) {
        render_page('RED Payment Notice Approvals','<div class="alert alert-warning">Apply database migration 036 first.</div>'); return;
    }
    $rows=query_all("SELECT n.*,a.project_title,c.name AS applicant_name FROM fpoms_payment_notices n
        JOIN permit_step2_applications a ON a.id=n.application_id AND a.revision=n.revision
        JOIN user_client c ON c.id=a.client_id
        WHERE n.status IN ('pending_red','signed') ORDER BY n.created_at ASC LIMIT 100");
    $config=fpoms_notice_config(); $canEsign=!empty($config['signature_path'])&&!empty($config['signature_sha256']);
    $html='<div class="panel"><h2 class="h5">RED – Template No. 2 Payment Notice Approvals</h2>'
        .'<p class="text-secondary">Review the Complete Documents / Advise to Pay letter, apply the RED electronic signature or upload a physically signed PDF, then review and Finalize & Send.</p>';
    if (!$rows) $html.='<div class="alert alert-success">No Template No. 2 notices awaiting RED action.</div>';
    foreach($rows as $row) {
        $id=(int)$row['application_id']; $signed=(string)$row['status']==='signed';
        $preview=$signed ? url('payment_notice_pdf',['id'=>$id]) : url('payment_notice_pdf',['id'=>$id]+($canEsign?['signed_preview'=>1]:[]));
        $draft=url('payment_notice_pdf',['id'=>$id]);
        $html.='<div class="border rounded p-3 mb-3"><strong>FP #'.$id.' · '.e((string)$row['project_title']).'</strong>'
            .'<div class="small text-secondary">'.e((string)$row['applicant_name']).' · Revision '.(int)$row['revision'].'</div>'
            .'<div class="d-flex flex-wrap gap-2 mt-2"><a class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener" href="'.e($preview).'">'
            .'<i class="bi bi-file-earmark-pdf me-1"></i>'.($signed?'View Signed PDF':($canEsign?'Preview with RED e-Signature':'Preview Unsigned Draft')).'</a>'
            .(!$signed?'<a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" href="'.e($draft).'"><i class="bi bi-printer me-1"></i>Open / Print Draft for Signing</a>':'')
            .(($signed||$canEsign)?'<form method="post" action="'.e(url('payment_notice_approve',['id'=>$id])).'">'.csrf_field()
                .'<input type="hidden" name="confirmation" value="1"><input type="hidden" name="action" value="'.($signed?'finalize':'sign').'">'
                .'<button class="btn btn-success btn-sm" type="submit">'.($signed?'Finalize & Send':'Approve E-Sign').'</button></form>':'')
            .'</div>'
            .(!$signed?'<form class="mt-3 border-top pt-3" method="post" enctype="multipart/form-data" action="'.e(url('payment_notice_upload_signed',['id'=>$id])).'">'
                .csrf_field().'<label class="form-label fw-semibold">Upload physically signed PDF</label>'
                .'<input class="form-control form-control-sm mb-2" type="file" name="signed_pdf" accept=".pdf,application/pdf" required>'
                .'<label class="small d-flex gap-2"><input type="checkbox" class="form-check-input" name="confirmation" value="1" required>'
                .'<span>I confirm this is the RED-signed Template No. 2 for FP #'.$id.'.</span></label>'
                .'<button class="btn btn-outline-success btn-sm mt-2" type="submit"><i class="bi bi-upload me-1"></i>Upload Signed PDF</button></form>':'')
            .'</div>';
    }
    render_page('RED Payment Notice Approvals',$html.'</div>');
}
