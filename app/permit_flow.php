<?php
declare(strict_types=1);

/**
 * Updated Annex A (flow_version=2). Status strings from the old implementation
 * are retained for database compatibility, but Step 2 now owns the documentary
 * completeness/sufficiency decision BEFORE RO Receiving.
 *
 * Never migrate active legacy cases automatically; they remain flow_version=1.
 */
function permit_flow_ready(): bool
{
    static $ready=null;
    if ($ready!==null) return $ready;
    try {
        db()->query('SELECT flow_version FROM permit_step2_applications LIMIT 0');
        db()->query('SELECT application_id,current_step FROM permit_flow_cases LIMIT 0');
        db()->query('SELECT id FROM permit_flow_artifacts LIMIT 0');
        db()->query('SELECT id FROM permit_flow_events LIMIT 0');
        return $ready=true;
    } catch (Throwable $e) {
        error_log('FP-OMS migration 031 required: '.$e->getMessage());
        return $ready=false;
    }
}
function permit_flow_is_new(array $app): bool
{
    return (int)($app['flow_version']??1)===2;
}
/** Version-aware display without mutating legacy status codes or audit history. */
function permit_flow_status_badge(array $app): string
{
    if (!permit_flow_is_new($app)) return step2_status_badge((string)($app['status']??''));
    $status=(string)($app['status']??'');
    $label=match ($status) {
        'submitted'=>'Step 2 – Awaiting LPDD-FUS Evaluation',
        'step4_under_evaluation'=>'Step 2 – Documentary Evaluation',
        'step4_corrections_required'=>'Step 2 – Compliance Required',
        'step4_resubmitted'=>'Step 2 – Resubmitted',
        'forwarded_to_ro'=>'Step 2 Complete / RO Step 3',
        'ro_received'=>'Step 3 – Received by RO',
        'forwarded_to_lpdd'=>'Step 4 – Incoming to LPDD-FUS',
        'step4_complete'=>permit_flow_step_label((int)(permit_flow_case((int)$app['id'])['current_step']??5)),
        'reverted'=>'Reverted – Editable',
        default=>'Updated Annex A – '.ucwords(str_replace('_',' ',$status))
    };
    $tone=in_array($status,['step4_corrections_required'],true)?'danger'
        :(in_array($status,['step4_complete'],true)?'success':'info');
    return '<span class="badge text-bg-'.$tone.'">'.e($label).'</span>';
}

function permit_flow_require(): void
{
    if (!permit_flow_ready()) show_error('Apply database migration 031 before using the revised Annex A workflow.',503);
}
function permit_flow_case(int $id): ?array
{
    if (!permit_flow_ready()) return null;
    return query_one('SELECT * FROM permit_flow_cases WHERE application_id=?',[$id]);
}
function permit_flow_roles(): array
{
    return ['lpdd_fus','ro_receiving','cenro_twg','gis_focal','smd','cdd',
        'rrdc_member','rrdc_secretariat','lpdd_chief','ard_ts','red','super_admin'];
}
function permit_flow_staff_can_view(array $user): bool
{
    if (($user['user_kind']??'')!=='internal' || ($user['status']??'')!=='active') return false;
    foreach (permit_flow_roles() as $role) if (has_role($user,$role)) return true;
    return false;
}
function permit_flow_staff_can_act(array $user,int $step): bool
{
    if (!permit_flow_staff_can_view($user)) return false;
    $roles=match ($step) {
        4,7=>['lpdd_fus'],5=>['cenro_twg'],6=>['rrdc_secretariat'],
        8=>['lpdd_chief'],9=>['ard_ts'],10=>['red'],11=>['ro_receiving'],
        default=>[]
    };
    foreach ($roles as $role) if (has_role($user,$role)) return true;
    return false;
}
function permit_flow_step_label(int $step): string
{
    return [
        2=>'LPDD-FUS Documentary Completeness and Sufficiency',
        3=>'RO Receiving/Releasing',
        4=>'LPDD-FUS Payment Notice, Schedule and CENRO Instruction',
        5=>'CENRO Field Validation and Assessment',
        6=>'RRDC Deliberation (Days 1–4)',
        7=>'LPDD-FUS Document Preparation',
        8=>'LPDD Initial',
        9=>'ARD-TS Review',
        10=>'RED Approval',
        11=>'RO Release of Approved Documents',
        12=>'Feasibility Permit Issued'
    ][$step] ?? 'Unknown Step';
}

/** Required uploaded documents and the authorized role for each category. */
function permit_flow_categories(int $step): array
{
    return match ($step) {
        2=>['red_signed_deficiency_notice'=>['RED-signed electronic deficiency notice','lpdd_fus']],
        4=>['cenro_memo'=>['Memo instruction to CENRO','lpdd_fus']],
        5=>[
            'csw_report'=>['CENRO CSW / Field Validation Report','cenro_twg'],
            'gis_map'=>['CENRO GIS Map','cenro_twg'],
            'shapefiles'=>['Shapefiles (ZIP archive)','cenro_twg'],
            'td'=>['TD / Technical Description','cenro_twg'],
            'authenticity_certificate'=>['Certification of Authenticity','cenro_twg']
        ],
        6=>[
            'final_map'=>['SMD / GIS Final Map (Day 1)','gis_focal'],
            'minutes'=>['CDD Minutes (Day 3)','cdd'],
            'agreements'=>['CDD Agreements (Day 3)','cdd'],
            'approval_sheet'=>['Secretariat Signed Approval Sheet','rrdc_secretariat'],
            'signed_final_map'=>['Scanned with Signed Final Map (Days 3–4)','rrdc_secretariat']
        ],
        7=>[
            'memo_endorsement'=>['Memo Endorsement to RED','lpdd_fus'],
            'fp_draft'=>['Generated Feasibility Permit (FP)','lpdd_fus'],
            'tor_draft'=>['Generated Terms of Reference (TOR)','lpdd_fus']
        ],
        8=>[
            'initialed_memo'=>['LPDD-Initialed Memo','lpdd_chief'],
            'initialed_fp'=>['LPDD-Initialed FP','lpdd_chief'],
            'initialed_tor'=>['LPDD-Initialed TOR','lpdd_chief']
        ],
        9=>[
            'signed_memo'=>['ARD-TS Signed Memo Endorsement','ard_ts'],
            'initialed_fp_ard'=>['ARD-TS Initialed FP','ard_ts'],
            'initialed_tor_ard'=>['ARD-TS Initialed TOR','ard_ts']
        ],
        10=>[
            'approved_fp'=>['RED-Approved Feasibility Permit','red'],
            'approved_tor'=>['RED-Approved Terms of Reference','red'],
            'approved_map'=>['RED-Approved Map','red'],
            'approved_sheet'=>['RED-Approved Approval Sheet','red'],
            'memo_to_penro_cenro'=>['RED-Approved Memo to PENRO/CENRO','red']
        ],
        11=>[
            'release_record'=>['Records Hard-Copy Release Record / Acknowledgment','ro_receiving'],
            'printed_memo'=>['Printed Memo to PENRO/CENRO','ro_receiving'],
            'transmittal'=>['Approved Documents Transmittal','ro_receiving']
        ],
        default=>[]
    };
}
function permit_flow_upload_allowed(array $staff,array $app,int $step,string $category): bool
{
    if (!permit_flow_is_new($app) || !isset(permit_flow_categories($step)[$category])
        || !permit_flow_staff_can_view($staff)) return false;
    // Step 2 deficiency notices are generated by the system and signed only by RED.
    // The former per-application manual upload endpoint is deliberately disabled.
    if ($step===2) return false;
    {
        $case=permit_flow_case((int)$app['id']);
        if (!$case || (int)$case['current_step']!==$step || $case['completed_at']!==null) return false;
    }
    $role=permit_flow_categories($step)[$category][1];
    return has_role($staff,$role) || ($step===6 && $category==='final_map' && has_role($staff,'smd'));
}
function permit_flow_artifacts(int $id,?int $step=null): array
{
    if (!permit_flow_ready()) return [];
    return query_all(
        'SELECT d.*,u.name AS uploader FROM permit_flow_artifacts d
         LEFT JOIN users u ON u.id=d.uploaded_by
         WHERE d.application_id=?'.($step!==null ? ' AND d.step_number=?' : '')
        .' ORDER BY d.id DESC',$step===null?[$id]:[$id,$step]);
}
/** Bind the actual RED-signed deficiency notice to the review revision. */
function permit_flow_signed_notice_for_revision(int $id,int $revision): bool
{
    return permit_flow_signed_notice_artifact_for_revision($id,$revision)!==null;
}
function permit_flow_signed_notice_artifact_for_revision(int $id,int $revision): ?array
{
    if (!permit_flow_ready() || $revision<1) return null;
    return query_one(
        "SELECT * FROM permit_flow_artifacts
         WHERE application_id=? AND step_number=2 AND application_revision=?
           AND category='red_signed_deficiency_notice'
         ORDER BY id DESC LIMIT 1",
        [$id,$revision]
    );
}

function permit_flow_events(int $id): array
{
    if (!permit_flow_ready()) return [];
    return query_all('SELECT e.*,u.name AS actor_name FROM permit_flow_events e
        LEFT JOIN users u ON u.id=e.actor_id WHERE e.application_id=?
        ORDER BY e.id DESC',[$id]);
}
function permit_flow_record_event(PDO $pdo,int $id,int $step,array $staff,string $action,string $notes): void
{
    $pdo->prepare('INSERT INTO permit_flow_events
        (application_id,step_number,action,remarks,actor_id) VALUES (?,?,?,?,?)')
        ->execute([$id,$step,$action,$notes!==''?$notes:null,(int)$staff['id']]);
    audit((int)$staff['id'],'permit.annex_a.'.$action,
        'permit_step2_application',$id,['step'=>$step,'remarks'=>$notes]);
}

/** Explicit receipt of RED-signed templates, and audited officer document uploads. */
function permit_flow_upload(array $staff,int $id,int $step,string $category,mixed $file): array
{
    if (!permit_flow_ready()) return ['Migration 031 is required.'];
    $app=step2_application($id);
    if (!$app || !permit_flow_upload_allowed($staff,$app,$step,$category)) {
        return ['You cannot upload this document at the current workflow step or with your current role.'];
    }
    $upload=upload_details($file,true);
    $isZip=$category==='shapefiles' && is_array($file)
        && (int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK
        && is_string($file['tmp_name']??null)
        && is_uploaded_file($file['tmp_name'])
        && preg_match('/\.zip$/i',(string)($file['name']??''));
    if ($isZip) {
        $tmp=(string)$file['tmp_name'];
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $limit=max(1,(int)env_value('MAX_UPLOAD_BYTES','5242880'));
        $size=(int)($file['size']??0);
        if (!in_array($mime,['application/zip','application/x-zip','application/x-zip-compressed'],true)
            || $size<1 || $size>$limit) {
            return ['Shapefiles must be a ZIP archive within the configured upload size limit.'];
        }
        $upload=['file'=>$file,'name'=>basename((string)$file['name']),
            'extension'=>'zip','mime'=>'application/zip','size'=>$size];
    } elseif (isset($upload['error'])) {
        return ['Upload: '.$upload['error']];
    }
    if (empty($upload['file'])) return ['Choose a valid PDF/JPG/PNG document, or a ZIP for shapefiles.'];
    $path='fp_workflow/'.$id.'/'.$step.'/'.bin2hex(random_bytes(20)).'.'.$upload['extension'];
    $absolute=PROJECT_ROOT.'/storage/'.$path;
    $pdo=db();
    try {
        $pdo->beginTransaction();
        $locked=query_one('SELECT * FROM permit_step2_applications WHERE id=? FOR UPDATE',[$id]);
        if (!$locked || !permit_flow_upload_allowed($staff,$locked,$step,$category)) {
            $pdo->rollBack();
            return ['Application has moved to a different step. Refresh the page.'];
        }
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute),0700,true)
            && !is_dir(dirname($absolute))) throw new RuntimeException('Unable to create private upload directory.');
        fpoms_file_store_upload((string)$upload['file']['tmp_name'],$absolute);
        @chmod($absolute,0600);
        $digest=fpoms_file_plaintext_sha256($absolute);
        $pdo->prepare('INSERT INTO permit_flow_artifacts
            (application_id,step_number,application_revision,category,original_name,
             stored_path,mime_type,file_size,sha256,uploaded_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$step,(int)$locked['revision'],$category,$upload['name'],
                $path,$upload['mime'],(int)$upload['size'],$digest,(int)$staff['id']]);
        permit_flow_record_event($pdo,$id,$step,$staff,'document_uploaded',
            $category.': '.$upload['name']);
        $pdo->commit();
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_file($absolute)) @unlink($absolute);
        error_log('FP-OMS Annex A document upload error: '.$e->getMessage());
        return ['Unable to securely store workflow document.'];
    }
}
function permit_flow_missing_artifacts(int $id,int $step): array
{
    $existing=array_column(permit_flow_artifacts($id,$step),'category');
    $missing=[];
    foreach (permit_flow_categories($step) as $category=>$config) {
        if (!in_array($category,$existing,true)) $missing[]=$config[0];
    }
    return $missing;
}

/**
 * Advances one signed/validated stage at a time. Step 4 automatically sends the
 * Cashier→LPDD-FUS notice, but does NOT mark the applicant as paid.
 */
function permit_flow_advance(array $staff,int $id,int $step,array $input): array
{
    if (!permit_flow_ready()) return ['Migration 031 is required.'];
    if (!permit_flow_staff_can_act($staff,$step)) return ['Current role cannot complete this step.'];
    if (($input['confirmation']??'')!=='1') return ['Confirm the stage hand-off first.'];
    $notes=trim((string)($input['remarks']??''));
    if (strlen($notes)>3000) return ['Remarks must not exceed 3,000 characters.'];

    $schedule=null;
    $instructions='';
    if ($step===4) {
        $schedule=step4_parse_schedule(trim((string)($input['field_validation_schedule']??'')));
        $instructions=trim((string)($input['cenro_instructions']??''));
        if (!$schedule) return ['Set a valid CENRO field validation/assessment schedule.'];
        if (strlen($instructions)<10 || strlen($instructions)>3000) {
            return ['Provide CENRO memo instructions (10–3,000 characters).'];
        }
        if (!permit_payment_schema_ready()) return ['Apply migration 028 for Cashier payment tracking.'];
    }
    if ($step===5 && !permit_flow_event_exists($id,5,'hardcopy_notice_sent')) {
        return ['CENRO must notify the applicant to submit hard copies before completing Step 5.'];
    }
    if ($step===5 && !permit_flow_event_exists($id,5,'twg_instructed')) {
        return ['CENRO must instruct the TWG to conduct field validation/assessment before completing Step 5.'];
    }
    if ($step===6 && (!permit_flow_event_exists($id,6,'rrdc_scheduled')
        || !permit_flow_event_exists($id,6,'rrdc_deliberated'))) {
        return ['Record RRDC meeting schedule (CDD) and actual deliberation (RRDC) before forwarding signed documents.'];
    }
    $missing=permit_flow_missing_artifacts($id,$step);
    if ($missing) return ['Upload required documents first: '.implode('; ',$missing).'.'];
    $pdo=db();
    $app=null;
    try {
        $pdo->beginTransaction();
        $app=query_one('SELECT a.*,c.name AS applicant_name,c.email AS applicant_email
            FROM permit_step2_applications a JOIN user_client c ON c.id=a.client_id
            WHERE a.id=? FOR UPDATE',[$id]);
        $case=query_one('SELECT * FROM permit_flow_cases WHERE application_id=? FOR UPDATE',[$id]);
        if (!$app || !permit_flow_is_new($app) || !$case
            || (int)$case['current_step']!==$step || $case['completed_at']!==null
            || ($step===4 && (string)$app['status']!=='forwarded_to_lpdd')) {
            $pdo->rollBack();
            return ['Application is no longer at this step. Refresh the workflow.'];
        }
        // Recheck required documents inside the transaction after locking the case.
        $missing=permit_flow_missing_artifacts($id,$step);
        if ($missing) { $pdo->rollBack(); return ['Required signed documents are still missing.']; }
        if ($step===5 && (!permit_flow_event_exists($id,5,'hardcopy_notice_sent')
            || !permit_flow_event_exists($id,5,'twg_instructed'))) {
            $pdo->rollBack(); return ['Record CENRO hardcopy notice and TWG instructions first.'];
        }
        if ($step===6 && (!permit_flow_event_exists($id,6,'rrdc_scheduled')
            || !permit_flow_event_exists($id,6,'rrdc_deliberated'))) {
            $pdo->rollBack(); return ['RRDC schedule and deliberation records are required.'];
        }
        if ($step===4) {
            $pdo->prepare('UPDATE permit_flow_cases
                SET field_validation_schedule=?,cenro_instructions=?,notice_text=? WHERE application_id=?')
                ->execute([$schedule,$instructions,permit_payment_instructions($id),$id]);
            $pdo->prepare("UPDATE permit_step2_applications SET status='step4_complete' WHERE id=?")
                ->execute([$id]);
            $pdo->prepare("INSERT INTO permit_payment_verifications
                (application_id,status,notified_at) VALUES (?,'awaiting_payment',NOW())
                ON DUPLICATE KEY UPDATE notified_at=COALESCE(notified_at,VALUES(notified_at))")
                ->execute([$id]);
        }
        $next=$step===11?12:$step+1;
        $pdo->prepare('UPDATE permit_flow_cases
            SET current_step=?,completed_at=IF(?=12,NOW(),NULL) WHERE application_id=?')
            ->execute([$next,$next,$id]);
        permit_flow_record_event($pdo,$id,$step,$staff,$step===11?'permit_issued':'step_completed',$notes);
        $pdo->commit();
        fpoms_pusher_flush_pending();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS Annex A step advance error: '.$e->getMessage());
        return ['Stage update could not be saved. Check migration 031 and contact RICTU.'];
    }
    $nextRole=match ($step) {
        4=>'cenro_twg',5=>'rrdc_secretariat',6=>'lpdd_fus',
        7=>'lpdd_chief',8=>'ard_ts',9=>'red',10=>'ro_receiving',default=>null
    };
    try {
        if ($nextRole) notify_role($nextRole,'FP Application #'.$id.' – Step '.($step+1).' Action Required',
            'Updated Annex A application #'.$id.' is ready for '.permit_flow_step_label($step+1).'.',
            'file-earmark-check','info','permit_flow',['id'=>$id]);
        if ($step===4) {
            $message=permit_payment_instructions($id)
                .' Field validation schedule: '.fpoms_display_datetime((string)$schedule).'.';
            create_client_notification((int)$app['client_id'],'Notice to Pay Application Fee',
                $message,'receipt','info','permit_application',['id'=>$id]);
            notify_role('lpdd_fus','FP Cashier Payment Awaiting Verification',
                'FP application #'.$id.' requires Cashier payment and LPDD-FUS receipt verification.',
                'receipt','info','lpdd_fus_payment_queue',['tab'=>'awaiting']);
            permit_flow_email($app,'Notice to Pay – FP Application #'.$id,$message);
        } elseif ($step===11) {
            $message='Your FP application #'.$id.' has been approved and released. '
                .'See the authorized FP/TOR documents and records release instructions in FP-OMS.';
            create_client_notification((int)$app['client_id'],'Feasibility Permit Issued',
                $message,'check-circle','success','permit_flow',['id'=>$id]);
            permit_flow_email($app,'Approved Feasibility Permit Released – #'.$id,$message);
        }
    } catch (Throwable $e) {
        error_log('FP-OMS Annex A step notification failure: '.$e->getMessage());
    }
    return [];
}
/** Build email links only from the configured system URL, never the Host header. */
function permit_flow_application_email_url(int $id): ?string
{
    if ($id<1) return null;
    $base=rtrim(trim(env_value('APP_URL')),'/');
    $parts=parse_url($base);
    if ($parts===false || filter_var($base,FILTER_VALIDATE_URL)===false
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) return null;
    $scheme=strtolower((string)($parts['scheme']??''));
    $host=strtolower(trim((string)$parts['host'],'[]'));
    $loopback=in_array($host,['localhost','127.0.0.1','::1'],true);
    if ($scheme!=='https' && !($scheme==='http' && $loopback)) return null;
    return $base.'/permit-application?id='.$id;
}

/** Read the authorized notice from encrypted storage for email attachment. */
function permit_flow_notice_email_attachments(int $id,int $revision): array
{
    $doc=permit_flow_signed_notice_artifact_for_revision($id,$revision);
    if (!$doc) return [];
    $base=realpath(PROJECT_ROOT.'/storage/fp_workflow');
    $absolute=realpath(PROJECT_ROOT.'/storage/'.(string)$doc['stored_path']);
    if (!$base || !$absolute || !str_starts_with($absolute,$base.DIRECTORY_SEPARATOR)
        || !is_file($absolute)) {
        error_log('FP-OMS deficiency notice email attachment is unavailable.');
        return [];
    }
    try {
        $data=fpoms_file_read($absolute);
    } catch (Throwable $e) {
        error_log('FP-OMS deficiency notice email read failed: '.$e->getMessage());
        return [];
    }
    if (!hash_equals((string)$doc['sha256'],hash('sha256',$data))) {
        error_log('FP-OMS deficiency notice email attachment failed integrity verification.');
        return [];
    }
    return [[
        'data'=>$data,
        'name'=>(string)$doc['original_name'],
        'mime'=>(string)$doc['mime_type'],
    ]];
}

function permit_flow_email(
    array $app,string $subject,string $message,?string $applicationUrl=null,
    array $attachments=[],string $linkLabel='Open FP Application'
): bool
{
    $email=(string)($app['applicant_email']??'');
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) {
        error_log('FP-OMS Annex A email skipped: applicant email is invalid.');
        return false;
    }
    try {
        $linkHtml=$applicationUrl!==null
            ? '<p><a href="'.e($applicationUrl).'" style="display:inline-block;padding:10px 16px;'
                .'background:#198754;color:#fff;text-decoration:none;border-radius:6px">'
                .e($linkLabel).'</a></p><p style="font-size:13px">'.e($applicationUrl).'</p>'
            : '';
        $plain=$message.($applicationUrl!==null ? "\n\nOpen FP Application: ".$applicationUrl : '');
        return smtp_send_html($email,$subject,
            registration_email_layout($subject,(string)($app['applicant_name']??'Applicant'),
                '<p>'.nl2br(e($message)).'</p>'.$linkHtml),$plain,[],$attachments);
    } catch (Throwable $e) {
        error_log('FP-OMS Annex A mail error: '.$e->getMessage());
        return false;
    }
}
function permit_flow_event_exists(int $id,int $step,string $action): bool
{
    return permit_flow_ready() && query_one(
        'SELECT id FROM permit_flow_events
         WHERE application_id=? AND step_number=? AND action=? LIMIT 1',[$id,$step,$action]
    )!==null;
}
/**
 * Audited non-documentary milestones prevent premature Step 5 and Step 6
 * approvals. An RRDC event records an action, never fabricates a signature.
 */
function permit_flow_milestone(array $staff,int $id,string $action,array $input): array
{
    $config=match ($action) {
        'twg_instructed'=>['step'=>5,'roles'=>['cenro_twg']],
        'rrdc_scheduled'=>['step'=>6,'roles'=>['cdd']],
        'rrdc_deliberated'=>['step'=>6,'roles'=>['rrdc_member','rrdc_secretariat']],
        default=>null
    };
    if (!$config) return ['Invalid Annex A milestone.'];
    $step=(int)$config['step'];
    if (!permit_flow_staff_can_view($staff)) return ['Only authorized DENR personnel may record milestones.'];
    $authorized=false;
    foreach ($config['roles'] as $role) if (has_role($staff,$role)) $authorized=true;
    if (!$authorized) return ['This milestone belongs to another DENR office/role.'];
    if (($input['confirmation']??'')!=='1') return ['Confirm the milestone first.'];
    $notes=trim((string)($input['remarks']??''));
    if (strlen($notes)<10 || strlen($notes)>3000) {
        return ['Provide a meaningful 10–3,000 character description of the action.'];
    }
    $schedule=null;
    if ($action==='rrdc_scheduled') {
        $schedule=step4_parse_schedule(trim((string)($input['rrdc_schedule']??'')));
        if (!$schedule) return ['Provide a valid RRDC deliberation date/time.'];
    }
    $pdo=db();
    try {
        $pdo->beginTransaction();
        $app=query_one('SELECT a.id,a.flow_version FROM permit_step2_applications a WHERE a.id=? FOR UPDATE',[$id]);
        $case=query_one('SELECT * FROM permit_flow_cases WHERE application_id=? FOR UPDATE',[$id]);
        if (!$app || !permit_flow_is_new($app) || !$case
            || (int)$case['current_step']!==$step || $case['completed_at']!==null) {
            $pdo->rollBack(); return ['The application has moved to another step. Refresh.'];
        }
        if (permit_flow_event_exists($id,$step,$action)) {
            $pdo->rollBack(); return ['This stage milestone was already recorded.'];
        }
        if ($action==='rrdc_deliberated'
            && !permit_flow_event_exists($id,6,'rrdc_scheduled')) {
            $pdo->rollBack(); return ['CDD must first record the RRDC schedule.'];
        }
        if ($schedule!==null) {
            $pdo->prepare('UPDATE permit_flow_cases SET rrdc_schedule=? WHERE application_id=?')
                ->execute([$schedule,$id]);
        }
        permit_flow_record_event($pdo,$id,$step,$staff,$action,
            ($schedule!==null?'RRDC schedule: '.$schedule.'. ':'').$notes);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS Annex A milestone error: '.$e->getMessage());
        return ['Milestone could not be recorded.'];
    }
    try {
        if ($action==='rrdc_scheduled') {
            foreach (['rrdc_member','rrdc_secretariat','smd'] as $role) {
                notify_role($role,'RRDC Deliberation Scheduled – FP #'.$id,
                    'CDD scheduled RRDC deliberation on '
                    .fpoms_display_datetime((string)$schedule)
                    .'. Open the Annex A case to review the schedule.',
                    'calendar','info','permit_flow',['id'=>$id]);
            }
        }
    } catch (Throwable $e) {
        error_log('RRDC milestone notifications failed: '.$e->getMessage());
    }
    return [];
}

function permit_flow_notify_hardcopies(array $staff,int $id): array
{
    if (!permit_flow_staff_can_act($staff,5)) return ['Only CENRO TWG can send this notice.'];
    $app=step2_application($id);
    $case=permit_flow_case($id);
    if (!$app || !permit_flow_is_new($app) || !$case || (int)$case['current_step']!==5) {
        return ['Application is not awaiting CENRO Step 5 action.'];
    }
    if (permit_flow_event_exists($id,5,'hardcopy_notice_sent')) return ['Hard-copy notice was already issued.'];
    $pdo=db();
    try {
        $pdo->beginTransaction();
        $locked=query_one('SELECT * FROM permit_flow_cases WHERE application_id=? FOR UPDATE',[$id]);
        if (!$locked || (int)$locked['current_step']!==5
            || permit_flow_event_exists($id,5,'hardcopy_notice_sent')) {
            $pdo->rollBack();return ['CENRO notice already sent or stage changed.'];
        }
        permit_flow_record_event($pdo,$id,5,$staff,'hardcopy_notice_sent',
            'Applicant requested to submit hard copies for CENRO field validation.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('CENRO notice failed: '.$e->getMessage());
        return ['Unable to record CENRO hardcopy notification.'];
    }
    $message='CENRO requests submission of the required hard copies for FP application #'.$id
        .'. Coordinate with CENRO/TWG for field validation/assessment of the proposed area.';
    try {
        create_client_notification((int)$app['client_id'],'Submit Hard Copies to CENRO',
            $message,'file-earmark-text','info','permit_flow',['id'=>$id]);
    } catch (Throwable $e) { error_log('CENRO bell notice failed: '.$e->getMessage()); }
    permit_flow_email($app,'CENRO Field Validation – Hard Copies – #'.$id,$message);
    return [];
}
function permit_flow_send_artifact(array $user,int $id,int $documentId): never
{
    permit_flow_require();
    $app=step2_application($id);
    $doc=query_one('SELECT * FROM permit_flow_artifacts WHERE id=? AND application_id=?',[$documentId,$id]);
    if (!$app || !$doc || !permit_flow_is_new($app)) show_error('Document not found.',404);
    $mine=($user['user_kind']??'')==='applicant'
        && (int)$user['id']===(int)$app['client_id'];
    $case=permit_flow_case($id);
    $issued=$case && (int)$case['current_step']===12
        && !empty($case['completed_at']);
    $latestNotice=query_one("SELECT MAX(created_at) AS sent_at
        FROM permit_flow_events WHERE application_id=? AND step_number=2
        AND action='deficiency_notice_issued'",[$id]);
    $noticeReleased=!empty($latestNotice['sent_at'])
        && (string)$doc['uploaded_at']<=(string)$latestNotice['sent_at'];
    $allowedClient=((int)$doc['step_number']===2
            && $doc['category']==='red_signed_deficiency_notice'
            && $noticeReleased)
        || ($issued && (int)$doc['step_number']>=10
            && in_array($doc['category'],[
                'approved_fp','approved_tor','approved_map','approved_sheet',
                'transmittal'],true));
    if (!permit_flow_staff_can_view($user) && !($mine && $allowedClient)) {
        show_error('Document access denied.',404);
    }
    $base=realpath(PROJECT_ROOT.'/storage/fp_workflow');
    $absolute=realpath(PROJECT_ROOT.'/storage/'.(string)$doc['stored_path']);
    if (!$base || !$absolute || !str_starts_with($absolute,$base.DIRECTORY_SEPARATOR)
        || !is_file($absolute)) show_error('Protected document not found.',404);
    $data=fpoms_file_read($absolute);
    if (!hash_equals((string)$doc['sha256'],hash('sha256',$data))) {
        show_error('Document integrity verification failed.',500);
    }
    $mime=(string)$doc['mime_type'];
    if (!in_array($mime,['application/pdf','image/jpeg','image/png','application/zip'],true)) {
        show_error('Unsupported document.',415);
    }
    $preview=(string)($_GET['preview']??'')==='1' && $mime!=='application/zip';
    header('Content-Type: '.$mime);
    header('Content-Length: '.strlen($data));
    header('Cache-Control: private,no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: '.($preview?'inline':'attachment')
        .'; filename="document"; filename*=UTF-8\'\''.rawurlencode((string)$doc['original_name']));
    echo $data;
    exit;
}
