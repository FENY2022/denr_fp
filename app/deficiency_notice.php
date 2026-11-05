<?php
declare(strict_types=1);

/** Generated deficiency notices, controlled RED approval and protected storage. */

function fpoms_notice_schema_ready(): bool
{
    try {
        db()->query('SELECT id FROM fpoms_notice_config LIMIT 0');
        db()->query('SELECT id FROM fpoms_deficiency_notices LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fpoms_notice_config(): array
{
    if (!fpoms_notice_schema_ready()) return [];
    return query_one('SELECT * FROM fpoms_notice_config WHERE id=1') ?? [];
}

function fpoms_notice_record(int $applicationId,int $revision): ?array
{
    if (!fpoms_notice_schema_ready()) return null;
    return query_one(
        'SELECT * FROM fpoms_deficiency_notices WHERE application_id=? AND revision=?',
        [$applicationId,$revision]
    );
}

function fpoms_notice_application(int $applicationId): ?array
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

function fpoms_notice_private_path(string $name,int $applicationId=0): array
{
    $scope=$applicationId>0
        ? 'fp_workflow/'.$applicationId.'/2'
        : 'fp_notices/config';
    $directory=PROJECT_ROOT.'/storage/'.$scope;
    if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare secure document storage.');
    }
    $relative=$scope.'/'.bin2hex(random_bytes(20)).'.'.$name;
    return [$relative,PROJECT_ROOT.'/storage/'.$relative];
}

function fpoms_notice_safe_read(string $relative,string $sha256=''): string
{
    if ($relative==='' || str_contains($relative,'..')
        || !str_starts_with($relative,'fp_workflow/')
            && !str_starts_with($relative,'fp_notices/')) {
        throw new RuntimeException('Invalid protected document path.');
    }
    $base=realpath(PROJECT_ROOT.'/storage');
    $absolute=realpath(PROJECT_ROOT.'/storage/'.$relative);
    if (!$base || !$absolute || !str_starts_with($absolute,$base.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Protected document not found.');
    }
    $data=fpoms_file_read($absolute);
    if ($sha256!=='' && !hash_equals($sha256,hash('sha256',$data))) {
        throw new RuntimeException('Document integrity verification failed.');
    }
    return $data;
}

function fpoms_notice_upload_option(string $name,array $upload,string $kind): array
{
    $error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
    if ($error===UPLOAD_ERR_NO_FILE) return ['',null,null];
    if ($error!==UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name']??''))) {
        throw new RuntimeException('Invalid '.$kind.' upload.');
    }
    $size=(int)($upload['size']??0);
    if ($size<1 || $size>5242880) {
        throw new RuntimeException('The '.$kind.' file must not exceed 5 MB.');
    }
    $original=basename((string)($upload['name']??$name));
    $ext=strtolower((string)pathinfo($original,PATHINFO_EXTENSION));
    $tmp=(string)$upload['tmp_name'];
    $header=file_get_contents($tmp,false,null,0,8);
    if ($kind==='template') {
        if ($ext!=='docx' || !str_starts_with((string)$header,'PK')) {
            throw new RuntimeException('Upload a valid DOCX letter reference template.');
        }
        $storedExt='docx';
    } else {
        $info=@getimagesize($tmp);
        if ($ext!=='jpg' && $ext!=='jpeg' || !$info || (int)($info[2]??0)!==IMAGETYPE_JPEG) {
            throw new RuntimeException('Upload the RED e-sign image as JPG/JPEG. PNG can be converted to JPEG first.');
        }
        $storedExt='jpg';
    }
    [$relative,$absolute]=fpoms_notice_private_path($storedExt);
    fpoms_file_store_upload($tmp,$absolute);
    return [$relative,$original,fpoms_file_plaintext_sha256($absolute)];
}

function fpoms_notice_save_config(array $staff,array $input,array $uploads): array
{
    if (($staff['status']??'')!=='active' || !has_role($staff,'super_admin')) {
        return ['Super Administrator permission required.'];
    }
    if (!fpoms_notice_schema_ready()) return ['Apply migration 032 before configuring notices.'];
    $name=trim((string)($input['signatory_name']??''));
    $title=trim((string)($input['signatory_title']??''));
    $contact=trim((string)($input['contact_emails']??''));
    $footer=trim((string)($input['footer_text']??''));
    if (mb_strlen($name)<3 || mb_strlen($name)>180
        || mb_strlen($title)<3 || mb_strlen($title)>180
        || mb_strlen($contact)>400 || mb_strlen($footer)>300) {
        return ['Check the signatory name/title, contact information and footer lengths.'];
    }
    $newPaths=[];
    try {
        [$templatePath,$templateName,$unused]=fpoms_notice_upload_option(
            'template.docx',$uploads['template']??[],'template'
        );
        if ($templatePath!=='') $newPaths[]=PROJECT_ROOT.'/storage/'.$templatePath;
        [$signaturePath,$signatureName,$signatureSha]=fpoms_notice_upload_option(
            'signature.jpg',$uploads['signature']??[],'signature'
        );
        if ($signaturePath!=='') $newPaths[]=PROJECT_ROOT.'/storage/'.$signaturePath;
        $pdo=db();
        $pdo->beginTransaction();
        $previous=fpoms_notice_config();
        $pdo->prepare(
            'INSERT INTO fpoms_notice_config
             (id,signatory_name,signatory_title,contact_emails,footer_text,
              template_name,template_path,signature_path,signature_sha256,updated_by)
             VALUES (1,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                signatory_name=VALUES(signatory_name),
                signatory_title=VALUES(signatory_title),
                contact_emails=VALUES(contact_emails),
                footer_text=VALUES(footer_text),
                template_name=VALUES(template_name),
                template_path=VALUES(template_path),
                signature_path=VALUES(signature_path),
                signature_sha256=VALUES(signature_sha256),
                updated_by=VALUES(updated_by)'
        )->execute([
            $name,$title,$contact,$footer,
            $templatePath!==''?$templateName:($previous['template_name']??null),
            $templatePath!==''?$templatePath:($previous['template_path']??null),
            $signaturePath!==''?$signaturePath:($previous['signature_path']??null),
            $signaturePath!==''?$signatureSha:($previous['signature_sha256']??null),
            (int)$staff['id']
        ]);
        audit((int)$staff['id'],'fp.notice_config.updated','fp_notice_config',1,[
            'template_changed'=>$templatePath!=='',
            'signature_changed'=>$signaturePath!==''
        ]);
        $pdo->commit();
        return [];
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        foreach ($newPaths as $path) if (is_file($path)) @unlink($path);
        error_log('FP-OMS notice config error: '.$e->getMessage());
        return ['Unable to save notice settings: '.$e->getMessage()];
    }
}

function fpoms_notice_rows(array $items): array
{
    $rows=[];
    foreach (step2_requirement_types() as $type=>$label) {
        $item=$items[$type]??[];
        if (!in_array((string)($item['assessment']??''),['incomplete','insufficient'],true)) continue;
        $rows[]=[
            'label'=>$label,
            'assessment'=>(string)$item['assessment'],
            'remarks'=>trim((string)($item['remarks']??'')),
        ];
    }
    return $rows;
}

function fpoms_notice_prepare(array $reviewer,int $applicationId,array $input,array $items): array
{
    if (!fpoms_notice_schema_ready()) return ['Apply database migration 032 first.'];
    if (!has_role($reviewer,'lpdd_fus')) return ['LPDD-FUS access required.'];
    $app=fpoms_notice_application($applicationId);
    if (!$app || !permit_flow_is_new($app)
        || (string)$app['status']!=='step4_under_evaluation') {
        return ['This application is no longer in Step 2 evaluation.'];
    }
    $area=trim((string)($input['area_hectares']??''));
    if ($area!=='' && (!preg_match('/^\d{1,10}(?:\.\d{1,4})?$/D',$area)
        || (float)$area<=0)) {
        return ['Area in hectares must be a positive number with up to 4 decimals.'];
    }
    $rows=fpoms_notice_rows($items);
    if (!$rows) return ['At least one deficient requirement is needed before preparing a notice.'];
    $payload=[
        'decision'=>'return',
        'assessment'=>[],
        'requirement_remarks'=>[],
        'overall_remarks'=>(string)($input['overall_remarks']??''),
        'applicant_instructions'=>(string)($input['applicant_instructions']??''),
        'area_hectares'=>$area,
        'notice_rows'=>$rows
    ];
    foreach ($items as $type=>$item) {
        $payload['assessment'][$type]=(string)$item['assessment'];
        $payload['requirement_remarks'][$type]=(string)$item['remarks'];
    }
    $config=fpoms_notice_config();
    // Keep the exact uploaded template revision for RED approval.
    $payload['notice_template_path']=(string)($config['template_path']??'');
    $payload['notice_config_snapshot']=[
        'signatory_name'=>(string)($config['signatory_name']??''),
        'signatory_title'=>(string)($config['signatory_title']??''),
        'contact_emails'=>(string)($config['contact_emails']??''),
        'footer_text'=>(string)($config['footer_text']??'')
    ];
    try {
        $layout=$payload['notice_config_snapshot'];
        $layout['letterhead_images']=fpoms_notice_template_branding($config);
        $pdf=FpomsNoticePdf::render($app,$payload,$layout);
        [$relative,$absolute]=fpoms_notice_private_path('pdf',$applicationId);
        fpoms_file_write_encrypted_bytes($pdf,$absolute);
        $pdo=db();
        $pdo->beginTransaction();
        $locked=query_one('SELECT status,revision FROM permit_step2_applications WHERE id=? FOR UPDATE',
            [$applicationId]);
        $review=query_one(
            "SELECT id FROM permit_step4_reviews
             WHERE application_id=? AND revision=? AND review_status='under_evaluation' FOR UPDATE",
            [$applicationId,(int)$app['revision']]
        );
        if (!$locked || (string)$locked['status']!=='step4_under_evaluation'
            || (int)$locked['revision']!==(int)$app['revision'] || !$review) {
            throw new RuntimeException('Application/review changed; reload before preparing the notice.');
        }
        $existing=fpoms_notice_record($applicationId,(int)$app['revision']);
        if ($existing && (string)$existing['status']==='signed') {
            throw new RuntimeException('RED has already approved this notice for the current revision.');
        }
        $pdo->prepare(
            'INSERT INTO fpoms_deficiency_notices
             (application_id,revision,reviewer_user_id,payload_json,draft_path,draft_sha256,status)
             VALUES (?,?,?,?,?,?,"pending_red")
             ON DUPLICATE KEY UPDATE
                reviewer_user_id=VALUES(reviewer_user_id),
                payload_json=VALUES(payload_json),
                draft_path=VALUES(draft_path),
                draft_sha256=VALUES(draft_sha256),
                status="pending_red",approved_by=NULL,approved_at=NULL,artifact_id=NULL'
        )->execute([
            $applicationId,(int)$app['revision'],(int)$reviewer['id'],
            json_encode($payload,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $relative,hash('sha256',$pdf)
        ]);
        permit_flow_record_event($pdo,$applicationId,2,$reviewer,
            'deficiency_notice_prepared','Draft letter generated; awaiting RED approval.');
        audit((int)$reviewer['id'],'fp.deficiency_notice.prepared',
            'permit_step2_application',$applicationId,['revision'=>(int)$app['revision']]);
        $pdo->commit();
        try {
            notify_role('red','Deficiency Notice for RED Approval – FP #'.$applicationId,
                'LPDD-FUS prepared an unsigned deficiency notice for FP application #'.$applicationId.
                '. RED must preview and explicitly approve before applicant release.',
                'file-earmark-check','warning','deficiency_notice_queue');
        } catch (Throwable $e) { error_log('RED notice queue notification: '.$e->getMessage()); }
        return [];
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        if (isset($absolute) && is_file($absolute)) @unlink($absolute);
        error_log('FP-OMS draft deficiency notice failed: '.$e->getMessage());
        return ['Unable to generate a draft deficiency notice: '.$e->getMessage()];
    }
}

function fpoms_notice_red_approve(array $red,int $applicationId): array
{
    if (($red['status']??'')!=='active' || !has_role($red,'red')) {
        return ['Only an active RED account may approve this notice.'];
    }
    if (!fpoms_notice_schema_ready()) return ['Apply database migration 032 first.'];
    $app=fpoms_notice_application($applicationId);
    if (!$app || !permit_flow_is_new($app)
        || (string)$app['status']!=='step4_under_evaluation') {
        return ['Application cannot be approved in the current workflow status.'];
    }
    $notice=fpoms_notice_record($applicationId,(int)$app['revision']);
    if (!$notice) return ['LPDD-FUS must prepare a draft notice before RED approval.'];
    $payload=json_decode((string)$notice['payload_json'],true);
    if (!is_array($payload)) return ['Stored assessment snapshot is invalid.'];
    $originalReviewer=query_one('SELECT * FROM users WHERE id=?',
        [(int)$notice['reviewer_user_id']]);
    if (!$originalReviewer || (string)$originalReviewer['status']!=='active'
        || !has_role($originalReviewer,'lpdd_fus')) {
        return ['Original LPDD-FUS reviewer is no longer authorized. Reassign/re-evaluate first.'];
    }
    $signed=(string)$notice['status']==='signed';
    if (!$signed) {
        $config=fpoms_notice_config();
        if (empty($config['signature_path']) || empty($config['signature_sha256'])) {
            return ['No RED e-sign JPEG configured. Ask Super Admin to configure it first.'];
        }
        try {
            $signature=fpoms_notice_safe_read((string)$config['signature_path'],
                (string)$config['signature_sha256']);
            // Use the saved immutable findings, never browser-provided assessments.
            $layout=is_array($payload['notice_config_snapshot']??null)
                ? $payload['notice_config_snapshot'] : $config;
            $layout['letterhead_images']=fpoms_notice_template_branding([
                'template_path'=>(string)($payload['notice_template_path']??$config['template_path']??'')
            ]);
            $pdf=FpomsNoticePdf::render($app,$payload,$layout,$signature,date('F d, Y'));
            [$relative,$absolute]=fpoms_notice_private_path('pdf',$applicationId);
            fpoms_file_write_encrypted_bytes($pdf,$absolute);
            $pdo=db();
            $pdo->beginTransaction();
            $locked=query_one('SELECT status,revision FROM permit_step2_applications WHERE id=? FOR UPDATE',
                [$applicationId]);
            $pending=query_one('SELECT * FROM fpoms_deficiency_notices
                WHERE application_id=? AND revision=? FOR UPDATE',[$applicationId,(int)$app['revision']]);
            if (!$locked || (string)$locked['status']!=='step4_under_evaluation'
                || (int)$locked['revision']!==(int)$app['revision']
                || !$pending || (string)$pending['status']!=='pending_red') {
                throw new RuntimeException('Notice changed; reload the RED approval queue.');
            }
            $filename='DENR_RED_Deficiency_Notice_FP'.$applicationId.'_R'.$app['revision'].'.pdf';
            $pdo->prepare('INSERT INTO permit_flow_artifacts
                (application_id,step_number,application_revision,category,original_name,
                 stored_path,mime_type,file_size,sha256,uploaded_by)
                VALUES (?,2,?,"red_signed_deficiency_notice",?,?,"application/pdf",?,?,?)')
                ->execute([
                    $applicationId,(int)$app['revision'],$filename,$relative,
                    strlen($pdf),hash('sha256',$pdf),(int)$red['id']
                ]);
            $artifactId=(int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE fpoms_deficiency_notices
                SET status='signed',artifact_id=?,approved_by=?,approved_at=NOW()
                WHERE id=?")->execute([$artifactId,(int)$red['id'],(int)$notice['id']]);
            permit_flow_record_event($pdo,$applicationId,2,$red,
                'deficiency_notice_red_approved','RED explicitly approved generated notice PDF.');
            audit((int)$red['id'],'fp.deficiency_notice.red_approved',
                'permit_step2_application',$applicationId,[
                    'revision'=>(int)$app['revision'],'artifact_id'=>$artifactId
                ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            if (isset($absolute) && is_file($absolute)) @unlink($absolute);
            error_log('FP-OMS RED notice approval failed: '.$e->getMessage());
            return ['RED approval could not be saved: '.$e->getMessage()];
        }
    }

    // Existing return path records the decision, releases the artifact, and emails applicant.
    // A signed notice can be retried if the legacy downstream save/email failed.
    $payload['_red_finalize']='1';
    return step4_decide($originalReviewer,$applicationId,$payload);
}


/** Migration 033 is additive; older installs can still release and email notices. */
function fpoms_notice_delivery_schema_ready(): bool
{
    try {
        db()->query('SELECT email_status FROM fpoms_deficiency_notices LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Send only the signed/released official PDF; never email unsigned draft findings. */
function fpoms_notice_dispatch_official_email(int $applicationId,int $actorUserId=0): bool
{
    $app=fpoms_notice_application($applicationId);
    if (!$app || (string)$app['status']!=='step4_corrections_required') return false;
    $notice=fpoms_notice_record($applicationId,(int)$app['revision']);
    if (!$notice || (string)$notice['status']!=='signed' || (int)$notice['artifact_id']<1) return false;

    $attachments=permit_flow_notice_email_attachments($applicationId,(int)$app['revision']);
    $sent=false;
    if ($attachments) {
        $url=permit_flow_application_email_url($applicationId);
        $direct=$url;
        if ($url!==null) {
            $direct=preg_replace(
                '~permit-application\?id=[0-9]+$~',
                'permit-flow-file?id='.$applicationId.'&file='.(int)$notice['artifact_id'].'&preview=1',
                $url
            ) ?: $url;
        } else {
            error_log('FP-OMS official notice email: APP_URL is not a valid reachable HTTPS base URL.');
        }
        $payload=json_decode((string)$notice['payload_json'],true);
        $overall=is_array($payload)?trim((string)($payload['overall_remarks']??'')):'';
        $sent=permit_flow_email($app,
            'Official RED-Approved Deficiency Notice – FP Application #'.$applicationId,
            'LPDD-FUS determined that your FP application requires documentary compliance. '
            .'Please open the official RED-approved notice, correct the listed requirements and '
            .'resubmit through your FP-OMS account. '
            .($overall!==''?'Evaluation remarks: '.$overall:''),
            $direct,$attachments,'View Official Deficiency Notice PDF');
    } else {
        error_log('FP-OMS official notice email: signed PDF attachment is missing or failed integrity verification.');
    }

    if (fpoms_notice_delivery_schema_ready()) {
        try {
            db()->prepare("UPDATE fpoms_deficiency_notices
                SET email_status=?,email_last_attempt_at=NOW(),
                    email_sent_at=CASE WHEN ?='sent' THEN NOW() ELSE email_sent_at END
                WHERE id=?")
                ->execute([$sent?'sent':'failed',$sent?'sent':'failed',(int)$notice['id']]);
        } catch (Throwable $e) {
            error_log('FP-OMS notice email status update failed: '.$e->getMessage());
        }
    }
    if ($actorUserId>0) {
        try {
            audit($actorUserId,$sent?'fp.deficiency_notice.email_sent':'fp.deficiency_notice.email_failed',
                'permit_step2_application',$applicationId,[
                    'revision'=>(int)$app['revision'],
                    'artifact_id'=>(int)$notice['artifact_id']
                ]);
        } catch (Throwable $e) {
            error_log('FP-OMS notice email audit failed: '.$e->getMessage());
        }
    }
    return $sent;
}

function fpoms_notice_retry_official_email(array $staff,int $applicationId): array
{
    if (($staff['user_kind']??'')!=='internal'
        || ($staff['status']??'')!=='active'
        || !(has_role($staff,'red') || has_role($staff,'lpdd_fus'))) {
        return ['Only authorized RED or LPDD-FUS staff can retry official notices.'];
    }
    $app=fpoms_notice_application($applicationId);
    if (!$app || (string)$app['status']!=='step4_corrections_required') {
        return ['Only an officially returned application may have its notice resent.'];
    }
    if (!fpoms_notice_dispatch_official_email($applicationId,(int)$staff['id'])) {
        return ['Email delivery failed. Check MAIL_ENABLED, SMTP credentials, recipient email and server logs. The in-system PDF remains available.'];
    }
    return [];
}

function fpoms_notice_send_pdf(array $viewer,int $applicationId): never
{
    $app=step2_application($applicationId);
    if (!$app) show_error('Application not found.',404);
    $notice=fpoms_notice_record($applicationId,(int)$app['revision']);
    if (!$notice) show_error('Notice not found.',404);
    $isStaff=($viewer['user_kind']??'')==='internal'
        && ($viewer['status']??'')==='active'
        && (has_role($viewer,'lpdd_fus') || has_role($viewer,'red')
            || has_role($viewer,'super_admin'));
    if (!$isStaff) show_error('You cannot view an unsigned notice.',403);
    try {
        $pdf=fpoms_notice_safe_read((string)$notice['draft_path'],(string)$notice['draft_sha256']);
    } catch (Throwable $e) { show_error('Protected notice unavailable.',404); }
    header('Content-Type: application/pdf');
    header('Cache-Control: private,no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="Draft_Deficiency_Notice.pdf"');
    header('Content-Length: '.strlen($pdf));
    echo $pdf;
    exit;
}

function fpoms_notice_config_view(array $admin,array $errors=[]): void
{
    if (!has_role($admin,'super_admin')) show_error('Access denied.',403);
    $config=fpoms_notice_config();
    $html='<div class="panel"><h2 class="h5">Deficiency Notice Template / RED e-Signature</h2>'
        .'<p class="text-secondary">Upload the official Template No. 1 DOCX. '
        .'FP-OMS reads its DENR seal, Bagong Pilipinas and SOCOTEC images for the PDF letterhead, '
        .'then fills the applicant, project and deficiency table automatically. '
        .'The generated PDF follows the approved letter structure; exact Word pagination may vary.</p>'
        .errors_html($errors);
    if (!$config) {
        $html.='<div class="alert alert-danger">Apply database migration 032 first.</div>';
        render_page('Deficiency Notice Configuration',$html.'</div>'); return;
    }
    $html.='<form method="post" enctype="multipart/form-data" action="'
        .e(url('deficiency_notice_settings')).'">'.csrf_field()
        .'<div class="row g-3">'
        .'<div class="col-md-6"><label class="form-label">RED Signatory Name</label>'
        .'<input class="form-control" name="signatory_name" maxlength="180" required value="'
        .e((string)$config['signatory_name']).'"></div>'
        .'<div class="col-md-6"><label class="form-label">Signatory Position/Designation</label>'
        .'<input class="form-control" name="signatory_title" maxlength="180" required value="'
        .e((string)$config['signatory_title']).'"></div>'
        .'<div class="col-12"><label class="form-label">Contact Emails / Assistance</label>'
        .'<input class="form-control" name="contact_emails" maxlength="400" value="'
        .e((string)$config['contact_emails']).'"></div>'
        .'<div class="col-12"><label class="form-label">Footer Address / Hotline</label>'
        .'<input class="form-control" name="footer_text" maxlength="300" value="'
        .e((string)$config['footer_text']).'"></div>'
        .'<div class="col-md-6"><label class="form-label">Official DOCX Template (reference)</label>'
        .'<input class="form-control" type="file" name="template" accept=".docx">'
        .'<div class="small text-secondary mt-1">Current: '
        .e((string)($config['template_name']??'No official template uploaded')).'</div>'
        .'<div class="small text-secondary">Required: official DOCX with DENR, Bagong Pilipinas and SOCOTEC artwork.</div></div>'
        .'<div class="col-md-6"><label class="form-label">RED Signature Image (JPG/JPEG)</label>'
        .'<input class="form-control" type="file" name="signature" accept=".jpg,.jpeg,image/jpeg">'
        .'<div class="small text-secondary mt-1">'
        .(!empty($config['signature_path'])?'Configured securely':'Not yet configured')
        .'. Uploaded signature is NEVER applied until RED explicitly approves each notice.</div></div>'
        .'</div><div class="mt-3"><button class="btn btn-success" type="submit">'
        .'Save Notice Configuration</button></div></form>'
        .'<div class="alert alert-info mt-3 mb-0">A JPEG signature image is an electronic visual mark, '
        .'not a PNPKI cryptographic signature. Use your approved PNPKI document-signing integration '
        .'when a digitally verifiable signature is required.</div>'
        .'</div>';
    render_page('Deficiency Notice Configuration',$html);
}

function fpoms_notice_queue_view(array $red): void
{
    if (!has_role($red,'red') || ($red['status']??'')!=='active') show_error('RED approval role required.',403);
    if (!fpoms_notice_schema_ready()) {
        render_page('RED Deficiency Approvals',
            '<div class="alert alert-warning">Apply database migration 032 first.</div>');return;
    }
    $rows=query_all(
        "SELECT n.*,a.project_title,a.status AS application_status,c.name AS applicant_name
         FROM fpoms_deficiency_notices n
         JOIN permit_step2_applications a ON a.id=n.application_id AND a.revision=n.revision
         JOIN user_client c ON c.id=a.client_id
         WHERE n.status='pending_red' OR
             (n.status='signed' AND a.status='step4_under_evaluation')
         ORDER BY n.created_at ASC LIMIT 100"
    );
    $html='<div class="panel"><h2 class="h5">RED – Pending Generated Deficiency Notices</h2>'
        .'<p class="text-secondary">Preview drafts before approving. RED-approved notices awaiting '
        .'final application return also appear here for safe retry.</p>';
    if (!$rows) $html.='<div class="alert alert-success">No notices awaiting RED approval.</div>';
    foreach ($rows as $row) {
        $id=(int)$row['application_id'];
        $html.='<div class="border rounded p-3 mb-3"><strong>FP #'.$id.' · '
            .e((string)$row['project_title']).'</strong><div class="small text-secondary">'
            .e((string)$row['applicant_name']).' · Revision '.(int)$row['revision'].'</div>'
            .'<div class="d-flex flex-wrap gap-2 mt-2">'
            .'<a target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm" href="'
            .e(url('deficiency_notice_pdf',['id'=>$id])).'">Preview Draft PDF</a>'
            .'<form method="post" action="'.e(url('deficiency_notice_approve',['id'=>$id])).'">'
            .csrf_field()
            .'<label class="d-block small my-2"><input type="checkbox" name="confirmation" '
            .'value="1" required> I reviewed the PDF and explicitly approve applying the RED signature.</label>'
            .'<button type="submit" class="btn btn-success btn-sm">'
            .((string)$row['status']==='signed'?'Finalize Approved Return':'RED Approve &amp; Sign')
            .'</button>'
            .'</form></div></div>';
    }
    render_page('RED Deficiency Approvals',$html.'</div>');
}
