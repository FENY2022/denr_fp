<?php
declare(strict_types=1);

/** Role-aware Annex A post-receipt workflow (Step 4 through final release). */
function permit_flow_stage_form_html(array $app,array $user,array $case,array $errors=[]): string
{
    $id=(int)$app['id'];
    $step=(int)$case['current_step'];
    $html='<div class="panel soft mt-3" id="annex-a-step-'.$step.'">'
        .'<div class="d-flex gap-3 align-items-center mb-3 flex-wrap">'
        .'<span class="badge text-bg-primary">STEP '.$step.'</span>'
        .'<h3 class="h5 mb-0">'.e(permit_flow_step_label($step)).'</h3></div>';
    if ($step>=12) {
        return $html.'<div class="alert alert-success">END – FEASIBILITY PERMIT ISSUED. '
            .'Final approval and RO release events are recorded in the audit trail.</div></div>';
    }
    $html.=errors_html($errors);
    $all=permit_flow_artifacts($id,$step);
    $categories=permit_flow_categories($step);
    $available=array_column($all,'category');
    if ($step===4) {
        $html.='<div class="alert alert-info small">'
            .'LPDD-FUS must notify the applicant to pay at the Regional Office Cashier, '
            .'provide the field validation schedule, and prepare the memo instruction to CENRO. '
            .'The Cashier receives payment; LPDD-FUS separately verifies and uploads the Official Receipt.</div>';
    } elseif ($step===5) {
        $html.='<p class="small">CENRO notifies applicant to submit hard copies, instructs TWG, '
            .'conducts the field assessment and uploads the CENRO/GIS Report, GIS Map, '
            .'Shapefiles, TD and authenticity certification.</p>';
    } elseif ($step===6) {
        $html.='<div class="small mb-3"><strong>Day 1:</strong> SMD/GIS prepares final map and CDD schedules RRDC. '
            .'<strong>Day 2:</strong> RRDC hybrid deliberation. '
            .'<strong>Day 3:</strong> CDD prepares minutes/agreements and Secretariat obtains signatures. '
            .'<strong>Days 3–4:</strong> Secretariat uploads approved/signed documents, '
            .'including the scanned, signed Final Map.</div>';
    } elseif ($step===11) {
        $html.='<p class="small">RO Receiving/Releasing must notify the applicant, release approved hard copies, '
            .'print the Memo to PENRO/CENRO and record approved-document transmittal.</p>';
    }
    if ($step===5 && permit_flow_staff_can_act($user,5)
        && !permit_flow_event_exists($id,5,'hardcopy_notice_sent')) {
        $html.='<form method="post" action="'.e(url('permit_flow_notice',['id'=>$id])).'">'
            .csrf_field().'<button class="btn btn-outline-primary mb-3" type="submit" '
            .'name="confirmation" value="1"><i class="bi bi-envelope-paper me-1"></i>'
            .'Notify Applicant to Submit Hard Copies</button></form>';
    }
    if ($step===5 && permit_flow_event_exists($id,5,'hardcopy_notice_sent')) {
        $html.='<p class="small text-success"><i class="bi bi-check-circle me-1"></i>'
            .'CENRO hard-copy notice recorded and sent.</p>';
    }
    if ($step===5 && has_role($user,'cenro_twg')
        && !permit_flow_event_exists($id,5,'twg_instructed')) {
        $html.='<form method="post" action="'.e(url('permit_flow_milestone',['id'=>$id])).'">'
            .csrf_field()
            .'<input type="hidden" name="action" value="twg_instructed">'
            .'<label class="form-label" for="twg-instructions-note">TWG Instruction / Field Assessment Details *</label>'
            .'<textarea class="form-control mb-2" name="remarks" id="twg-instructions-note" minlength="10" '
            .'maxlength="3000" required rows="2" placeholder="Record instructions issued to TWG"></textarea>'
            .'<button class="btn btn-outline-primary mb-3" name="confirmation" value="1" type="submit">'
            .'Record TWG Field Validation Instruction</button></form>';
    }
    if ($step===5 && permit_flow_event_exists($id,5,'twg_instructed')) {
        $html.='<p class="small text-success">TWG instruction officially recorded.</p>';
    }
    if ($step===6) {
        $scheduled=permit_flow_event_exists($id,6,'rrdc_scheduled');
        $deliberated=permit_flow_event_exists($id,6,'rrdc_deliberated');
        if ($scheduled) $html.='<p class="small text-success">CDD RRDC Schedule: '
            .e(fpoms_display_datetime((string)($case['rrdc_schedule']??''))).'</p>';
        if (!$scheduled && has_role($user,'cdd')) {
            $html.='<form method="post" action="'.e(url('permit_flow_milestone',['id'=>$id])).'">'
                .csrf_field()
                .'<input type="hidden" name="action" value="rrdc_scheduled">'
                .'<label class="form-label" for="rrdc-schedule">RRDC Deliberation Schedule *</label>'
                .'<input class="form-control mb-2" type="datetime-local" name="rrdc_schedule" id="rrdc-schedule" required>'
                .'<label class="form-label" for="rrdc-schedule-remarks">Scheduling / Member Notification Details *</label>'
                .'<textarea class="form-control mb-2" id="rrdc-schedule-remarks" name="remarks" '
                .'minlength="10" maxlength="3000" required rows="2"></textarea>'
                .'<button class="btn btn-outline-primary mb-3" name="confirmation" value="1" type="submit">'
                .'Record RRDC Schedule & Notify Members</button></form>';
        }
        if ($deliberated) $html.='<p class="small text-success">RRDC hybrid deliberation recorded.</p>';
        if ($scheduled && !$deliberated
            && (has_role($user,'rrdc_member') || has_role($user,'rrdc_secretariat'))) {
            $html.='<form method="post" action="'.e(url('permit_flow_milestone',['id'=>$id])).'">'
                .csrf_field()
                .'<input type="hidden" name="action" value="rrdc_deliberated">'
                .'<label class="form-label" for="rrdc-deliberation-remarks">RRDC Deliberation Record *</label>'
                .'<textarea class="form-control mb-2" id="rrdc-deliberation-remarks" name="remarks" '
                .'minlength="10" maxlength="3000" required rows="3" '
                .'placeholder="Summarize actual hybrid deliberation and recorded agreements"></textarea>'
                .'<button class="btn btn-outline-primary mb-3" name="confirmation" value="1" type="submit">'
                .'Record Completed RRDC Deliberation</button></form>';
        }
    }
    if ($categories) {
        $html.='<div class="table-responsive"><table class="table table-hover align-middle">'
            .'<thead><tr><th>Required Documents</th><th>Status</th><th>Upload / View</th></tr></thead><tbody>';
        foreach ($categories as $key=>$info) {
            $matching=array_values(array_filter($all,static fn(array $doc): bool=>
                (string)$doc['category']===$key));
            $html.='<tr><td><strong>'.e($info[0]).'</strong><br>'
                .'<span class="small text-secondary">Responsible: '.e($info[1]).'</span></td>'
                .'<td>'.($matching
                    ? '<span class="badge text-bg-success">Uploaded</span>'
                    : '<span class="badge text-bg-warning">Required</span>').'</td>'
                .'<td>';
            foreach ($matching as $doc) {
                $html.='<a class="d-block small mb-1" href="'
                    .e(url('permit_flow_file',['id'=>$id,'file'=>(int)$doc['id']]))
                    .'">'.e((string)$doc['original_name']).' ('
                    .e((string)($doc['uploader']??'DENR Personnel')).')</a>';
            }
            if (permit_flow_upload_allowed($user,$app,$step,$key)) {
                $html.='<form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2" '
                    .'action="'.e(url('permit_flow_upload',['id'=>$id,'step'=>$step])).'">'
                    .csrf_field()
                    .'<input type="hidden" name="category" value="'.e($key).'">'
                    .'<input type="file" class="form-control form-control-sm" '
                    .'name="document" accept="'.($key==='shapefiles'?'.zip':'.pdf,.jpg,.jpeg,.png')
                    .'" aria-label="'.e($info[0]).'" required>'
                    .'<button type="submit" class="btn btn-sm btn-outline-success">'
                    .'Upload</button></form>';
            }
            $html.='</td></tr>';
        }
        $html.='</tbody></table></div>';
    }
    $missing=permit_flow_missing_artifacts($id,$step);
    if ($missing) $html.='<div class="alert alert-warning small">Before completing this step, upload: '
        .e(implode('; ',$missing)).'</div>';
    $milestoneMissing=($step===5 && (
        !permit_flow_event_exists($id,5,'hardcopy_notice_sent')
        || !permit_flow_event_exists($id,5,'twg_instructed')))
        || ($step===6 && (
            !permit_flow_event_exists($id,6,'rrdc_scheduled')
            || !permit_flow_event_exists($id,6,'rrdc_deliberated')));
    if ($milestoneMissing) $html.='<div class="alert alert-warning small">'
        .'Required CENRO/RRDC action checkpoints must be recorded before forwarding.</div>';
    if (permit_flow_staff_can_act($user,$step)) {
        $html.='<form method="post" action="'.e(url('permit_flow_advance',['id'=>$id,'step'=>$step])).'">'
            .csrf_field();
        if ($step===4) {
            $html.='<label class="form-label" for="annex-field-date">Field Validation / Assessment Schedule *</label>'
                .'<input type="datetime-local" class="form-control mb-3" name="field_validation_schedule" '
                .'id="annex-field-date" required>'
                .'<label class="form-label" for="annex-cenro-instructions">Memo Instruction to CENRO *</label>'
                .'<textarea class="form-control mb-3" id="annex-cenro-instructions" '
                .'name="cenro_instructions" rows="4" minlength="10" maxlength="3000" required></textarea>';
        }
        $html.='<label class="form-label" for="annex-handoff-remarks">Remarks (Optional)</label>'
            .'<textarea class="form-control mb-2" id="annex-handoff-remarks" '
            .'name="remarks" maxlength="3000" rows="2"></textarea>'
            .'<div class="form-check mb-3"><input class="form-check-input" type="checkbox" '
            .'name="confirmed_by_staff" value="yes" id="annex-handoff-confirm" required>'
            .'<label class="form-check-label small" for="annex-handoff-confirm">'
            .'I confirm the required actions and uploaded documents have been checked. '
            .'This records an authorized hand-off, not an automatic or fabricated signature.</label></div>'
            .'<button class="btn btn-success w-100" name="confirmation" value="1" type="submit"'
            .($missing || $milestoneMissing?' disabled':'').'>'
            .e($step===11?'Confirm Release & Issue Feasibility Permit':'Complete Step '.$step.' & Forward')
            .'</button></form>';
    } else {
        $html.='<p class="small text-secondary">Only the authorized office for this stage can complete its actions.</p>';
    }
    return $html.'</div>';
}
function permit_flow_history_html(int $id): string
{
    $rows=permit_flow_events($id);
    if (!$rows) return '<p class="text-secondary small">No Annex A stage actions recorded yet.</p>';
    $html='<div class="table-responsive"><table class="table table-sm"><thead>'
        .'<tr><th>Date / Time</th><th>Step</th><th>Action</th><th>DENR Personnel</th><th>Remarks</th>'
        .'</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html.='<tr><td>'.e(fpoms_display_datetime((string)$row['created_at'])).'</td>'
            .'<td>'.(int)$row['step_number'].'</td>'
            .'<td>'.e(ucwords(str_replace('_',' ',(string)$row['action']))).'</td>'
            .'<td>'.e((string)($row['actor_name']??'DENR Personnel')).'</td>'
            .'<td>'.nl2br(e((string)($row['remarks']??''))).'</td></tr>';
    }
    return $html.'</tbody></table></div>';
}
function permit_flow_public_documents_html(array $app,array $viewer,bool $noticeModal=false): string
{
    $id=(int)$app['id'];
    $mine=($viewer['user_kind']??'')==='applicant'
        && (int)$viewer['id']===(int)$app['client_id'];
    if (!$mine) return '';
    $all=permit_flow_artifacts($id);
    $releasedNoticeIds=permit_flow_released_notice_artifact_ids($id);
    $case=permit_flow_case($id);
    $issued=$case && (int)$case['current_step']===12
        && !empty($case['completed_at']);
    $permitted=array_values(array_filter($all,static fn(array $d): bool=>
        (in_array((int)$d['id'],$releasedNoticeIds,true) && (int)$d['step_number']===2
            && $d['category']==='red_signed_deficiency_notice')
        || ($issued && (int)$d['step_number']>=10
            && in_array($d['category'],[
                'approved_fp','approved_tor','approved_map','approved_sheet',
                'transmittal'],true))));
    if (!$permitted) return '';
    $html='<div class="panel mt-3"><h3 class="h6">Official Notices / Approved Documents</h3><ul>';
    foreach ($permitted as $d) {
        $isNotice=(string)$d['category']==='red_signed_deficiency_notice';
        if ($noticeModal && $isNotice) {
            $preview=url('permit_flow_file',[
                'id'=>$id,'file'=>(int)$d['id'],'preview'=>1,'raw'=>1
            ]);
            $html.='<li><button type="button" class="btn btn-link p-0 align-baseline" '
                .'data-bs-toggle="modal" data-bs-target="#notice-pdf-preview-modal" '
                .'data-notice-preview-url="'.e($preview).'" '
                .'data-notice-preview-title="'.e('FP #'.$id.' · RED-Signed Deficiency Notice').'">'
                .e((string)$d['original_name']).'</button></li>';
        } else {
            $html.='<li><a target="_blank" rel="noopener" href="'.e(url('permit_flow_file',
                    ['id'=>$id,'file'=>(int)$d['id'],'preview'=>1]))
                .'">'.e((string)$d['original_name']).'</a></li>';
        }
    }
    return $html.'</ul></div>';
}
function view_permit_flow(int $id,array $errors=[]): void
{
    $viewer=require_user();
    permit_flow_require();
    $app=step2_application($id);
    if (!$app || !permit_flow_is_new($app)) show_error('Updated Annex A workflow case not found.',404);
    $mine=($viewer['user_kind']??'')==='applicant' && (int)$viewer['id']===(int)$app['client_id'];
    if (!$mine && !permit_flow_staff_can_view($viewer)) show_error('You may not view this workflow.',404);
    $case=permit_flow_case($id);
    $step=(int)($case['current_step']??0);
    $html='<div class="mb-3"><a href="'.e(url('permit_application',['id'=>$id]))
        .'">← FP Application #'.$id.'</a></div>'
        .'<div class="panel"><h2 class="h5">Annex A – Feasibility Permit Processing</h2>'
        .'<p><strong>Application #'.$id.'</strong> · '.e((string)$app['project_title']).'</p>'
        .'<p class="small"><strong>Current Stage:</strong> '
        .e($step ? permit_flow_step_label($step)
            : 'Step 2 / Step 3 – documentary evaluation and RO Receiving')
        .'</p>'
        .'<p class="small text-secondary mb-0">Step 1 registration is excluded from the 15-day '
        .'processing clock. Process milestones and timestamps are recorded in the audit trail; '
        .'holidays, pauses and statutory SLA calculations must be verified separately.</p></div>';
    if ($case) {
        if ($mine && $step>=5) $html.='<div class="panel mt-3"><h3 class="h6">Payment and Field Validation</h3>'
            .'<p class="small">'.e(permit_payment_instructions($id)).'</p></div>';
        if (!$mine) $html.=permit_flow_stage_form_html($app,$viewer,$case,$errors);
    } elseif (!$mine) {
        $html.='<div class="alert alert-info mt-3">This application has not completed '
            .'LPDD-FUS Step 2 and RO Step 3 yet. Check the Step 2 evaluation queue or RO inbox.</div>';
    }
    $html.=permit_flow_public_documents_html($app,$viewer)
        .'<div class="panel mt-3"><h3 class="h6">Annex A Activity History</h3>'
        .permit_flow_history_html($id).'</div>';
    render_page('Annex A Workflow · Application #'.$id,$html);
}
function view_permit_flow_queue(): void
{
    $user=require_user();
    permit_flow_require();
    if (!permit_flow_staff_can_view($user)) show_error('Only authorized DENR users can view this queue.',403);
    $rows=query_all(
        'SELECT c.current_step,c.updated_at,a.id,a.project_title,a.status,cl.name AS applicant_name
         FROM permit_flow_cases c JOIN permit_step2_applications a ON a.id=c.application_id
         JOIN user_client cl ON cl.id=a.client_id
         WHERE a.flow_version=2
         ORDER BY (c.current_step=12) ASC,c.current_step ASC,c.updated_at DESC LIMIT 300'
    );
    $html='<div class="panel"><h2 class="h5">Updated Annex A – Steps 4–11</h2>'
        .'<p class="small text-secondary">Role-restricted stage actions, receipt uploads, '
        .'CENRO validation, RRDC signed maps, FP/TOR approvals and release records.</p>'
        .'<div class="table-responsive"><table class="table table-hover align-middle">'
        .'<thead><tr><th>Application</th><th>Applicant</th><th>Current Step</th>'
        .'<th>Responsible Office</th><th>Action</th></tr></thead><tbody>';
    if (!$rows) $html.='<tr><td colspan="5">No updated Annex A cases yet.</td></tr>';
    foreach ($rows as $row) {
        $step=(int)$row['current_step'];
        $html.='<tr><td>#'.(int)$row['id'].' – '.e((string)$row['project_title']).'</td>'
            .'<td>'.e((string)$row['applicant_name']).'</td>'
            .'<td>'.e(permit_flow_step_label($step)).'</td>'
            .'<td>'.(permit_flow_staff_can_act($user,$step)
                ? '<span class="badge text-bg-success">Your Office to Act</span>'
                : '<span class="text-secondary">View Only</span>').'</td>'
            .'<td><a class="btn btn-sm btn-outline-primary" href="'
            .e(url('permit_flow',['id'=>(int)$row['id']])).'">'
            .(permit_flow_staff_can_act($user,$step)?'Open & Process':'View History')
            .'</a></td></tr>';
    }
    render_page('Annex A Workflow Queue',$html.'</tbody></table></div></div>');
}
