<?php
declare(strict_types=1);

/** Annex A Step 2 screens. Existing DENR shell, Bootstrap and document modal. */

function step2_status_label(string $status): string
{
    return match ($status) {
        'submitted' => 'Pending LPDD-FUS Initial Review',
        'corrections_required' => 'Corrections Required',
        'forwarded_to_ro' => 'Forwarded to RO Receiving',
        'ro_received' => 'Received by RO Receiving',
        'forwarded_to_lpdd' => 'Step 4 – Incoming to LPDD-FUS',
        'step4_under_evaluation' => 'Step 4 – Under Documentary Evaluation',
        'step4_corrections_required' => 'Step 4 – Compliance Required',
        'step4_resubmitted' => 'Step 4 – Resubmitted for Re-evaluation',
        'step4_complete' => 'Step 4 – Complete & Sufficient',
        'reverted' => 'Reverted – Editable',
        default => 'Unknown Status'
    };
}

function step2_status_badge(string $status): string
{
    $tone = match ($status) {
        'submitted' => 'info',
        'corrections_required' => 'warning',
        'forwarded_to_ro' => 'info',
        'ro_received' => 'warning',
        'forwarded_to_lpdd' => 'info',
        'step4_under_evaluation' => 'warning',
        'step4_corrections_required' => 'danger',
        'step4_resubmitted' => 'info',
        'step4_complete' => 'success',
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

function step2_document_list_html(array $docs): string
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
            'other','Other Attached Documents',$groups['other']
        );
    }
    return $html . '</div>';
}

function step2_document_preview_modal_html(): string
{
    return '<div class="modal fade" id="registration-document-modal" tabindex="-1" '
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

function step2_documents_html(array $docs): string
{
    return step2_document_list_html($docs)
        . ($docs ? step2_document_preview_modal_html() : '');
}

/** Dedicated read-only file-list modal; the existing preview is a sibling modal. */
function step2_files_modal_html(array $docs): string
{
    return '<div class="modal fade" id="fp-files-modal" tabindex="-1" '
        . 'aria-labelledby="fp-files-modal-title" aria-hidden="true">'
        . '<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">'
        . '<div class="modal-content"><div class="modal-header">'
        . '<h2 class="modal-title h5" id="fp-files-modal-title">'
        . '<i class="bi bi-folder2-open me-2" aria-hidden="true"></i>Submitted Supporting Documents</h2>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
        . '</div><div class="modal-body">'
        . '<p class="small text-secondary">Choose a document to preview it. '
        . 'Earlier submitted revisions remain available to authorized viewers.</p>'
        . step2_document_list_html($docs)
        . '</div><div class="modal-footer">'
        . '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>'
        . '</div></div></div></div>'
        . ($docs ? step2_document_preview_modal_html() : '');
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
            . 'data-document-id="' . (int)$doc['id'] . '" '
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
        'SELECT id,project_title,project_location,status,flow_version,revision,' . $receiptField . ',last_submitted_at
         FROM permit_step2_applications WHERE client_id=?
         ORDER BY created_at DESC,id DESC LIMIT 100', [(int)$client['id']]
    );
    // Fetch payment status in one query instead of reading one record per application.
    $paymentMap=[];
    $paymentAppIds=array_values(array_map(
        static fn(array $row): int => (int)$row['id'],
        array_filter($apps,static fn(array $row): bool => (string)$row['status']==='step4_complete')
    ));
    if ($paymentAppIds && permit_payment_schema_ready()) {
        $marks=implode(',',array_fill(0,count($paymentAppIds),'?'));
        foreach (query_all(
            'SELECT application_id,status FROM permit_payment_verifications '
                .'WHERE application_id IN ('.$marks.')',$paymentAppIds
        ) as $paymentRow) {
            $paymentMap[(int)$paymentRow['application_id']]=(string)$paymentRow['status'];
        }
    }
    $html = '<div class="panel"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3">'
        . '<div><h2 class="h5 mb-1">My FP Applications</h2>'
        . '<p class="small text-secondary mb-0">Track documentary reviews, payment instructions and verified receipts.</p></div>'
        . '<a class="btn btn-primary" href="' . url('permit_apply') . '">'
        . '<i class="bi bi-plus-circle me-1"></i>New FP Application</a></div>'
        . '<div class="alert alert-info small mt-3">'
        . 'Once LPDD-FUS marks your documents <strong>Complete &amp; Sufficient</strong>, '
        . 'click <strong>View Payment Instructions</strong>. Pay at the DENR Regional Office Cashier, '
        . 'then proceed to LPDD-FUS with the Official Receipt for verification.</div>';
    if (!$apps) $html .= '<p class="text-secondary mb-0">No FP applications submitted yet.</p>';
    else {
        $html .= '<div class="table-responsive"><table class="table table-hover align-middle">'
            . '<thead><tr><th>No.</th><th>Project</th><th>Submitted</th><th>Revision</th><th>Status</th><th>Payment</th><th>Current Office</th><th>Next to Act</th><th>Action</th></tr></thead><tbody>';
        foreach ($apps as $app) {
            $routing = permit_tracking_state($app);
            $html .= '<tr><td>#' . (int)$app['id'] . '</td><td><strong>'
                . e((string)$app['project_title']) . '</strong><div class="text-secondary small">'
                . e((string)$app['project_location']) . '</div></td><td>'
                . e(review_queue_display_date($app['last_submitted_at'])) . '</td><td>' . (int)$app['revision']
                . '</td><td>' . permit_flow_status_badge($app) . '</td>'
                . '<td>' . ((string)$app['status']==='step4_complete'
                    ? permit_payment_status_badge(['status'=>$paymentMap[(int)$app['id']] ?? 'awaiting_payment'])
                    : '<span class="text-secondary small">Not yet required</span>') . '</td>'
                . '<td>' . e($routing['office']) . '</td><td>' . e($routing['next_role']) . '</td>'
                . '<td><div class="d-flex flex-wrap gap-2">'
                . ((string)$app['status']==='step4_complete'
                    ? '<a class="btn btn-sm btn-success" href="'
                        .e(url('permit_application',['id'=>(int)$app['id']]).'#fp-payment-verification')
                        .'">'.(($paymentMap[(int)$app['id']] ?? '')==='verified'
                            ? 'View Payment Receipt' : 'View Payment Instructions').'</a>'
                    : '')
                . '<a class="btn btn-sm btn-outline-primary" href="'
                . e(url('permit_application',['id'=>(int)$app['id']]))
                . '">Details</a>'
                . '<a class="btn btn-sm btn-outline-primary" href="'
                . e(url('permit_application',['id'=>(int)$app['id'],'view'=>'files']))
                . '">View Files</a>'
                . '<a class="btn btn-sm btn-outline-secondary" href="'
                . e(url('permit_application',['id'=>(int)$app['id'],'view'=>'audit']))
                . '">View Audit Trail</a></div></td></tr>';
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
        . 'Rotate scanned pages, duplicate, remove, or preview any page.</span></div>'
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
 * Structured Caraga address selector. PSGC ancestry and DENR jurisdiction are
 * revalidated server-side on final submission.
 */
function step2_structured_location_editor_html(array $values): string
{
    $provinceCode=trim((string)($values['project_province_code']??''));
    $cityCode=trim((string)($values['project_city_municipality_code']??''));
    $barangayCode=trim((string)($values['project_barangay_code']??''));
    $street=trim((string)($values['project_street_purok']??''));
    $address=trim((string)($values['project_location']??''));
    $areas=fp_location_areas();
    $selectedArea=null;
    $selectedCity=null;
    foreach ($areas as $area) {
        if ((string)($area['code']??'')===$provinceCode) {
            $selectedArea=$area;
            foreach (($area['cities']??[]) as $city) {
                if ((string)($city['code']??'')===$cityCode) {
                    $selectedCity=$city;
                    break;
                }
            }
            break;
        }
    }
    $resolved=fp_location_resolve($provinceCode,$cityCode,$barangayCode);
    $provinceName=$resolved['project_province_name']
        ?? (string)($values['project_province_name']??'');
    $cityName=$resolved['project_city_municipality_name']
        ?? (string)($values['project_city_municipality_name']??'');
    $barangayName=$resolved['project_barangay_name']
        ?? (string)($values['project_barangay_name']??'');
    $penro=$resolved['concerned_penro']??(string)($values['concerned_penro']??'');
    $cenro=$resolved['concerned_cenro']??(string)($values['concerned_cenro']??'');
    if ($resolved && $street!=='') $address=fp_location_format_address($street,$resolved);

    $html='<div class="border rounded-3 p-3 mb-3" data-fp-structured-location '
        .'data-psgc-url="assets/caraga-psgc-2026-q2.json?v=1" '
        .'data-selected-province="'.e($provinceCode).'" '
        .'data-selected-city="'.e($cityCode).'" '
        .'data-selected-barangay="'.e($barangayCode).'">'
        .'<div class="alert alert-info small py-2">'
        .'<i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>'
        .'Select the project address. FP-OMS automatically determines the concerned PENRO and CENRO from the Municipality/City jurisdiction.</div>'
        .'<div class="row g-3">'
        .'<div class="col-md-4"><label class="form-label" for="project_province_code">'
        .'Province / Independent City <span class="text-danger">*</span></label>'
        .'<select class="form-select" id="project_province_code" name="project_province_code" required>'
        .'<option value="">Select Province / Independent City</option>';
    foreach ($areas as $area) {
        $code=(string)($area['code']??'');
        $label=(string)($area['name']??'');
        if ((string)($area['kind']??'')==='independent_city') $label.=' (Independent City)';
        $html.='<option value="'.e($code).'"'.($code===$provinceCode?' selected':'').'>'
            .e($label).'</option>';
    }
    $html.='</select></div>'
        .'<div class="col-md-4"><label class="form-label" for="project_city_municipality_code">'
        .'Municipality / City <span class="text-danger">*</span></label>'
        .'<select class="form-select" id="project_city_municipality_code" '
        .'name="project_city_municipality_code" required>'
        .'<option value="">Select Municipality / City</option>';
    foreach (($selectedArea['cities']??[]) as $city) {
        $code=(string)($city['code']??'');
        $html.='<option value="'.e($code).'"'.($code===$cityCode?' selected':'').'>'
            .e((string)($city['name']??'')).'</option>';
    }
    $html.='</select></div>'
        .'<div class="col-md-4"><label class="form-label" for="project_barangay_code">'
        .'Barangay <span class="text-danger">*</span></label>'
        .'<select class="form-select" id="project_barangay_code" name="project_barangay_code" required>'
        .'<option value="">Select Barangay</option>';
    foreach (($selectedCity['barangays']??[]) as $barangay) {
        if (!is_array($barangay) || count($barangay)<2) continue;
        $code=(string)$barangay[0];
        $html.='<option value="'.e($code).'"'.($code===$barangayCode?' selected':'').'>'
            .e((string)$barangay[1]).'</option>';
    }
    $html.='</select></div></div>'
        .'<div class="mt-3"><label class="form-label" for="project_street_purok">'
        .'Street / Purok / Sitio <span class="text-danger">*</span></label>'
        .'<input class="form-control" id="project_street_purok" name="project_street_purok" '
        .'required minlength="2" maxlength="180" autocomplete="street-address" '
        .'placeholder="e.g. Purok 3 / Sitio Riverside / J.P. Rizal Street" value="'.e($street).'"></div>'
        .'<div class="mt-3"><label class="form-label" for="project_location_display">'
        .'Complete Project Location</label>'
        .'<input class="form-control bg-body-tertiary" id="project_location_display" '
        .'data-fp-complete-address readonly value="'.e($address).'">'
        .'<div class="form-text">Generated automatically from the structured address above.</div></div>'
        .'<input type="hidden" id="project_location" name="project_location" value="'.e($address).'">'
        .'<input type="hidden" name="project_province_name" value="'.e($provinceName).'">'
        .'<input type="hidden" name="project_city_municipality_name" value="'.e($cityName).'">'
        .'<input type="hidden" name="project_barangay_name" value="'.e($barangayName).'">'
        .'<input type="hidden" name="concerned_penro" value="'.e($penro).'">'
        .'<input type="hidden" name="concerned_cenro" value="'.e($cenro).'">'
        .'<div class="row g-2 mt-2">'
        .'<div class="col-md-6"><div class="border rounded-3 p-3 h-100">'
        .'<div class="small text-secondary">Concerned PENRO</div>'
        .'<strong class="text-success" data-fp-penro>'.e($penro!==''?$penro:'Select a Municipality / City').'</strong>'
        .'</div></div>'
        .'<div class="col-md-6"><div class="border rounded-3 p-3 h-100">'
        .'<div class="small text-secondary">Concerned CENRO</div>'
        .'<strong class="text-success" data-fp-cenro>'
        .e($cenro!==''?$cenro:($penro!==''?'Direct PENRO jurisdiction':'Select a Municipality / City'))
        .'</strong></div></div></div>'
        .'<p class="small text-secondary mt-2 mb-0" role="status" aria-live="polite" data-fp-address-status>'
        .'Complete the Province/Independent City, Municipality/City, Barangay, and Street/Purok/Sitio.</p>'
        .'<noscript><div class="alert alert-warning small mt-2 mb-0">'
        .'JavaScript is required for the cascading Municipality/City and Barangay lists.</div></noscript>'
        .'</div>';
    return $html;
}

function step2_area_photo_grouped(array $photos): array
{
    $groups=[];
    foreach ($photos as $photo) {
        $group=max(1,min(10,(int)($photo['documentation_group']??1)));
        $groups[$group][]=$photo;
    }
    ksort($groups,SORT_NUMERIC);
    return $groups;
}

function step2_area_photo_location_index(array $locations): array
{
    $indexed=[];
    foreach ($locations as $location) {
        $group=max(1,min(10,(int)($location['documentation_group']??1)));
        $revision=(int)($location['revision']??0);
        if (!isset($indexed[$group]) || $revision>=(int)($indexed[$group]['revision']??0)) {
            $indexed[$group]=$location;
        }
    }
    ksort($indexed,SORT_NUMERIC);
    return $indexed;
}

function step2_area_photo_group_location(
    int $group,array $photos,array $locationIndex
): array {
    $row=$locationIndex[$group]??[];
    $lat=trim((string)($row['latitude']??''));
    $lon=trim((string)($row['longitude']??''));
    if ($lat!=='' && $lon!=='') {
        return ['latitude'=>$lat,'longitude'=>$lon];
    }
    foreach ($photos as $photo) {
        if ((int)($photo['documentation_group']??1)!==$group) continue;
        $lat=trim((string)($photo['latitude']??''));
        $lon=trim((string)($photo['longitude']??''));
        if ($lat!=='' && $lon!=='') return ['latitude'=>$lat,'longitude'=>$lon];
    }
    return ['latitude'=>'','longitude'=>''];
}

function step2_area_photo_card_html(array $photo,bool $editable,int $applicationId): string
{
    $id=(int)($photo['id']??0);
    $group=max(1,min(10,(int)($photo['documentation_group']??1)));
    $name=(string)($photo['original_name']??'Area photo');
    $lat=(string)($photo['latitude']??'');
    $lon=(string)($photo['longitude']??'');
    $preview=(string)($photo['preview_url']??url('permit_area_photo',[
        'application_id'=>$applicationId,'id'=>$id
    ]));
    $revision=(int)($photo['revision']??0);
    return '<div class="card mb-2" data-area-photo-id="'.$id.'" '
        .'data-documentation-group="'.$group.'" '
        .'data-photo-name="'.e($name).'" data-latitude="'.e($lat).'" '
        .'data-longitude="'.e($lon).'" data-preview-url="'.e($preview).'" '
        .'data-revision="'.$revision.'">'
        .'<div class="card-body p-2"><div class="d-flex gap-2 align-items-start">'
        .'<img src="'.e($preview).'" alt="Area photo preview" loading="lazy" '
        .'width="92" height="72" class="rounded border object-fit-cover flex-shrink-0">'
        .'<div class="flex-grow-1 min-width-0"><div class="fw-semibold text-break">'
        .e($name).'</div>'
        .'<div class="small text-secondary">Documentation '.$group.'</div>'
        .'<div class="small text-secondary" data-area-photo-coords>GPS: '
        .e($lat).', '.e($lon).'</div>'
        .($revision>0?'<span class="badge text-bg-light mt-1">Revision '.$revision.'</span>':'')
        .($editable
            ? '<div class="small text-secondary mt-1">Drag this photo marker on this documentation map '
                .'to tag a different point.</div>'
            : '')
        .'<div class="d-flex flex-wrap gap-1 mt-2">'
        .'<button type="button" class="btn btn-sm btn-outline-primary" '
        .'data-area-photo-locate="'.$id.'">'
        .'<i class="bi bi-geo-alt me-1" aria-hidden="true"></i>Show on Map</button>'
        .($editable
            ? '<button type="button" class="btn btn-sm btn-outline-success" '
                .'data-area-photo-use-group="'.$id.'">'
                .'<i class="bi bi-crosshair me-1" aria-hidden="true"></i>Use Documentation Pin</button>'
                .'<button type="button" class="btn btn-sm btn-outline-danger" '
                .'data-area-photo-delete="'.$id.'">'
                .'<i class="bi bi-trash3 me-1" aria-hidden="true"></i>Remove</button>'
            : '')
        .'</div></div></div></div></div>';
}

function step2_area_photo_group_html(
    int $group,array $photos,array $location,bool $editable,int $applicationId,
    int $maxBytes=0
): string {
    $inputId='fp_area_photos_'.$group;
    $lat=trim((string)($location['latitude']??''));
    $lon=trim((string)($location['longitude']??''));
    $hasPin=$lat!=='' && $lon!=='';
    $html='<section class="border rounded-3 p-3 mb-3 bg-body" '
        .'data-area-photo-group="'.$group.'">'
        .'<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">'
        .'<h4 class="h6 mb-0"><i class="bi bi-camera-fill text-success me-1" '
        .'aria-hidden="true"></i>Area Photo Documentation '.$group.'</h4>'
        .'<span class="badge text-bg-light border"><span data-area-photo-group-count>'
        .count($photos).'</span> photo'.(count($photos)===1?'':'s').'</span></div>'
        .'<div class="border rounded-3 p-3 mb-3" data-area-photo-group-pin '
        .'data-documentation-group="'.$group.'" data-latitude="'.e($lat).'" '
        .'data-longitude="'.e($lon).'">'
        .'<div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">'
        .'<div><h5 class="h6 mb-1"><i class="bi bi-geo-alt-fill text-success me-1" '
        .'aria-hidden="true"></i>Pinpoint Project Location '.$group.'</h5>'
        .'<p class="small text-secondary mb-0">This pin belongs only to Area Photo Documentation '
        .$group.'. Photos uploaded in this block start at this point.</p></div>';
    if ($editable) {
        $html.='<div class="d-flex flex-wrap gap-1">'
            .'<button type="button" class="btn btn-sm btn-outline-success" data-area-group-gps>'
            .'<i class="bi bi-crosshair me-1" aria-hidden="true"></i>Use My Current Location</button>'
            .'</div>';
    }
    $html.='</div>';
    if ($editable) {
        $html.='<div class="row g-2 mb-2">'
            .'<div class="col-md-6"><label class="form-label small">Latitude</label>'
            .'<input class="form-control form-control-sm" type="text" inputmode="decimal" '
            .'data-area-group-latitude value="'.e($lat).'" placeholder="e.g. 8.9471234"></div>'
            .'<div class="col-md-6"><label class="form-label small">Longitude</label>'
            .'<input class="form-control form-control-sm" type="text" inputmode="decimal" '
            .'data-area-group-longitude value="'.e($lon).'" placeholder="e.g. 125.5423456"></div>'
            .'</div>';
    } else {
        $html.='<p class="small mb-2">Latitude: <strong>'.e($lat).'</strong> · Longitude: <strong>'
            .e($lon).'</strong></p>';
    }
    $html.='<div class="fp-project-location-map" data-area-photo-group-map '
        .'data-documentation-group="'.$group.'" aria-label="Area Photo Documentation '.$group
        .' pinpoint map"></div>'
        .'<p class="small text-secondary mt-2 mb-0" role="status" data-area-group-status>'
        .($hasPin
            ? 'Pinned documentation location: '.e($lat).', '.e($lon)
            : ($editable
                ? 'No documentation pin selected. Use GPS, click this map, or enter coordinates.'
                : 'Documentation pin unavailable.'))
        .'</p></div>';
    if ($editable) {
        $html.='<label class="form-label" for="'.$inputId.'">Upload / Take Area Photos</label>'
            .'<input class="form-control" type="file" id="'.$inputId.'" '
            .'data-fp-area-photo-input data-documentation-group="'.$group.'" '
            .'accept="image/jpeg,image/png,.jpg,.jpeg,.png" multiple '
            .($hasPin?'':'disabled').'>'
            .'<div class="form-text">Pinpoint Project Location '.$group.' first. JPG/JPEG/PNG only. Maximum '
            .e((string)round($maxBytes/1048576,1)).' MB each. New photos use this documentation pin; '
            .'individual photo markers may still be adjusted afterward.</div>';
    }
    $html.='<div class="mt-3" data-fp-area-photo-list>';
    foreach ($photos as $photo) $html.=step2_area_photo_card_html($photo,$editable,$applicationId);
    return $html.'</div><p class="small text-secondary mb-0" data-fp-area-photo-empty'
        .($photos?' hidden':'').'>No area photos uploaded in this documentation yet.</p>'
        .'</section>';
}

function step2_area_photo_editor_html(
    array $photos,array $locations,int $applicationId,int $maxBytes
): string {
    $photoGroups=step2_area_photo_grouped($photos);
    $locationIndex=step2_area_photo_location_index($locations);
    $groupIds=array_values(array_unique(array_merge(
        array_keys($photoGroups),array_keys($locationIndex)
    )));
    sort($groupIds,SORT_NUMERIC);
    if (!$groupIds) $groupIds=[1];

    $html='<section class="border rounded-3 p-3 mt-3" data-fp-area-photos data-editable="1" '
        .'data-application-id="'.$applicationId.'" data-max-bytes="'.$maxBytes.'" '
        .'data-max-documentation-groups="10">'
        .'<div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">'
        .'<div><h3 class="h6 mb-1"><i class="bi bi-images text-success me-1" '
        .'aria-hidden="true"></i>Pinpoint Project Location / Area Photo Documentation</h3>'
        .'<p class="small text-secondary mb-0">Each documentation block has its own '
        .'<strong>Pinpoint Project Location</strong>. Use GPS or a manual map pin for that block, '
        .'then upload its photos. All maps use free OpenStreetMap/Leaflet.</p></div>'
        .'<span class="badge text-bg-secondary"><span data-fp-area-photo-count>'
        .count($photos).'</span>/20 photos</span></div>'
        .'<div data-fp-area-photo-groups>';
    foreach ($groupIds as $group) {
        $items=$photoGroups[$group]??[];
        $location=step2_area_photo_group_location((int)$group,$items,$locationIndex);
        $html.=step2_area_photo_group_html(
            (int)$group,$items,$location,true,$applicationId,$maxBytes,$projectLat,$projectLon
        );
    }
    $html.='</div>'
        .'<button type="button" class="btn btn-outline-success btn-sm" '
        .'data-fp-add-area-photo-documentation>'
        .'<i class="bi bi-plus-circle me-1" aria-hidden="true"></i>'
        .'Add Another Area Photo Documentation</button>'
        .'<p class="small text-secondary mt-2 mb-0">Maximum 10 documentation blocks and '
        .'20 photos total per editable application draft.</p>'
        .'<p class="small text-secondary mt-2 mb-0" role="status" aria-live="polite" '
        .'data-fp-area-photo-feedback></p></section>';
    return $html;
}

function step2_area_photo_gallery_html(
    array $photos,array $locations,int $applicationId
): string {
    if (!$photos) return '';
    $photoGroups=step2_area_photo_grouped($photos);
    $locationIndex=step2_area_photo_location_index($locations);
    $html='<section class="panel mt-3" data-fp-area-photos data-editable="0" '
        .'data-application-id="'.$applicationId.'" data-max-documentation-groups="10">'
        .'<div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">'
        .'<div><h3 class="h6 mb-1"><i class="bi bi-images text-success me-1" '
        .'aria-hidden="true"></i>Geotagged Area Photo Documentation</h3>'
        .'<p class="small text-secondary mb-0">Each submitted documentation set keeps its own '
        .'Pinpoint Project Location plus the individual photo markers recorded by the applicant.</p></div>'
        .'<span class="badge text-bg-secondary"><span data-fp-area-photo-count>'
        .count($photos).'</span> photos</span></div>'
        .'<div data-fp-area-photo-groups>';
    foreach ($photoGroups as $group=>$items) {
        $location=step2_area_photo_group_location((int)$group,$items,$locationIndex);
        $html.=step2_area_photo_group_html(
            (int)$group,$items,$location,false,$applicationId
        );
    }
    return $html.'</div><p class="small text-secondary mt-2 mb-0" '
        .'data-fp-area-photo-feedback></p></section>';
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
        if (!in_array($app['status'],[
            'corrections_required','reverted','step4_corrections_required'
        ],true)) {
            show_error('This FP application is not available for correction.');
        }
        $docs = step2_documents($applicationId);
    }
    $editing = $app !== null;
    $step4Correction = $editing && (string)$app['status']==='step4_corrections_required';
    $modernCorrection=$step4Correction && permit_flow_is_new($app);
    $persistent = step2_persistent_ready();
    $fieldDraft = $persistent ? step2_persistent_form((int)$client['id'],$applicationId) : null;
    $title = (string)($_POST['project_title'] ?? $fieldDraft['project_title'] ?? $app['project_title'] ?? '');
    $projectType=(string)($_POST['project_type'] ?? $fieldDraft['project_type'] ?? $app['project_type'] ?? '');
    $projectOther=(string)($_POST['project_type_other'] ?? $fieldDraft['project_type_other'] ?? $app['project_type_other'] ?? '');
    $location = (string)($_POST['project_location'] ?? $fieldDraft['project_location'] ?? $app['project_location'] ?? '');
    $hasStructuredLocation=fp_location_structured_schema_ready();
    $structuredKeys=[
        'project_province_code','project_province_name',
        'project_city_municipality_code','project_city_municipality_name',
        'project_barangay_code','project_barangay_name','project_street_purok',
        'concerned_penro','concerned_cenro'
    ];
    $structuredLocation=['project_location'=>$location];
    foreach ($structuredKeys as $key) {
        $structuredLocation[$key]=(string)($_POST[$key]
            ?? ($fieldDraft[$key]??null)
            ?? ($app[$key]??''));
    }
    $area = (string)($_POST['project_area_hectares'] ?? $fieldDraft['project_area_hectares'] ?? $app['project_area_hectares'] ?? '');
    $hasArea = step2_area_schema_ready();
    $hasCoordinates = step2_location_schema_ready();
    $hasAreaPhotoStorage=step2_area_photo_schema_ready();
    $areaPhotoFeature=$hasAreaPhotoStorage;
    $savedAreaPhotos=$areaPhotoFeature
        ? step2_area_photo_draft_public_list((int)$client['id'],$applicationId) : [];
    $savedAreaPhotoGroups=$areaPhotoFeature
        ? step2_area_photo_group_draft_public_list((int)$client['id'],$applicationId) : [];
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
        . '<header class="step2-form-intro"><span class="step2-form-kicker">'
        . ($step4Correction ? 'Annex A · Step 4 Compliance' : 'Annex A · Step 2')
        . '</span>'
        . '<h2 class="h4 mb-2">' . ($step4Correction
            ? 'Step 4 Compliance – Edit and Resubmit FP Application #' . $applicationId
            : ($editing ? 'Edit and Resubmit FP Application #' . $applicationId
                : 'Submit FP Application')) . '</h2>'
        . errors_html($errors)
        . (!$hasArea
            ? '<div class="alert alert-warning small">Project Area requires database migration 034 before final submission and secure draft autosave.</div>'
            : '')
        . (!$hasStructuredLocation
            ? '<div class="alert alert-warning small">Structured Project Location requires database migration 037. FP-OMS will attempt to initialize it automatically when the database account has ALTER permission.</div>'
            : '')
        . (!$hasAreaPhotoStorage
            ? '<div class="alert alert-warning small">Geotagged Area Photos and independent documentation pins require database migrations 038, 039 and 040. FP-OMS will attempt to initialize/upgrade the photo tables automatically when CREATE/ALTER TABLE permission is available.</div>'
            : '')

        . '<p class="small text-secondary">Provide basic project identification and upload supporting documents. '
        . 'Documentary completeness will be evaluated by LPDD-FUS Technical Staff, '
        . 'using the official checklist approved by DENR.</p></header>'
        . step2_form_section_navigation_html();
    if ($editing) {
        $returnAction=$step4Correction
            ? 'step4_returned_for_compliance' : 'returned_for_correction';
        $latest = query_one(
            "SELECT remarks FROM permit_step2_events
             WHERE application_id=? AND action=?
             ORDER BY id DESC LIMIT 1", [$applicationId,$returnAction]
        );
        $html .= '<div class="alert alert-warning"><strong>'
            . ($step4Correction ? 'Step 4 documentary compliance required:' : 'Reviewer corrections required:')
            . '</strong><p class="mb-0">'
            . nl2br(e((string)($latest['remarks'] ?? 'Please follow the review instructions.')))
            . '</p></div>';
    }
    if (!step2_project_type_schema_ready()) {
        $html .= '<div class="alert alert-warning"><strong>Database migration 030 is required.</strong> '
            . 'Type of Project and autosave cannot be submitted until the ICT administrator installs it.</div>';
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
        . '<div class="mb-3"><label class="form-label" for="project_type">Type of Project '
        . '<span class="text-danger">*</span></label>'
        . '<select class="form-select" id="project_type" name="project_type" '
        . 'data-fp-project-type required>'
        . '<option value="">Select Type of Project</option>';
    foreach (step2_project_types() as $type=>$label) {
        $html.='<option value="'.e($type).'"'.($projectType===$type?' selected':'').'>'
            .e($label).'</option>';
    }
    $html.='</select><div class="form-text">Solar and Biomass are exempt from Annex 1 #10. '
        .'For Other, specify your project and upload the required DOE ESC/COA (#10).</div></div>'
        .'<div class="mb-3'.($projectType==='other'?'':' d-none').'" data-fp-other-project-wrap>'
        .'<label class="form-label" for="project_type_other">Specify Other Type of Project '
        .'<span class="text-danger">*</span></label>'
        .'<input class="form-control" id="project_type_other" name="project_type_other" '
        .'data-fp-other-project maxlength="160" minlength="3" '
        .($projectType==='other'?'required ':'').'value="'.e($projectOther).'"></div>'
        . '<div class="mb-3"><label class="form-label" for="project_area_hectares">Project Area (hectares) '
        . '<span class="text-danger">*</span></label>'
        . '<div class="input-group"><input class="form-control" id="project_area_hectares" '
        . 'name="project_area_hectares" inputmode="decimal" autocomplete="off" required '
        . 'pattern="\d{1,10}(?:\.\d{1,4})?" maxlength="15" placeholder="e.g. 15.25" '
        . 'value="' . e($area) . '"><span class="input-group-text">hectares</span></div>'
        . '<div class="form-text">Enter the total proposed project area. This value will appear in official FP notices.</div></div>'
        . '<div class="mb-3"><label class="form-label" for="purpose">Project Description (optional)</label>'
        . '<textarea class="form-control" id="purpose" name="purpose" rows="4" '
        . 'maxlength="2000">' . e($purpose) . '</textarea></div></section>'
        . '<section class="step2-form-section" id="step2-section-2" '
        . 'aria-labelledby="step2-section-title-2" data-step2-form-section="2">'
        . step2_form_section_heading_html(2,'Project Location','geo-alt')
         . ($hasStructuredLocation
            ? step2_structured_location_editor_html($structuredLocation)
            : '<div class="mb-3"><label class="form-label" for="project_location">Project Location / Complete Address '
                .'<span class="text-danger">*</span></label>'
                .'<input class="form-control" id="project_location" name="project_location" '
                .'required minlength="5" maxlength="300" value="'.e($location).'"></div>')
         . ($hasCoordinates
            ? '<input type="hidden" name="project_latitude" value="'.e($latitude).'">'
                .'<input type="hidden" name="project_longitude" value="'.e($longitude).'">'
            : '')
         . ($areaPhotoFeature
            ? step2_area_photo_editor_html(
                $savedAreaPhotos,$savedAreaPhotoGroups,$applicationId,
                max(1,(int)env_value('MAX_UPLOAD_BYTES','5242880'))
              )
            : '')
        . '</section><section class="step2-form-section" id="step2-section-3" '
        . 'aria-labelledby="step2-section-title-3" data-step2-form-section="3">'
        . step2_form_section_heading_html(3,'FP Supporting Documentary Requirements','files')
         . '<div class="mb-3"><label class="form-label" for="application_files">FP Supporting Documents '
        . ($editing ? '(optional additional files)' : '<span class="text-danger">*</span>') . '</label>'
        . '<div class="border rounded-3 p-3 mb-3" data-fp-requirement-guide>'
        . '<h3 class="h6 text-success">FP Supporting Documentary Requirements</h3>'
        . '<p class="small text-secondary">Select a requirement and upload the matching '
        . 'PDF/JPG/PNG. Multiple files may be uploaded per requirement, subject '
        . 'to the existing 10-file total. Other Attached Document is optional. '
        . 'Annex 1 #3 (Cashier proof of payment) is handled after Step 4 by LPDD-FUS; '
        . '#4 (GIS map) is uploaded by the Secretariat after deliberation. '
        . 'ECC/CNC and NCIP final clearances have the post-issuance timelines below.</p>'
        . '<p class="alert alert-info small" data-fp-doe-hint role="status">'
        . 'Select your Type of Project to determine whether DOE #10 applies.</p>'
        . '<div class="row g-2">';
    foreach (step2_upload_categories() as $type => $label) {
        $html .= '<div class="col-md-6'.($type==='doe_esc_coa' && step2_project_doe_exempt($projectType)?' d-none':'').'">'
            .'<div class="border rounded-3 p-3 h-100" '
            . 'data-step2-requirement-card="' . e($type) . '"'
            . ($type==='doe_esc_coa'?' data-step2-doe-upload':'').'>'
            . '<div class="small fw-semibold mb-2">' . e($label) . '</div>'
            . (($note=step2_requirement_note($type,$projectType))!==''
                ? '<p class="small text-secondary mb-2">'.e($note).'</p>' : '')
            . ($type==='other'?'<p class="small text-secondary">Optional supporting attachments; '
                .'not a replacement for Annex 1 named requirements.</p>':'')
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
        . '<div id="step2-draft-uploader" tabindex="-1" data-step2-mode="'
        . ($draftMode ? 'saved' : 'direct') . '" data-persistent="'
        . ($persistent ? '1' : '0') . '" data-application-id="'
        . $applicationId . '" data-max-bytes="'
        . max(1,(int)env_value('MAX_UPLOAD_BYTES','5242880')) . '">'

        . '<div class="step2-upload-fallback">'
        . '<label for="step2_requirement_type" class="form-label">'
        . 'Document Category <span class="text-danger">*</span></label>'
        . '<select class="form-select mb-2" id="step2_requirement_type" '
        . 'name="document_category" data-step2-category-select>'
        . '<option value="">Choose the matching requirement before selecting files</option>';
    foreach (step2_upload_categories() as $type => $label) {
        $html .= '<option value="' . e($type) . '"' .
            ($type==='doe_esc_coa' && step2_project_doe_exempt($projectType)?' disabled':'')
            . '>' . e($label) . '</option>';
    }
    $html .= '</select>'
        . '<div data-step2-direct-categories></div>'
        . '<input class="form-control" type="file" id="application_files" '
        . ($draftMode ? '' : 'name="application_files[]" ')
        . 'accept=".pdf,.jpg,.jpeg,.png" multiple '
        . (!$editing && !$draftMode ? 'required ' : '') . '>'
        . '<div class="form-text">Up to 10 files per submission. PDF/JPG/PNG, maximum '
        . e((string)$maxMb) . ' MB each. Documents are saved privately outside the website. '
        . 'Each saved file retains its selected category for LPDD-FUS review.</div></div>'
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
        . ($step4Correction
            ? ($modernCorrection
                ? 'documents above before resubmitting to LPDD-FUS Step 2 completeness evaluation.'
                : 'documents above before resubmitting compliance to legacy LPDD-FUS Step 4.')
            : 'documents above before sending the application to LPDD-FUS Initial Review.')
        . '</p>'
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
         . ($step4Correction ? ($modernCorrection
                ? 'Resubmit Compliance for Step 2 Re-evaluation'
                : 'Resubmit Compliance for Legacy Step 4')
            : ($editing ? 'Resubmit Corrections for Initial Review'
                : 'Submit to LPDD-FUS Initial Review'))
        . '</button></section></form></div></div><div class="col-lg-4">'
        . ($step4Correction
            ? '<div class="panel soft"><h3 class="h6">Annex A Step 4 Compliance</h3>'
                . '<ol class="small"><li>Review LPDD-FUS documentary evaluation findings.</li>'
                . '<li>Correct deficiencies and complete missing requirements.</li>'
                . '<li>Upload the required corrected/additional documents.</li>'
                . '<li>Resubmit directly to LPDD-FUS Step 4 re-evaluation.</li></ol>'
                . '<p class="small text-secondary mb-0">The application will not repeat Step 2 or RO Step 3 after this compliance resubmission.</p></div>'
            : '<div class="panel soft"><h3 class="h6">Annex A Step 2 Routing</h3>'
                . '<ol class="small"><li>Applicant uploads application documents.</li>'
                . '<li>LPDD-FUS Technical Staff reviews completeness.</li>'
                . '<li>Incomplete → applicant corrects and resubmits.</li>'
                . '<li>Complete → forward to RO Receiving/Releasing.</li></ol></div>');
    if ($docs) $html .= '<div class="panel mt-3"><h3 class="h6">Previously Uploaded Documents</h3>'
        . step2_documents_html($docs) . '</div>';
    $html .= '</div></div>' . step2_pdf_choice_modal_html()
        . step2_pdf_organizer_modal_html();
    if ($persistent) $html .= step2_clear_form_modal_html();
    $html .= '<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js" defer></script>'
        . '<script src="assets/permit-pdf-organizer.js?v=2" defer></script>'
        . '<script src="assets/permit-pdf-choice.js?v=2" defer></script>'
        . '<script src="assets/permit-project-type.js?v=2" defer></script>'
        . ($hasStructuredLocation
            ? '<script src="assets/permit-location-address.js?v=1" defer></script>' : '')
        . '<script src="assets/permit-step2-drafts.js?v=14" defer></script>'
        . ($areaPhotoFeature
            ? '<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" defer></script>'
                . '<script src="assets/permit-area-photos.js?v=4" defer></script>'
            : '')
        . '<script src="assets/permit-step2-sections.js?v=6" defer></script>';
    render_page($editing ? 'Resubmit FP Application' : 'New FP Application', $html);
}

function view_step2_application(int $applicationId, array $errors = []): void
{
    $viewer = require_user();
    $app = step2_application_for_view($viewer,$applicationId);
    $documents = step2_documents($applicationId);
    $areaPhotos=step2_area_photo_schema_ready()?step2_area_photos($applicationId):[];
    $areaPhotoGroups=step2_area_photo_schema_ready()?step2_area_photo_groups($applicationId):[];
    $mine = $viewer['user_kind'] === 'applicant';
    $reviewer = !$mine && has_role($viewer,'lpdd_fus');
    $modern=permit_flow_is_new($app);
    $noticePdfPreviewModal=false;
    $clientNoticePreview='';
    $clientReleasedNoticeIds=$mine && $modern
        ? permit_flow_released_notice_artifact_ids($applicationId) : [];
    $clientPaymentNoticeReleased=$mine && $modern
        ? fpoms_payment_notice_is_released($applicationId,(int)$app['revision']) : false;
    if ($clientReleasedNoticeIds || $clientPaymentNoticeReleased) $noticePdfPreviewModal=true;
    if ($mine && $modern && (string)$app['status']==='step4_corrections_required') {
        $releasedNotice=fpoms_notice_record($applicationId,(int)$app['revision']);
        $releasedArtifact=$releasedNotice
            ? permit_flow_signed_notice_artifact_for_revision($applicationId,(int)$app['revision'])
            : null;
        if ($releasedArtifact
            && in_array((int)$releasedArtifact['id'],$clientReleasedNoticeIds,true)
            && (string)($releasedNotice['status']??'')==='signed'
            && (int)($releasedNotice['artifact_id']??0)===(int)$releasedArtifact['id']) {
            $clientNoticePreview=url('permit_flow_file',[
                'id'=>$applicationId,'file'=>(int)$releasedArtifact['id'],
                'preview'=>1,'raw'=>1
            ]);
            $noticePdfPreviewModal=true;
        }
    }
    $step4Statuses=[
        'forwarded_to_lpdd','step4_under_evaluation','step4_corrections_required',
        'step4_resubmitted','step4_complete'
    ];
    $back = $mine ? 'permit_applications'
        : ($reviewer
            ? ($modern
                ? (in_array((string)$app['status'],[
                    'submitted','step4_under_evaluation','step4_corrections_required',
                    'step4_resubmitted'],true)
                    ? 'permit_initial_queue' : 'permit_flow_queue')
                : (in_array((string)$app['status'],$step4Statuses,true)
                    ? 'permit_step4_queue' : 'permit_initial_queue'))
            : (has_role($viewer,'ro_receiving') ? 'permit_ro_inbox' : 'permit_flow_queue'));
    $html = '<div class="mb-3"><a href="' . url($back) . '">← Back to list</a></div>'
        . '<div class="row g-3"><div class="col-lg-8"><div class="panel">'
        . errors_html($errors)
        . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">'
        . '<h2 class="h5">FP Application #' . $applicationId . '</h2>'
        . '<span class="badge text-bg-secondary">'.($modern?'Updated Annex A':'Legacy Annex A').'</span>'
        . permit_flow_status_badge($app) . '</div>'
        . '<dl class="row details mt-3">'
        . '<dt class="col-sm-4">Applicant</dt><dd class="col-sm-8">'
        . e((string)$app['applicant_name']) . '</dd>'
        . '<dt class="col-sm-4">Project</dt><dd class="col-sm-8">'
        . e((string)$app['project_title']) . '</dd>'
        . '<dt class="col-sm-4">Type of Project</dt><dd class="col-sm-8">'
        . e(step2_project_label((string)($app['project_type'] ?? ''),
            (string)($app['project_type_other'] ?? ''))) . '</dd>'
        . '<dt class="col-sm-4">Location</dt><dd class="col-sm-8">'
        . e((string)$app['project_location']) . '</dd>'
        . (trim((string)($app['concerned_penro']??''))!==''
            ? '<dt class="col-sm-4">Concerned PENRO</dt><dd class="col-sm-8">'
                .e((string)$app['concerned_penro']).'</dd>'
                .'<dt class="col-sm-4">Concerned CENRO</dt><dd class="col-sm-8">'
                .e(trim((string)($app['concerned_cenro']??''))!==''
                    ? (string)$app['concerned_cenro'] : 'Direct PENRO jurisdiction').'</dd>'
            : '')
        . '<dt class="col-sm-4">Project Area</dt><dd class="col-sm-8">'
        . e(step2_area_display($app['project_area_hectares'] ?? null)) . '</dd>'
        . '<dt class="col-sm-4">Description</dt><dd class="col-sm-8">'
        . nl2br(e((string)($app['purpose'] ?? ''))) . '</dd>'
        . '<dt class="col-sm-4">Revision</dt><dd class="col-sm-8">'
        . (int)$app['revision'] . '</dd>'
        . '<dt class="col-sm-4">Last submitted</dt><dd class="col-sm-8">'
        . e((string)$app['last_submitted_at']) . '</dd></dl>'
        . permit_tracking_summary_html($app)
        . ($modern
            ? '<div class="alert alert-info mt-2 mb-3"><strong>Updated Annex A:</strong> '
                .'Step 2 LPDD-FUS documentary completeness → Step 3 RO Receiving → '
                .'Step 4 payment notice, schedule and CENRO instructions → Steps 5–11. '
                .'<a href="'.e(url('permit_flow',['id'=>$applicationId]))
                .'" class="alert-link">Open Annex A Workflow / Audit</a></div>'
            : '')
        . (((string)$app['status']==='step4_complete'
                || ($modern && fpoms_payment_notice_is_released($applicationId,(int)$app['revision'])))
            ? '<div class="alert alert-info mt-3 mb-3" role="status">'
                .'<h3 class="h6 mb-2">Next Action – Application Fee</h3>'
                .'<p class="small mb-2">'.(
                    ($mine
                        ? 'Pay at the DENR CARAGA Regional Office Cashier, then go directly to LPDD-FUS with your Official Receipt.'
                        : (has_role($viewer,'lpdd_fus')
                            ? 'Check the original Cashier-issued Official Receipt before uploading and confirming payment.'
                            : 'Check whether LPDD-FUS has verified the Cashier payment.'))
                ).'</p>'
                .'<a class="btn btn-sm btn-success" href="#fp-payment-verification">'
                .($mine ? 'View Payment Instructions / Status'
                    : (has_role($viewer,'lpdd_fus') ? 'Verify Payment / View OR'
                        : 'View Payment Status')).'</a></div>'
            : '')
        . '<div class="d-flex flex-wrap gap-2 mb-3" aria-label="FP application documents and history">'
        . '<button type="button" class="btn btn-primary" data-bs-toggle="modal" '
        . 'data-bs-target="#fp-files-modal">'
        . '<i class="bi bi-folder2-open me-1" aria-hidden="true"></i>View Files</button>'
        . '<button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" '
        . 'data-bs-target="#fp-audit-trail-modal">'
        . '<i class="bi bi-clock-history me-1" aria-hidden="true"></i>View Audit Trail</button>'
        . '</div>'
        . '<p class="small text-secondary mb-0">View supporting files or the application audit trail '
        . 'in separate windows. Previous submitted revisions and recorded actions are preserved.</p>'
        . '</div></div><div class="col-lg-4">';
    if ($mine) {
        if ($modern && (string)$app['status']==='step4_under_evaluation') {
            $notice=fpoms_notice_record($applicationId,(int)$app['revision']);
            if ($notice && (string)$notice['status']==='pending_red') {
                $html.='<div class="alert alert-info" role="status">'
                    .'<strong>Awaiting RED Approval.</strong> DENR has completed a documentary review, '
                    .'but the official notice has not been approved or released. '
                    .'You will receive an in-system notification and email after official release.'
                    .'</div>';
            } elseif ($notice && (string)$notice['status']==='signed') {
                $html.='<div class="alert alert-info" role="status">'
                    .'<strong>Signed Notice Under Final Review.</strong> RED has signed the PDF. '
                    .'It will be available here after final release.'
                    .'</div>';
            }
        }
        $html .= '<div class="panel soft"><h3 class="h6">Next Action</h3>'
            . (in_array((string)$app['status'],['corrections_required','step4_corrections_required'],true)
                ? '<p>'.($app['status']==='step4_corrections_required'
                        ? ($modern
                            ? 'LPDD-FUS Step 2 determined that documentary compliance is required. Review the actual RED-signed deficiency notice, correct deficiencies, and resubmit for Step 2 re-evaluation.'
                            : 'LPDD-FUS Step 4 evaluation requires documentary compliance. Review findings, correct deficiencies, and resubmit.')
                        : 'LPDD-FUS requested corrections. Review the remarks under View Audit Trail, then edit and resubmit your FP documents.')
                    . '</p><a class="btn btn-primary" href="'
                    . e(url('permit_apply',['id'=>$applicationId])) . '">'
                    . ($app['status']==='step4_corrections_required'
                        ? ($modern?'Comply / Resubmit to Step 2':'Comply / Resubmit to Step 4')
                        : 'Correct / Resubmit') . '</a>'
                    .($clientNoticePreview!==''
                        ? ' <button type="button" class="btn btn-outline-primary ms-2" '
                            .'data-bs-toggle="modal" data-bs-target="#notice-pdf-preview-modal" '
                            .'data-notice-auto-open '
                            .'data-notice-preview-url="'.e($clientNoticePreview).'" '
                            .'data-notice-preview-title="'.e('FP #'.$applicationId.' · RED-Signed Deficiency Notice').'">'
                            .'<i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>'
                            .'View RED-Signed PDF</button>'
                        : '')
                : '<p class="mb-0">'
                    . (in_array((string)$app['status'],[
                        'forwarded_to_ro','ro_received','forwarded_to_lpdd',
                        'step4_under_evaluation','step4_resubmitted','step4_complete'
                    ],true)
                        ? permit_tracking_state($app)['next_action']
                        : ($app['status'] === 'reverted'
                            ? 'Your application is reverted; you can edit and resubmit it.'
                            : 'Your application is awaiting the LPDD-FUS Technical Staff initial review.'))
                    . '</p>')
            . '</div>'
            . (in_array((string)$app['status'],['step4_corrections_required','step4_complete'],true)
                && step4_schema_ready() ? step4_result_panel_html($app) : '')
            . step2_revert_client_html($app)
            . ($modern ? permit_flow_public_documents_html($app,$viewer,true) : '');
    } elseif ($reviewer && $modern
        && (string)$app['status']==='step4_under_evaluation'
        && ($redNotice=fpoms_notice_record($applicationId,(int)$app['revision']))
        && in_array((string)$redNotice['status'],['pending_red','signed'],true)) {
        $signed=(string)$redNotice['status']==='signed';
        $approved=$signed && (int)$redNotice['artifact_id']>0;
        $noticePreview=$approved
            ? url('permit_flow_file',[
                'id'=>$applicationId,'file'=>(int)$redNotice['artifact_id'],
                'preview'=>1,'raw'=>1
            ])
            : url('deficiency_notice_pdf',['id'=>$applicationId,'embed'=>1]);
        $noticePreviewLabel=$approved?'View RED-Approved PDF':'Preview Draft PDF';
        $noticePdfPreviewModal=true;
        $html.='<div class="panel soft"><h3 class="h6">'
            .($signed?'RED Approved – Return Finalization Pending':'Awaiting RED Approval').'</h3>'
            .'<p class="mb-2">'.($signed
                ? 'RED has approved the official deficiency PDF. Release could not be completed yet; an authorized RED user can retry Finalize Approved Return from the RED queue.'
                : 'LPDD-FUS submitted the draft deficiency notice for RED review. It is no longer in the LPDD-FUS active queue. The applicant is notified only after RED approval and successful Return finalization.')
            .'</p><div class="d-flex flex-wrap gap-2">'
            .'<a class="btn btn-outline-primary btn-sm" href="'.e($noticePreview).'" '
            .'data-bs-toggle="modal" data-bs-target="#notice-pdf-preview-modal" '
            .'data-notice-preview-url="'.e($noticePreview).'" '
            .'data-notice-preview-title="'.e('FP #'.$applicationId.' · '.$noticePreviewLabel).'">'
            .'<i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>'
            .e($noticePreviewLabel).'</a>'
            .(!$signed
                ? '<form method="post" class="d-inline" action="'
                    .e(url('deficiency_notice_regenerate',['id'=>$applicationId])).'">'
                    .csrf_field()
                    .'<button type="submit" class="btn btn-outline-success btn-sm">'
                    .'<i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>'
                    .'Regenerate PDF</button></form>'
                : '')
            .'</div>'
            .(!$signed
                ? '<p class="small text-secondary mt-2 mb-0">Rebuilds the protected one-page draft '
                    .'from the same frozen findings. This does not notify the applicant.</p>'
                : '')
            .'</div>';
    } elseif ($reviewer && $modern
        && in_array((string)$app['status'],[
            'submitted','step4_under_evaluation','step4_resubmitted',
            'step4_corrections_required'],true)) {
        $html .= step4_panel_html($app,$documents,$errors);
    } elseif ($reviewer && $modern && (string)$app['status']==='forwarded_to_lpdd') {
        $html .= '<div class="panel soft"><h3 class="h6">Step 4 – LPDD-FUS Actions</h3>'
            .'<p>Step 2 documentary evaluation is already completed. '
            .'RO Receiving forwarded the application back for payment notice, '
            .'field schedule and CENRO instructions.</p>'
            .'<a class="btn btn-primary" href="'.e(url('permit_flow',['id'=>$applicationId]))
            .'">Open Step 4 Actions</a></div>';
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
            . (!empty($app['received_at'])
                ? '<button class="btn btn-success" type="button" data-confirm-decision="forward">'
                    . 'Complete – Forward to RO Receiving</button>'
                : '<div class="alert alert-info small mb-0">'
                    . 'Receive Documents first before the Complete – Forward action becomes available.'
                    . '</div>')
            . '<button class="btn btn-outline-danger" type="button" data-confirm-decision="return">'
            . 'Incomplete – Return for Correction</button></div>'
            . '<noscript><p class="alert alert-warning small mt-3 mb-0">'
            . 'Enable JavaScript to confirm and submit a review decision.</p></noscript>'
            . '</form></div>';
    } elseif ($reviewer && !$modern && in_array((string)$app['status'],$step4Statuses,true)) {
        $html .= step4_panel_html($app,$documents,$errors);
    } elseif (has_role($viewer,'cenro_twg') && (string)$app['status']==='step4_complete') {
        $html .= step4_result_panel_html($app);
    } elseif (has_role($viewer,'ro_receiving')
        && in_array((string)$app['status'],[
            'forwarded_to_ro','ro_received','forwarded_to_lpdd',
            'step4_under_evaluation','step4_corrections_required',
            'step4_resubmitted','step4_complete'
        ],true)) {
        $html .= step3_action_panel_html($app);
    } else {
        $html .= '<div class="panel soft"><h3 class="h6">Routing Status</h3>'
            . '<p class="small mb-0">Current status: '
            . e(step2_status_label((string)$app['status']))
            . '. Check the audit trail for completed actions.</p></div>';
    }
    $html .= '</div></div>'
        . ($modern ? permit_flow_schedule_summary_html($app,$viewer) : '')
        . (((string)$app['status']==='step4_complete'
                || ($modern && fpoms_payment_notice_is_released($applicationId,(int)$app['revision'])))
            ? permit_payment_panel_html($app,$viewer,$errors) : '')
        . step2_files_modal_html($documents)
        . permit_tracking_audit_modal_html($applicationId,'fp-audit-trail-modal',$mine)
        . ($reviewer && !$modern && $app['status'] === 'submitted'
            ? decision_confirmation_modal_html() : '');
    if (step2_location_schema_ready()) {
        $hasMainProjectPin=($app['project_latitude'] ?? null)!==null
            && ($app['project_longitude'] ?? null)!==null
            && (string)$app['project_latitude']!=='' && (string)$app['project_longitude']!=='';
        if ($hasMainProjectPin) $html .= step2_location_preview_html($app);
        if ($areaPhotos) $html .= step2_area_photo_gallery_html(
            $areaPhotos,$areaPhotoGroups,$applicationId
        );
        if ($hasMainProjectPin || $areaPhotos) {
            $html .= '<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" defer></script>';
        }
        if ($hasMainProjectPin) {
            $html .= '<script src="assets/permit-location-map.js?v=3" defer></script>';
        }
        if ($areaPhotos) {
            $html .= '<script src="assets/permit-area-photos.js?v=3" defer></script>';
        }
    }
    if ($noticePdfPreviewModal) $html .= fpoms_notice_pdf_preview_modal_html();
    render_page('FP Application', $html);
}

function view_step2_review_queue(): void
{
    step2_require_reviewer();
    // A drafted notice is an action for RED, not an active LPDD-FUS review.
    $noticeReady=fpoms_notice_schema_ready();
    $pendingCount=0;
    $pendingCondition=$noticeReady ? " AND NOT EXISTS (
        SELECT 1 FROM fpoms_deficiency_notices n
        WHERE n.application_id=a.id AND n.revision=a.revision
          AND n.status IN ('pending_red','signed')
    )" : '';
    if ($noticeReady) {
        $pending=query_one("SELECT COUNT(*) AS total FROM fpoms_deficiency_notices n
            JOIN permit_step2_applications a ON a.id=n.application_id
                AND a.revision=n.revision
            WHERE n.status='pending_red' AND a.status='step4_under_evaluation'");
        $pendingCount=(int)($pending['total']??0);
    }
    $apps=query_all(
        "SELECT a.id,a.project_title,a.flow_version,a.status,a.revision,
                a.last_submitted_at,c.name AS applicant_name
         FROM permit_step2_applications a JOIN user_client c ON c.id=a.client_id
         WHERE ((a.flow_version=1 AND a.status='submitted')
            OR (a.flow_version=2 AND a.status IN (
                'submitted','step4_under_evaluation',
                'step4_resubmitted','step4_corrections_required')))
         ".$pendingCondition."
         ORDER BY a.last_submitted_at ASC,a.id ASC LIMIT 200"
    );
    $html = '<div class="panel"><h2 class="h5">LPDD-FUS – Step 2 Completeness & Sufficiency '
        . '<span class="badge text-bg-primary">' . count($apps) . '</span></h2>'
        .($pendingCount>0
            ? '<div class="alert alert-warning py-2 small mt-2 mb-2">'
                .$pendingCount.' deficiency notice(s) are Awaiting RED Approval. '
                .'These are excluded from the LPDD-FUS active action list and appear in the RED queue.</div>'
            : '')
        . '<p class="text-secondary small">Updated Annex A: applicant submits directly '
        . 'to LPDD-FUS, which decides documentary completeness and sufficiency BEFORE Step 3 RO Receiving. '
        . 'Legacy applications continue the original review route.</p>';
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
                . '">'.((int)$app['flow_version']===2
                    ? ((string)$app['status']==='step4_corrections_required'
                        ? 'View Deficiencies' : 'Evaluate Completeness')
                    : 'Legacy Initial Review').'</a></td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    render_page('LPDD-FUS Initial Review Queue', $html . '</div>');
}

/** Preserve existing RO inbox routes, now handled by the Step 3 module. */
function view_step2_ro_inbox(): void
{
    view_step3_ro_inbox();
}
