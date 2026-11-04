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
        "SELECT a.id,a.project_title,a.project_type,a.project_type_other,a.revision,a.status,a.last_submitted_at,
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

    $paymentReady=$selected==='step4_complete' && permit_payment_schema_ready();
    $paymentMap=[];
    if ($paymentReady && $apps) {
        $ids=array_map(static fn(array $row): int => (int)$row['id'],$apps);
        $marks=implode(',',array_fill(0,count($ids),'?'));
        foreach (query_all(
            'SELECT application_id,status FROM permit_payment_verifications '
                .'WHERE application_id IN ('.$marks.')',$ids
        ) as $paymentRow) {
            $paymentMap[(int)$paymentRow['application_id']]=(string)$paymentRow['status'];
        }
    }

    $html='<div class="panel"><div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">'
        .'<div><h2 class="h5 mb-1">Step 4 – Documentary Evaluation</h2>'
        .'<p class="small text-secondary mb-0">LPDD-FUS determines documentary completeness and sufficiency. '
        .'Lacking/Inappropriate applications return to the applicant; complete/sufficient applications proceed toward Step 5 field validation preparation.</p></div>'
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
            .'<thead><tr><th>FP No.</th><th>Applicant</th><th>Project</th><th>Type of Project</th><th>Revision</th>'
            .'<th>Date / Time</th><th>Status</th>'
            .($selected==='step4_complete' ? '<th>Payment Status</th>' : '')
            .'<th>Action</th></tr></thead><tbody>';
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
                .'<td>'.e(step2_project_label((string)($app['project_type']??''),
                    (string)($app['project_type_other']??''))).'</td>'
                .'<td>'.(int)$app['revision'].'</td>'
                .'<td>'.e(fpoms_display_datetime($date)).'</td>'
                .'<td>'.step2_status_badge((string)$app['status']).'</td>'
                .($selected==='step4_complete'
                    ? '<td>'.(
                        $paymentReady
                            ? permit_payment_status_badge([
                                'status'=>$paymentMap[(int)$app['id']] ?? 'awaiting_payment'
                            ])
                            : '<span class="badge text-bg-secondary">Payment Tracking Not Enabled</span>'
                    ).'</td>'
                    : '')
                .'<td><div class="d-flex flex-wrap gap-2">'
                .'<a class="btn btn-sm btn-outline-primary" href="'
                .e(url('permit_application',['id'=>(int)$app['id']])).'">'
                .e($label).'</a>'
                .($selected==='step4_complete'
                    ? '<a class="btn btn-sm btn-outline-success" href="'
                        .e(url('permit_application',['id'=>(int)$app['id']]).'#fp-payment-verification')
                        .'"><i class="bi bi-receipt me-1"></i>'
                        .(($paymentMap[(int)$app['id']] ?? 'awaiting_payment')==='verified'
                            ? 'View Payment Receipt' : 'Verify Payment &amp; Upload OR')
                        .'</a>'
                    : '')
                .'</div></td></tr>';
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
            .'<a class="btn btn-sm btn-outline-primary" data-step4-file-link '
            .'data-document-id="'.(int)$doc['id'].'" '
            .'data-document-mime="'.e($mime).'" '
            .'data-document-name="'.e($name).'" '
            .'data-document-category="'.e(step2_requirement_label($type)).'" '
            .'href="'.e($preview).'">'
            .'<i class="bi bi-eye me-1" aria-hidden="true"></i>View File</a></div>';
    }
    return $html.'</div></div>';
}


/** Child-modal file directory; every link uses the authorized permit_document route. */
function step4_modal_all_files_content_html(array $documents): string
{
    $html='<p class="small text-secondary">Select a file to preview. Earlier revisions remain available. '
        .'Closing this child modal returns to the current Step 4 review step.</p>';
    if (!$documents) {
        return $html.'<div class="alert alert-light border">No uploaded documents recorded for this application.</div>';
    }
    $groups=step4_documents_by_requirement($documents);
    foreach (step2_requirement_types() as $type=>$label) {
        if (empty($groups[$type])) continue;
        $html.='<div class="border rounded-3 p-3 mb-3">'
            .'<h4 class="h6">'.e($label).' <span class="badge text-bg-secondary">'
            .count($groups[$type]).'</span></h4>'
            .step4_requirement_uploaded_files_html($type,$groups[$type]).'</div>';
    }
    foreach ($groups as $type=>$docs) {
        if (isset(step2_requirement_types()[$type])) continue;
        $html.='<div class="border rounded-3 p-3 mb-3">'
            .'<h4 class="h6">'.e(step2_requirement_label($type)).' '
            .'<span class="badge text-bg-warning">'.count($docs).'</span></h4>'
            .step4_requirement_uploaded_files_html($type,$docs).'</div>';
    }
    return $html;
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
    string $type,string $label,array $documents,string $current,string $remark,
    string $projectType=''
): string {
    $exempt=$type==='doe_esc_coa' && step2_project_doe_exempt($projectType);
    if ($exempt) {
        $current='not_applicable';
        $remark='Exempt from Annex 1 #10 for Solar/Biomass.';
    }
    $uploaded=count($documents);
    $html='<section class="border rounded-3 p-3 bg-body" data-step4-requirement="'.e($type).'">'
        .'<div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">'
        .'<div><strong>'.e($label).'</strong>'
        .'<div class="small text-secondary">Uploaded file(s), all revisions: '.$uploaded.'</div>'
        .(($note=step2_requirement_note($type,$projectType))!==''
            ? '<div class="small text-info mt-1">'.e($note).'</div>' : '')
        .'</div>'
        .'<span class="badge '.($uploaded>0?'text-bg-success':'text-bg-secondary').'">'
        .($uploaded>0?'Files available':'No categorized file').'</span></div>'
        .step4_requirement_uploaded_files_html($type,$documents)
        .'<div class="row g-2 mt-1"><div class="col-md-5">'
        .'<label class="form-label" for="step4-assessment-'.e($type).'">Assessment</label>'
        .'<select class="form-select" id="step4-assessment-'.e($type).'" '
        .'name="assessment['.e($type).']" data-step4-assessment required'
        .($exempt?' disabled aria-disabled="true"':'').'>'
        .'<option value="">Select assessment</option>';
    foreach ([
        'complete'=>'Complete',
        'incomplete'=>'Lacking',
        'insufficient'=>'Inappropriate',
        'not_applicable'=>'Not Applicable'
    ] as $value=>$text) {
        $html.='<option value="'.e($value).'"'.($current===$value?' selected':'').'>'
            .e($text).'</option>';
    }
    return $html.'</select></div><div class="col-md-7">'
        .'<label class="form-label" for="step4-remarks-'.e($type).'">Requirement Remarks</label>'
        .'<textarea class="form-control" id="step4-remarks-'.e($type).'" '
        .'name="requirement_remarks['.e($type).']" maxlength="1500" rows="2" '
        .($exempt?'readonly ':'')
        .'placeholder="Required when Lacking or Inappropriate">'.e($remark).'</textarea>'
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
    $requirements=step2_requirement_types();
    $projectType=(string)($app['project_type'] ?? 'unspecified');
    $totalRequirements=count($requirements);
    $assessedCount=0;
    foreach ($requirements as $type=>$label) {
        $value=$type==='doe_esc_coa' && step2_project_doe_exempt($projectType)
            ? 'not_applicable'
            : (string)($postAssess[$type] ?? $saved[$type]['assessment'] ?? '');
        if (in_array($value,step4_allowed_assessments(),true)) $assessedCount++;
    }
    $progressPercent=$totalRequirements>0
        ? (int)round(($assessedCount/$totalRequirements)*100) : 0;
    $initialStep=$errors ? 4 : 1;

    $html='<div class="panel soft">'
        .'<h3 class="h6 mb-2"><i class="bi bi-clipboard-check me-2" aria-hidden="true"></i>'
        .'Step 4 – Documentary Evaluation</h3>'
        .'<p class="small text-secondary">Review the documentary requirements using a guided '
        .'four-step workflow. Uploaded files open in a child preview modal without closing this evaluation.</p>'
        .'<button type="button" class="btn btn-success w-100" data-bs-toggle="modal" '
        .'data-bs-target="#step4-evaluation-modal">'
        .'<i class="bi bi-clipboard2-check me-2" aria-hidden="true"></i>'
        .'Open Documentary Evaluation</button>'
        .'<p class="small text-secondary mt-2 mb-0">No decision is saved until final confirmation.</p>'
        .'</div>'
        .'<div class="modal fade" id="step4-evaluation-modal" tabindex="-1" '
        .'aria-labelledby="step4-evaluation-modal-title" aria-hidden="true" data-bs-backdrop="static">'
        .'<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">'
        .'<div class="modal-content step4-modal-content">'
        .'<div class="modal-header">'
        .'<div><h2 class="modal-title h5 mb-1" id="step4-evaluation-modal-title">'
        .'Step 4 – Documentary Evaluation · FP #'.$id.'</h2>'
        .'<p class="small text-secondary mb-0">'
        .e((string)($app['applicant_name'] ?? 'Applicant')).' · '
        .e((string)($app['project_title'] ?? 'FP Application'))
        .' · '.e(step2_project_label($projectType,(string)($app['project_type_other'] ?? '')))
        .' · Revision '.(int)$app['revision'].'</p></div>'
        .'<button type="button" class="btn-close" data-bs-dismiss="modal" '
        .'aria-label="Close Documentary Evaluation"></button></div>'
        .'<div class="modal-body" data-step4-modal-body>'
        .'<div data-step4-main-view data-step4-wizard '
        .'data-step4-project-type="'.e($projectType).'" '
        .'data-step4-initial-step="'.$initialStep.'">'
        .'<div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-3">'
        .'<div><h3 class="h6 mb-1"><i class="bi bi-clipboard-check me-2"></i>'
        .'LPDD-FUS Step 4 Documentary Evaluation</h3>'
        .'<p class="small text-secondary mb-0">Use Previous and Next to review one section at a time. '
        .'Your selections stay in this evaluation while opening and closing document previews.</p></div>'
        .'<button type="button" class="btn btn-sm btn-outline-primary" data-step4-view-all>'
        .'<i class="bi bi-folder2-open me-1" aria-hidden="true"></i>View All Uploaded Files</button></div>'
        .(!empty($groupedDocuments['other'])
            ? '<div class="alert alert-info small py-2"><strong>Other Attached Documents:</strong> '
                .count($groupedDocuments['other']).' optional Other Attached Document file(s) are not assigned to a named '
                .'Step 4 requirement. Use <strong>View All Uploaded Files</strong> to inspect them before deciding.</div>'
            : '')
        .errors_html($errors)
        .'<div class="border rounded-3 p-3 mb-3 bg-body step4-sticky-progress" data-step4-sticky-progress>'
        .'<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap" data-step4-progress-details>'
        .'<div><strong data-step4-step-label>Step 1 of 4</strong>'
        .'<div class="small text-secondary" data-step4-step-title>Core Application Documents</div></div>'
        .'<span class="badge text-bg-light border" data-step4-progress-percent>'.$progressPercent.'%</span></div>'
        .'<progress class="w-100 mt-2" value="'.$assessedCount.'" max="'.$totalRequirements.'" '
        .'aria-label="Step 4 documentary evaluation progress" data-step4-progress-meter>'
        .$assessedCount.' of '.$totalRequirements.'</progress>'
        .'<div class="small text-secondary mt-1" data-step4-progress-details><span data-step4-progress-count>'.$assessedCount.'</span> of '
        .$totalRequirements.' documentary requirements assessed</div>'
        .'<div class="d-flex justify-content-between gap-2 mt-3 small" data-step4-progress-details aria-label="Step 4 wizard steps">'
        .'<span data-step4-step-indicator="1" class="fw-semibold text-success">01 Documents</span>'
        .'<span data-step4-step-indicator="2" class="text-secondary">02 Clearances</span>'
        .'<span data-step4-step-indicator="3" class="text-secondary">03 Evaluation</span>'
        .'<span data-step4-step-indicator="4" class="text-secondary">04 Review</span>'
        .'</div></div>'
        .'<form id="step4-decision-form" method="post" action="'
        .e(url('permit_step4_decision',['id'=>$id])).'">'.csrf_field()
        .'<input type="hidden" name="decision" value="" data-step4-decision-value>';

    $sections=step4_requirement_sections();
    $core=$sections['core'];
    $regulatory=$sections['regulatory'];

    $html.='<section data-step4-wizard-step="1" data-step4-step-name="Core Application Documents">'
        .'<div class="border rounded-3 p-3 bg-body">'
        .'<div class="d-flex align-items-start gap-2 mb-3">'
        .'<span class="badge text-bg-success">01</span>'
        .'<div><h4 class="h6 mb-1">Core Application Documents</h4>'
        .'<p class="small text-secondary mb-0">Review the principal application documents before continuing.</p></div></div>'
        .'<div class="vstack gap-3">';
    foreach ($core['requirements'] as $type) {
        $label=$requirements[$type] ?? step2_requirement_label($type);
        $current=(string)($postAssess[$type] ?? $saved[$type]['assessment'] ?? '');
        $remark=(string)($postRemarks[$type] ?? $saved[$type]['remarks'] ?? '');
        $docs=is_array($groupedDocuments[$type] ?? null) ? $groupedDocuments[$type] : [];
        $html.=step4_requirement_review_card_html($type,$label,$docs,$current,$remark,$projectType);
    }
    $html.='</div></div></section>';

    $html.='<section class="d-none" data-step4-wizard-step="2" '
        .'data-step4-step-name="Environmental / Regulatory Clearances">'
        .'<div class="border rounded-3 p-3 bg-body">'
        .'<div class="d-flex align-items-start gap-2 mb-3">'
        .'<span class="badge text-bg-success">02</span>'
        .'<div><h4 class="h6 mb-1">Environmental / Regulatory Clearances</h4>'
        .'<p class="small text-secondary mb-0">ECC/CNC: six months after FP issuance. '
        .'NCIP Clearance: one year after FP issuance. '
        .'DOE #10 is exempt for Solar/Biomass and required for Other project types.</p></div></div>'
        .'<div class="vstack gap-3">';
    foreach ($regulatory['requirements'] as $type) {
        $label=$requirements[$type] ?? step2_requirement_label($type);
        $current=(string)($postAssess[$type] ?? $saved[$type]['assessment'] ?? '');
        $remark=(string)($postRemarks[$type] ?? $saved[$type]['remarks'] ?? '');
        $docs=is_array($groupedDocuments[$type] ?? null) ? $groupedDocuments[$type] : [];
        $html.=step4_requirement_review_card_html($type,$label,$docs,$current,$remark,$projectType);
    }
    $html.='</div></div></section>';

    $html.='<section class="d-none" data-step4-wizard-step="3" '
        .'data-step4-step-name="Overall Evaluation & Instructions">'
        .'<div class="border rounded-3 p-3 bg-body">'
        .'<div class="d-flex align-items-start gap-2 mb-3">'
        .'<span class="badge text-bg-success">03</span>'
        .'<div><h4 class="h6 mb-1">Overall Evaluation & Instructions</h4>'
        .'<p class="small text-secondary mb-0">Summarize findings and prepare any applicant or field-validation instructions.</p></div></div>'
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
        .'<span class="text-secondary">(required only for Complete & Sufficient)</span></label>'
        .'<input class="form-control" id="step4-field-schedule" name="field_validation_schedule" '
        .'type="datetime-local" value="'.e((string)($_POST['field_validation_schedule'] ?? '')).'">'
        .'</div></section>';

    $html.='<section class="d-none" data-step4-wizard-step="4" '
        .'data-step4-step-name="Review & Final Decision">'
        .'<div class="border rounded-3 p-3 bg-body">'
        .'<div class="d-flex align-items-start gap-2 mb-3">'
        .'<span class="badge text-bg-success">04</span>'
        .'<div><h4 class="h6 mb-1">Review & Final Decision</h4>'
        .'<p class="small text-secondary mb-0">Verify all assessments and remarks before recording the official Step 4 decision.</p></div></div>'
        .'<div class="table-responsive mb-3"><table class="table table-sm align-middle mb-0">'
        .'<thead><tr><th>Documentary Requirement</th><th>Assessment</th><th>Remarks</th></tr></thead><tbody>';
    foreach ($requirements as $type=>$label) {
        $html.='<tr><td>'.e($label).'</td>'
            .'<td><span class="badge text-bg-secondary" data-step4-summary-assessment="'.e($type).'">'
            .'Not assessed</span></td>'
            .'<td class="small text-secondary" data-step4-summary-remarks="'.e($type).'">—</td></tr>';
    }
    $html.='</tbody></table></div>'
        .'<div class="row g-2 mb-3">'
        .'<div class="col-md-6"><div class="border rounded-2 p-2 h-100">'
        .'<div class="small text-secondary">Overall Evaluation Remarks</div>'
        .'<div class="small" data-step4-summary-overall>—</div></div></div>'
        .'<div class="col-md-6"><div class="border rounded-2 p-2 h-100">'
        .'<div class="small text-secondary">Applicant Instructions</div>'
        .'<div class="small" data-step4-summary-instructions>—</div></div></div>'
        .'<div class="col-12"><div class="border rounded-2 p-2">'
        .'<div class="small text-secondary">Field Validation / Assessment Schedule</div>'
        .'<div class="small" data-step4-summary-schedule>Not set</div></div></div></div>'
        .'<div class="alert alert-warning small d-none" data-step4-validation role="alert"></div>'
        .'<div class="alert alert-warning d-none mt-3" data-step4-confirm-panel '
        .'role="alertdialog" aria-labelledby="step4-confirm-title">'
        .'<h5 class="h6" id="step4-confirm-title" data-step4-modal-title>Confirm Step 4 Decision</h5>'
        .'<p class="small" data-step4-modal-message></p>'
        .'<div class="d-flex flex-wrap gap-2">'
        .'<button type="button" class="btn btn-outline-secondary" data-step4-cancel-confirm>'
        .'Cancel</button>'
        .'<button type="button" class="btn btn-danger" data-step4-confirm>'
        .'Confirm Decision</button></div></div>'
        .'</div></section></form></div>'
        .'</div>'
        .'<div class="modal-footer step4-modal-footer">'
        .'<div class="d-flex align-items-center gap-2 flex-wrap me-auto" data-step4-wizard-footer>'
        .'<span class="small text-secondary me-2" data-step4-footer-label>Step 1 of 4</span>'
        .'<button type="button" class="btn btn-outline-secondary d-none" data-step4-prev>'
        .'<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Previous</button>'
        .'<button type="button" class="btn btn-primary" data-step4-next>'
        .'Next <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></button>'
        .'<div class="d-none d-flex flex-wrap gap-2" data-step4-decision-controls>'
        .'<button type="button" class="btn btn-success" data-step4-decision="complete" '
        .($assessedCount<$totalRequirements?'disabled':'').'>'
        .'<i class="bi bi-check-circle me-1"></i>Complete & Sufficient</button>'
        .'<button type="button" class="btn btn-outline-danger" data-step4-decision="return">'
        .'<i class="bi bi-arrow-return-left me-1"></i>Return to Applicant</button></div>'
        .'</div>'
        .'<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>'
        .'</div>'
        .'<div class="step4-child-modal d-none" data-step4-child-modal role="dialog" '
        .'aria-modal="true" aria-labelledby="step4-child-modal-title">'
        .'<div class="step4-child-backdrop" data-step4-child-close aria-hidden="true"></div>'
        .'<div class="step4-child-dialog" role="document">'
        .'<div class="step4-child-header">'
        .'<div><h3 class="h5 mb-1" id="step4-child-modal-title" data-step4-child-title>'
        .'Uploaded Documents</h3>'
        .'<p class="small text-secondary mb-0" data-step4-preview-category></p></div>'
        .'<div class="d-flex align-items-center gap-2">'
        .'<button type="button" class="btn btn-sm btn-outline-primary" data-step4-fullscreen>'
        .'<i class="bi bi-arrows-fullscreen me-1" aria-hidden="true"></i>'
        .'<span data-step4-fullscreen-label>Full Screen</span></button>'
        .'<button type="button" class="btn-close" data-step4-child-close '
        .'aria-label="Close document preview"></button></div></div>'
        .'<div class="step4-child-body">'
        .'<section data-step4-child-files>'
        .step4_modal_all_files_content_html($documents)
        .'</section>'
        .'<section class="d-none" data-step4-child-preview>'
        .'<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">'
        .'<strong data-step4-preview-name>Document Preview</strong>'
        .'<a class="btn btn-sm btn-outline-primary" data-step4-download href="#">'
        .'<i class="bi bi-download me-1" aria-hidden="true"></i>Download File</a></div>'
        .'<div class="step4-document-viewer border rounded-3" data-step4-preview-area></div>'
        .'<p class="small text-danger d-none mt-2" data-step4-preview-error>'
        .'Preview unavailable. Use Download File to access the document.</p>'
        .'</section></div>'
        .'<div class="step4-child-footer">'
        .'<div class="d-none d-flex align-items-center gap-2 flex-wrap me-auto" '
        .'data-step4-preview-navigation>'
        .'<button type="button" class="btn btn-sm btn-outline-secondary" data-step4-file-prev>'
        .'<i class="bi bi-chevron-left"></i> Previous File</button>'
        .'<span class="badge text-bg-light border" data-step4-file-count>0/0</span>'
        .'<button type="button" class="btn btn-sm btn-outline-secondary" data-step4-file-next>'
        .'Next File <i class="bi bi-chevron-right"></i></button>'
        .'<button type="button" class="btn btn-sm btn-outline-primary d-none" data-step4-back-files>'
        .'<i class="bi bi-arrow-left me-1"></i>Back to File List</button></div>'
        .'<button type="button" class="btn btn-secondary" data-step4-child-close>Close</button>'
        .'</div></div></div>'
        .'</div></div></div>'
        .'<script src="assets/permit-step4-review.js?v=8" defer></script>';

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
