<?php
declare(strict_types=1);

/** Template No. 2: complete documents / RED-signed advice to pay. */

function fpoms_complete_notice_install_schema(): bool
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS fpoms_complete_notices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            application_id BIGINT UNSIGNED NOT NULL,
            revision INT UNSIGNED NOT NULL,
            received_by_user_id BIGINT UNSIGNED NOT NULL,
            payload_json LONGTEXT NOT NULL,
            draft_path VARCHAR(300) NOT NULL,
            draft_sha256 CHAR(64) NOT NULL,
            status ENUM('pending_red','signed','released') NOT NULL DEFAULT 'pending_red',
            artifact_id BIGINT UNSIGNED DEFAULT NULL,
            approved_by BIGINT UNSIGNED DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            released_by BIGINT UNSIGNED DEFAULT NULL,
            released_at DATETIME DEFAULT NULL,
            email_status ENUM('not_sent','sent','failed') NOT NULL DEFAULT 'not_sent',
            email_last_attempt_at DATETIME DEFAULT NULL,
            email_sent_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fp_complete_notice_application_revision (application_id,revision),
            KEY idx_fp_complete_notice_status (status,created_at),
            KEY idx_fp_complete_notice_email (email_status),
            CONSTRAINT fk_fp_complete_notice_app FOREIGN KEY (application_id)
                REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
            CONSTRAINT fk_fp_complete_notice_receiver FOREIGN KEY (received_by_user_id)
                REFERENCES users(id),
            CONSTRAINT fk_fp_complete_notice_approver FOREIGN KEY (approved_by)
                REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_fp_complete_notice_releaser FOREIGN KEY (released_by)
                REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_fp_complete_notice_artifact FOREIGN KEY (artifact_id)
                REFERENCES permit_flow_artifacts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return true;
    } catch (Throwable $e) {
        error_log('FP-OMS complete-docs notice schema initialization failed: '.$e->getMessage());
        return false;
    }
}

function fpoms_complete_notice_schema_ready(): bool
{
    try {
        db()->query('SELECT id,status,email_status FROM fpoms_complete_notices LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return fpoms_complete_notice_install_schema();
    }
}

function fpoms_complete_notice_record(int $applicationId,int $revision): ?array
{
    if (!fpoms_complete_notice_schema_ready()) return null;
    return query_one('SELECT * FROM fpoms_complete_notices WHERE application_id=? AND revision=?',
        [$applicationId,$revision]);
}

function fpoms_complete_notice_application(int $applicationId): ?array
{
    return query_one(
        'SELECT a.*,c.name AS applicant_name,c.email AS applicant_email,
             r.company_name,r.company_address,r.representative_position
         FROM permit_step2_applications a
         JOIN user_client c ON c.id=a.client_id
         LEFT JOIN applicant_registrations r ON r.client_id=a.client_id
         WHERE a.id=?',[$applicationId]
    );
}

function fpoms_complete_notice_private_path(int $applicationId): array
{
    $directory=PROJECT_ROOT.'/storage/fp_workflow/'.$applicationId.'/3';
    if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare secure Template No. 2 storage.');
    }
    $relative='fp_workflow/'.$applicationId.'/3/'.bin2hex(random_bytes(20)).'.pdf';
    return [$relative,PROJECT_ROOT.'/storage/'.$relative];
}

function fpoms_complete_notice_payload(array $app): array
{
    $area=trim((string)($app['project_area_hectares']??''));
    if ($area==='' || !preg_match('/^\d{1,10}(?:\.\d{1,4})?$/D',$area) || (float)$area<=0) {
        throw new RuntimeException('Project area (hectares) is missing or invalid.');
    }
    $config=fpoms_notice_config();
    return [
        'area_hectares'=>$area,
        'penro_name'=>'',
        'cenro_name'=>'',
        'notice_date'=>date('F d, Y'),
        'notice_template_path'=>(string)($config['template_path']??''),
        'notice_config_snapshot'=>[
            'signatory_name'=>(string)($config['signatory_name']??'MA. THERESA J. ALLEN'),
            'signatory_title'=>(string)($config['signatory_title']??'OIC, Regional Executive Director'),
            'contact_emails'=>(string)($config['contact_emails']??'r13@denr.gov.ph; lasangutilizationlpdd@gmail.com'),
            'footer_text'=>(string)($config['footer_text']??
                'DENR-XIII, Ambago, Butuan City, Philippines | Hotline No. 0981-017-3551 | E-Mail: r13@denr.gov.ph')
        ]
    ];
}

/** Called inside the RO receipt transaction; returns created draft path for rollback cleanup. */
function fpoms_complete_notice_prepare_in_transaction(PDO $pdo,array $staff,array $lockedApp): ?string
{
    if (!permit_flow_is_new($lockedApp)) return null;
    $id=(int)$lockedApp['id'];
    $revision=(int)$lockedApp['revision'];
    if (fpoms_complete_notice_record($id,$revision)) return null;

    $app=fpoms_complete_notice_application($id);
    if (!$app) throw new RuntimeException('Unable to load Template No. 2 applicant data.');
    $payload=fpoms_complete_notice_payload($app);
    $layout=$payload['notice_config_snapshot'];
    $layout['letterhead_images']=fpoms_notice_template_branding([
        'template_path'=>(string)$payload['notice_template_path']
    ]);
    $pdf=FpomsCompleteNoticePdf::render($app,$payload,$layout,null,(string)$payload['notice_date']);
    if (!str_starts_with($pdf,'%PDF-1.4') || !str_contains($pdf,'/Type /Pages /Count 1')
        || !str_ends_with($pdf,"%%EOF\n")) {
        throw new RuntimeException('Generated Template No. 2 failed PDF integrity checks.');
    }
    [$relative,$absolute]=fpoms_complete_notice_private_path($id);
    fpoms_file_write_encrypted_bytes($pdf,$absolute);
    @chmod($absolute,0600);
    $pdo->prepare(
        "INSERT INTO fpoms_complete_notices
         (application_id,revision,received_by_user_id,payload_json,draft_path,draft_sha256,status)
         VALUES (?,?,?,?,?,?,'pending_red')"
    )->execute([
        $id,$revision,(int)$staff['id'],
        json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
        $relative,hash('sha256',$pdf)
    ]);
    permit_flow_record_event($pdo,$id,3,$staff,'complete_docs_notice_prepared',
        'Template No. 2 generated after RO receipt; applicant release withheld pending RED signature.');
    return $absolute;
}

function fpoms_complete_notice_prepare_existing(array $staff,int $applicationId): array
{
    if (($staff['user_kind']??'')!=='internal' || ($staff['status']??'')!=='active'
        || !has_role($staff,'ro_receiving')) return ['Only RO Receiving/Records can generate this notice.'];
    if (!fpoms_complete_notice_schema_ready()) return ['Template No. 2 notice storage is unavailable.'];
    $app=fpoms_complete_notice_application($applicationId);
    if (!$app || !permit_flow_is_new($app)) return ['Updated Annex A application not found.'];
    if (!in_array((string)$app['status'],['ro_received','forwarded_to_lpdd','step4_complete'],true)) {
        return ['Template No. 2 can be generated only after RO receipt of complete documents.'];
    }
    $movement=step3_movement($applicationId);
    if (!$movement || empty($movement['received_at'])) return ['RO document receipt has not been recorded.'];
    if (fpoms_complete_notice_record($applicationId,(int)$app['revision'])) {
        return ['Template No. 2 already exists for this application revision.'];
    }

    $pdo=db();
    $createdPath=null;
    try {
        $pdo->beginTransaction();
        $locked=query_one('SELECT * FROM permit_step2_applications WHERE id=? FOR UPDATE',[$applicationId]);
        if (!$locked || (int)$locked['revision']!==(int)$app['revision']
            || !in_array((string)$locked['status'],['ro_received','forwarded_to_lpdd','step4_complete'],true)) {
            throw new RuntimeException('Application changed; reload before generating Template No. 2.');
        }
        $createdPath=fpoms_complete_notice_prepare_in_transaction($pdo,$staff,$locked);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($createdPath && is_file($createdPath)) @unlink($createdPath);
        error_log('Template No. 2 recovery generation failed: '.$e->getMessage());
        return ['Template No. 2 could not be generated: '.$e->getMessage()];
    }
    fpoms_complete_notice_after_receipt($app,$applicationId);
    return [];
}

function fpoms_complete_notice_after_receipt(array $app,int $applicationId): void
{
    try {
        notify_role('red','Template No. 2 for RED Signature – FP #'.$applicationId,
            'RO Receiving recorded the complete FP documents. Review and sign the generated Template No. 2 before official release to the applicant.',
            'file-earmark-check','warning','complete_notice_queue');
    } catch (Throwable $e) {
        error_log('Template No. 2 RED notification failed: '.$e->getMessage());
    }
    try {
        create_client_notification((int)$app['client_id'],
            'RO Documents Received – Awaiting RED-Signed Notice',
            'RO Receiving recorded your complete FP documents. The official Template No. 2 advice-to-pay letter is awaiting RED signature and final release. Payment instructions will be released with the signed notice.',
            'file-earmark-lock','info','permit_application',['id'=>$applicationId]);
    } catch (Throwable $e) {
        error_log('Template No. 2 applicant status notification failed: '.$e->getMessage());
    }
}

function fpoms_complete_notice_render_signed(array $app,array $payload,array $config,string $signature): string
{
    $layout=is_array($payload['notice_config_snapshot']??null)
        ?$payload['notice_config_snapshot']:$config;
    $layout['letterhead_images']=fpoms_notice_template_branding([
        'template_path'=>(string)($payload['notice_template_path']??$config['template_path']??'')
    ]);
    return FpomsCompleteNoticePdf::render($app,$payload,$layout,$signature,date('F d, Y'));
}

function fpoms_complete_notice_red_approve(array $red,int $applicationId,bool $signOnly=false): array
{
    if (($red['user_kind']??'')!=='internal' || ($red['status']??'')!=='active'
        || !has_role($red,'red')) return ['Only an active RED account may approve this notice.'];
    if (!fpoms_complete_notice_schema_ready()) return ['Template No. 2 notice storage is unavailable.'];
    $app=fpoms_complete_notice_application($applicationId);
    if (!$app || !permit_flow_is_new($app)) return ['Updated Annex A application not found.'];
    $notice=fpoms_complete_notice_record($applicationId,(int)$app['revision']);
    if (!$notice) return ['RO Receiving must receive the application and generate Template No. 2 first.'];
    if ((string)$notice['status']==='released') return ['This Template No. 2 notice was already released.'];
    $payload=json_decode((string)$notice['payload_json'],true);
    if (!is_array($payload)) return ['Stored Template No. 2 data is invalid.'];
    $signed=(string)$notice['status']==='signed';
    if ($signOnly && $signed) return ['This notice is already signed. Preview it before finalizing.'];
    if (!$signOnly && !$signed) return ['Sign the notice first, then review the signed PDF before finalizing.'];

    if (!$signed) {
        $config=fpoms_notice_config();
        if (empty($config['signature_path']) || empty($config['signature_sha256'])) {
            return ['No RED e-sign JPEG is configured. Use the physically signed PDF upload option or configure the RED signature.'];
        }
        try {
            $signature=fpoms_notice_safe_read((string)$config['signature_path'],
                (string)$config['signature_sha256']);
            $pdf=fpoms_complete_notice_render_signed($app,$payload,$config,$signature);
            [$relative,$absolute]=fpoms_complete_notice_private_path($applicationId);
            fpoms_file_write_encrypted_bytes($pdf,$absolute);
            @chmod($absolute,0600);
            $pdo=db();
            $pdo->beginTransaction();
            $pending=query_one('SELECT * FROM fpoms_complete_notices
                WHERE application_id=? AND revision=? FOR UPDATE',
                [$applicationId,(int)$app['revision']]);
            if (!$pending || (string)$pending['status']!=='pending_red') {
                throw new RuntimeException('Template No. 2 changed; reload the RED queue.');
            }
            $filename='DENR_RED_Template_No_2_Notice_to_Pay_FP'.$applicationId.'_R'.$app['revision'].'.pdf';
            $pdo->prepare('INSERT INTO permit_flow_artifacts
                (application_id,step_number,application_revision,category,original_name,
                 stored_path,mime_type,file_size,sha256,uploaded_by)
                VALUES (?,3,?,"red_signed_complete_docs_notice",?,?,"application/pdf",?,?,?)')
                ->execute([
                    $applicationId,(int)$app['revision'],$filename,$relative,
                    strlen($pdf),hash('sha256',$pdf),(int)$red['id']
                ]);
            $artifactId=(int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE fpoms_complete_notices
                SET status='signed',artifact_id=?,approved_by=?,approved_at=NOW()
                WHERE id=? AND status='pending_red'")
                ->execute([$artifactId,(int)$red['id'],(int)$pending['id']]);
            permit_flow_record_event($pdo,$applicationId,3,$red,'complete_docs_notice_red_esigned',
                'RED applied the configured e-signature to Template No. 2; final applicant release remains pending.');
            $pdo->commit();
            if ($signOnly) return [];
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            if (isset($absolute) && is_file($absolute)) @unlink($absolute);
            error_log('Template No. 2 RED e-sign failed: '.$e->getMessage());
            return ['RED signature could not be saved: '.$e->getMessage()];
        }
    }
    return fpoms_complete_notice_finalize($red,$applicationId);
}

function fpoms_complete_notice_red_upload_signed(array $red,int $applicationId,array $upload): array
{
    if (($red['user_kind']??'')!=='internal' || ($red['status']??'')!=='active'
        || !has_role($red,'red')) return ['Only an active RED account may upload a signed notice.'];
    if (!fpoms_complete_notice_schema_ready()) return ['Template No. 2 notice storage is unavailable.'];
    if ((int)($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK
        || !is_uploaded_file((string)($upload['tmp_name']??''))) {
        return ['Select the physically signed Template No. 2 PDF to upload.'];
    }
    $size=(int)($upload['size']??0);
    if ($size<100 || $size>10485760) return ['The signed PDF must be 10 MB or less.'];
    if (strtolower((string)pathinfo((string)($upload['name']??''),PATHINFO_EXTENSION))!=='pdf') {
        return ['Upload a PDF document.'];
    }
    $tmp=(string)$upload['tmp_name'];
    $pdf=file_get_contents($tmp);
    if (!is_string($pdf) || strlen($pdf)!==$size
        || !preg_match('/^%PDF-1\.[0-7]/',$pdf)
        || !preg_match('/%%EOF\s*$/',$pdf)
        || (new finfo(FILEINFO_MIME_TYPE))->buffer($pdf)!=='application/pdf') {
        return ['The uploaded file is not a valid PDF.'];
    }

    $app=fpoms_complete_notice_application($applicationId);
    if (!$app || !permit_flow_is_new($app)) return ['Updated Annex A application not found.'];
    $notice=fpoms_complete_notice_record($applicationId,(int)$app['revision']);
    if (!$notice || (string)$notice['status']!=='pending_red') {
        return ['Only a pending Template No. 2 notice can receive a signed upload.'];
    }

    try {
        [$relative,$absolute]=fpoms_complete_notice_private_path($applicationId);
        fpoms_file_write_encrypted_bytes($pdf,$absolute);
        @chmod($absolute,0600);
        $pdo=db();
        $pdo->beginTransaction();
        $pending=query_one('SELECT id,status FROM fpoms_complete_notices
            WHERE application_id=? AND revision=? FOR UPDATE',[$applicationId,(int)$app['revision']]);
        if (!$pending || (string)$pending['status']!=='pending_red') {
            throw new RuntimeException('Template No. 2 changed; reload the RED queue.');
        }
        $filename='DENR_RED_Manually_Signed_Template_No_2_FP'.$applicationId.'_R'.$app['revision'].'.pdf';
        $pdo->prepare('INSERT INTO permit_flow_artifacts
            (application_id,step_number,application_revision,category,original_name,
             stored_path,mime_type,file_size,sha256,uploaded_by)
            VALUES (?,3,?,"red_signed_complete_docs_notice",?,?,"application/pdf",?,?,?)')
            ->execute([
                $applicationId,(int)$app['revision'],$filename,$relative,
                $size,hash('sha256',$pdf),(int)$red['id']
            ]);
        $artifactId=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE fpoms_complete_notices
            SET status='signed',artifact_id=?,approved_by=?,approved_at=NOW()
            WHERE id=? AND status='pending_red'")
            ->execute([$artifactId,(int)$red['id'],(int)$pending['id']]);
        permit_flow_record_event($pdo,$applicationId,3,$red,'complete_docs_notice_red_signed_upload',
            'RED uploaded a physically signed Template No. 2 for final review.');
        $pdo->commit();
        return [];
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        if (isset($absolute) && is_file($absolute)) @unlink($absolute);
        error_log('Template No. 2 signed upload failed: '.$e->getMessage());
        return ['Unable to save the signed Template No. 2 PDF: '.$e->getMessage()];
    }
}

function fpoms_complete_notice_finalize(array $red,int $applicationId): array
{
    if (!permit_payment_schema_ready()) return ['Payment tracking migration 028 is required before releasing the advice-to-pay notice.'];
    $app=fpoms_complete_notice_application($applicationId);
    if (!$app || !permit_flow_is_new($app)) return ['Updated Annex A application not found.'];
    $notice=fpoms_complete_notice_record($applicationId,(int)$app['revision']);
    if (!$notice || (string)$notice['status']!=='signed' || (int)$notice['artifact_id']<1) {
        return ['A RED-signed Template No. 2 PDF must be reviewed before final release.'];
    }
    if (!fpoms_complete_notice_attachment($notice)) {
        return ['The RED-signed Template No. 2 PDF is missing or failed integrity verification. Final release was blocked.'];
    }
    $pdo=db();
    try {
        $pdo->beginTransaction();
        $locked=query_one('SELECT * FROM permit_step2_applications WHERE id=? FOR UPDATE',[$applicationId]);
        $lockedNotice=query_one('SELECT * FROM fpoms_complete_notices
            WHERE application_id=? AND revision=? FOR UPDATE',[$applicationId,(int)$app['revision']]);
        if (!$locked || (int)$locked['revision']!==(int)$app['revision']
            || !$lockedNotice || (string)$lockedNotice['status']!=='signed') {
            throw new RuntimeException('Application or Template No. 2 changed; reload before final release.');
        }
        if (!in_array((string)$locked['status'],['ro_received','forwarded_to_lpdd','step4_complete'],true)) {
            throw new RuntimeException('Application is no longer in a stage where the advice-to-pay notice can be released.');
        }
        $pdo->prepare("INSERT INTO permit_payment_verifications
            (application_id,status,notified_at) VALUES (?,'awaiting_payment',NOW())
            ON DUPLICATE KEY UPDATE notified_at=COALESCE(notified_at,VALUES(notified_at))")
            ->execute([$applicationId]);
        $pdo->prepare("UPDATE fpoms_complete_notices
            SET status='released',released_by=?,released_at=NOW()
            WHERE id=? AND status='signed'")
            ->execute([(int)$red['id'],(int)$lockedNotice['id']]);
        permit_flow_record_event($pdo,$applicationId,3,$red,'complete_docs_notice_released',
            'RED finalized and released the signed Template No. 2 advice-to-pay notice to the applicant.');
        $pdo->commit();
        fpoms_pusher_flush_pending();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Template No. 2 final release failed: '.$e->getMessage());
        return ['Final release could not be saved: '.$e->getMessage()];
    }

    $message='Your FP application #'.$applicationId
        .' was found complete and meritorious. The official RED-signed Template No. 2 is now available. '
        .'Submit the required hard copies and pay the Application Fee of PHP 10,000.00 at the DENR CARAGA Regional Office Cashier.';
    try {
        create_client_notification((int)$app['client_id'],'Official RED-Signed Notice to Pay – FP #'.$applicationId,
            $message,'receipt','success','permit_application',['id'=>$applicationId]);
        notify_role('lpdd_fus','FP Payment Notice Released – FP #'.$applicationId,
            'RED released the signed Template No. 2. Applicant was advised to pay at the Cashier; LPDD-FUS should verify the Official Receipt after payment.',
            'receipt','info','lpdd_fus_payment_queue',['tab'=>'awaiting']);
    } catch (Throwable $e) {
        error_log('Template No. 2 release notification failed: '.$e->getMessage());
    }
    fpoms_complete_notice_dispatch_official_email($applicationId,(int)$red['id']);
    return [];
}

function fpoms_complete_notice_released(int $applicationId,int $revision): bool
{
    $notice=fpoms_complete_notice_record($applicationId,$revision);
    return $notice && (string)$notice['status']==='released' && (int)$notice['artifact_id']>0;
}

function fpoms_complete_notice_released_artifact_ids(int $applicationId): array
{
    if (!fpoms_complete_notice_schema_ready()) return [];
    $rows=query_all("SELECT artifact_id FROM fpoms_complete_notices
        WHERE application_id=? AND status='released' AND artifact_id IS NOT NULL",[$applicationId]);
    return array_map('intval',array_column($rows,'artifact_id'));
}

function fpoms_complete_notice_attachment(array $notice): array
{
    $artifactId=(int)($notice['artifact_id']??0);
    if ($artifactId<1) return [];
    $doc=query_one('SELECT * FROM permit_flow_artifacts WHERE id=? AND application_id=?',
        [$artifactId,(int)$notice['application_id']]);
    if (!$doc || (string)$doc['category']!=='red_signed_complete_docs_notice') return [];
    $base=realpath(PROJECT_ROOT.'/storage/fp_workflow');
    $absolute=realpath(PROJECT_ROOT.'/storage/'.(string)$doc['stored_path']);
    if (!$base || !$absolute || !str_starts_with($absolute,$base.DIRECTORY_SEPARATOR)
        || !is_file($absolute)) return [];
    try {
        $data=fpoms_file_read($absolute);
    } catch (Throwable $e) {
        error_log('Template No. 2 email attachment read failed: '.$e->getMessage());
        return [];
    }
    if (!hash_equals((string)$doc['sha256'],hash('sha256',$data))) return [];
    return [['data'=>$data,'name'=>(string)$doc['original_name'],'mime'=>'application/pdf']];
}

function fpoms_complete_notice_dispatch_official_email(int $applicationId,int $actorUserId=0): bool
{
    $app=fpoms_complete_notice_application($applicationId);
    if (!$app) return false;
    $notice=fpoms_complete_notice_record($applicationId,(int)$app['revision']);
    if (!$notice || (string)$notice['status']!=='released') return false;
    $attachments=fpoms_complete_notice_attachment($notice);
    $sent=false;
    if ($attachments) {
        $message='Your FP application #'.$applicationId
            .' was found complete and meritorious. Attached is the official RED-signed Template No. 2. '
            .'Please submit a hard copy of the complete documentary requirements and pay the Application Fee '
            .'of PHP 10,000.00 at the DENR CARAGA Regional Office Cashier. After payment, present the Official Receipt to LPDD-FUS for verification.';
        $sent=permit_flow_email($app,'Official RED-Signed Notice to Pay – FP Application #'.$applicationId,
            $message,permit_flow_application_email_url($applicationId),$attachments,'Open FP Application');
    }
    try {
        db()->prepare("UPDATE fpoms_complete_notices
            SET email_status=?,email_last_attempt_at=NOW(),
                email_sent_at=CASE WHEN ?='sent' THEN NOW() ELSE email_sent_at END
            WHERE id=?")->execute([$sent?'sent':'failed',$sent?'sent':'failed',(int)$notice['id']]);
        if ($actorUserId>0) audit($actorUserId,'fp.complete_notice.email_attempt',
            'permit_step2_application',$applicationId,['sent'=>$sent]);
    } catch (Throwable $e) {
        error_log('Template No. 2 email delivery status update failed: '.$e->getMessage());
    }
    return $sent;
}

function fpoms_complete_notice_retry_email(array $staff,int $applicationId): array
{
    if (($staff['user_kind']??'')!=='internal' || ($staff['status']??'')!=='active'
        || !(has_role($staff,'red') || has_role($staff,'ro_receiving')
            || has_role($staff,'lpdd_fus') || has_role($staff,'super_admin'))) {
        return ['Authorized DENR staff access required.'];
    }
    if (!fpoms_complete_notice_dispatch_official_email($applicationId,(int)$staff['id'])) {
        return ['Official Template No. 2 email could not be sent. Check SMTP and the protected signed PDF.'];
    }
    return [];
}

function fpoms_complete_notice_send_pdf(array $viewer,int $applicationId): never
{
    $app=fpoms_complete_notice_application($applicationId);
    if (!$app) show_error('Application not found.',404);
    $notice=fpoms_complete_notice_record($applicationId,(int)$app['revision']);
    if (!$notice) show_error('Template No. 2 notice not found.',404);
    $isStaff=($viewer['user_kind']??'')==='internal' && ($viewer['status']??'')==='active'
        && (has_role($viewer,'red') || has_role($viewer,'ro_receiving') || has_role($viewer,'super_admin'));
    if (!$isStaff) show_error('You cannot view an unsigned Template No. 2 notice.',403);
    $signedPreview=(string)($_GET['signed_preview']??'')==='1';
    $printForSigning=(string)($_GET['print_for_signing']??'')==='1';
    if (($signedPreview || $printForSigning)
        && (!has_role($viewer,'red') || (string)$notice['status']!=='pending_red')) {
        show_error('RED signing copy is unavailable.',403);
    }
    try {
        $payload=json_decode((string)$notice['payload_json'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new RuntimeException('Stored Template No. 2 data is invalid.');
        $config=fpoms_notice_config();
        $layout=is_array($payload['notice_config_snapshot']??null)
            ?$payload['notice_config_snapshot']:$config;
        $layout['letterhead_images']=fpoms_notice_template_branding([
            'template_path'=>(string)($payload['notice_template_path']??'')
        ]);
        if ($signedPreview) {
            if (empty($config['signature_path']) || empty($config['signature_sha256'])) {
                show_error('RED e-signature is not configured.',404);
            }
            $signature=fpoms_notice_safe_read((string)$config['signature_path'],(string)$config['signature_sha256']);
            $pdf=FpomsCompleteNoticePdf::render($app,$payload,$layout,$signature,date('F d, Y'));
        } else {
            $pdf=FpomsCompleteNoticePdf::render($app,$payload,$layout,null,
                (string)($payload['notice_date']??date('F d, Y')),$printForSigning);
        }
    } catch (Throwable $e) {
        show_error('Protected Template No. 2 notice unavailable.',404);
    }
    $embedded=(string)($_GET['embed']??'')==='1';
    if ($embedded) {
        header_remove('X-Frame-Options');
        header_remove('Content-Security-Policy');
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
    }
    header('Content-Type: application/pdf');
    header('Cache-Control: private,no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="'
        .($signedPreview?'RED_Template_No_2_ESign_Preview.pdf'
            :($printForSigning?'RED_Template_No_2_Print_For_Signing.pdf':'Template_No_2_Draft.pdf')).'"');
    header('Content-Length: '.strlen($pdf));
    echo $pdf;
    exit;
}

function fpoms_complete_notice_queue_view(array $red): void
{
    if (!has_role($red,'red') || ($red['status']??'')!=='active') show_error('RED approval role required.',403);
    if (!fpoms_complete_notice_schema_ready()) {
        render_page('RED Template No. 2 Approvals',
            '<div class="alert alert-warning">Template No. 2 storage could not initialize.</div>');
        return;
    }
    $rows=query_all(
        "SELECT n.*,a.project_title,c.name AS applicant_name
         FROM fpoms_complete_notices n
         JOIN permit_step2_applications a ON a.id=n.application_id AND a.revision=n.revision
         JOIN user_client c ON c.id=a.client_id
         WHERE n.status IN ('pending_red','signed')
         ORDER BY n.created_at ASC LIMIT 100"
    );
    $config=fpoms_notice_config();
    $canEsign=!empty($config['signature_path']) && !empty($config['signature_sha256']);
    $html='<div class="panel"><h2 class="h5">RED – Template No. 2 / Notice to Pay Approvals</h2>'
        .'<p class="text-secondary">RO Receiving generates Template No. 2 after receipt of complete documents. '
        .'RED may approve the configured e-signature or upload a physically signed PDF. '
        .'The applicant receives the signed PDF only after Finalize &amp; Send.</p>';
    if (!$rows) $html.='<div class="alert alert-success">No Template No. 2 notices awaiting RED action.</div>';

    foreach ($rows as $row) {
        $id=(int)$row['application_id'];
        $approved=(string)$row['status']==='signed' && (int)$row['artifact_id']>0;
        $preview=$approved
            ?url('permit_flow_file',['id'=>$id,'file'=>(int)$row['artifact_id'],'preview'=>1,'raw'=>1])
            :url('complete_notice_pdf',['id'=>$id,'embed'=>1]+($canEsign?['signed_preview'=>1]:[]));
        $previewLabel=$approved?'View Signed PDF':($canEsign?'Preview with RED e-Signature':'Preview Unsigned Draft');
        $draftUrl=url('complete_notice_pdf',['id'=>$id,'print_for_signing'=>1]);
        $html.='<div class="border rounded p-3 mb-3"><strong>FP #'.$id.' · '
            .e((string)$row['project_title']).'</strong><div class="small text-secondary">'
            .e((string)$row['applicant_name']).' · Revision '.(int)$row['revision'].'</div>'
            .($approved
                ?'<p class="small text-warning mb-0 mt-1">RED-signed Template No. 2 is saved. Review it, then Finalize &amp; Send to Applicant.</p>'
                :'<p class="small text-secondary mb-0 mt-1">Applicant payment email is withheld until RED signs and finalizes this notice.</p>')
            .'<div class="d-flex flex-wrap gap-2 mt-2">'
            .'<a class="btn btn-outline-primary btn-sm" href="'.e($preview).'" '
            .'data-bs-toggle="modal" data-bs-target="#notice-pdf-preview-modal" '
            .'data-notice-preview-url="'.e($preview).'" '
            .'data-notice-preview-title="'.e('FP #'.$id.' · '.$previewLabel).'">'
            .'<i class="bi bi-file-earmark-pdf me-1"></i>'.e($previewLabel).'</a>'
            .(!$approved?'<a class="btn btn-outline-secondary btn-sm" href="'.e($draftUrl)
                .'" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>'
                .'Open / Print Draft for Signing</a>':'')
            .(!$approved && !$canEsign
                ?'<span class="small text-warning align-self-center">RED e-signature is not configured; use signed PDF upload.</span>':'')
            .($approved || $canEsign
                ?'<form method="post" action="'.e(url('complete_notice_approve',['id'=>$id])).'">'
                    .csrf_field()
                    .'<button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" '
                    .'data-bs-target="#completeNoticeModal'.$id.'">'.($approved?'Finalize & Send':'Approve E-Sign').'</button>'
                    .'<div class="modal fade" id="completeNoticeModal'.$id.'" tabindex="-1" aria-hidden="true">'
                    .'<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
                    .'<div class="modal-header"><h5 class="modal-title">'
                    .($approved?'Confirm Finalize & Send':'Confirm RED E-Signature').'</h5>'
                    .'<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
                    .'<div class="modal-body"><p><strong>FP #'.$id.'</strong> – '.e((string)$row['project_title']).'</p>'
                    .($approved
                        ?'<p>The reviewed RED-signed Template No. 2 will be officially released and emailed to the applicant with payment instructions.</p>'
                        :'<p>RED will apply the configured e-signature and save the signed PDF for review. Nothing is emailed yet.</p>')
                    .'<label class="d-flex align-items-start gap-2"><input type="checkbox" class="form-check-input mt-1" '
                    .'name="confirmation" value="1" required><span>'
                    .($approved
                        ?'I reviewed the signed PDF and confirm official release to the applicant.'
                        :'I approve applying my RED e-signature and will inspect the saved PDF before release.')
                    .'</span></label></div>'
                    .'<input type="hidden" name="action" value="'.($approved?'finalize':'sign').'">'
                    .'<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" '
                    .'data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">'
                    .($approved?'Finalize & Send':'Approve E-Sign').'</button></div></div></div></div></form>':'')
            .'</div>'
            .(!$approved
                ?'<form class="mt-3 border-top pt-3" method="post" enctype="multipart/form-data" '
                    .'action="'.e(url('complete_notice_upload_signed',['id'=>$id])).'">'
                    .csrf_field()
                    .'<label class="form-label fw-semibold" for="completeSigned'.$id.'">Upload physically signed Template No. 2 PDF</label>'
                    .'<div class="d-flex flex-wrap align-items-center gap-2">'
                    .'<input class="form-control form-control-sm" type="file" id="completeSigned'.$id
                    .'" name="signed_pdf" accept=".pdf,application/pdf" required>'
                    .'<button class="btn btn-outline-success btn-sm" type="submit"><i class="bi bi-upload me-1"></i>Upload Signed PDF</button></div>'
                    .'<label class="small d-flex align-items-start gap-2 mt-2">'
                    .'<input type="checkbox" class="form-check-input mt-0" name="confirmation" value="1" required>'
                    .'<span>I confirm this is the RED-signed Template No. 2 for FP #'.$id
                    .'. I will preview it before final release.</span></label>'
                    .'<p class="small text-secondary mb-0">PDF only, up to 10 MB. Uploading does not email the applicant; Finalize &amp; Send does.</p>'
                    .'</form>':'')
            .'</div>';
    }
    $html.='</div>'.fpoms_notice_pdf_preview_modal_html();
    render_page('RED Template No. 2 Approvals',$html);
}
