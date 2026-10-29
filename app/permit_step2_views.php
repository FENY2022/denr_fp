<?php
declare(strict_types=1);

/** Annex A Step 2 screens. Existing DENR shell, Bootstrap and document modal. */

function step2_status_label(string $status): string
{
    return match ($status) {
        'submitted' => 'Pending LPDD-FUS Initial Review',
        'corrections_required' => 'Corrections Required',
        'forwarded_to_ro' => 'Forwarded to RO Receiving',
        default => 'Unknown Status'
    };
}

function step2_status_badge(string $status): string
{
    $tone = match ($status) {
        'submitted' => 'info',
        'corrections_required' => 'warning',
        'forwarded_to_ro' => 'success',
        default => 'secondary'
    };
    return '<span class="badge text-bg-' . $tone . '">' . e(step2_status_label($status)) . '</span>';
}

function step2_documents_html(array $docs): string
{
    if (!$docs) return '<p class="small text-secondary">No application documents uploaded yet.</p>';
    $html = '<ul class="list-group mb-3" data-document-list>';
    foreach ($docs as $doc) {
        $url = url('permit_document',['id'=>(int)$doc['id'],'preview'=>1]);
        $html .= '<li class="list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap">'
            . '<div class="small"><strong>' . e((string)$doc['original_name']) . '</strong>'
            . '<div class="text-secondary">Revision ' . (int)$doc['revision'] . ' · '
            . e(round(((int)$doc['file_size']) / 1024, 1) . ' KB') . '</div></div>'
            . '<a class="btn btn-sm btn-outline-primary" data-document-preview '
            . 'data-document-mime="' . e((string)$doc['mime_type']) . '" '
            . 'data-document-name="' . e((string)$doc['original_name']) . '" '
            . 'href="' . e($url) . '">View / Download</a></li>';
    }
    return $html . '</ul>'
        . '<div class="modal fade" id="registration-document-modal" tabindex="-1" '
        . 'aria-labelledby="registration-document-modal-title" aria-hidden="true">'
        . '<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">'
        . '<div class="modal-content"><div class="modal-header">'
        . '<h2 class="modal-title h5" id="registration-document-modal-title">FP Document Preview</h2>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
        . '</div><div class="modal-body text-center">'
        . '<div id="registration-document-preview" class="overflow-auto"></div>'
        . '<p id="registration-document-error" class="text-danger d-none">Preview unavailable. Use Download instead.</p>'
        . '</div><div class="modal-footer">'
        . '<button type="button" class="btn btn-outline-secondary" data-document-zoom="out">Zoom Out</button>'
        . '<button type="button" class="btn btn-outline-secondary" data-document-zoom="reset">Fit to Screen</button>'
        . '<button type="button" class="btn btn-outline-secondary" data-document-zoom="in">Zoom In</button>'
        . '<a class="btn btn-primary" id="registration-document-download" href="#">Download</a>'
        . '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>'
        . '</div></div></div></div>';
}

function step2_history_html(int $applicationId): string
{
    $events = step2_events($applicationId);
    $html = '<h3 class="h6">Submission and Review History</h3>';
    if (!$events) return $html . '<p class="text-secondary small">No events recorded yet.</p>';
    foreach ($events as $event) {
        $actor = (string)($event['staff_actor'] ?? $event['applicant_actor'] ?? 'System');
        $label = match ($event['action']) {
            'submitted' => 'Application Submitted',
            'resubmitted' => 'Corrections Resubmitted',
            'returned_for_correction' => 'Returned for Correction',
            'forwarded_to_ro' => 'Forwarded to RO Receiving',
            default => ucwords(str_replace('_',' ',(string)$event['action']))
        };
        $html .= '<div class="history"><strong>' . e($label) . '</strong>'
            . ' · Revision ' . (int)$event['revision']
            . '<div class="small text-secondary">' . e($actor) . ' · '
            . e((string)$event['occurred_at']) . '</div>';
        if (trim((string)($event['remarks'] ?? '')) !== '') {
            $html .= '<p class="small mb-0 mt-2">' . nl2br(e((string)$event['remarks'])) . '</p>';
        }
        $html .= '</div>';
    }
    return $html;
}

function view_step2_applications(): void
{
    $client = step2_require_applicant();
    $apps = query_all(
        'SELECT id,project_title,project_location,status,revision,last_submitted_at
         FROM permit_step2_applications WHERE client_id=?
         ORDER BY created_at DESC,id DESC LIMIT 100', [(int)$client['id']]
    );
    $html = '<div class="panel"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3">'
        . '<div><h2 class="h5 mb-1">My FP Applications</h2>'
        . '<p class="small text-secondary mb-0">Step 2: Submit documents for LPDD-FUS Technical Staff initial review.</p></div>'
        . '<a class="btn btn-primary" href="' . url('permit_apply') . '">'
        . '<i class="bi bi-plus-circle me-1"></i>New FP Application</a></div>'
        . '<div class="alert alert-info small mt-3">As required by Annex A, an application goes first to '
        . '<strong>LPDD-FUS Technical Staff</strong> for an initial completeness check. '
        . 'Only complete applications proceed to RO Receiving. '
        . 'The official documentary checklist must be confirmed with LPDD/FUS before production use.</div>';
    if (!$apps) $html .= '<p class="text-secondary mb-0">No FP applications submitted yet.</p>';
    else {
        $html .= '<div class="table-responsive"><table class="table table-hover align-middle">'
            . '<thead><tr><th>No.</th><th>Project</th><th>Submitted</th><th>Revision</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($apps as $app) {
            $html .= '<tr><td>#' . (int)$app['id'] . '</td><td><strong>'
                . e((string)$app['project_title']) . '</strong><div class="text-secondary small">'
                . e((string)$app['project_location']) . '</div></td><td>'
                . e((string)$app['last_submitted_at']) . '</td><td>' . (int)$app['revision']
                . '</td><td>' . step2_status_badge((string)$app['status']) . '</td>'
                . '<td><a class="btn btn-sm btn-outline-primary" href="'
                . e(url('permit_application',['id'=>(int)$app['id']])) . '">View</a></td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    render_page('My FP Applications', $html . '</div>');
}

/**
 * Browser-side PDF organizer; no file is sent until the user confirms and
 * the existing secure upload flow receives the resulting File.
 */
function step2_pdf_organizer_modal_html(): string
{
    return '<div class="modal fade fp-pdf-organizer" id="fp-pdf-organizer-modal" tabindex="-1" '
        . 'aria-labelledby="fp-pdf-organizer-title" aria-hidden="true">'
        . '<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">'
        . '<div class="modal-content">'
        . '<div class="modal-header"><div><h2 class="modal-title h5" id="fp-pdf-organizer-title">'
        . '<i class="bi bi-file-earmark-pdf text-danger me-2" aria-hidden="true"></i>'
        . 'Organize PDF Pages</h2><p class="small text-secondary mb-0" data-pdf-file-name></p></div>'
        . '<button type="button" class="btn-close" data-pdf-cancel '
        . 'aria-label="Cancel PDF organization"></button></div>'
        . '<div class="modal-body">'
        . '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">'
        . '<span class="badge text-bg-success" data-pdf-page-count>Loading pages…</span>'
        . '<span class="fp-pdf-organizer-summary">Drag cards or use arrows to change order. '
        . 'Duplicate, remove, or preview any page.</span></div>'
        . '<div class="alert alert-warning small py-2">'
        . '<i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>'
        . 'Reorganizing a digitally signed PDF can invalidate signatures, links, '
        . 'bookmarks or forms. Keep the signed original separately.</div>'
        . '<div class="fp-pdf-organizer-body">'
        . '<div class="fp-pdf-organizer-grid" data-pdf-page-grid role="group" '
        . 'aria-label="Reorder or duplicate PDF pages"></div>'
        . '<div class="fp-pdf-preview-panel" data-pdf-preview '
        . 'aria-label="Selected PDF page preview"></div></div>'
        . '<p class="small text-secondary mt-3 mb-0" role="status" aria-live="polite" '
        . 'data-pdf-status></p></div>'
        . '<div class="modal-footer">'
        . '<button type="button" class="btn btn-outline-secondary" data-pdf-reset>'
        . '<i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</button>'
        . '<button type="button" class="btn btn-secondary" data-pdf-cancel>Cancel</button>'
        . '<button type="button" class="btn btn-primary" data-pdf-save>'
        . '<i class="bi bi-check2-circle me-1" aria-hidden="true"></i><span data-pdf-save-label>Save &amp; Upload PDF</span></button>'
        . '</div></div></div></div>';
}

function view_step2_form(int $applicationId = 0, array $errors = []): void
{
    $client = step2_require_applicant();
    $app = null;
    $docs = [];
    if ($applicationId > 0) {
        $app = step2_application_for_view($client,$applicationId);
        if ($app['status'] !== 'corrections_required') {
            show_error('This FP application is not available for correction.');
        }
        $docs = step2_documents($applicationId);
    }
    $editing = $app !== null;
    $title = (string)($_POST['project_title'] ?? $app['project_title'] ?? '');
    $location = (string)($_POST['project_location'] ?? $app['project_location'] ?? '');
    $purpose = (string)($_POST['purpose'] ?? $app['purpose'] ?? '');
    $maxMb = round(max(1,(int)env_value('MAX_UPLOAD_BYTES','5242880'))/1048576,1);
    $formUrl = url('permit_apply',$editing ? ['id'=>$applicationId] : []);
    $draftMode = step2_drafts_schema_ready();
    $savedDocs = $draftMode ? step2_draft_public_list((int)$client['id'],$applicationId) : [];
    $html = '<div class="mb-3"><a href="' . url('permit_applications')
        . '">← My FP Applications</a></div><div class="row g-3">'
        . '<div class="col-lg-8"><div class="panel">'
        . '<h2 class="h5">' . ($editing ? 'Correct and Resubmit FP Application #'
            . $applicationId : 'Submit FP Application – Step 2') . '</h2>'
        . errors_html($errors)
        . '<p class="small text-secondary">Provide basic project identification and upload supporting documents. '
        . 'Documentary completeness will be evaluated by LPDD-FUS Technical Staff, '
        . 'using the official checklist approved by DENR.</p>';
    if ($editing) {
        $latest = query_one(
            "SELECT remarks FROM permit_step2_events
             WHERE application_id=? AND action='returned_for_correction'
             ORDER BY id DESC LIMIT 1", [$applicationId]
        );
        $html .= '<div class="alert alert-warning"><strong>Reviewer corrections required:</strong><p class="mb-0">'
            . nl2br(e((string)($latest['remarks'] ?? 'Please follow the review instructions.')))
            . '</p></div>';
    }
    $html .= '<form id="permit-step2-form" method="post" action="' . e($formUrl)
        . '" enctype="multipart/form-data" data-draft-key="fpoms.permit.'
        . (int)$client['id'] . '.' . $applicationId . '.v1">'
        . csrf_field()
        . '<div class="mb-3"><label class="form-label" for="project_title">Application / Project Title '
        . '<span class="text-danger">*</span></label>'
        . '<input class="form-control" id="project_title" name="project_title" required minlength="5" '
        . 'maxlength="180" value="' . e($title) . '"></div>'
        . '<div class="mb-3"><label class="form-label" for="project_location">Project Location '
        . '<span class="text-danger">*</span></label>'
        . '<input class="form-control" id="project_location" name="project_location" '
        . 'required minlength="5" maxlength="300" value="' . e($location) . '"></div>'
        . '<div class="mb-3"><label class="form-label" for="purpose">Project Description (optional)</label>'
        . '<textarea class="form-control" id="purpose" name="purpose" rows="4" '
        . 'maxlength="2000">' . e($purpose) . '</textarea></div>'
        . '<div class="mb-3"><label class="form-label" for="application_files">FP Supporting Documents '
        . ($editing ? '(optional additional files)' : '<span class="text-danger">*</span>') . '</label>'
        . '<div id="step2-draft-uploader" data-step2-mode="'
        . ($draftMode ? 'saved' : 'direct') . '" data-application-id="'
        . $applicationId . '" data-max-bytes="'
        . max(1,(int)env_value('MAX_UPLOAD_BYTES','5242880')) . '">'

        . '<input class="form-control" type="file" id="application_files" '
        . ($draftMode ? '' : 'name="application_files[]" ')
        . 'accept=".pdf,.jpg,.jpeg,.png" multiple '
        . (!$editing && !$draftMode ? 'required ' : '') . '>'
        . '<div class="form-text">Up to 10 files per submission. PDF/JPG/PNG, maximum '
        . e((string)$maxMb) . ' MB each. Documents are saved privately outside the website. '
        . 'The exact required file types must be agreed with LPDD-FUS.</div>'
        . ($draftMode ? step2_draft_list_html($savedDocs)
            : step2_direct_selected_files_html())
        . '<p class="small text-secondary mt-2" role="status" data-step2-feedback></p>'
        . '<p class="small text-warning mt-2" data-step2-js-warning>'
        . 'JavaScript is needed for the Selected Files display and upload progress. '
        . ($draftMode
            ? 'Do not submit until each file shows Saved — ready for submission.'
            : 'You may still submit the chosen files using the standard upload.')
        . '</p>'
        . ($draftMode ? ''
            : '<div class="alert alert-warning small mt-2 mb-0" role="note">'
                . '<strong>Draft uploads not enabled.</strong> '
                . 'Install database migration 015 to save files immediately and retain '
                . 'them after refresh. Until then, files upload only when you submit.'
                . '</div>')
        . '</div></div>'
        . '<button class="btn btn-primary" type="submit">'
        . ($editing ? 'Resubmit Corrections for Initial Review' : 'Submit to LPDD-FUS Initial Review')
        . '</button></form></div></div><div class="col-lg-4">'
        . '<div class="panel soft"><h3 class="h6">Annex A Step 2 Routing</h3>'
        . '<ol class="small"><li>Applicant uploads application documents.</li>'
        . '<li>LPDD-FUS Technical Staff reviews completeness.</li>'
        . '<li>Incomplete → applicant corrects and resubmits.</li>'
        . '<li>Complete → forward to RO Receiving/Releasing.</li></ol>'
        . '<p class="small text-secondary mb-0">Step 4 formal documentary evaluation and '
        . 'the 15-day processing tracker are not part of this release.</p></div>';
    if ($docs) $html .= '<div class="panel mt-3"><h3 class="h6">Previously Uploaded Documents</h3>'
        . step2_documents_html($docs) . '</div>';
    $html .= '</div></div>' . step2_pdf_organizer_modal_html();
    $html .= '<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js" defer></script>'
        . '<script src="assets/permit-pdf-organizer.js?v=1" defer></script>'
        . '<script src="assets/permit-step2-drafts.js?v=4" defer></script>';
    render_page($editing ? 'Resubmit FP Application' : 'New FP Application', $html);
}

function view_step2_application(int $applicationId, array $errors = []): void
{
    $viewer = require_user();
    $app = step2_application_for_view($viewer,$applicationId);
    $mine = $viewer['user_kind'] === 'applicant';
    $reviewer = !$mine && has_role($viewer,'lpdd_fus');
    $back = $mine ? 'permit_applications'
        : ($reviewer ? 'permit_initial_queue' : 'permit_ro_inbox');
    $html = '<div class="mb-3"><a href="' . url($back) . '">← Back to list</a></div>'
        . '<div class="row g-3"><div class="col-lg-8"><div class="panel">'
        . errors_html($errors)
        . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">'
        . '<h2 class="h5">FP Application #' . $applicationId . '</h2>'
        . step2_status_badge((string)$app['status']) . '</div>'
        . '<dl class="row details mt-3">'
        . '<dt class="col-sm-4">Applicant</dt><dd class="col-sm-8">'
        . e((string)$app['applicant_name']) . '</dd>'
        . '<dt class="col-sm-4">Project</dt><dd class="col-sm-8">'
        . e((string)$app['project_title']) . '</dd>'
        . '<dt class="col-sm-4">Location</dt><dd class="col-sm-8">'
        . e((string)$app['project_location']) . '</dd>'
        . '<dt class="col-sm-4">Description</dt><dd class="col-sm-8">'
        . nl2br(e((string)($app['purpose'] ?? ''))) . '</dd>'
        . '<dt class="col-sm-4">Revision</dt><dd class="col-sm-8">'
        . (int)$app['revision'] . '</dd>'
        . '<dt class="col-sm-4">Last submitted</dt><dd class="col-sm-8">'
        . e((string)$app['last_submitted_at']) . '</dd></dl>'
        . '<h3 class="h6">Submitted Supporting Documents</h3>'
        . step2_documents_html(step2_documents($applicationId))
        . step2_history_html($applicationId)
        . '</div></div><div class="col-lg-4">';
    if ($mine) {
        $html .= '<div class="panel soft"><h3 class="h6">Next Action</h3>'
            . ($app['status'] === 'corrections_required'
                ? '<p>LPDD-FUS requested corrections. Review the remarks in the history, '
                    . 'then edit and resubmit your FP documents.</p>'
                    . '<a class="btn btn-primary" href="'
                    . e(url('permit_apply',['id'=>$applicationId])) . '">Correct / Resubmit</a>'
                : '<p class="mb-0">'
                    . ($app['status'] === 'forwarded_to_ro'
                        ? 'Your application passed LPDD-FUS initial review and was forwarded to RO Receiving.'
                        : 'Your application is awaiting the LPDD-FUS Technical Staff initial review.')
                    . '</p>')
            . '</div>';
    } elseif ($reviewer && $app['status'] === 'submitted') {
        $html .= '<div class="panel soft"><h3 class="h6">LPDD-FUS Technical Staff – Initial Review</h3>'
            . '<p class="small">Check initial documentary completeness. '
            . 'This is <strong>not</strong> Step 4 formal documentary evaluation.</p>'
            . '<form method="post" action="' . e(url('permit_initial_decision',['id'=>$applicationId])) . '" '
            . 'data-decision-confirm-form data-confirm-kind="step2" '
            . 'data-confirm-reference="FP Application #' . (int)$applicationId . '" '
            . 'data-confirm-applicant="' . e((string)$app['applicant_name']) . '">'
            . csrf_field()
            . '<input type="hidden" name="decision" value="" data-confirm-decision-value>'
            . '<label class="form-label" for="step2_remarks">Deficiencies / Review Notes</label>'
            . '<textarea class="form-control mb-2" id="step2_remarks" name="remarks" rows="6" '
            . 'maxlength="3000" placeholder="Required when returning to applicant">'
            . e((string)($_POST['remarks'] ?? '')) . '</textarea>'
            . '<div class="alert alert-warning small d-none mb-3" role="alert" '
            . 'data-decision-confirm-validation></div>'
            . '<div class="d-grid gap-2">'
            . '<button class="btn btn-success" type="button" data-confirm-decision="forward">'
            . 'Complete – Forward to RO Receiving</button>'
            . '<button class="btn btn-outline-danger" type="button" data-confirm-decision="return">'
            . 'Incomplete – Return for Correction</button></div>'
            . '<noscript><p class="alert alert-warning small mt-3 mb-0">'
            . 'Enable JavaScript to confirm and submit a review decision.</p></noscript>'
            . '</form></div>';
    } else {
        $html .= '<div class="panel soft"><h3 class="h6">Routing Status</h3>'
            . '<p class="small mb-0">This application is '
            . e(strtolower(step2_status_label((string)$app['status'])))
            . '. RO Receiving may view forwarded documents; receiving actions are a separate Step 3 build.</p>'
            . '</div>';
    }
    $html .= '</div></div>'
        . ($reviewer && $app['status'] === 'submitted' ? decision_confirmation_modal_html() : '');
    render_page('FP Application Review – Step 2', $html);
}

function view_step2_review_queue(): void
{
    step2_require_reviewer();
    $apps = query_all(
        "SELECT a.id,a.project_title,a.revision,a.last_submitted_at,c.name AS applicant_name
         FROM permit_step2_applications a JOIN user_client c ON c.id=a.client_id
         WHERE a.status='submitted' ORDER BY a.last_submitted_at ASC,a.id ASC LIMIT 200"
    );
    $html = '<div class="panel"><h2 class="h5">LPDD-FUS Initial FP Review Queue '
        . '<span class="badge text-bg-primary">' . count($apps) . '</span></h2>'
        . '<p class="text-secondary small">Annex A Step 2: review initial documentary completeness '
        . 'before forwarding to RO Receiving. Return incomplete submissions for correction.</p>';
    if (!$apps) $html .= '<div class="alert alert-success">No FP applications awaiting initial review.</div>';
    else {
        $html .= '<div class="table-responsive"><table class="table table-hover align-middle">'
            . '<thead><tr><th>Application</th><th>Applicant</th><th>Revision</th><th>Submitted</th><th></th></tr></thead><tbody>';
        foreach ($apps as $app) {
            $html .= '<tr><td><strong>#' . (int)$app['id'] . '</strong><div class="small">'
                . e((string)$app['project_title']) . '</div></td><td>'
                . e((string)$app['applicant_name']) . '</td><td>' . (int)$app['revision']
                . '</td><td>' . e((string)$app['last_submitted_at']) . '</td><td>'
                . '<a class="btn btn-sm btn-outline-primary" href="'
                . e(url('permit_application',['id'=>(int)$app['id']]))
                . '">Initial Review</a></td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    render_page('LPDD-FUS Initial Review Queue', $html . '</div>');
}

function view_step2_ro_inbox(): void
{
    step2_require_receiving();
    $apps = query_all(
        "SELECT a.id,a.project_title,a.forwarded_at,c.name AS applicant_name
         FROM permit_step2_applications a JOIN user_client c ON c.id=a.client_id
         WHERE a.status='forwarded_to_ro' ORDER BY a.forwarded_at DESC,a.id DESC LIMIT 200"
    );
    $html = '<div class="panel"><h2 class="h5">RO Receiving Inbox – Forwarded FP Applications</h2>'
        . '<p class="small text-secondary">Read-only Step 2 handoff. Formal RO document receiving, '
        . 'acknowledgment and onward forwarding will be implemented in Step 3.</p>';
    if (!$apps) $html .= '<p class="text-secondary">No applications forwarded from LPDD-FUS yet.</p>';
    else {
        $html .= '<div class="table-responsive"><table class="table table-hover align-middle">'
            . '<thead><tr><th>No.</th><th>Applicant</th><th>Project</th><th>Forwarded</th><th></th></tr></thead><tbody>';
        foreach ($apps as $app) {
            $html .= '<tr><td>#' . (int)$app['id'] . '</td><td>'
                . e((string)$app['applicant_name']) . '</td><td>'
                . e((string)$app['project_title']) . '</td><td>'
                . e((string)$app['forwarded_at']) . '</td><td>'
                . '<a class="btn btn-sm btn-outline-primary" href="'
                . e(url('permit_application',['id'=>(int)$app['id']])) . '">View Documents</a>'
                . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    render_page('RO Receiving Inbox', $html . '</div>');
}
