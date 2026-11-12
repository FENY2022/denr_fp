<?php
declare(strict_types=1);

function fpoms_payment_notice_install_schema(): bool
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS fpoms_payment_notices (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          application_id BIGINT UNSIGNED NOT NULL,
          revision INT UNSIGNED NOT NULL,
          prepared_by BIGINT UNSIGNED NOT NULL,
          draft_path VARCHAR(400) NOT NULL,
          draft_sha256 CHAR(64) NOT NULL,
          status ENUM('pending_red','signed','released') NOT NULL DEFAULT 'pending_red',
          artifact_id BIGINT UNSIGNED NULL,
          approved_by BIGINT UNSIGNED NULL,
          approved_at DATETIME NULL,
          released_by BIGINT UNSIGNED NULL,
          released_at DATETIME NULL,
          applicant_viewed_at DATETIME NULL,
          email_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
          email_last_attempt_at DATETIME NULL,
          email_sent_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_payment_notice_revision (application_id,revision),
          KEY idx_payment_notice_status (status,created_at),
          CONSTRAINT fk_payment_notice_app FOREIGN KEY (application_id)
            REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
          CONSTRAINT fk_payment_notice_prepared_by FOREIGN KEY (prepared_by)
            REFERENCES users(id) ON DELETE RESTRICT,
          CONSTRAINT fk_payment_notice_approved_by FOREIGN KEY (approved_by)
            REFERENCES users(id) ON DELETE RESTRICT,
          CONSTRAINT fk_payment_notice_released_by FOREIGN KEY (released_by)
            REFERENCES users(id) ON DELETE RESTRICT,
          CONSTRAINT fk_payment_notice_artifact FOREIGN KEY (artifact_id)
            REFERENCES permit_flow_artifacts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return true;
    } catch (Throwable $e) {
        error_log('FP-OMS Template No. 2 schema initialization failed: '.$e->getMessage());
        return false;
    }
}
function fpoms_payment_notice_schema_ready(): bool
{
    static $ready=null;
    if ($ready!==null) return $ready;
    try {
        db()->query('SELECT id,status FROM fpoms_payment_notices LIMIT 0');
        return $ready=true;
    } catch (Throwable $e) {
        return $ready=fpoms_payment_notice_install_schema();
    }
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
function fpoms_payment_notice_applicant_view_tracking_ready(): bool
{
    static $ready=null;
    if ($ready!==null) return $ready;
    try {
        db()->query('SELECT applicant_viewed_at FROM fpoms_payment_notices LIMIT 0');
        return $ready=true;
    } catch (Throwable $e) {
        return $ready=false;
    }
}
function fpoms_payment_notice_is_unread_for_applicant(int $applicationId,int $revision): bool
{
    if (!fpoms_payment_notice_applicant_view_tracking_ready()) return false;
    $notice=fpoms_payment_notice_record($applicationId,$revision);
    return $notice && (string)$notice['status']==='released'
        && (int)($notice['artifact_id']??0)>0
        && ($notice['applicant_viewed_at']??null)===null;
}
function fpoms_payment_notice_mark_applicant_viewed(int $noticeId): void
{
    if (!fpoms_payment_notice_applicant_view_tracking_ready()) return;
    try {
        db()->prepare('UPDATE fpoms_payment_notices '
            .'SET applicant_viewed_at=COALESCE(applicant_viewed_at,NOW()) WHERE id=?')
            ->execute([$noticeId]);
    } catch (Throwable $e) {
        error_log('FP-OMS payment notice read receipt failed: '.$e->getMessage());
    }
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
        @chmod($absolute,0600);
        $pdo=db();
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO fpoms_payment_notices
            (application_id,revision,prepared_by,draft_path,draft_sha256,status)
            VALUES (?,?,?,?,?,'pending_red')")
            ->execute([$id,$revision,(int)$staff['id'],$relative,hash('sha256',$pdf)]);
        permit_flow_record_event($pdo,$id,3,$staff,'payment_notice_prepared',
            'Template No. 2 generated after RO receipt; applicant release withheld pending RED signature.');
        $pdo->commit();
        return [];
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        if (isset($absolute) && is_file($absolute)) @unlink($absolute);
        error_log('FP-OMS Template No. 2 prepare failed: '.$e->getMessage());
        return ['Unable to generate Template No. 2.'];
    }
}
function fpoms_payment_notice_is_released(int $applicationId,int $revision): bool
{
    $notice=fpoms_payment_notice_record($applicationId,$revision);
    return $notice && (string)$notice['status']==='released' && (int)($notice['artifact_id']??0)>0;
}

function fpoms_payment_notice_notify_pending(array $app,int $id): void
{
    try {
        notify_role('red','Template No. 2 for RED Signature – FP #'.$id,
            'RO Receiving recorded the complete electronic documents. Review and sign the Complete Documents / Advise to Pay letter before applicant release.',
            'cash-coin','warning','payment_notice_queue');
    } catch (Throwable $e) {
        error_log('FP-OMS Template No. 2 RED notification failed: '.$e->getMessage());
    }
    try {
        create_client_notification((int)$app['client_id'],
            'RO Documents Received – Awaiting RED-Signed Notice',
            'RO Receiving recorded your complete FP documents. The official Template No. 2 advice-to-pay letter is awaiting RED signature and final release. Payment instructions will be released with the signed notice.',
            'file-earmark-lock','info','permit_application',['id'=>$id]);
    } catch (Throwable $e) {
        error_log('FP-OMS Template No. 2 applicant status notification failed: '.$e->getMessage());
    }
}

function fpoms_payment_notice_prepare_existing(array $staff,int $id): array
{
    if (($staff['user_kind']??'')!=='internal' || ($staff['status']??'')!=='active'
        || !has_role($staff,'ro_receiving')) {
        return ['Only RO Receiving/Records can generate this notice.'];
    }
    $app=fpoms_payment_notice_app($id);
    if (!$app || !permit_flow_is_new($app)) return ['Updated Annex A application not found.'];
    if (!in_array((string)$app['status'],['ro_received','forwarded_to_lpdd','step4_complete'],true)) {
        return ['Template No. 2 can be generated only after RO receipt of complete documents.'];
    }
    $movement=step3_movement($id);
    if (!$movement || empty($movement['received_at'])) return ['RO document receipt has not been recorded.'];
    if (fpoms_payment_notice_record($id,(int)$app['revision'])) {
        return ['Template No. 2 already exists for this application revision.'];
    }
    $errors=fpoms_payment_notice_prepare_on_ro_receipt($app,$staff);
    if (!$errors) fpoms_payment_notice_notify_pending($app,$id);
    return $errors;
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
    $link=pnpki_bridge_link($id,(int)$app['revision']);
    $pending=pnpki_pending_signed_upload($id,(int)$app['revision']);
    if ($pending && !$link) return ['PNPKI signed PDF was saved; retry forwarding to Records instead of using E-Sign.'];
    if ($link && (string)$link['stage']!=='released_verified') {
        return ['Notice already forwarded to PNPKI_2. Use Check PNPKI Status rather than parallel signing.'];
    }
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
    $currentApp=fpoms_payment_notice_app($id);
    $link=$currentApp?pnpki_bridge_link($id,(int)$currentApp['revision']):null;
    $pending=$currentApp?pnpki_pending_signed_upload($id,(int)$currentApp['revision']):null;
    if ($pending && !$link) return ['PNPKI signed PDF was saved; retry forwarding to Records instead of uploading a different signed PDF.'];
    if ($link && (string)$link['stage']!=='released_verified') {
        return ['Notice already forwarded to PNPKI_2. Wait for Records release instead of uploading another PDF.'];
    }
    if ((int)($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name']??''))) return ['Select the physically signed PDF.'];
    $size=(int)($upload['size']??0);
    if ($size<100 || $size>10485760 || strtolower(pathinfo((string)($upload['name']??''),PATHINFO_EXTENSION))!=='pdf') return ['Upload a valid PDF up to 10 MB.'];
    $pdf=file_get_contents((string)$upload['tmp_name']);
    $mime=is_string($pdf) ? (new finfo(FILEINFO_MIME_TYPE))->buffer($pdf) : '';
    if (!is_string($pdf) || strlen($pdf)!==$size
        || !preg_match('/^%PDF-1\.[0-7]/',$pdf)
        || !preg_match('/%%EOF\s*$/',$pdf)
        || $mime!=='application/pdf') return ['The uploaded file is not a valid PDF.'];
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
function fpoms_payment_notice_released_artifact_ids(int $id): array
{
    if ($id<1 || !fpoms_payment_notice_schema_ready()) return [];
    $rows=query_all("SELECT artifact_id FROM fpoms_payment_notices
        WHERE application_id=? AND status='released' AND artifact_id IS NOT NULL",[$id]);
    return array_map('intval',array_column($rows,'artifact_id'));
}
function fpoms_payment_notice_artifact(array $notice): ?array
{
    $artifactId=(int)($notice['artifact_id']??0);
    $applicationId=(int)($notice['application_id']??0);
    return $artifactId>0 && $applicationId>0
        ? query_one("SELECT * FROM permit_flow_artifacts
            WHERE id=? AND application_id=? AND category='red_signed_payment_notice'",
            [$artifactId,$applicationId])
        : null;
}
function fpoms_payment_notice_attachment(array $notice): array
{
    $artifact=fpoms_payment_notice_artifact($notice); if (!$artifact) return [];
    $base=realpath(PROJECT_ROOT.'/storage/fp_workflow');
    $path=realpath(PROJECT_ROOT.'/storage/'.(string)$artifact['stored_path']);
    if (!$base || !$path || !str_starts_with($path,$base.DIRECTORY_SEPARATOR) || !is_file($path)) return [];
    try {
        $data=fpoms_file_read($path);
    } catch (Throwable $e) {
        error_log('FP-OMS Template No. 2 protected PDF read failed: '.$e->getMessage());
        return [];
    }
    if (!hash_equals((string)$artifact['sha256'],hash('sha256',$data))) return [];
    return [['data'=>$data,'name'=>(string)$artifact['original_name'],'mime'=>'application/pdf']];
}
function fpoms_payment_notice_finalize(array $red,int $id): array
{
    $app=fpoms_payment_notice_app($id); if (!$app) return ['Application not found.'];
    $notice=fpoms_payment_notice_record($id,(int)$app['revision']);
    if (!$notice || (string)$notice['status']!=='signed') return ['A RED-signed Template No. 2 is required.'];
    if (!in_array((string)$app['status'],['ro_received','forwarded_to_lpdd','step4_complete'],true)) {
        return ['Application is no longer in a stage where Template No. 2 may be released.'];
    }
    $attachments=fpoms_payment_notice_attachment($notice);
    if (!$attachments) return ['Signed Template No. 2 is unavailable or failed integrity verification.'];
    try {
        $pdo=db(); $pdo->beginTransaction();
        $lockedApp=query_one('SELECT status,revision FROM permit_step2_applications WHERE id=? FOR UPDATE',[$id]);
        $locked=query_one('SELECT * FROM fpoms_payment_notices WHERE id=? FOR UPDATE',[(int)$notice['id']]);
        if (!$lockedApp || (int)$lockedApp['revision']!==(int)$app['revision']
            || !in_array((string)$lockedApp['status'],['ro_received','forwarded_to_lpdd','step4_complete'],true)
            || !$locked || (string)$locked['status']!=='signed') {
            throw new RuntimeException('Application or notice changed; reload.');
        }
        $pdo->prepare("INSERT INTO permit_payment_verifications
            (application_id,status,notified_at) VALUES (?,'awaiting_payment',NOW())
            ON DUPLICATE KEY UPDATE notified_at=COALESCE(notified_at,VALUES(notified_at))")
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
        notify_role('lpdd_fus','FP Payment Notice Released – FP #'.$id,
            'RED released the signed Template No. 2. The applicant was advised to pay at the Regional Office Cashier; LPDD-FUS should verify the Official Receipt after payment.',
            'receipt','info','lpdd_fus_payment_queue',['tab'=>'awaiting']);
    } catch (Throwable $e) { error_log('Template No. 2 release notification failed: '.$e->getMessage()); }
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
    if (($staff['user_kind']??'')!=='internal' || ($staff['status']??'')!=='active'
        || !(has_role($staff,'red') || has_role($staff,'ro_receiving')
            || has_role($staff,'lpdd_fus') || has_role($staff,'super_admin'))) {
        return ['Authorized DENR staff required.'];
    }
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
    $isRed=($viewer['user_kind']??'')==='internal'
        && ($viewer['status']??'')==='active' && has_role($viewer,'red');
    $mine=($viewer['user_kind']??'')==='applicant' && (int)$viewer['id']===(int)$app['client_id'];
    $signed=(string)$notice['status']!=='pending_red';
    $printForSigning=(string)($_GET['print_for_signing']??'')==='1';
    if (!$isRed && !($mine && (string)$notice['status']==='released')) show_error('Notice not available.',403);
    if ($printForSigning && (!$isRed || $signed)) show_error('Print-for-signing copy is unavailable.',403);
    $raw=null;
    if ($signed) {
        $attachment=fpoms_payment_notice_attachment($notice);
        $raw=$attachment[0]['data']??null;
    } else {
        if ($printForSigning) {
            $raw=FpomsPaymentNoticePdf::render(
                $app,fpoms_payment_notice_config(),null,date('F d, Y'),true
            );
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
    }
    if (!is_string($raw)) show_error('Notice PDF unavailable.',404);
    if ($mine && (string)$notice['status']==='released') {
        fpoms_payment_notice_mark_applicant_viewed((int)$notice['id']);
    }
    $embedded=(string)($_GET['embed']??'')==='1';
    if ($embedded) {
        header_remove('X-Frame-Options');
        header_remove('Content-Security-Policy');
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
    }
    header('Content-Type: application/pdf'); header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="'
        .($printForSigning?'Template_No_2_Print_For_Signing_FP'.$id.'.pdf':'Template_No_2_FP'.$id.'.pdf').'"');
    echo $raw; exit;
}
function fpoms_payment_notice_queue_view(array $red): void
{
    if (!fpoms_payment_notice_schema_ready()) {
        render_page('RED Payment Notice Approvals',
            '<div class="alert alert-warning">Template No. 2 storage could not initialize. Check database CREATE TABLE permission.</div>');
        return;
    }
    $rows=query_all("SELECT n.*,a.project_title,c.name AS applicant_name FROM fpoms_payment_notices n
        JOIN permit_step2_applications a ON a.id=n.application_id AND a.revision=n.revision
        JOIN user_client c ON c.id=a.client_id
        WHERE n.status IN ('pending_red','signed') ORDER BY n.created_at ASC LIMIT 100");
    $config=fpoms_notice_config();
    $canEsign=!empty($config['signature_path'])&&!empty($config['signature_sha256']);
    $pnpkiReady=pnpki_bridge_configured() && pnpki_authorized_upload_ready();
    $pnpkiAuth=$pnpkiReady?pnpki_authorization_for((int)$red['id']):null;
    $html='<div class="panel"><h2 class="h5">RED – Template No. 2 Payment Notice Approvals</h2>'
        .'<p class="text-secondary">Review the Complete Documents / Advise to Pay letter, apply the RED electronic signature '
        .'or upload a physically signed PDF, review the saved signed PDF, then Finalize &amp; Send to the applicant.</p>'
        .'<p class="small text-secondary border-start border-primary border-3 ps-3">'
        .'<strong>PNPKI_2:</strong> Authorize RED account once &rarr; upload digitally signed '
        .'Template No. 2 in FP-OMS &rarr; automatically route to PNPKI_2 Records &rarr; '
        .'Records officially releases &rarr; FP-OMS confirms the release and notifies the applicant to pay. '
        .'No payment notification is sent before verified Records release.</p>'
        .($pnpkiReady
            ? ($pnpkiAuth
                ? '<div class="alert alert-success py-2"><i class="bi bi-shield-check me-1"></i>'
                    .'PNPKI_2 RED account authorized (Account #'.(int)$pnpkiAuth['remote_user_id'].'). '
                    .'Upload your PNPKI-signed PDF below.</div>'
                : '<form method="post" action="'.e(url('payment_notice_pnpki_authorize')).'" '
                    .'class="border rounded p-3 mb-3">'
                    .csrf_field()
                    .'<strong>Connect your PNPKI_2 Account</strong>'
                    .'<p class="small text-secondary mb-2">Sign in and authorize FP-OMS once. '
                    .'No PNPKI password or private key is stored in FP-OMS.</p>'
                    .'<button type="submit" class="btn btn-primary btn-sm">'
                    .'<i class="bi bi-link-45deg me-1"></i>Authorize PNPKI_2 Account</button></form>')
            : '<div class="alert alert-warning small">'
                .'PNPKI_2 integration requires migrations 041/042 and server-side configuration. '
                .'Other signed-PDF options remain available.</div>');
    if (!$rows) $html.='<div class="alert alert-success">No Template No. 2 notices awaiting RED action.</div>';

    foreach($rows as $row) {
        $id=(int)$row['application_id'];
        $signed=(string)$row['status']==='signed';
        $pnpkiLink=pnpki_bridge_link($id,(int)$row['revision']);
        $pnpkiPending=pnpki_pending_signed_upload($id,(int)$row['revision']);
        $pnpkiInProgress=((bool)$pnpkiLink || (bool)$pnpkiPending) && !$signed;
        $preview=$signed
            ? url('payment_notice_pdf',['id'=>$id,'embed'=>1])
            : url('payment_notice_pdf',['id'=>$id,'embed'=>1]
                +($canEsign&&!$pnpkiInProgress?['signed_preview'=>1]:[]));
        $draft=url('payment_notice_pdf',['id'=>$id,'print_for_signing'=>1]);
        $modalId='paymentNoticeConfirm'.$id;
        $html.='<div class="border rounded p-3 mb-3"><strong>FP #'.$id.' · '.e((string)$row['project_title']).'</strong>'
            .'<div class="small text-secondary">'.e((string)$row['applicant_name']).' · Revision '.(int)$row['revision'].'</div>'
            .($signed
                ? '<p class="small text-warning mb-0 mt-1">RED-signed Template No. 2 saved. Final release/email is still pending.</p>'
                : '<p class="small text-secondary mb-0 mt-1">Unsigned draft. Applicant payment notice/email is withheld until RED finalization.</p>')
            .($pnpkiPending && !$pnpkiLink
                ? '<p class="small text-warning mt-2 mb-1">Signed PDF saved in FP-OMS. '
                    .'PNPKI_2 forwarding is pending; use Retry Submission below. '
                    .'No applicant notice has been sent.</p>'
                : '')
            .($pnpkiLink
                ? '<div class="alert alert-info py-2 mt-2 mb-1 small">'
                    .'<strong>PNPKI_2 Document #'.(int)$pnpkiLink['remote_doc_id'].'</strong> — '
                    .e(match ((string)$pnpkiLink['stage']) {
                        'awaiting_records'=>'RED forwarded; awaiting Records release',
                        'verification_required'=>'Awaiting trusted RED digital-signature verification',
                        'released_verified'=>'RED signature and Records release verified',
                        default=>'Awaiting RED PNPKI signing'
                    })
                    .'</div>'
                : '')
            .'<div class="d-flex flex-wrap gap-2 mt-2">'
            .'<a class="btn btn-outline-primary btn-sm" href="'.e($preview).'" '
            .'data-bs-toggle="modal" data-bs-target="#notice-pdf-preview-modal" '
            .'data-notice-preview-url="'.e($preview).'" '
            .'data-notice-preview-title="'.e('FP #'.$id.' · '.($signed?'RED-Signed Template No. 2':'Template No. 2 RED Preview')).'">'
            .'<i class="bi bi-file-earmark-pdf me-1"></i>'
            .($signed?'View Signed PDF':($canEsign&&!$pnpkiInProgress?'Preview with RED e-Signature':'Preview Unsigned Draft')).'</a>'
            .(!$signed
                ? '<a class="btn btn-outline-secondary btn-sm" href="'.e($draft).'" target="_blank" rel="noopener">'
                    .'<i class="bi bi-printer me-1"></i>Open / Print Draft for Signing</a>'
                : '')
            .(($signed||($canEsign&&!$pnpkiInProgress))
                ? '<button class="btn btn-success btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#'.$modalId.'">'
                    .($signed?'Finalize & Send':'Approve E-Sign').'</button>'
                : '')
            .(!$signed && !$pnpkiInProgress
                ? ($pnpkiReady && $pnpkiAuth
                    ? '<a class="btn btn-outline-primary btn-sm" href="#pnpki-upload-'.$id.'">'
                        .'<i class="bi bi-shield-check me-1"></i>PNPKI Sign / Upload</a>'
                    : '<button type="button" class="btn btn-outline-primary btn-sm" disabled>'
                        .'<i class="bi bi-shield-check me-1"></i>PNPKI Sign / Upload</button>')
                : '')
            .($pnpkiLink && $pnpkiAuth
                ? '<form method="post" class="d-inline" action="'
                    .e(url('payment_notice_pnpki_open',['id'=>$id])).'">'
                    .csrf_field()
                    .'<button class="btn btn-outline-secondary btn-sm" type="submit">'
                    .'<i class="bi bi-box-arrow-up-right me-1"></i>Auto-Login PNPKI_2</button></form>'
                : '')
            .($pnpkiLink && !$signed
                ? '<form method="post" class="d-inline" action="'.e(url('payment_notice_pnpki_check',['id'=>$id])).'">'
                    .csrf_field()
                    .'<button class="btn btn-outline-primary btn-sm" type="submit">'
                    .'<i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Check PNPKI Status</button></form>'
                : '')
            .'</div>';

        if ($signed||($canEsign&&!$pnpkiInProgress)) {
            $html.='<div class="modal fade" id="'.$modalId.'" tabindex="-1" aria-hidden="true">'
                .'<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
                .'<div class="modal-header"><h5 class="modal-title">'
                .($signed?'Confirm Finalize & Send':'Confirm RED E-Signature').'</h5>'
                .'<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
                .'<div class="modal-body"><p><strong>FP #'.$id.'</strong> – '.e((string)$row['project_title']).'</p>'
                .($signed
                    ? '<p>The existing RED-signed Template No. 2 will be officially released. The applicant will receive an in-system notice and an email attempt with the signed PDF attached.</p>'
                    : '<p>RED will apply the configured electronic signature to Template No. 2. The signed PDF must still be reviewed before Finalize &amp; Send.</p>')
                .'<form id="paymentNoticeAction'.$id.'" method="post" action="'.e(url('payment_notice_approve',['id'=>$id])).'">'
                .csrf_field()
                .'<input type="hidden" name="confirmation" value="1">'
                .'<input type="hidden" name="action" value="'.($signed?'finalize':'sign').'">'
                .'<label class="d-flex gap-2 align-items-start"><input type="checkbox" class="form-check-input mt-1" required>'
                .'<span>'.($signed
                    ? 'I have reviewed the RED-signed Template No. 2 and confirm release to the applicant.'
                    : 'I approve applying the RED electronic signature and will review the signed PDF before release.')
                .'</span></label></form></div>'
                .'<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
                .'<button type="submit" form="paymentNoticeAction'.$id.'" class="btn btn-success">'
                .($signed?'Finalize & Send':'Approve E-Sign').'</button></div>'
                .'</div></div></div>';
        }

        if (!$signed && !$pnpkiInProgress) {
            $html.='<form class="mt-3 border-top pt-3" method="post" enctype="multipart/form-data" '
                .'action="'.e(url('payment_notice_upload_signed',['id'=>$id])).'">'
                .csrf_field()
                .'<label class="form-label fw-semibold" for="paymentNoticeSigned'.$id.'">Upload physically signed PDF</label>'
                .'<input class="form-control form-control-sm mb-2" type="file" id="paymentNoticeSigned'.$id.'" '
                .'name="signed_pdf" accept=".pdf,application/pdf" required>'
                .'<label class="small d-flex gap-2 align-items-start">'
                .'<input type="checkbox" class="form-check-input mt-0" name="confirmation" value="1" required>'
                .'<span>I confirm this is the RED-signed Template No. 2 for FP #'.$id.'. I will review it before final release.</span></label>'
                .'<button class="btn btn-outline-success btn-sm mt-2" type="submit">'
                .'<i class="bi bi-upload me-1"></i>Upload Signed PDF</button>'
                .'<p class="small text-secondary mb-0 mt-2">PDF only, up to 10 MB. Upload saves the signed document; it does not email the applicant until Finalize &amp; Send.</p>'
                .'</form>';
        }
        if (!$signed && !$pnpkiLink && $pnpkiReady && $pnpkiAuth) {
            if ($pnpkiPending) {
                $html.='<form class="mt-3 border-top pt-3" method="post" action="'
                    .e(url('payment_notice_pnpki_retry',['id'=>$id])).'">'
                    .csrf_field()
                    .'<p class="fw-semibold mb-1">PNPKI Digitally-Signed PDF Saved</p>'
                    .'<p class="small text-secondary">The signed PDF is already encrypted in FP-OMS. '
                    .'Retry forwarding to Records without uploading another file.</p>'
                    .'<button type="submit" class="btn btn-primary btn-sm">'
                    .'<i class="bi bi-arrow-clockwise me-1"></i>Retry PNPKI_2 Submission</button></form>';
            } else {
                $html.='<form id="pnpki-upload-'.$id.'" class="mt-3 border rounded p-3" '
                    .'method="post" enctype="multipart/form-data" action="'
                    .e(url('payment_notice_pnpki_upload',['id'=>$id])).'">'
                    .csrf_field()
                    .'<input type="hidden" name="confirmation" value="1">'
                    .'<label class="form-label fw-semibold" for="pnpkiFile'.$id.'">'
                    .'<i class="bi bi-shield-check me-1"></i>PNPKI Sign — Upload RED-Signed PDF</label>'
                    .'<input type="file" class="form-control form-control-sm mb-2" '
                    .'id="pnpkiFile'.$id.'" name="signed_pdf" accept=".pdf,application/pdf" required>'
                    .'<p class="small text-secondary">Sign the Template No. 2 PDF with RED PNPKI locally; '
                    .'upload it here (PDF, max 10 MB). FP-OMS will forward the verified PDF '
                    .'directly to PNPKI_2 Records.</p>'
                    .'<label class="d-flex align-items-start gap-2 small mb-2">'
                    .'<input type="checkbox" class="form-check-input mt-1" required>'
                    .'<span>I confirm this is the correct RED PNPKI-signed Template No. 2 '
                    .'for FP #'.$id.'. The client will be notified only after Records release.</span></label>'
                    .'<button type="submit" class="btn btn-primary btn-sm">'
                    .'<i class="bi bi-send me-1"></i>Submit Signed PDF to PNPKI_2 Records</button>'
                    .'</form>';
            }
        }
        $html.='</div>';
    }
    render_page('RED Payment Notice Approvals',$html.'</div>'.fpoms_notice_pdf_preview_modal_html());
}
