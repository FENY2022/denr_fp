<?php
declare(strict_types=1);

/** Annex A Step 2 screens. Existing DENR shell, Bootstrap and document modal. */

function step2_status_label(string $status): string
{
    return match ($status) {
        'submitted' => 'Pending LPDD-FUS Initial Review',
        'corrections_required' => 'Corrections Required',
        'forwarded_to_ro' => 'Forwarded to RO Receiving',
        'reverted' => 'Reverted – Editable',
        default => 'Unknown Status'
    };
}

function step2_status_badge(string $status): string
{
    $tone = match ($status) {
        'submitted' => 'info',
        'corrections_required' => 'warning',
        'forwarded_to_ro' => 'success',
        'reverted' => 'secondary',
        default => 'secondary'
    };
    return '<span class="badge text-bg-' . $tone . '">' . e(step2_status_label($status)) . '</span>';
}

function step2_form_section_heading_html(int $number, string $title, string $icon): string
{
    return '<div class="step2-form-section-heading" data-step2-form-heading="'
        . $number . '"><span class="step2-form-section-number" aria-hidden="true">'
        . sprintf('%02d',$number) . '</span><h3 class="h5 m-0" id="step2-section-title-'
        . $number . '">'
        . '<i class="bi bi-' . e($icon) . ' me-1" aria-hidden="true"></i>'
        . e($title) . '</h3></div>';
}

function step2_form_section_navigation_html(): string
{
    $sections = [
        1=>['Application Information','clipboard-data'],
        2=>['Project Location','geo-alt'],
        3=>['Supporting Documents','files'],
        4=>['Review and Submit','check-circle'],
    ];
    $html = '<nav class="step2-section-nav" aria-label="FP application form sections">'
        . '<p class="step2-section-nav-label">Application sections</p><ol>';
    foreach ($sections as $number => [$label,$icon]) {
        $html .= '<li><a href="#step2-section-' . $number . '">'
            . '<span class="step2-section-nav-number">' . sprintf('%02d',$number) . '</span>'
            . '<span><i class="bi bi-' . e($icon) . '" aria-hidden="true"></i>'
            . e($label) . '</span></a></li>';
    }
    return $html . '</ol></nav>';
}

function step2_documents_html(array $docs): string
{
    if (!$docs) return '<p class="small text-secondary">No application documents uploaded yet.</p>';
    $groups = array_fill_keys(array_keys(step2_requirement_types()), []);
    foreach ($docs as $doc) {
        $type = (string)($doc['requirement_type'] ?? 'other');
        if (!isset($groups[$type])) $type = 'other';
        $groups[$type][] = $doc;
    }
    $html = '<div class="list-group mb-3 step2-requirements-list" data-document-list>';
    foreach (step2_requirement_types() as $type => $label) {
        $items = $groups[$type];
        $html .= step2_document_requirement_group_html($type,$label,$items);
    }
    if (!empty($groups['other'])) {
        $html .= step2_document_requirement_group_html(
            'other','Other / Earlier Supporting Documents',$groups['other']
        );
    }
    return $html . '</div>'
        . '<div class="modal fade" id="registration-document-modal" tabindex="-1" '
        . 'aria-labelledby="registration-document-modal-title" aria-hidden="true">'
        . '<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">'
        . '<div class="modal-content"><div class="modal-header">'
        . '<div><h2 class="modal-title h5" id="registration-document-modal-title">FP Document Preview</h2>'
        . '<div class="small text-secondary mt-1" data-document-filename hidden></div></div>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
        . '</div><div class="modal-body text-center">'
        . '<div id="registration-document-preview" class="overflow-auto"></div>'
        . '<p id="registration-document-error" class="text-danger d-none">Preview unavailable. Use Download instead.</p>'
        . '</div><div class="modal-footer">'
        . '<div class="d-flex align-items-center gap-2 flex-wrap me-auto" '
        . 'role="group" aria-label="Application document navigation">'
        . '<button type="button" class="btn btn-outline-primary" '
        . 'data-document-previous disabled>'
        . '<i class="bi bi-chevron-left me-1" aria-hidden="true"></i>Previous</button>'
        . '<span class="badge text-bg-light border px-3 py-2" '
        . 'data-document-counter aria-live="polite" aria-atomic="true">0/0</span>'
        . '<button type="button" class="btn btn-outline-primary" '
        . 'data-document-next disabled>'
        . 'Next<i class="bi bi-chevron-right ms-1" aria-hidden="true"></i></button>'
        . '</div>'
        . '<button type="button" class="btn btn-outline-secondary" data-document-zoom="out">Zoom Out</button>'
        . '<button type="button" class="btn btn-outline-secondary" data-document-zoom="reset">Fit to Screen</button>'
        . '<button type="button" class="btn btn-outline-secondary" data-document-zoom="in">Zoom In</button>'
        . '<a class="btn btn-primary" id="registration-document-download" href="#" download>Download</a>'
        . '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>'
        . '</div></div></div></div>';
}

function step2_document_requirement_group_html(string $type, string $label, array $docs): string
{
    $count = count($docs);
    $html = '<section class="list-group-item step2-document-requirement-group">'
        . '<div class="step2-requirement-review-item">'
        . '<span class="step2-requirement-review-label"><i class="bi bi-'
        . e(step2_requirement_icon($type)) . '" aria-hidden="true"></i><span>'
        . e($label) . '</span></span>'
        . '<span class="badge step2-requirement-review-status '
        . ($count ? 'text-bg-success' : 'text-bg-secondary') . '"><i class="bi bi-'
        . ($count ? 'check-circle-fill' : 'dash-circle') . '" aria-hidden="true"></i>'
        . ($count ? 'Uploaded: '.$count : 'Not uploaded') . '</span></div>';
    if (!$docs) return $html . '</section>';

    $html .= '<div class="step2-requirement-uploaded-files">';
    foreach ($docs as $doc) {
        $name = (string)$doc['original_name'];
        $mime = (string)$doc['mime_type'];
        $url = url('permit_document',['id'=>(int)$doc['id'],'preview'=>1]);
        $fileIcon = $mime === 'application/pdf' ? 'file-earmark-pdf' : 'file-earmark-image';
        $html .= '<a class="step2-requirement-file" data-document-preview '
            . 'data-document-mime="' . e($mime) . '" data-document-name="' . e($name) . '" '
            . 'data-document-category="' . e($label) . '" '
            . 'href="' . e($url) . '"><i class="bi bi-' . $fileIcon . '" aria-hidden="true"></i>'
            . '<span><strong>' . e($name) . '</strong><small>Revision ' . (int)$doc['revision']
            . ' · ' . e(round(((int)$doc['file_size']) / 1024, 1) . ' KB') . '</small></span>'
            . '<i class="bi bi-eye ms-auto" aria-hidden="true"></i></a>';
    }
    return $html . '</div></section>';
}

function step2_history_html(int $applicationId): string
{
    return permit_tracking_timeline_html($applicationId);
}

function view_step2_applications(): void
{
    $client = step2_require_applicant();
    $receiptField = step2_revert_schema_ready() ? 'received_at' : 'NULL AS received_at';
    $apps = query_all(
        'SELECT id,project_title,project_location,status,revision,' . $receiptField . ',last_submitted_at
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
            . '<thead><tr><th>No.</th><th>Project</th><th>Submitted</th><th>Revision</th><th>Status</th><th>Current Office</th><th>Next to Act</th><th></th></tr></thead><tbody>';
        foreach ($apps as $app) {
            $routing = permit_tracking_state($app);
            $html .= '<tr><td>#' . (int)$app['id'] . '</td><td><strong>'
                . e((string)$app['project_title']) . '</strong><div class="text-secondary small">'
                . e((string)$app['project_location']) . '</div></td><td>'
                . e(review_queue_display_date($app['last_submitted_at'])) . '</td><td>' . (int)$app['revision']
                . '</td><td>' . step2_status_badge((string)$app['status']) . '</td>'
                . '<td>' . e($routing['office']) . '</td><td>' . e($routing['next_role']) . '</td>'
                . '<td><a class="btn btn-sm btn-outline-primary" href="'
                 . e(url('permit_application',['id'=>(int)$app['id']])) . '">Track / View Files</a></td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    render_page('My FP Applications', $html . '</div>');
}

/**
 * Browser-side PDF organizer; no file is sent until the user confirms and
 * the existing secure upload flow receives the resulting File.
 */
/** Applicant's choice before rendering a potentially lengthy PDF organizer. */
function step2_pdf_choice_modal_html(): string
{
    return '<div class="modal fade" id="fp-pdf-upload-choice-modal" tabindex="-1" '
        . 'aria-labelledby="fp-pdf-upload-choice-title" aria-hidden="true">'
        . '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header">'
        . '<h2 class="modal-title h5" id="fp-pdf-upload-choice-title">'
        . '<i class="bi bi-file-earmark-pdf text-danger me-2" aria-hidden="true"></i>'
        . 'Would You Like to Organize Your PDF Pages?</h2>'
        . '<button type="button" class="btn-close" data-pdf-choice-cancel '
        . 'aria-label="Cancel PDF upload"></button></div>'
        . '<div class="modal-body">'
        . '<p class="small text-secondary text-break mb-2" data-pdf-choice-filename></p>'
        . '<p>If you need to rearrange, duplicate, remove, or adjust the order of '
        . 'pages in your PDF document, click <strong>Yes, Organize Pages</strong> '
        . 'to make those changes using the PDF Page Organizer.</p>'
        . '<p>If your document is already complete and no changes are necessary, '
        . 'click <strong>No</strong> to continue with the original PDF.</p>'
        . '<p class="alert alert-warning small py-2 mb-2">'
        . '<i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>'
        . 'Changing pages in a digitally signed PDF may invalidate its signatures. '
        . 'Keep the signed original if you need to preserve it.</p>'
        . '<p class="small text-secondary mb-0" data-pdf-choice-mode-note></p>'
        . '</div>'
        . '<div class="modal-footer d-flex flex-wrap gap-2">'
        . '<button type="button" class="btn btn-outline-secondary" data-pdf-choice-cancel>'
        . 'Cancel</button>'
        . '<button type="button" class="btn btn-secondary" data-pdf-choice-no>'
        . 'No, Upload Original PDF</button>'
        . '<button type="button" class="btn btn-primary" data-pdf-choice-yes>'
        . '<i class="bi bi-files me-1" aria-hidden="true"></i>'
        . 'Yes, Organize Pages</button>'
        . '</div></div></div></div>';
}

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


/** Clear confirmation is explicit; the server also verifies ownership and CSRF. */
function step2_clear_form_modal_html(): string
{
    return '<div class="modal fade" id="step2-clear-modal" tabindex="-1" '
        . 'aria-labelledby="step2-clear-title" aria-hidden="true">'
        . '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h2 class="modal-title h5" id="step2-clear-title">'
        . 'Clear FP Draft?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" '
        . 'aria-label="Close"></button></div>'
        . '<div class="modal-body"><p class="mb-2">This will permanently remove '
        . 'the saved fields and all unsubmitted supporting documents for this form.</p>'
        . '<p class="small text-secondary mb-0">Previously submitted application files '
        . 'and review history will not be changed.</p></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-secondary" '
        . 'data-bs-dismiss="modal">Keep Draft</button>'
        . '<button type="button" class="btn btn-danger" data-step2-confirm-clear>'
        . 'Yes, Clear Form</button></div></div></div></div>';
}

/**
 * Applicant map pin + optional decimal coordinate inputs.
 * Address remains required independently of the GPS pin.
 */
function step2_location_editor_html(string $latitude, string $longitude): string
{
    return '<section class="mb-3 fp-location-fields" data-fp-location-wrapper>'
        . '<h3 class="h6 mb-2"><i class="bi bi-geo-alt-fill text-success me-1" '
        . 'aria-hidden="true"></i>Pinpoint Project Location (optional)</h3>'
        . '<p class="small text-secondary">Click or drag the map pin to identify the project site. '
        . 'You may enter WGS84 decimal coordinates manually. The address above is still required.</p>'
        . '<div class="fp-project-location-map mb-2" data-fp-location-map="edit" '
        . 'data-latitude="'.e($latitude).'" data-longitude="'.e($longitude).'" '
        . 'aria-label="Interactive project location map"></div>'
        . '<div class="row g-2 mb-2">'
        . '<div class="col-sm-6"><label class="form-label" for="project_latitude">'
        . 'Latitude</label><input class="form-control" id="project_latitude" '
        . 'name="project_latitude" type="text" inputmode="decimal" '
        . 'autocomplete="off" maxlength="32" placeholder="e.g. 8.9471230" '
        . 'value="'.e($latitude).'"></div>'
        . '<div class="col-sm-6"><label class="form-label" for="project_longitude">'
        . 'Longitude</label><input class="form-control" id="project_longitude" '
        . 'name="project_longitude" type="text" inputmode="decimal" '
        . 'autocomplete="off" maxlength="32" placeholder="e.g. 125.5423450" '
        . 'value="'.e($longitude).'"></div></div>'
        . '<div class="d-flex flex-wrap gap-2 mb-2">'
        . '<button type="button" class="btn btn-outline-primary btn-sm" '
        . 'data-fp-location-gps><i class="bi bi-crosshair me-1" '
        . 'aria-hidden="true"></i>Use My Current Location</button>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm" '
        . 'data-fp-location-clear><i class="bi bi-x-circle me-1" '
        . 'aria-hidden="true"></i>Clear Pin</button></div>'
        . '<p class="fp-location-help text-secondary small" role="status" '
        . 'data-fp-location-status>Coordinates are optional. A pin is never selected '
        . 'automatically; GPS location requires your permission.</p>'
        . '<p class="fp-location-attribution text-secondary small mb-0">'
        . 'Map data © OpenStreetMap contributors. Verify that the pin identifies '
        . 'the actual project site.</p></section>';
}

/** Show captured coordinates and a read-only map to the permitted viewer. */
function step2_location_preview_html(array $application): string
{
    $lat = $application['project_latitude'] ?? null;
    $lon = $application['project_longitude'] ?? null;
    if ($lat === null || $lon === null || $lat === '' || $lon === '') return '';
    $latText = e((string)$lat);
    $lonText = e((string)$lon);
    return '<section class="panel mt-3 fp-location-readonly" data-fp-location-wrapper>'
        . '<h3 class="h6 mb-2"><i class="bi bi-geo-alt-fill text-success me-1" '
        . 'aria-hidden="true"></i>Project Map Pin (WGS84)</h3>'
        . '<p class="small mb-2">Latitude: <strong>'.$latText.'</strong> · '
        . 'Longitude: <strong>'.$lonText.'</strong></p>'
        . '<div class="fp-project-location-map" data-fp-location-map="readonly" '
        . 'data-latitude="'.$latText.'" data-longitude="'.$lonText.'" '
        . 'aria-label="Read-only project location pin"></div>'
        . '<p class="small text-secondary mb-0 mt-2">The pin is supplied by the applicant '
        . 'and should be verified against the declared project address.</p></section>';
}

function view_step2_form(int $applicationId = 0, array $errors = []): void
{
    $client = step2_require_applicant();
    $app = null;
    $docs = [];
    if ($applicationId > 0) {
        $app = step2_application_for_view($client,$applicationId);
        if (!in_array($app['status'],['corrections_required','reverted'],true)) {
            show_error('This FP application is not available for correction.');
        }
        $docs = step2_documents($applicationId);
    }
    $editing = $app !== null;
    $persistent = step2_persistent_ready();
    $fieldDraft = $persistent ? step2_persistent_form((int)$client['id'],$applicationId) : null;
    $title = (string)($_POST['project_title'] ?? $fieldDraft['project_title'] ?? $app['project_title'] ?? '');
    $location = (string)($_POST['project_location'] ?? $fieldDraft['project_location'] ?? $app['project_location'] ?? '');
    $hasCoordinates = step2_location_schema_ready();
    $latitude = $hasCoordinates
        ? (string)($_POST['project_latitude'] ?? $fieldDraft['project_latitude'] ?? $app['project_latitude'] ?? '')
        : '';
    $longitude = $hasCoordinates
        ? (string)($_POST['project_longitude'] ?? $fieldDraft['project_longitude'] ?? $app['project_longitude'] ?? '')
        : '';
    $purpose = (string)($_POST['purpose'] ?? $fieldDraft['purpose'] ?? $app['purpose'] ?? '');
    $maxMb = round(max(1,(int)env_value('MAX_UPLOAD_BYTES','5242880'))/1048576,1);
    $formUrl = url('permit_apply',$editing ? ['id'=>$applicationId] : []);
    $draftMode = step2_drafts_schema_ready();
    $savedDocs = $draftMode ? step2_draft_public_list((int)$client['id'],$applicationId) : [];
    $html = '<div class="mb-3"><a href="' . url('permit_applications')
        . '">← My FP Applications</a></div><div class="row g-3">'
        . '<div class="col-lg-8"><div class="panel">'
        . '<header class="step2-form-intro"><span class="step2-form-kicker">Annex A · Step 2</span>'
        . '<h2 class="h4 mb-2">' . ($editing ? 'Edit and Resubmit FP Application #'
            . $applicationId : 'Submit FP Application') . '</h2>'
        . errors_html($errors)
        . '<p class="small text-secondary">Provide basic project identification and upload supporting documents. '
        . 'Documentary completeness will be evaluated by LPDD-FUS Technical Staff, '
        . 'using the official checklist approved by DENR.</p></header>'
        . step2_form_section_navigation_html();
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
    if (!step2_category_schema_ready()) {
        $html .= '<div class="alert alert-warning"><strong>Database migration 019 '
            . 'is required.</strong> Ask the ICT administrator to install it before '
            . 'uploading or submitting classified FP documents.</div>';
    }
    $html .= '<form id="permit-step2-form" method="post" action="' . e($formUrl)
         . '" enctype="multipart/form-data" data-draft-key="fpoms.permit.'
         . (int)$client['id'] . '.' . $applicationId . '.v1">'
         . csrf_field()
        . '<section class="step2-form-section" id="step2-section-1" '
        . 'aria-labelledby="step2-section-title-1" data-step2-form-section="1">'
        . step2_form_section_heading_html(1,'Application / Project Information','clipboard-data')
         . '<div class="mb-3"><label class="form-label" for="project_title">Application / Project Title '
         . '<span class="text-danger">*</span></label>'
         . '<input class="form-control" id="project_title" name="project_title" required minlength="5" '
         . 'maxlength="180" value="' . e($title) . '"></div>'
        . '<div class="mb-3"><label class="form-label" for="purpose">Project Description (optional)</label>'
        . '<textarea class="form-control" id="purpose" name="purpose" rows="4" '
        . 'maxlength="2000">' . e($purpose) . '</textarea></div></section>'
        . '<section class="step2-form-section" id="step2-section-2" '
        . 'aria-labelledby="step2-section-title-2" data-step2-form-section="2">'
        . step2_form_section_heading_html(2,'Project Location','geo-alt')
         . '<div class="mb-3"><label class="form-label" for="project_location">Project Location / Complete Address '
         . '<span class="text-danger">*</span></label>'
         . '<input class="form-control" id="project_location" name="project_location" '
         . 'required minlength="5" maxlength="300" value="' . e($location) . '"></div>'
         . ($hasCoordinates ? step2_location_editor_html($latitude,$longitude)
             : '<p class="small text-secondary">Interactive location pin and coordinates '
                . 'will be available after the ICT administrator applies migration 017.</p>')
        . '</section><section class="step2-form-section" id="step2-section-3" '
        . 'aria-labelledby="step2-section-title-3" data-step2-form-section="3">'
        . step2_form_section_heading_html(3,'FP Supporting Documentary Requirements','files')
         . '<div class="mb-3"><label class="form-label" for="application_files">FP Supporting Documents '
        . ($editing ? '(optional additional files)' : '<span class="text-danger">*</span>') . '</label>'
        . '<div class="border rounded-3 p-3 mb-3" data-fp-requirement-guide>'
        . '<h3 class="h6 text-success">FP Supporting Documentary Requirements</h3>'
        . '<p class="small text-secondary">Select a requirement and upload the matching '
        . 'PDF/JPG/PNG. Multiple files may be uploaded per requirement, subject '
        . 'to the existing 10-file total. The * and ** markers are preserved '
        . 'as supplied; LPDD-FUS determines applicability.</p>'
        . '<div class="row g-2">';
    foreach (step2_requirement_types() as $type => $label) {
        $html .= '<div class="col-md-6"><div class="border rounded-3 p-3 h-100" '
            . 'data-step2-requirement-card="' . e($type) . '">'
            . '<div class="small fw-semibold mb-2">' . e($label) . '</div>'
             . '<button class="btn btn-sm btn-outline-success" type="button" '
             . 'data-step2-pick-requirement="' . e($type) . '">'
             . '<i class="bi bi-upload me-1" aria-hidden="true"></i>'
             . 'Upload for This Requirement</button>'
             . '<ul class="step2-requirement-files" data-step2-requirement-files="'
             . e($type) . '">'
             . '<li class="step2-requirement-files-empty" data-step2-requirement-empty>'
             . 'No file uploaded for this requirement yet.</li></ul></div></div>';
    }
    $html .= '</div></div>'
        . '<div id="step2-draft-uploader" data-step2-mode="'
        . ($draftMode ? 'saved' : 'direct') . '" data-persistent="'
        . ($persistent ? '1' : '0') . '" data-application-id="'
        . $applicationId . '" data-max-bytes="'
        . max(1,(int)env_value('MAX_UPLOAD_BYTES','5242880')) . '">'

        . '<label for="step2_requirement_type" class="form-label">'
        . 'Document Category <span class="text-danger">*</span></label>'
        . '<select class="form-select mb-2" id="step2_requirement_type" '
        . 'name="document_category" data-step2-category-select>'
        . '<option value="">Choose the matching requirement before selecting files</option>';
    foreach (step2_requirement_types() as $type => $label) {
        $html .= '<option value="' . e($type) . '">' . e($label) . '</option>';
    }
    $html .= '</select>'
        . '<div data-step2-direct-categories></div>'
        . '<input class="form-control" type="file" id="application_files" '
        . ($draftMode ? '' : 'name="application_files[]" ')
        . 'accept=".pdf,.jpg,.jpeg,.png" multiple '
        . (!$editing && !$draftMode ? 'required ' : '') . '>'
        . '<div class="form-text">Up to 10 files per submission. PDF/JPG/PNG, maximum '
        . e((string)$maxMb) . ' MB each. Documents are saved privately outside the website. '
        . 'Each saved file retains its selected category for LPDD-FUS review.</div>'
        . ($draftMode ? step2_draft_list_html($savedDocs,$persistent)
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
        . '</div></div></section><section class="step2-form-section step2-form-section-submit" '
        . 'id="step2-section-4" aria-labelledby="step2-section-title-4" data-step2-form-section="4">'
        . step2_form_section_heading_html(4,'Review and Submit','check-circle')
        . '<p class="small text-secondary">Review the project details, location, and uploaded '
        . 'documents above before sending the application to LPDD-FUS Initial Review.</p>'
         . '<p class="small text-secondary" data-step2-autosave-status role="status">'
        . ($persistent ? 'Draft saved securely to your account when you edit fields.'
            : 'Persistent draft autosave requires migration 016.') . '</p>'
        . ($persistent
            ? '<button type="button" class="btn btn-outline-danger me-2 mb-2" '
                . 'data-bs-toggle="modal" data-bs-target="#step2-clear-modal">'
                . '<i class="bi bi-trash3 me-1" aria-hidden="true"></i>Clear Form</button>'
            : '<div class="alert alert-warning small">Database migration 016 is '
                . 'required to keep fields and uploaded files across logins until submission.</div>')
         . '<button class="btn btn-primary mb-2" type="submit">'
         . ($editing ? 'Resubmit Corrections for Initial Review' : 'Submit to LPDD-FUS Initial Review')
        . '</button></section></form></div></div><div class="col-lg-4">'
        . '<div class="panel soft"><h3 class="h6">Annex A Step 2 Routing</h3>'
        . '<ol class="small"><li>Applicant uploads application documents.</li>'
        . '<li>LPDD-FUS Technical Staff reviews completeness.</li>'
        . '<li>Incomplete → applicant corrects and resubmits.</li>'
        . '<li>Complete → forward to RO Receiving/Releasing.</li></ol>'
        . '<p class="small text-secondary mb-0">Step 4 formal documentary evaluation and '
        . 'the 15-day processing tracker are not part of this release.</p></div>';
    if ($docs) $html .= '<div class="panel mt-3"><h3 class="h6">Previously Uploaded Documents</h3>'
        . step2_documents_html($docs) . '</div>';
    $html .= '</div></div>' . step2_pdf_choice_modal_html()
        . step2_pdf_organizer_modal_html();
    if ($persistent) $html .= step2_clear_form_modal_html();
    $html .= '<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js" defer></script>'
        . '<script src="assets/permit-pdf-organizer.js?v=1" defer></script>'
        . '<script src="assets/permit-pdf-choice.js?v=1" defer></script>'
        . '<script src="assets/permit-step2-drafts.js?v=10" defer></script>'
        . ($hasCoordinates
            ? '<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" defer></script>'
                . '<script src="assets/permit-location-map.js?v=2" defer></script>'
            : '')
        . '<script src="assets/permit-step2-sections.js?v=1" defer></script>';
    render_page($editing ? 'Resubmit FP Application' : 'New FP Application', $html);
}

function view_step2_application(int $applicationId, array $errors = []): void
{
    $viewer = require_user();
    $app = step2_application_for_view($viewer,$applicationId);
    $documents = step2_documents($applicationId);
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
        . permit_tracking_summary_html($app)
        . '<h3 class="h6">Submitted Supporting Documents</h3>'
        . step2_documents_html($documents)
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
                        : ($app['status'] === 'reverted'
                            ? 'Your application is reverted; you can edit and resubmit it.'
                            : 'Your application is awaiting the LPDD-FUS Technical Staff initial review.'))
                    . '</p>')
            . '</div>'
            . step2_revert_client_html($app);
    } elseif ($reviewer && $app['status'] === 'submitted') {
        $html .= '<div class="panel soft"><h3 class="h6">LPDD-FUS Technical Staff – Initial Review</h3>'
            . step2_receive_documents_html($app)
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
    if (step2_location_schema_ready()) {
        $html .= step2_location_preview_html($app);
        if (($app['project_latitude'] ?? null) !== null
            && ($app['project_longitude'] ?? null) !== null) {
            $html .= '<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" defer></script>'
                . '<script src="assets/permit-location-map.js?v=1" defer></script>';
        }
    }
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
