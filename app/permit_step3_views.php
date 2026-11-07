<?php
declare(strict_types=1);

/** Step 3 controls inside the existing authorized FP application details view. */
function step3_action_panel_html(array $app): string
{
    $id=(int)$app['id'];
    $status=(string)$app['status'];
    $movement=step3_movement($id);
    $html='<div class="panel soft"><h3 class="h6"><i class="bi bi-inboxes me-2"></i>'
        .'RO Receiving/Releasing – Step 3</h3>'
        .'<p class="small text-secondary">Receive the electronic application documents, then '
        .(permit_flow_is_new($app)
            ? 'forward complete applications to LPDD-FUS for Step 4 payment notice, field schedule and CENRO instruction. '
            : 'forward them to LPDD-FUS for legacy Step 4 documentary evaluation. ')
        .'Annex A processing time: within 1/2 day.</p>';
    if (permit_flow_is_new($app) && $status==='ro_received') {
        $paymentNotice=fpoms_payment_notice_record_for_app($id);
        if ($paymentNotice) {
            $noticeStatus=(string)$paymentNotice['status'];
            $html.='<div class="alert '.($noticeStatus==='released'?'alert-success':'alert-warning').' small">'
                .'<strong>Template No. 2:</strong> '
                .($noticeStatus==='pending_red'?'Awaiting RED signature. Applicant payment email is withheld.'
                    :($noticeStatus==='signed'?'RED-signed PDF saved; awaiting Finalize & Send.'
                    :'Official RED-signed payment notice released to applicant.'))
                .'</div>';
        }
    }
    if ($movement) {
        $html.='<p class="small"><strong>RO Received:</strong> '.e((string)$movement['received_at'])
            .'<br><strong>Received by:</strong> '.e((string)($movement['receiver_name'] ?? 'DENR Personnel'))
            .(!empty($movement['forwarded_at'])
                ? '<br><strong>Forwarded:</strong> '.e((string)$movement['forwarded_at'])
                .'<br><strong>Forwarded by:</strong> '.e((string)($movement['forwarder_name'] ?? 'DENR Personnel'))
                : '').'</p>';
    }
    if ($status==='forwarded_to_ro' || $status==='ro_received') {
        $receive=$status==='forwarded_to_ro';
        $name=$receive ? 'Receive Documents':'Forward to LPDD-FUS';
        $action=$receive ? 'permit_ro_receive':'permit_ro_forward';
        $form=$receive ? 'ro-step3-receive-form':'ro-step3-forward-form';
        $modal=$receive ? 'ro-step3-receive-modal':'ro-step3-forward-modal';
        $html.='<form id="'.$form.'" method="post" action="'.e(url($action,['id'=>$id])).'">'
            .csrf_field()
            .'<input type="hidden" name="confirmation" value="'.($receive?'receive':'forward').'">'
            .'<label class="form-label" for="ro-step3-remarks">Remarks (optional)</label>'
            .'<textarea id="ro-step3-remarks" name="remarks" rows="4" maxlength="2000" '
            .'class="form-control mb-3" placeholder="Notes for the audit trail"></textarea>'
            .'<button type="button" class="btn btn-success w-100" data-bs-toggle="modal" '
            .'data-bs-target="#'.$modal.'">'.e($name).'</button></form>'
            .'<div class="modal fade" id="'.$modal.'" tabindex="-1" '
            .'aria-labelledby="'.$modal.'-title" aria-hidden="true">'
            .'<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
            .'<div class="modal-header"><h2 class="modal-title h5" id="'.$modal.'-title">'
            .'Confirm '.e($name).'</h2><button type="button" class="btn-close" '
            .'data-bs-dismiss="modal" aria-label="Close"></button></div>'
            .'<div class="modal-body"><p><strong>FP Application #'.$id.'</strong> – '
            .e((string)$app['applicant_name']).'</p><p class="mb-0">'
            .($receive ? 'Confirm actual RO receipt of electronic documents.'
                : (permit_flow_is_new($app)
                    ? 'Confirm forwarding complete documents to LPDD-FUS for Step 4 payment notice and CENRO instructions.'
                    : 'Confirm forwarding to LPDD-FUS for legacy Step 4 evaluation.'))
            .'</p></div><div class="modal-footer"><button type="button" '
            .'class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
            .'<button type="submit" class="btn btn-success" form="'.$form.'">Confirm '
            .e($name).'</button></div></div></div></div>';
    } else {
        $html.='<div class="alert alert-success mb-0">RO Step 3 completed. '
            .'Application is forwarded to LPDD-FUS for Step 4.</div>';
    }
    if ($status==='forwarded_to_lpdd') {
        $html.=records_archive_action_panel_html($app);
    }
    return $html.'</div>';
}

function view_step3_ro_inbox(): void
{
    step3_require_officer();
    if (!records_archive_schema_ready()) {
        show_error('Apply database migration 023 to enable Records action-list archiving.',503);
    }
    // Tabs are filtered on the server; counts include all matching rows.
    $tabs=[
        'awaiting'=>['label'=>'Awaiting Receipt','status'=>'forwarded_to_ro'],
        'received'=>['label'=>'Received / To Forward','status'=>'ro_received'],
        'forwarded'=>['label'=>'Forwarded','status'=>'forwarded_to_lpdd']
    ];
    $tab=(string)($_GET['tab'] ?? 'awaiting');
    if (!isset($tabs[$tab])) show_error('Invalid RO Receiving status tab.',400);
    $tallies=query_all("SELECT a.status,COUNT(*) AS total
        FROM permit_step2_applications a
        LEFT JOIN permit_records_archives ra ON ra.application_id=a.id
        WHERE ra.application_id IS NULL
          AND a.status IN ('forwarded_to_ro','ro_received','forwarded_to_lpdd')
        GROUP BY a.status");
    $counts=['forwarded_to_ro'=>0,'ro_received'=>0,'forwarded_to_lpdd'=>0];
    foreach ($tallies as $item) {
        $status=(string)$item['status'];
        if (array_key_exists($status,$counts)) $counts[$status]=(int)$item['total'];
    }
    $selected=$tabs[$tab]['status'];
    $apps=query_all("SELECT a.id,a.project_title,a.status,a.forwarded_at,
        c.name AS applicant_name,m.received_at
        FROM permit_step2_applications a
        JOIN user_client c ON c.id=a.client_id
        LEFT JOIN permit_step3_movements m ON m.application_id=a.id
        LEFT JOIN permit_records_archives ra ON ra.application_id=a.id
        WHERE ra.application_id IS NULL AND a.status=?
        ORDER BY a.forwarded_at DESC,a.id DESC LIMIT 200",[$selected]);
    $html='<div class="panel"><h2 class="h5">RO Receiving/Releasing – Step 3</h2>'
        .'<p class="small text-secondary">Electronic receipt and forwarding to LPDD-FUS. '
        .'Annex A: within 1/2 day. Archived Records actions are stored separately.</p>'
        .'<nav class="nav nav-pills fp-ro-status-tabs d-flex flex-wrap mb-3" '
        .'aria-label="RO Receiving status tabs">';
    foreach ($tabs as $key=>$data) {
        $active=$key===$tab;
        $html.='<a class="nav-link'.($active?' active':'').'" href="'
            .e(url('permit_ro_inbox',['tab'=>$key])).'"'
            .($active?' aria-current="page"':'').'>'
            .e($data['label']).': <span class="badge '
            .($active?'text-bg-light':'text-bg-success').'">'
            .$counts[$data['status']].'</span></a>';
    }
    $html.='</nav><h3 class="h6 mb-3">'.e($tabs[$tab]['label']).' '
        .'<span class="badge text-bg-secondary">'.$counts[$selected].'</span></h3>';
    if (!$apps) {
        $html.='<p class="text-secondary">No applications under '
            .e(strtolower($tabs[$tab]['label'])).'.</p>';
    } else {
        $html.='<div class="table-responsive"><table class="table table-hover align-middle">'
            .'<thead><tr><th>FP No.</th><th>Applicant</th><th>Project</th>'
            .'<th class="fp-date-time-heading">Step 2 Forwarded</th>'
            .'<th class="fp-date-time-heading">RO Received</th>'
            .'<th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($apps as $a) {
            $html.='<tr><td>#'.(int)$a['id'].'</td>'
                .'<td>'.e((string)$a['applicant_name']).'</td>'
                .'<td>'.e((string)$a['project_title']).'</td>'
                .'<td>'.e(fpoms_display_datetime((string)$a['forwarded_at'])).'</td>'
                .'<td>'.e(fpoms_display_datetime((string)($a['received_at'] ?? ''))).'</td>'
                .'<td>'.step2_status_badge((string)$a['status']).'</td>'
                .'<td><a class="btn btn-sm btn-outline-primary" href="'
                .e(url('permit_application',['id'=>(int)$a['id']])).'">'
                .($a['status']==='forwarded_to_ro'?'Receive'
                    :($a['status']==='ro_received'?'Forward':'View Record'))
                .'</a></td></tr>';
        }
        $html.='</tbody></table></div>';
    }
    render_page('RO Receiving – Step 3',$html.'</div>');
}

/** Backward-compatible alias; Step 4 is now an active documentary evaluation module. */
function view_step4_incoming_queue(): void
{
    view_step4_queue();
}
