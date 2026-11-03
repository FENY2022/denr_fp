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

/** @return array<string,list<array<string,mixed>>> */
function step4_documents_by_requirement(array $documents): array
{
    $groups=[];
    foreach ($documents as $document) {
        $type=(string)($document['requirement_type'] ?? 'other');
        $groups[$type][]=$document;
    }
    return $groups;
}

function step4_requirement_uploaded_files_html(string $type,array $documents): string
{
    if (!$documents) {
        return '<div class="small text-secondary mt-2">'
            . '<i class="bi bi-file-earmark-x me-1" aria-hidden="true"></i>'
            . 'No categorized file is available under this requirement.</div>';
    }

    $html='<div class="mt-2"><div class="small fw-semibold mb-2">'
        . '<i class="bi bi-folder2-open me-1" aria-hidden="true"></i>Uploaded File(s)</div>'
        . '<div class="vstack gap-2">';
    foreach ($documents as $doc) {
        $mime=(string)($doc['mime_type'] ?? '');
        $icon=$mime==='application/pdf' ? 'file-earmark-pdf' : 'file-earmark-image';
        $name=(string)($doc['original_name'] ?? 'Uploaded document');
        $preview=url('permit_document',['id'=>(int)$doc['id'],'preview'=>1]);
        $html.='<div class="border rounded-2 p-2 d-flex align-items-center gap-2 flex-wrap">'
            .'<i class="bi bi-'.e($icon).' fs-5" aria-hidden="true"></i>'
            .'<div class="flex-grow-1 min-w-0"><div class="small fw-semibold text-break">'
            .e($name).'</div><div class="small text-secondary">Revision '
            .(int)($doc['revision'] ?? 0).' · '
            .e(round(((int)($doc['file_size'] ?? 0))/1024,1).' KB').'</div></div>'
            .'<a class="btn btn-sm btn-outline-primary" data-document-preview '
            .'data-document-id="'.(int)$doc['id'].'" '
            .'data-document-mime="'.e($mime).'" '
            .'data-document-name="'.e($name).'" '
            .'data-document-category="'.e(step2_requirement_label($type)).'" '
            .'href="'.e($preview).'">'
            .'<i class="bi bi-eye me-1" aria-hidden="true"></i>View File</a></div>';
    }
    return $html.'</div></div>';
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

function step4_requirement_sections(): array
{
    return [
        'core'=>[
            'number'=>'01',
            'title'=>'Core Application Documents',
            'icon'=>'file-earmark-text',
            'requirements'=>['letter_of_intent','annex_1a','annex_1b']
        ],
        'regulatory'=>[
            'number'=>'02',
            'title'=>'Environmental / Regulatory Clearances',
            'icon'=>'shield-check',
            'requirements'=>['ecc_cnc','ncip_clearance','doe_esc_coa']
        ]
    ];
}

function step4_requirement_review_card_html(
    string $type,string $label,array $documents,string $current,string $remark
): string {
    $uploaded=count($documents);
    $html='<section class="border rounded-3 p-3 bg-body" data-step4-requirement="'.e($type).'">'
        .'<div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">'
        .'<div><strong>'.e($label).'</strong>'
        .'<div class="small text-secondary">Uploaded file(s), all revisions: '.$uploaded.'</div></div>'
        .'<span class="badge '.($uploaded>0?'text-bg-success':'text-bg-secondary').'">'
        .($uploaded>0?'Files available':'No categorized file').'</span></div>'
        .step4_requirement_uploaded_files_html($type,$documents)
        .'<div class="row g-2 mt-1"><div class="col-md-5">'
        .'<label class="form-label" for="step4-assessment-'.e($type).'">Assessment</label>'
        .'<select class="form-select" id="step4-assessment-'.e($type).'" '
        .'name="assessment['.e($type).']" data-step4-assessment required>'
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
    return $html.'</select></div><div class="col-md-7">'
        .'<label class="form-label" for="step4-remarks-'.e($type).'">Requirement Remarks</label>'
        .'<textarea class="form-control" id="step4-remarks-'.e($type).'" '
        .'name="requirement_remarks['.e($type).']" maxlength="1500" rows="2" '
        .'placeholder="Required when incomplete or insufficient">'.e($remark).'</textarea>'
        .'</div></div></section>';
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
    $groupedDocuments=step4_documents_by_requirement($documents);
    $postAssess=is_array($_POST['assessment'] ?? null) ? $_POST['assessment'] : [];
    $postRemarks=is_array($_POST['requirement_remarks'] ?? null) ? $_POST['requirement_remarks'] : [];
    $totalRequirements=count(step2_requirement_types());
    $assessedCount=0;
    foreach (step2_requirement_types() as $type=>$label) {
        $value=(string)($postAssess[$type] ?? $saved[$type]['assessment'] ?? '');
        if (in_array($value,step4_allowed_assessments(),true)) $assessedCount++;
    }
    $progressPercent=$totalRequirements>0
        ? (int)round(($assessedCount/$totalRequirements)*100) : 0;

    $html='<div class="panel soft">'
        .'<div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-3">'
        .'<div><h3 class="h6 mb-1"><i class="bi bi-clipboard-check me-2"></i>'
        .'LPDD-FUS Step 4 Documentary Evaluation</h3>'
        .'<p class="small text-secondary mb-0">Review the uploaded documents by section, '
        .'then record the overall evaluation and final decision.</p></div>'
        .'<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" '
        .'data-bs-target="#fp-files-modal">'
        .'<i class="bi bi-folder2-open me-1" aria-hidden="true"></i>View All Uploaded Files</button></div>'
        .(!empty($groupedDocuments['other'])
            ? '<div class="alert alert-warning small py-2"><strong>Unclassified files detected:</strong> '
                .count($groupedDocuments['other']).' earlier uploaded file(s) are not assigned to a named '
                .'Step 4 requirement. Use <strong>View All Uploaded Files</strong> to inspect them before deciding.</div>'
            : '')
        .errors_html($errors)
        .'<div class="border rounded-3 p-3 mb-3 bg-body">'
        .'<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">'
        .'<div><strong>Documentary Evaluation Progress</strong>'
        .'<div class="small text-secondary"><span data-step4-progress-count>'.$assessedCount.'</span> of '
        .$totalRequirements.' requirements assessed</div></div>'
        .'<span class="badge text-bg-light border" data-step4-progress-percent>'.$progressPercent.'%</span></div>'
        .'<progress class="w-100 mt-2" value="'.$assessedCount.'" max="'.$totalRequirements.'" '
        .'aria-label="Step 4 documentary evaluation progress" data-step4-progress-meter>'
        .$assessedCount.' of '.$totalRequirements.'</progress></div>'
        .'<form id="step4-decision-form" method="post" action="'
        .e(url('permit_step4_decision',['id'=>$id])).'">'.csrf_field()
        .'<input type="hidden" name="decision" value="" data-step4-decision-value>'
        .'<div class="accordion mb-3" id="step4-document-sections">';

    foreach (step4_requirement_sections() as $sectionKey=>$section) {
        $sectionId='step4-section-'.$sectionKey;
        $headingId=$sectionId.'-heading';
        $collapseId=$sectionId.'-collapse';
        $sectionAssessed=0;
        $sectionFiles=0;
        foreach ($section['requirements'] as $type) {
            $current=(string)($postAssess[$type] ?? $saved[$type]['assessment'] ?? '');
            if (in_array($current,step4_allowed_assessments(),true)) $sectionAssessed++;
            $sectionFiles+=count(is_array($groupedDocuments[$type] ?? null)
                ? $groupedDocuments[$type] : []);
        }

        $html.='<div class="accordion-item">'
            .'<h4 class="accordion-header" id="'.e($headingId).'">'
            .'<button class="accordion-button'.($sectionKey==='core'?'':' collapsed').'" type="button" '
            .'data-bs-toggle="collapse" data-bs-target="#'.e($collapseId).'" '
            .'aria-expanded="'.($sectionKey==='core'?'true':'false').'" '
            .'aria-controls="'.e($collapseId).'">'
            .'<span class="badge text-bg-success me-2">'.e((string)$section['number']).'</span>'
            .'<span><strong>'.e((string)$section['title']).'</strong>'
            .'<span class="small text-secondary ms-2">'.$sectionAssessed.'/'
            .count($section['requirements']).' assessed · '.$sectionFiles.' file(s)</span></span>'
            .'</button></h4>'
            .'<div id="'.e($collapseId).'" class="accordion-collapse collapse'
            .($sectionKey==='core'?' show':'').'" aria-labelledby="'.e($headingId).'" '
            .'data-bs-parent="#step4-document-sections">'
            .'<div class="accordion-body"><div class="vstack gap-3">';

        foreach ($section['requirements'] as $type) {
            $label=step2_requirement_types()[$type] ?? step2_requirement_label($type);
            $current=(string)($postAssess[$type] ?? $saved[$type]['assessment'] ?? '');
            $remark=(string)($postRemarks[$type] ?? $saved[$type]['remarks'] ?? '');
            $docs=is_array($groupedDocuments[$type] ?? null) ? $groupedDocuments[$type] : [];
            $html.=step4_requirement_review_card_html($type,$label,$docs,$current,$remark);
        }
        $html.='</div></div></div></div>';
    }

    $html.='</div>'
        .'<section class="border rounded-3 p-3 mb-3 bg-body" id="step4-overall-section">'
        .'<div class="d-flex align-items-center gap-2 mb-3">'
        .'<span class="badge text-bg-success">03</span>'
        .'<div><h4 class="h6 mb-0">Overall Evaluation & Instructions</h4>'
        .'<div class="small text-secondary">Summarize the documentary findings and next instructions.</div></div></div>'
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
        .'<input class="form-control" id="step4-field-schedule" name="field_validation_schedule" '
        .'type="datetime-local" value="'.e((string)($_POST['field_validation_schedule'] ?? '')).'">'
        .'</section>'
        .'<section class="border rounded-3 p-3 bg-body" id="step4-final-decision-section">'
        .'<div class="d-flex align-items-center gap-2 mb-3">'
        .'<span class="badge text-bg-success">04</span>'
        .'<div><h4 class="h6 mb-0">Final Documentary Evaluation Decision</h4>'
        .'<div class="small text-secondary">Complete all six requirement assessments before finalizing.</div></div></div>'
        .'<div class="alert alert-warning small d-none" data-step4-validation></div>'
        .'<div class="d-grid gap-2">'
        .'<button type="button" class="btn btn-success" data-step4-decision="complete" '
        .($assessedCount<$totalRequirements?'disabled':'').'>'
        .'<i class="bi bi-check-circle me-1"></i>Complete & Sufficient</button>'
        .'<button type="button" class="btn btn-outline-danger" data-step4-decision="return">'
        .'<i class="bi bi-arrow-return-left me-1"></i>Incomplete / Insufficient – Return to Applicant</button>'
        .'</div></section></form>'
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
        .'<script src="assets/permit-step4-review.js?v=2" defer></script></div>';
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
