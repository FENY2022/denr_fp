<?php
declare(strict_types=1);

/** Step 4 queue and formal documentary evaluation panels. */
function step4_queue_tabs(): array
{
    return [
        'incoming'=>['label'=>'Incoming','status'=>'forwarded_to_lpdd'],
        'evaluation'=>['label'=>'Under Evaluation','status'=>'step4_under_evaluation'],
        'returned'=>['label'=>'Returned for Compliance','status'=>'step4_corrections_required'],
        'resubmitted'=>['label'=>'Resubmitted','status'=>'step4_resubmitted'],
        'complete'=>['label'=>'Complete & Sufficient','status'=>'step4_complete'],
    ];
}

function view_step4_queue(): void
{
    step4_require_reviewer();
    $tabs=step4_queue_tabs();
    $tab=(string)($_GET['tab'] ?? 'incoming');
    if (!isset($tabs[$tab])) show_error('Invalid Step 4 queue tab.',400);

    $tallies=query_all(
        "SELECT status,COUNT(*) AS total
         FROM permit_step2_applications
         WHERE status IN (
           'forwarded_to_lpdd','step4_under_evaluation',
           'step4_corrections_required','step4_resubmitted','step4_complete'
         )
         GROUP BY status"
    );
    $counts=[];
    foreach ($tabs as $item) $counts[$item['status']]=0;
    foreach ($tallies as $row) {
        if (array_key_exists((string)$row['status'],$counts)) {
            $counts[(string)$row['status']]=(int)$row['total'];
        }
    }

    $selected=$tabs[$tab]['status'];
    $apps=query_all(
        "SELECT a.id,a.project_title,a.revision,a.status,a.last_submitted_at,
                c.name AS applicant_name,m.forwarded_at,
                r.started_at,r.decided_at,r.field_validation_schedule
         FROM permit_step2_applications a
         JOIN user_client c ON c.id=a.client_id
         LEFT JOIN permit_step3_movements m ON m.application_id=a.id
         LEFT JOIN permit_step4_reviews r
           ON r.application_id=a.id AND r.revision=a.revision
         WHERE a.status=?
         ORDER BY COALESCE(r.started_at,m.forwarded_at,a.last_submitted_at) ASC,a.id ASC
         LIMIT 300",[$selected]
    );

    $html='<div class="panel"><div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">'
        .'<div><h2 class="h5 mb-1">Step 4 – Documentary Evaluation</h2>'
        .'<p class="small text-secondary mb-0">LPDD-FUS determines documentary completeness and sufficiency. '
        .'Incomplete/insufficient applications return to the applicant; complete/sufficient applications proceed toward Step 5 field validation preparation.</p></div>'
        .'<span class="badge text-bg-success p-2">Annex A · Within 1 1/2 days</span></div>'
        .'<nav class="nav nav-pills d-flex flex-wrap gap-2 mb-3" aria-label="Step 4 status tabs">';
    foreach ($tabs as $key=>$data) {
        $active=$key===$tab;
        $html.='<a class="nav-link'.($active?' active':'').'" href="'
            .e(url('permit_step4_queue',['tab'=>$key])).'"'
            .($active?' aria-current="page"':'').'>'.e($data['label'])
            .' <span class="badge '.($active?'text-bg-light':'text-bg-secondary').'">'
            .$counts[$data['status']].'</span></a>';
    }
    $html.='</nav>';

    if (!$apps) {
        $html.='<div class="alert alert-light border mb-0">No applications under '
            .e(strtolower($tabs[$tab]['label'])).'.</div>';
    } else {
        $html.='<div class="table-responsive"><table class="table table-hover align-middle">'
            .'<thead><tr><th>FP No.</th><th>Applicant</th><th>Project</th><th>Revision</th>'
            .'<th>Date / Time</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($apps as $app) {
            $date=(string)($app['started_at'] ?? $app['forwarded_at'] ?? $app['last_submitted_at'] ?? '');
            $label=match ((string)$app['status']) {
                'forwarded_to_lpdd'=>'Start Evaluation',
                'step4_under_evaluation'=>'Continue Evaluation',
                'step4_corrections_required'=>'View Deficiencies',
                'step4_resubmitted'=>'Re-evaluate',
                'step4_complete'=>'View Result',
                default=>'Open'
            };
            $html.='<tr><td>#'.(int)$app['id'].'</td>'
                .'<td>'.e((string)$app['applicant_name']).'</td>'
                .'<td><strong>'.e((string)$app['project_title']).'</strong></td>'
                .'<td>'.(int)$app['revision'].'</td>'
                .'<td>'.e(fpoms_display_datetime($date)).'</td>'
                .'<td>'.step2_status_badge((string)$app['status']).'</td>'
                .'<td><a class="btn btn-sm btn-outline-primary" href="'
                .e(url('permit_application',['id'=>(int)$app['id']])).'">'
                .e($label).'</a></td></tr>';
        }
        $html.='</tbody></table></div>';
    }
    render_page('Step 4 – Documentary Evaluation',$html.'</div>');
}

function step4_uploaded_counts(array $documents): array
{
    $counts=[];
    foreach ($documents as $doc) {
        $type=(string)($doc['requirement_type'] ?? 'other');
        $counts[$type]=($counts[$type] ?? 0)+1;
    }
    return $counts;
}

function step4_start_panel_html(array $app): string
{
    $id=(int)$app['id'];
    return '<div class="panel soft"><h3 class="h6">'
        .'<i class="bi bi-clipboard2-check me-2"></i>Step 4 – Documentary Evaluation</h3>'
        .'<p class="small text-secondary">The application was forwarded by RO Receiving. '
        .'Start the formal completeness and sufficiency evaluation.</p>'
        .'<form id="step4-start-form" method="post" action="'
        .e(url('permit_step4_start',['id'=>$id])).'">'.csrf_field()
        .'<button type="button" class="btn btn-success w-100" data-bs-toggle="modal" '
        .'data-bs-target="#step4-start-modal">Start Documentary Evaluation</button></form>'
        .'<div class="modal fade" id="step4-start-modal" tabindex="-1" '
        .'aria-labelledby="step4-start-modal-title" aria-hidden="true">'
        .'<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        .'<div class="modal-header"><h2 class="modal-title h5" id="step4-start-modal-title">'
        .'Start Step 4 Evaluation?</h2><button type="button" class="btn-close" '
        .'data-bs-dismiss="modal" aria-label="Close"></button></div>'
        .'<div class="modal-body"><p><strong>FP Application #'.$id.'</strong></p>'
        .'<p class="mb-0">This records the start of formal LPDD-FUS documentary evaluation '
        .'for Revision '.(int)$app['revision'].'.</p></div>'
        .'<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" '
        .'data-bs-dismiss="modal">Cancel</button>'
        .'<button type="submit" class="btn btn-success" form="step4-start-form">Confirm Start</button>'
        .'</div></div></div></div></div>';
}

function step4_evaluation_panel_html(array $app,array $documents,array $errors=[]): string
{
    $id=(int)$app['id'];
    $review=step4_review($id,(int)$app['revision']);
    if (!$review) {
        return '<div class="alert alert-danger">The active Step 4 review record is missing. '
            .'Refresh the queue or contact the ICT administrator.</div>';
    }
    $saved=step4_review_items((int)$review['id']);
    $counts=step4_uploaded_counts($documents);
    $postAssess=is_array($_POST['assessment'] ?? null) ? $_POST['assessment'] : [];
    $postRemarks=is_array($_POST['requirement_remarks'] ?? null) ? $_POST['requirement_remarks'] : [];

    $html='<div class="panel soft"><h3 class="h6 mb-1">'
        .'<i class="bi bi-clipboard-check me-2"></i>LPDD-FUS Step 4 Documentary Evaluation</h3>'
        .'<p class="small text-secondary">Assess every documentary requirement for completeness '
        .'and sufficiency. Requirements marked * / ** may be set Not Applicable when justified.</p>'
        .errors_html($errors)
        .'<form id="step4-decision-form" method="post" action="'
        .e(url('permit_step4_decision',['id'=>$id])).'">'.csrf_field()
        .'<input type="hidden" name="decision" value="" data-step4-decision-value>'
        .'<div class="vstack gap-3">';

    foreach (step2_requirement_types() as $type=>$label) {
        $current=(string)($postAssess[$type] ?? $saved[$type]['assessment'] ?? '');
        $remark=(string)($postRemarks[$type] ?? $saved[$type]['remarks'] ?? '');
        $uploaded=(int)($counts[$type] ?? 0);
        $html.='<section class="border rounded-3 p-3">'
            .'<div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">'
            .'<div><strong>'.e($label).'</strong>'
            .'<div class="small text-secondary">Uploaded file(s), all revisions: '.$uploaded.'</div></div>'
            .'<span class="badge '.($uploaded>0?'text-bg-success':'text-bg-secondary').'">'
            .($uploaded>0?'Files available':'No categorized file').'</span></div>'
            .'<div class="row g-2"><div class="col-md-5">'
            .'<label class="form-label" for="step4-assessment-'.e($type).'">Assessment</label>'
            .'<select class="form-select" id="step4-assessment-'.e($type).'" '
            .'name="assessment['.e($type).']" required>'
            .'<option value="">Select assessment</option>';
        foreach ([
            'complete'=>'Complete',
            'incomplete'=>'Incomplete / Missing',
            'insufficient'=>'Insufficient',
            'not_applicable'=>'Not Applicable'
        ] as $value=>$text) {
            $html.='<option value="'.e($value).'"'.($current===$value?' selected':'').'>'
                .e($text).'</option>';
        }
        $html.='</select></div><div class="col-md-7">'
            .'<label class="form-label" for="step4-remarks-'.e($type).'">Requirement Remarks</label>'
            .'<textarea class="form-control" id="step4-remarks-'.e($type).'" '
            .'name="requirement_remarks['.e($type).']" maxlength="1500" rows="2" '
            .'placeholder="Required when incomplete or insufficient">'.e($remark).'</textarea>'
            .'</div></div></section>';
    }

    $html.='</div><hr>'
        .'<label class="form-label" for="step4-overall-remarks">Overall Evaluation Remarks</label>'
        .'<textarea class="form-control mb-3" id="step4-overall-remarks" name="overall_remarks" '
        .'rows="4" maxlength="4000" placeholder="Summarize findings / deficiencies">'
        .e((string)($_POST['overall_remarks'] ?? $review['overall_remarks'] ?? '')).'</textarea>'
        .'<label class="form-label" for="step4-applicant-instructions">Applicant Instructions</label>'
        .'<textarea class="form-control mb-3" id="step4-applicant-instructions" '
        .'name="applicant_instructions" rows="3" maxlength="3000" '
        .'placeholder="Hard-copy, payment, compliance, or other instructions">'
        .e((string)($_POST['applicant_instructions'] ?? $review['applicant_instructions'] ?? '')).'</textarea>'
        .'<label class="form-label" for="step4-field-schedule">Field Validation / Assessment Schedule '
        .'<span class="text-secondary">(required for Complete & Sufficient)</span></label>'
        .'<input class="form-control mb-3" id="step4-field-schedule" name="field_validation_schedule" '
        .'type="datetime-local" value="'.e((string)($_POST['field_validation_schedule'] ?? '')).'">'
        .'<div class="alert alert-warning small d-none" data-step4-validation></div>'
        .'<div class="d-grid gap-2">'
        .'<button type="button" class="btn btn-success" data-step4-decision="complete">'
        .'<i class="bi bi-check-circle me-1"></i>Complete & Sufficient</button>'
        .'<button type="button" class="btn btn-outline-danger" data-step4-decision="return">'
        .'<i class="bi bi-arrow-return-left me-1"></i>Incomplete / Insufficient – Return to Applicant</button>'
        .'</div></form>'
        .'<div class="modal fade" id="step4-decision-modal" tabindex="-1" '
        .'aria-labelledby="step4-decision-modal-title" aria-hidden="true">'
        .'<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        .'<div class="modal-header"><h2 class="modal-title h5" id="step4-decision-modal-title" '
        .'data-step4-modal-title>Confirm Step 4 Decision</h2>'
        .'<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        .'<div class="modal-body"><p class="mb-0" data-step4-modal-message></p></div>'
        .'<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" '
        .'data-bs-dismiss="modal">Cancel</button>'
        .'<button type="button" class="btn btn-primary" data-step4-confirm>Confirm Decision</button>'
        .'</div></div></div></div>'
        .'<script src="assets/permit-step4-review.js?v=1" defer></script></div>';
    return $html;
}

function step4_result_panel_html(array $app): string
{
    $review=step4_review((int)$app['id']);
    if (!$review) return '';
    $items=step4_review_items((int)$review['id']);
    $returned=(string)$review['review_status']==='returned';
    $html='<div class="panel soft"><h3 class="h6">'
        .'<i class="bi bi-clipboard-data me-2"></i>Step 4 Documentary Evaluation</h3>'
        .'<p class="small"><strong>Review Status:</strong> '
        .e($returned?'Returned for Compliance':'Complete & Sufficient')
        .'<br><strong>Reviewer:</strong> '.e((string)($review['reviewer_name'] ?? 'LPDD-FUS'))
        .'<br><strong>Decision Date:</strong> '.e(fpoms_display_datetime((string)$review['decided_at'])).'</p>'
        .'<ul class="list-group mb-3">';
    foreach (step2_requirement_types() as $type=>$label) {
        $item=$items[$type] ?? null;
        $assessment=(string)($item['assessment'] ?? '');
        $tone=match ($assessment) {
            'complete'=>'success','not_applicable'=>'secondary',
            'incomplete','insufficient'=>'warning',default=>'secondary'
        };
        $html.='<li class="list-group-item"><div class="d-flex justify-content-between gap-2">'
            .'<span>'.e($label).'</span><span class="badge text-bg-'.$tone.'">'
            .e(step4_assessment_label($assessment)).'</span></div>'
            .(!empty($item['remarks'])?'<div class="small text-secondary mt-1">'
                .nl2br(e((string)$item['remarks'])).'</div>':'').'</li>';
    }
    $html.='</ul>';
    if (!empty($review['overall_remarks'])) {
        $html.='<p class="small"><strong>Overall Remarks:</strong><br>'
            .nl2br(e((string)$review['overall_remarks'])).'</p>';
    }
    if (!empty($review['applicant_instructions'])) {
        $html.='<p class="small"><strong>Instructions:</strong><br>'
            .nl2br(e((string)$review['applicant_instructions'])).'</p>';
    }
    if (!$returned && !empty($review['field_validation_schedule'])) {
        $html.='<div class="alert alert-success small mb-0">'
            .'<strong>Field Validation / Assessment Schedule:</strong> '
            .e(fpoms_display_datetime((string)$review['field_validation_schedule']))
            .'<br>Applicant is instructed to submit required hard copies and pay the application fee '
            .'at the DENR Regional Office. CENRO TWG has been notified for Step 5 preparation.</div>';
    }
    if ($returned) {
        $html.='<div class="alert alert-warning small mb-0">Waiting for applicant compliance and resubmission. '
            .'The next applicant submission returns directly to Step 4 re-evaluation.</div>';
    }
    return $html.'</div>';
}

function step4_panel_html(array $app,array $documents,array $errors=[]): string
{
    if (!step4_schema_ready()) {
        return '<div class="alert alert-warning">Step 4 requires database migration 027.</div>';
    }
    return match ((string)$app['status']) {
        'forwarded_to_lpdd','step4_resubmitted'=>step4_start_panel_html($app),
        'step4_under_evaluation'=>step4_evaluation_panel_html($app,$documents,$errors),
        'step4_corrections_required','step4_complete'=>step4_result_panel_html($app),
        default=>'<div class="panel soft"><p class="small mb-0">This application is not currently in Step 4.</p></div>'
    };
}
