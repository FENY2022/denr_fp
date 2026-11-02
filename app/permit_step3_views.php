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
        .'forward them to LPDD-FUS for Step 4 documentary evaluation. '
        .'Annex A processing time: within 1/2 day.</p>';
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
                : 'Confirm forwarding to LPDD-FUS for Step 4 evaluation; this is not a payment instruction.')
            .'</p></div><div class="modal-footer"><button type="button" '
            .'class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
            .'<button type="submit" class="btn btn-success" form="'.$form.'">Confirm '
            .e($name).'</button></div></div></div></div>';
    } else {
        $html.='<div class="alert alert-success mb-0">RO Step 3 completed. '
            .'Application is forwarded to LPDD-FUS for Step 4.</div>';
    }
    return $html.'</div>';
}

function view_step3_ro_inbox(): void
{
    step3_require_officer();
    $apps=query_all("SELECT a.id,a.project_title,a.status,a.forwarded_at,
        c.name AS applicant_name,m.received_at
        FROM permit_step2_applications a
        JOIN user_client c ON c.id=a.client_id
        LEFT JOIN permit_step3_movements m ON m.application_id=a.id
        WHERE a.status IN ('forwarded_to_ro','ro_received','forwarded_to_lpdd')
        ORDER BY CASE a.status WHEN 'forwarded_to_ro' THEN 0
           WHEN 'ro_received' THEN 1 ELSE 2 END,
           a.forwarded_at DESC,a.id DESC LIMIT 200");
    $pending=0;$received=0;$done=0;
    foreach ($apps as $a) {
        if ($a['status']==='forwarded_to_ro') $pending++;
        elseif ($a['status']==='ro_received') $received++;
        else $done++;
    }
    $html='<div class="panel"><h2 class="h5">RO Receiving/Releasing – Step 3</h2>'
        .'<p class="small text-secondary">Electronic receipt and forwarding to LPDD-FUS. '
        .'Annex A: within 1/2 day.</p><div class="d-flex flex-wrap gap-2 mb-3">'
        .'<span class="badge text-bg-warning">Awaiting Receipt: '.$pending.'</span>'
        .'<span class="badge text-bg-info">Received / To Forward: '.$received.'</span>'
        .'<span class="badge text-bg-success">Forwarded: '.$done.'</span></div>';
    if (!$apps) $html.='<p class="text-secondary">No applications forwarded from LPDD-FUS yet.</p>';
    else {
        $html.='<div class="table-responsive"><table class="table table-hover align-middle">'
            .'<thead><tr><th>FP No.</th><th>Applicant</th><th>Project</th>'
            .'<th>Step 2 Forwarded</th><th>RO Received</th><th>Status</th><th>Action</th>'
            .'</tr></thead><tbody>';
        foreach ($apps as $a) {
            $html.='<tr><td>#'.(int)$a['id'].'</td><td>'.e((string)$a['applicant_name'])
                .'</td><td>'.e((string)$a['project_title']).'</td>'
                .'<td>'.e((string)$a['forwarded_at']).'</td>'
                .'<td>'.e((string)($a['received_at'] ?? '—')).'</td>'
                .'<td>'.step2_status_badge((string)$a['status']).'</td>'
                .'<td><a class="btn btn-sm btn-outline-primary" href="'
                .e(url('permit_application',['id'=>(int)$a['id']])).'">'
                .($a['status']==='forwarded_to_ro' ? 'Receive'
                    :($a['status']==='ro_received'?'Forward':'View Record'))
                .'</a></td></tr>';
        }
        $html.='</tbody></table></div>';
    }
    render_page('RO Receiving – Step 3',$html.'</div>');
}

/** Step 4 is a visible incoming handoff, NOT active documentary evaluation/payment. */
function view_step4_incoming_queue(): void
{
    require_role('lpdd_fus');
    step3_require_schema();
    $apps=query_all("SELECT a.id,a.project_title,c.name AS applicant_name,m.forwarded_at
        FROM permit_step2_applications a
        JOIN permit_step3_movements m ON m.application_id=a.id
        JOIN user_client c ON c.id=a.client_id
        WHERE a.status='forwarded_to_lpdd' AND m.forwarded_at IS NOT NULL
        ORDER BY m.forwarded_at ASC,a.id ASC LIMIT 200");
    $html='<div class="panel"><h2 class="h5">Step 4 – LPDD-FUS Incoming Applications</h2>'
        .'<div class="alert alert-info small">Read-only handoff queue. Formal documentary '
        .'evaluation and payment requests are not enabled yet. '
        .'Under Annex A, payment is requested only after documentary requirements '
        .'are found complete and sufficient.</div>';
    if (!$apps) $html.='<p class="text-secondary">No applications received from RO Step 3.</p>';
    else {
        $html.='<div class="table-responsive"><table class="table table-hover align-middle">'
            .'<thead><tr><th>FP No.</th><th>Applicant</th><th>Project</th>'
            .'<th>Received from RO</th><th></th></tr></thead><tbody>';
        foreach ($apps as $a) {
            $html.='<tr><td>#'.(int)$a['id'].'</td>'
                .'<td>'.e((string)$a['applicant_name']).'</td>'
                .'<td>'.e((string)$a['project_title']).'</td>'
                .'<td>'.e((string)$a['forwarded_at']).'</td>'
                .'<td><a class="btn btn-sm btn-outline-primary" href="'
                .e(url('permit_application',['id'=>(int)$a['id']]))
                .'">View Documents / Audit Trail</a></td></tr>';
        }
        $html.='</tbody></table></div>';
    }
    render_page('Step 4 Incoming Queue',$html.'</div>');
}
