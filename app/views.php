<?php
declare(strict_types=1);

/** Shared navigation for desktop and mobile; never grants authorization. */
function shell_nav_item(string $page, string $label, string $icon, string $active): string
{
    $activeAttr = $active === $page ? ' active' : '';
    $currentAttr = $active === $page ? ' aria-current="page"' : '';
    return '<a class="sidebar-link' . $activeAttr . '" href="' . e(url($page)) . '" title="' . e($label) . '"' . $currentAttr . '>'
        . '<i class="bi bi-' . e($icon) . '" aria-hidden="true"></i><span>' . e($label) . '</span></a>';
}

function shell_sidebar_menu(array $user, array $roles, string $active): string
{
    $html = '<div class="sidebar-section-title">Main</div>'
        . shell_nav_item('dashboard', 'Dashboard', 'grid-1x2-fill', $active);
    if ($user['user_kind'] === 'applicant') {
        $html .= shell_nav_item('my_registration', 'My Registration', 'person-vcard', $active)
            . '<div class="sidebar-section-title">Permit Application</div>'
            . '<span class="sidebar-link" aria-disabled="true" title="Available in Phase 2"><i class="bi bi-file-earmark-plus"></i><span>Submit FP Application · Soon</span></span>';
    } else {
        $html .= '<div class="sidebar-section-title">Permit Processing</div>';
        if (in_array('lpdd_fus', $roles, true)) {
            $html .= shell_nav_item('review_queue', 'Registration Review', 'clipboard-check', $active);
        }
        foreach ([['file-earmark-text','FP Applications'],['clipboard-data','Document Evaluation'],['geo-alt','Field Validation'],['people','RRDC Deliberation'],['file-earmark-check','Permit Approval'],['send','Permit Release']] as [$icon, $label]) {
            $html .= '<span class="sidebar-link" aria-disabled="true" title="Available in a later phase"><i class="bi bi-' . e($icon) . '"></i><span>' . e($label) . ' · Soon</span></span>';
        }
        if (in_array('super_admin', $roles, true)) {
            $html .= '<div class="sidebar-section-title">Administration</div>'
                . shell_nav_item('staff', 'Manage Users', 'people-fill', $active);
        }
    }
    return $html;
}

function shell_user_card(array $user, array $roles): string
{
    $labels = role_definitions();
    $roleNames = array_map(static fn(string $role): string => $labels[$role][0] ?? $role, $roles);
    $roleText = $user['user_kind'] === 'applicant' ? 'Applicant' : implode(', ', $roleNames);
    $initial = strtoupper(substr(trim((string)$user['name']), 0, 1)) ?: 'U';
    return '<div class="sidebar-user"><div class="sidebar-user-row"><span class="sidebar-avatar">' . e($initial) . '</span>'
        . '<div class="sidebar-user-copy"><span class="sidebar-user-name" title="' . e($user['name']) . '">' . e($user['name']) . '</span>'
        . '<span class="sidebar-user-role">' . e($roleText) . '</span></div></div>'
        . '<div class="sidebar-user-actions"><a href="' . e(url('change_password')) . '"><i class="bi bi-lock"></i> Password</a>'
        . '<form method="post" action="' . e(url('logout')) . '">' . csrf_field()
        . '<button type="submit"><i class="bi bi-box-arrow-right"></i> Sign out</button></form></div></div>';
}

function render_page(string $title, string $content): void
{
    $user = current_user();
    $appName = e(env_value('APP_NAME', 'DENR FP-OMS'));
    $titleEsc = e($title);
    $flashes = take_flashes();
    $active = str_replace('-', '_', (string)($_GET['page'] ?? 'dashboard'));
    $roles = $user ? user_roles((int)$user['id']) : [];

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="description" content="DENR Caraga Feasibility Permit Online Management System">'
        . '<meta name="theme-color" content="#075c32">'
        . '<title>' . $titleEsc . ' | ' . $appName . '</title>'
        . '<link rel="icon" href="assets/fpoms-icon.svg" type="image/svg+xml">'
        . '<link rel="manifest" href="manifest.webmanifest">'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
        . '<link href="assets/style.css" rel="stylesheet">'
        . '<script src="assets/app-shell.js" defer></script></head><body>';

    if ($user) {
        $brandHtml = '<a class="sidebar-brand" href="' . e(url('dashboard')) . '">'
            . '<span class="sidebar-logo"><img src="assets/fpoms-icon.svg" alt="DENR FP-OMS"></span>'
            . '<span class="sidebar-brand-copy"><span class="sidebar-brand-title">DENR FP-OMS</span>'
            . '<span class="sidebar-brand-sub">Feasibility Permit System</span></span></a>';
        $navigation = shell_sidebar_menu($user, $roles, $active);
        $userCard = shell_user_card($user, $roles);
        $roleNames = role_definitions();
        $roleDisplay = $user['user_kind'] === 'applicant' ? 'Applicant' : ($roleNames[$roles[0] ?? ''][0] ?? 'DENR Personnel');
        $initial = strtoupper(substr(trim((string)$user['name']), 0, 1)) ?: 'U';
        echo '<div class="app-shell"><aside id="appSidebar" class="app-sidebar" aria-label="Primary navigation">'
            . '<button class="sidebar-collapse-toggle" type="button" data-sidebar-toggle aria-expanded="true" aria-label="Collapse navigation sidebar" title="Collapse sidebar"><i class="bi bi-layout-sidebar-inset"></i></button>'
            . $brandHtml . '<div class="sidebar-separator"></div><nav class="sidebar-scroll" aria-label="Main menu">' . $navigation . '</nav>' . $userCard . '</aside>'
            . '<div class="offcanvas offcanvas-start mobile-nav" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">'
            . '<div class="offcanvas-header"><h2 class="h6 mb-0" id="mobileSidebarLabel">DENR FP-OMS</h2><button class="btn-close" data-bs-dismiss="offcanvas" type="button" aria-label="Close"></button></div>'
            . '<div class="offcanvas-body d-flex flex-column"><nav aria-label="Mobile main menu">' . $navigation . '</nav>' . $userCard . '</div></div>'
            . '<div class="app-main"><header class="app-topbar"><div class="container-fluid app-topbar-inner px-3 px-lg-4">'
            . '<div class="d-flex align-items-center gap-3"><button class="mobile-menu-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" aria-label="Open navigation"><i class="bi bi-list fs-5"></i></button>'
            . '<div><div class="topbar-title">' . $titleEsc . '</div><div class="topbar-breadcrumb">DENR FP-OMS / ' . $titleEsc . '</div></div></div>'
            . '<div class="topbar-actions"><label for="textSizeSelect" class="visually-hidden">Adjust text size</label>'
            . '<select class="form-select form-select-sm text-size-select" id="textSizeSelect" aria-label="Adjust text size">'
            . '<option value="small">Text: Small</option><option value="medium">Text: Medium</option><option value="large">Text: Large</option><option value="xl">Text: XL</option><option value="xxl">Text: XXL</option><option value="xxxl">Text: XXXL</option></select>'
            . '<div class="topbar-user-mini" title="' . e($user['name']) . '"><span class="topbar-user-initial">' . e($initial) . '</span>'
            . '<div><span class="topbar-mini-name">' . e($user['name']) . '</span><span class="topbar-mini-role">' . e($roleDisplay) . '</span></div></div></div></div></header>'
            . '<main class="app-content"><div class="eyebrow">DENR · CARAGA REGION XIII</div><h1 class="page-title">' . $titleEsc . '</h1>';
    } else {
        echo '<div class="app-shell d-flex flex-column"><header class="public-header"><div class="container-xl public-header-inner">'
            . '<a class="public-brand" href="' . e(url('landing')) . '"><span class="brand-mark"><img src="assets/fpoms-icon.svg" alt="DENR FP-OMS"></span>'
            . '<span><strong>DENR FP-OMS</strong><small>Caraga Region XIII · Feasibility Permit System</small></span></a>'
            . '<nav class="public-links" aria-label="Public navigation"><a href="' . e(url('landing')) . '">Home</a><a href="' . e(url('login')) . '">Sign in</a>'
            . '<a class="btn btn-primary btn-sm" href="' . e(url('register')) . '">Register</a></nav>'
            . '</div></header><main class="public-content"><div class="eyebrow">DENR · CARAGA REGION XIII</div><h1 class="page-title">' . $titleEsc . '</h1>';
    }

    foreach ($flashes as $message) {
        $type = in_array($message['type'], ['success', 'warning', 'danger', 'info'], true) ? $message['type'] : 'info';
        echo '<div class="alert alert-' . $type . '" role="status">' . e((string)$message['message']) . '</div>';
    }
    echo $content;
    echo '</main><footer class="footer"><div class="d-flex flex-wrap gap-2 justify-content-between">'
        . '<span>DENR Regional Office XIII — Feasibility Permit Online Management System</span>'
        . '<span>Phase 1 · Registration &amp; Account Activation</span></div></footer></div></div>'
        . '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>'
        . '</body></html>';
}

function errors_html(array $errors): string
{
    if (!$errors) return '';
    $html = '<div class="alert alert-danger"><strong>Please correct the following:</strong><ul class="mb-0 mt-2">';
    foreach ($errors as $error) $html .= '<li>' . e((string)$error) . '</li>';
    return $html . '</ul></div>';
}

function field(string $name, string $label, string $value = '', string $type = 'text', bool $required = true, string $extra = ''): string
{
    return '<div class="mb-3"><label class="form-label" for="' . e($name) . '">' . e($label) . ($required ? ' <span class="text-danger">*</span>' : '') . '</label>'
        . '<input class="form-control" id="' . e($name) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '" ' . ($required ? 'required ' : '') . $extra . '></div>';
}

function form_value(string $name, array $values = []): string
{
    return (string)($_POST[$name] ?? $values[$name] ?? '');
}

function registration_fields(array $values = [], bool $new = true): string
{
    $html = '<div class="row"><div class="col-md-6">'
        . field('representative_name','Authorized representative / Full name',form_value('representative_name',$values), 'text',true,'maxlength="180"')
        . '</div><div class="col-md-6">'
        . field('representative_position','Representative position',form_value('representative_position',$values),'text',false,'maxlength="150"')
        . '</div></div>';
    if ($new) {
        $html .= field('email','Email for login',form_value('email'),'email',true,'autocomplete="email" maxlength="190"');
    }
    $html .= '<div class="row"><div class="col-md-6">'
        . field('company_name','Company / Proponent name',form_value('company_name',$values),'text',true,'maxlength="220"')
        . '</div><div class="col-md-6">'
        . field('contact_number','Contact number',form_value('contact_number',$values),'tel',true,'maxlength="40"')
        . '</div></div><div class="mb-3"><label class="form-label" for="company_address">Company address <span class="text-danger">*</span></label>'
        . '<textarea class="form-control" id="company_address" name="company_address" rows="2" maxlength="2000" required>'
        . e(form_value('company_address',$values)) . '</textarea></div>';
    if ($new) {
        $html .= '<div class="row"><div class="col-md-6">'
            . field('password','Password','','password',true,'autocomplete="new-password" minlength="12"')
            . '</div><div class="col-md-6">'
            . field('password_confirm','Confirm password','','password',true,'autocomplete="new-password" minlength="12"')
            . '</div></div><div class="form-text mb-3">Use at least 12 characters, including letters and numbers.</div>';
    }
    $html .= '<hr><h3 class="h6">Registration attachments</h3>'
        . '<p class="text-secondary small">Allowed: PDF, JPG or PNG. Maximum 5 MB per file. Files are stored outside the public web directory.</p>'
        . '<div class="row"><div class="col-md-6">'
        . '<label class="form-label" for="valid_id">Valid ID ' . ($new ? '<span class="text-danger">*</span>' : '(optional replacement)') . '</label>'
        . '<input class="form-control" id="valid_id" name="valid_id" type="file" accept=".pdf,.jpg,.jpeg,.png" ' . ($new ? 'required' : '') . '></div>'
        . '<div class="col-md-6"><label class="form-label" for="supporting_document">Other registration document (optional)</label>'
        . '<input class="form-control" id="supporting_document" name="supporting_document" type="file" accept=".pdf,.jpg,.jpeg,.png"></div></div>';
    return $html;
}

function view_register(array $errors = []): void
{
    if (current_user()) go('dashboard');
    $html = '<div class="row g-4"><div class="col-lg-8"><div class="panel">'
        . errors_html($errors)
        . '<p class="text-secondary">Create a one-time applicant account. LPDD-FUS will review the submitted information before activation.</p>'
        . '<form method="post" action="' . url('register') . '" enctype="multipart/form-data">' . csrf_field()
        . registration_fields()
        . '<div class="mt-4 d-flex gap-3 align-items-center flex-wrap"><button class="btn btn-primary" type="submit">Submit for review</button>'
        . '<a href="' . url('login') . '">Already registered? Sign in</a></div></form></div></div>'
        . '<div class="col-lg-4"><div class="panel soft"><h2 class="h5">Account activation</h2>'
        . '<ol class="small"><li>Register and upload a valid ID.</li><li>LPDD-FUS checks information and documents.</li><li>Correct deficiencies if requested.</li><li>LPDD-FUS approves and activates the account.</li></ol>'
        . '<div class="alert alert-info small mb-0">Step 1 is a one-working-day activity and is not included in the 15-day permit-processing clock.</div>'
        . '</div></div></div>';
    render_page('Applicant Registration', $html);
}

function view_login(array $errors = []): void
{
    if (current_user()) go('dashboard');
    $html = '<div class="row justify-content-center"><div class="col-md-7 col-lg-5"><div class="panel">'
        . errors_html($errors) . '<p class="text-secondary">Sign in to your applicant or DENR internal account.</p>'
        . '<form method="post" action="' . url('login') . '">' . csrf_field()
        . field('email','Email',form_value('email'),'email',true,'autocomplete="username" maxlength="190"')
        . field('password','Password','','password',true,'autocomplete="current-password"')
        . '<button class="btn btn-primary w-100" type="submit">Sign in</button></form>'
        . '<hr><p class="mb-0 small">New applicant? <a href="' . url('register') . '">Register for an account</a>.</p>'
        . '<div class="install-app-card"><div class="install-copy"><span class="install-icon"><i class="bi bi-download"></i></span>'
        . '<div><strong>Install DENR FP-OMS</strong><small>Use FP-OMS like a desktop or mobile app</small></div></div>'
        . '<button type="button" id="installAppButton" class="btn btn-sm btn-outline-primary"><i class="bi bi-display me-1"></i>Install</button></div>'
        . '<p id="installAppHelp" class="install-help" role="status" aria-live="polite"></p>'
        . '</div></div></div>';
    render_page('Sign In', $html);
}

function status_badge(string $status): string
{
    $colors = ['pending_review'=>'warning','needs_correction'=>'danger','active'=>'success','suspended'=>'secondary'];
    return '<span class="badge text-bg-' . ($colors[$status] ?? 'secondary') . '">' . e(status_label($status)) . '</span>';
}

function view_applicant_registration(array $user, array $errors = []): void
{
    require_kind($user, 'applicant');
    $reg = registration_for_user((int)$user['id']);
    if (!$reg) show_error('Applicant registration data was not found.', 404);
    $latest = latest_review((int)$reg['id']);
    $html = '<div class="row g-4"><div class="col-lg-8"><div class="panel">' . errors_html($errors)
        . '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3"><h2 class="h5 m-0">Registration Details</h2>' . status_badge($user['status']) . '</div>'
        . '<dl class="row details"><dt class="col-sm-4">Representative</dt><dd class="col-sm-8">' . e($reg['representative_name']) . '</dd>'
        . '<dt class="col-sm-4">Company</dt><dd class="col-sm-8">' . e($reg['company_name']) . '</dd>'
        . '<dt class="col-sm-4">Email</dt><dd class="col-sm-8">' . e($user['email']) . '</dd>'
        . '<dt class="col-sm-4">Contact</dt><dd class="col-sm-8">' . e($reg['contact_number']) . '</dd>'
        . '<dt class="col-sm-4">Revision</dt><dd class="col-sm-8">' . (int)$reg['revision'] . '</dd>'
        . '<dt class="col-sm-4">Last submitted</dt><dd class="col-sm-8">' . e($reg['submitted_at']) . '</dd></dl>';
    if ($latest) $html .= '<div class="alert ' . ($latest['decision'] === 'approved' ? 'alert-success' : 'alert-warning') . '"><strong>LPDD-FUS decision:</strong> ' . e(ucwords(str_replace('_',' ',$latest['decision'])))
        . '<div class="small mt-1">' . nl2br(e($latest['remarks'] ?: 'No additional remarks.')) . '</div></div>';
    $docs = registration_documents((int)$reg['id']);
    $html .= '<h3 class="h6">Submitted documents</h3><ul class="list-group mb-3">';
    foreach ($docs as $doc) {
        $html .= '<li class="list-group-item d-flex justify-content-between gap-2"><span>'
            . e(ucwords(str_replace('_',' ',$doc['document_type']))) . '</span><a href="' . url('download_reg',['id'=>(int)$doc['id']]) . '">' . e($doc['original_name']) . '</a></li>';
    }
    $html .= '</ul>';
    if ($user['status'] === 'active') {
        $html .= '<div class="alert alert-success mb-0"><strong>Your account is activated.</strong> Step 2 application submission will be enabled in a later development phase; no application has been filed by this registration.</div>';
    } elseif ($user['status'] === 'pending_review') {
        $html .= '<div class="alert alert-info mb-0">Your registration is awaiting LPDD-FUS review. Log back in to check its status.</div>';
    }
    $html .= '</div></div><div class="col-lg-4"><div class="panel soft"><h3 class="h6">Processing note</h3><p class="small mb-0">Registration and activation are excluded from the 15-working-day FP permit-processing period.</p></div></div></div>';
    if ($user['status'] === 'needs_correction') {
        $html .= '<div class="panel mt-4"><h2 class="h5">Submit Corrected Registration</h2><p class="text-secondary">Edit your information and optionally upload replacement attachments. Previously uploaded documents remain on file unless replaced.</p>'
            . '<form action="' . url('registration_update') . '" method="post" enctype="multipart/form-data">' . csrf_field()
            . registration_fields($reg, false)
            . '<div class="mt-4"><button class="btn btn-primary" type="submit">Resubmit for LPDD-FUS Review</button></div></form></div>';
    }
    render_page('My Registration', $html);
}

function view_review_queue(): void
{
    require_role('lpdd_fus');
    $queue = query_all("SELECT ar.id,ar.company_name,ar.representative_name,ar.submitted_at,ar.revision,u.email,u.status FROM applicant_registrations ar JOIN users u ON u.id=ar.user_id WHERE u.status='pending_review' ORDER BY ar.submitted_at ASC, ar.id ASC LIMIT 200");
    $html = '<div class="panel"><h2 class="h5">Pending Account Reviews <span class="badge text-bg-primary">' . count($queue) . '</span></h2>'
        . '<p class="text-secondary small">Approve valid registrations or return incomplete registrations with specific correction instructions.</p>';
    if (!$queue) $html .= '<div class="alert alert-success mb-0">No registrations are currently awaiting review.</div>';
    else {
        $html .= '<div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Applicant</th><th>Company</th><th>Submitted</th><th>Revision</th><th></th></tr></thead><tbody>';
        foreach ($queue as $row) $html .= '<tr><td><strong>' . e($row['representative_name']) . '</strong><div class="small text-secondary">' . e($row['email']) . '</div></td>'
            . '<td>' . e($row['company_name']) . '</td><td>' . e($row['submitted_at']) . '</td><td>' . (int)$row['revision'] . '</td><td>'
            . '<a class="btn btn-sm btn-outline-primary" href="' . url('review',['id'=>(int)$row['id']]) . '">Review</a></td></tr>';
        $html .= '</tbody></table></div>';
    }
    $html .= '</div>';
    render_page('LPDD-FUS Review Queue', $html);
}

function view_review_case(int $id, array $errors = []): void
{
    require_role('lpdd_fus');
    $reg = query_one('SELECT ar.*,u.email,u.status,u.activated_at FROM applicant_registrations ar JOIN users u ON u.id=ar.user_id WHERE ar.id=?',[$id]);
    if (!$reg) show_error('Registration not found.', 404);
    $html = '<div class="mb-3"><a href="' . url('review_queue') . '">← Back to review queue</a></div><div class="row g-4"><div class="col-lg-7"><div class="panel">' . errors_html($errors)
        . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h2 class="h5">Registration #' . (int)$reg['id'] . '</h2>' . status_badge($reg['status']) . '</div>'
        . '<dl class="row details"><dt class="col-sm-4">Representative</dt><dd class="col-sm-8">' . e($reg['representative_name']) . '</dd>'
        . '<dt class="col-sm-4">Position</dt><dd class="col-sm-8">' . e($reg['representative_position'] ?? '') . '</dd>'
        . '<dt class="col-sm-4">Company</dt><dd class="col-sm-8">' . e($reg['company_name']) . '</dd>'
        . '<dt class="col-sm-4">Company address</dt><dd class="col-sm-8">' . nl2br(e($reg['company_address'])) . '</dd>'
        . '<dt class="col-sm-4">Email</dt><dd class="col-sm-8">' . e($reg['email']) . '</dd>'
        . '<dt class="col-sm-4">Contact</dt><dd class="col-sm-8">' . e($reg['contact_number']) . '</dd>'
        . '<dt class="col-sm-4">Revision</dt><dd class="col-sm-8">' . (int)$reg['revision'] . '</dd></dl>'
        . '<h3 class="h6 mt-3">Latest attachments</h3><ul class="list-group">';
    foreach (registration_documents($id) as $doc) $html .= '<li class="list-group-item d-flex justify-content-between gap-2"><span>' . e(ucwords(str_replace('_',' ',$doc['document_type']))) . '</span>'
        . '<a href="' . url('download_reg',['id'=>(int)$doc['id']]) . '">' . e($doc['original_name']) . '</a></li>';
    $html .= '</ul></div></div><div class="col-lg-5"><div class="panel soft"><h2 class="h5">LPDD-FUS Decision</h2>';
    if ($reg['status'] === 'pending_review') {
        $html .= '<form method="post" action="' . url('review',['id'=>$id]) . '">' . csrf_field()
            . '<label class="form-label" for="remarks">Remarks / Corrections to address</label>'
            . '<textarea class="form-control mb-3" name="remarks" id="remarks" rows="5" maxlength="5000" placeholder="Required if returning for correction"></textarea>'
            . '<div class="d-flex gap-2 flex-wrap"><button class="btn btn-success" type="submit" name="decision" value="approved">Approve & Activate</button>'
            . '<button class="btn btn-outline-danger" type="submit" name="decision" value="needs_correction">Return for Correction</button></div></form>';
    } else $html .= '<p class="text-secondary mb-0">This registration is no longer awaiting review.</p>';
    $html .= '</div><div class="panel mt-3"><h3 class="h6">Previous review decisions</h3>';
    $history = query_all('SELECT rr.*,u.name AS reviewer FROM registration_reviews rr JOIN users u ON u.id=rr.reviewer_user_id WHERE rr.registration_id=? ORDER BY rr.id DESC',[$id]);
    if (!$history) $html .= '<p class="text-secondary small mb-0">No earlier reviews.</p>';
    foreach ($history as $event) $html .= '<div class="history"><strong>' . e(ucwords(str_replace('_',' ',$event['decision']))) . '</strong> · Revision ' . (int)$event['revision']
        . '<div class="small text-secondary">' . e($event['reviewer']) . ' · ' . e($event['reviewed_at']) . '</div><p class="small mb-0">' . nl2br(e($event['remarks'] ?? '')) . '</p></div>';
    $html .= '</div></div></div>';
    render_page('Review Registration', $html);
}

function view_staff(array $errors = []): void
{
    require_role('super_admin');
    $internal = query_all("SELECT u.id,u.name,u.email,u.status, GROUP_CONCAT(r.label ORDER BY r.label SEPARATOR ', ') AS role_names FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE u.user_kind='internal' GROUP BY u.id,u.name,u.email,u.status ORDER BY u.created_at DESC LIMIT 200");
    $html = '<div class="row g-4"><div class="col-lg-5"><div class="panel">' . errors_html($errors) . '<h2 class="h5">Create DENR Internal User</h2>'
        . '<p class="text-secondary small">Only RICTU Super Administrators can provision internal accounts. New users must change their initial password upon login.</p>'
        . '<form method="post" action="' . url('staff') . '">' . csrf_field()
        . field('name','Full name',form_value('name'),'text',true,'maxlength="180"')
        . field('email','Work email',form_value('email'),'email',true,'maxlength="190"')
        . '<div class="mb-3"><label class="form-label" for="role">Assigned role</label><select name="role" id="role" class="form-select" required><option value="">Choose internal role</option>';
    foreach (role_definitions() as $code => $definition) if ($code !== 'applicant') {
        $selected = ($_POST['role'] ?? '') === $code ? ' selected' : '';
        $html .= '<option value="' . e($code) . '"' . $selected . '>' . e($definition[0]) . '</option>';
    }
    $html .= '</select></div>' . field('password','Initial password','','password',true,'autocomplete="new-password" minlength="12"')
        . field('confirm_password','Confirm initial password','','password',true,'autocomplete="new-password" minlength="12"')
        . '<button class="btn btn-primary" type="submit">Create Internal Account</button></form></div></div>'
        . '<div class="col-lg-7"><div class="panel"><h2 class="h5">Internal Accounts</h2>'
        . '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Name</th><th>Email</th><th>Role</th></tr></thead><tbody>';
    foreach ($internal as $entry) $html .= '<tr><td>' . e($entry['name']) . '</td><td>' . e($entry['email']) . '</td><td>' . e($entry['role_names'] ?? 'Unassigned') . '</td></tr>';
    $html .= '</tbody></table></div><p class="small text-secondary mb-0">Office assignment, edit/suspend and granular permissions will be added in a later phase.</p></div></div></div>';
    render_page('RICTU User Administration', $html);
}

function view_change_password(array $errors = []): void
{
    $user = require_user();
    $html = '<div class="row justify-content-center"><div class="col-md-7 col-lg-6"><div class="panel">'
        . errors_html($errors) . ($user['must_change_password'] ? '<div class="alert alert-warning">Change your initial password to continue.</div>' : '')
        . '<form method="post" action="' . url('change_password') . '">' . csrf_field()
        . field('current_password','Current password','','password',true,'autocomplete="current-password"')
        . field('new_password','New password','','password',true,'autocomplete="new-password" minlength="12"')
        . field('confirm_password','Confirm new password','','password',true,'autocomplete="new-password" minlength="12"')
        . '<button class="btn btn-primary" type="submit">Update Password</button></form></div></div></div>';
    render_page('Change Password', $html);
}

function view_dashboard(): void
{
    $user = require_user();
    if ($user['user_kind'] === 'applicant') {
        view_applicant_registration($user);
        return;
    }
    $roles = user_roles((int)$user['id']);
    $rolesText = implode(', ', array_map(static fn(string $r): string => role_definitions()[$r][0] ?? $r, $roles));
    $html = '<div class="panel"><div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">'
        . '<div><h2 class="h5 mb-1">Welcome, ' . e($user['name']) . '</h2><p class="text-secondary mb-0">Assigned: ' . e($rolesText) . '</p></div>'
        . status_badge($user['status']) . '</div><hr><div class="row g-3">';
    if (has_role($user,'lpdd_fus')) {
        $pending = query_one("SELECT COUNT(*) AS n FROM users WHERE user_kind='applicant' AND status='pending_review'");
        $html .= '<div class="col-md-6"><a class="quick-action" href="' . url('review_queue') . '"><strong>Applicant Reviews</strong><span class="metric">' . (int)$pending['n'] . '</span><small>Awaiting LPDD-FUS action</small></a></div>';
    }
    if (has_role($user,'super_admin')) $html .= '<div class="col-md-6"><a class="quick-action" href="' . url('staff') . '"><strong>Internal User Administration</strong><span class="metric">RICTU</span><small>Create role-assigned accounts</small></a></div>';
    $html .= '</div><div class="alert alert-info mt-3 mb-0">This is the Phase 1 dashboard. Permit submission, receiving, evaluation, field validation, RRDC deliberation, RED approval and release (Steps 2–11) are not yet implemented.</div></div>';
    render_page('Dashboard', $html);
}
