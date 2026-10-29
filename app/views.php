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
            . '<div class="sidebar-section-title">Permit Application</div>';
        if ($user['status'] === 'active') {
            $html .= shell_nav_item('permit_applications', 'My FP Applications', 'folder2-open', $active)
                . shell_nav_item('permit_apply', 'Submit FP Application', 'file-earmark-plus', $active);
        } else {
            $html .= '<span class="sidebar-link" aria-disabled="true" title="Activate your account first"><i class="bi bi-file-earmark-plus"></i><span>Submit FP Application · Account Activation Required</span></span>';
        }
    } else {
        $html .= '<div class="sidebar-section-title">Permit Processing</div>';
        if (in_array('lpdd_fus', $roles, true)) {
            $html .= shell_nav_item('review_queue', 'Registration Review', 'clipboard-check', $active)
                . shell_nav_item('permit_initial_queue', 'FP Initial Review – Step 2', 'file-earmark-check', $active);
        }
        if (in_array('ro_receiving', $roles, true)) {
            $html .= shell_nav_item('permit_ro_inbox', 'RO Receiving Inbox', 'inboxes', $active);
        }
        foreach ([['clipboard-data','Document Evaluation'],['geo-alt','Field Validation'],['people','RRDC Deliberation'],['file-earmark-check','Permit Approval'],['send','Permit Release']] as [$icon, $label]) {
            $html .= '<span class="sidebar-link" aria-disabled="true" title="Available in a later phase"><i class="bi bi-' . e($icon) . '"></i><span>' . e($label) . ' · Soon</span></span>';
        }
        if (in_array('super_admin', $roles, true)) {
            $html .= '<div class="sidebar-section-title">Administration</div>'
                . '<details class="sidebar-config-menu"' . (in_array($active, ['user_management','user_create','user_edit'], true) ? ' open' : '') . '>'
                . '<summary class="sidebar-link"><i class="bi bi-gear-fill"></i><span>Configuration</span><i class="bi bi-chevron-down ms-auto"></i></summary>'
                . '<div class="ps-3">' . shell_nav_item('user_management','User Management','people-fill', in_array($active, ['user_management','user_create','user_edit'], true) ? 'user_management' : $active) . '</div></details>';
        }
    }
    return $html;
}

function shell_user_card(array $user, array $roles): string
{
    $labels = role_definitions();
    $roleNames = array_map(static fn(string $role): string => $labels[$role][0] ?? $role, $roles);
    $roleText = $user['user_kind'] === 'applicant' ? 'Applicant' : implode(', ', $roleNames);
    return '<div class="sidebar-user"><a class="sidebar-user-row sidebar-user-profile-link" href="' . e(url('profile')) . '" title="Open Profile">'
        . profile_avatar_html($user, 'sidebar-avatar', 'Profile photo')
        . '<div class="sidebar-user-copy"><span class="sidebar-user-name" title="' . e($user['name']) . '">' . e($user['name']) . '</span>'
        . '<span class="sidebar-user-role">' . e($roleText) . '</span></div></a>'
        . '<div class="sidebar-user-actions"><a href="' . e(url('profile')) . '"><i class="bi bi-person"></i> Profile</a>'
        . '<a href="' . e(url('change_password')) . '"><i class="bi bi-lock"></i> Password</a>'
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
        . '<link rel="icon" type="image/png" sizes="192x192" href="assets/fpoms-icon-192.png?v=24">'
        . '<link rel="icon" type="image/png" sizes="512x512" href="assets/fpoms-icon-512.png?v=24">'
        . '<link rel="apple-touch-icon" sizes="192x192" href="assets/fpoms-icon-192.png?v=24">'
        . '<link rel="manifest" href="manifest.webmanifest?v=24">'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
        . '<link href="assets/style.css?v=21" rel="stylesheet">'
        . (str_contains($content, 'id="applicant-fp-requirements"')
            ? '<link href="assets/applicant-requirements.css?v=1" rel="stylesheet">' : '')
        . (str_contains($content, 'id="fp-pdf-organizer-modal"')
            ? '<link href="assets/permit-pdf-organizer.css?v=1" rel="stylesheet">' : '')
        . (str_contains($content, 'data-fp-location-map=')
            ? '<link href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">'
                . '<link href="assets/permit-location-map.css?v=1" rel="stylesheet">'
            : '')
        . '<script src="assets/app-shell.js?v=21" defer></script></head><body>';

    if ($user) {
        $brandHtml = '<a class="sidebar-brand" href="' . e(url('dashboard')) . '">'
            . '<span class="sidebar-logo"><img src="assets/fpoms-icon-192.png?v=24" alt="DENR FP-OMS"></span>'
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
            . notification_dropdown_html($user)
            . '<a class="topbar-user-mini" href="' . e(url('profile')) . '" title="Open Profile · ' . e($user['name']) . '">' . profile_avatar_html($user, 'topbar-user-initial', 'Profile photo')
            . '<div><span class="topbar-mini-name">' . e($user['name']) . '</span><span class="topbar-mini-role">' . e($roleDisplay) . '</span></div></a></div></div></header>'
            . '<main class="app-content"><div class="eyebrow">DENR · CARAGA REGION XIII</div><h1 class="page-title">' . $titleEsc . '</h1>';
    } else {
        echo '<div class="app-shell d-flex flex-column"><header class="public-header"><div class="container-xl public-header-inner">'
            . '<a class="public-brand" href="' . e(url('landing')) . '"><span class="brand-mark"><img src="assets/fpoms-icon-192.png?v=24" alt="DENR FP-OMS"></span>'
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
        . (str_contains($content, 'id="registration-document-modal"')
            ? '<script src="assets/registration-document-preview.js?v=1" defer></script>' : '')
        . (str_contains($content, 'id="otp-verification-success-modal"')
            ? '<script src="assets/otp-verification-success.js?v=1"></script>' : '')
        . (str_contains($content, 'data-decision-confirm-form')
            ? '<script src="assets/decision-confirm.js?v=1" defer></script>' : '')
        . '</body></html>';
}

/**
 * Shared confirmation modal for authorized LPDD-FUS review decisions.
 * Text is filled with textContent, not dynamic innerHTML.
 */
function decision_confirmation_modal_html(): string
{
    return '<div class="modal fade" id="decision-confirm-modal" tabindex="-1" '
        . 'aria-labelledby="decision-confirm-title" aria-describedby="decision-confirm-description" '
        . 'aria-hidden="true" data-bs-backdrop="static">'
        . '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header">'
        . '<h2 class="modal-title h5" id="decision-confirm-title">Confirm Review Decision</h2>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
        . '</div><div class="modal-body">'
        . '<p id="decision-confirm-description" class="mb-3"></p>'
        . '<dl class="row details small mb-0">'
        . '<dt class="col-sm-4">Reference</dt>'
        . '<dd class="col-sm-8" id="decision-confirm-reference"></dd>'
        . '<dt class="col-sm-4">Applicant</dt>'
        . '<dd class="col-sm-8" id="decision-confirm-applicant"></dd>'
        . '<dt class="col-sm-4">New Status</dt>'
        . '<dd class="col-sm-8 fw-semibold" id="decision-confirm-status"></dd>'
        . '</dl>'
        . '<div class="border rounded-3 bg-light p-3 mt-3 d-none" id="decision-confirm-remarks-group">'
        . '<div class="small fw-semibold mb-1">Review Remarks / Deficiencies</div>'
        . '<p class="small text-break mb-0" id="decision-confirm-remarks"></p>'
        . '</div>'
        . '<p class="small text-secondary mt-3 mb-0">The applicant will be notified '
        . 'according to the current notification settings after the decision is saved.</p>'
        . '</div><div class="modal-footer">'
        . '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button type="button" class="btn btn-success" id="decision-confirm-submit">'
        . 'Confirm Decision</button>'
        . '</div></div></div></div>';
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
        . field('representative_name','Applicant Full Name',form_value('representative_name',$values), 'text',true,'maxlength="180"')
        . '</div><div class="col-md-6">'
        . field('representative_position','Position / Designation',form_value('representative_position',$values),'text',false,'maxlength="150"')
        . '</div></div>';
    if ($new) {
        $html .= field('email','Email for login',form_value('email'),'email',true,'autocomplete="email" maxlength="190"');
    }
    $html .= '<div class="row"><div class="col-md-6">'
        . field('company_name','Company Name',form_value('company_name',$values),'text',true,'maxlength="220"')
        . '</div><div class="col-md-6">'
        . field('contact_number','Contact number',form_value('contact_number',$values),'tel',true,'maxlength="40"')
        . '</div></div><div class="mb-3"><label class="form-label" for="company_address">Company address <span class="text-danger">*</span></label>'
        . '<textarea class="form-control" id="company_address" name="company_address" rows="2" maxlength="2000" required>'
        . e(form_value('company_address',$values)) . '</textarea></div>';
    if ($new) {
        $html .= '<div class="row g-3 mb-3"><div class="col-md-6">'
            . '<label class="form-label" for="password">Password <span class="text-danger">*</span></label>'
            . '<div class="input-group"><input class="form-control" id="password" name="password" type="password" required autocomplete="new-password" minlength="12" maxlength="200" aria-describedby="password-rules password-feedback">'
            . '<button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-controls="password" aria-pressed="false" aria-label="Show password" title="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button></div>'
            . '<div class="small fw-semibold mt-3 mb-1">Password requirements</div>'
            . '<ul class="list-unstyled small mb-0" id="password-rules" aria-label="Password validation requirements">'
            . '<li class="text-secondary mb-1" data-password-rule="minimum"><i class="bi bi-circle me-2" aria-hidden="true"></i>At least 12 characters <span class="visually-hidden password-rule-status">Not met</span></li>'
            . '<li class="text-secondary mb-1" data-password-rule="letter"><i class="bi bi-circle me-2" aria-hidden="true"></i>At least one letter (A–Z) <span class="visually-hidden password-rule-status">Not met</span></li>'
            . '<li class="text-secondary mb-1" data-password-rule="number"><i class="bi bi-circle me-2" aria-hidden="true"></i>At least one number (0–9) <span class="visually-hidden password-rule-status">Not met</span></li>'
            . '<li class="text-secondary mb-1" data-password-rule="maximum"><i class="bi bi-circle me-2" aria-hidden="true"></i>Maximum 200 characters <span class="visually-hidden password-rule-status">Not met</span></li>'
            . '</ul><div id="password-feedback" class="small text-secondary mt-2" role="status" aria-live="polite">Enter a password to check its requirements.</div>'
            . '</div><div class="col-md-6">'
            . '<label class="form-label" for="password_confirm">Confirm Password <span class="text-danger">*</span></label>'
            . '<div class="input-group"><input class="form-control" id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password" minlength="12" maxlength="200" aria-describedby="password-confirm-feedback">'
            . '<button class="btn btn-outline-secondary" type="button" data-toggle-password="password_confirm" aria-controls="password_confirm" aria-pressed="false" aria-label="Show confirmation password" title="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button></div>'
            . '<div id="password-confirm-feedback" class="small text-secondary mt-2" role="status" aria-live="polite">Re-enter your password to confirm.</div>'
            . '</div></div>';
    }
    $html .= registration_attachment_form_fields($new);
    return $html;
}

function view_register(array $errors = []): void
{
    if (current_user()) go('dashboard');
    $html = '<div class="row g-4"><div class="col-lg-8"><div class="panel">'
        . errors_html($errors)
        . '<p class="text-secondary">Register and verify your email with a six-digit code first. Your application is sent to LPDD-FUS only after email verification.</p>'
        . '<form id="applicant-registration-form" method="post" action="' . url('register') . '" enctype="multipart/form-data">' . csrf_field()
        . registration_fields()
        . '<p class="small text-secondary mb-0">Your entries are saved in this browser until submission or clearing. Passwords are not saved.</p>'
        . '<div class="mt-3 d-flex gap-3 align-items-center flex-wrap"><button class="btn btn-primary" type="submit">Submit for review</button>'
        . '<button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#clear-registration-modal">Clear Form</button>'
        . '<a href="' . url('login') . '">Already registered? Sign in</a></div></form></div></div>'
        . '<div class="col-lg-4"><div class="panel soft"><h2 class="h5">Account activation</h2>'
        . '<ol class="small"><li>Register and upload a valid ID.</li><li>Enter the emailed OTP to verify your registration.</li><li>Receive your registration acknowledgment after OTP verification.</li><li>LPDD-FUS checks information and documents.</li><li>Correct deficiencies if requested.</li><li>LPDD-FUS approves and activates the account.</li></ol>'
        . '<div class="alert alert-info small mb-0">Step 1 is a one-working-day activity and is not included in the 15-day permit-processing clock.</div>'
        . '</div></div></div>'
        . '<div class="modal fade" id="clear-registration-modal" tabindex="-1" aria-labelledby="clear-registration-modal-title" aria-hidden="true">'
        . '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h2 class="modal-title fs-5" id="clear-registration-modal-title">Clear registration form?</h2>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><p class="mb-2">This will permanently clear all registration details and uploaded files.</p>'
        . '<p class="small text-secondary mb-0">This action cannot be undone.</p>'
        . '<div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-clear-registration-error></div></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button type="button" class="btn btn-danger" data-confirm-clear-registration>Clear Form</button></div>'
        . '</div></div></div>'
        . '<script src="assets/register-password.js?v=1" defer></script>'
        . '<script src="assets/registration-attachments.js?v=7" defer></script>'
        . '<script src="assets/registration-form-draft.js?v=2" defer></script>';
    render_page('Applicant Registration', $html);
}

/**
 * One-time OTP success confirmation. The registration was already committed
 * before this page loads; this UI never changes verification or review state.
 */
function registration_verified_modal_html(bool $ackDeliveryFailed, string $applicantName = ''): string
{
    $warning = $ackDeliveryFailed
        ? '<div class="alert alert-warning text-start small mt-3 mb-0" role="alert">'
            . '<i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>'
            . 'Your email is verified, but delivery of the registration acknowledgment '
            . 'could not be confirmed. Please check your inbox or spam folder and '
            . 'contact the system administrator if it does not arrive.</div>'
        : '';
    $name = trim($applicantName) !== '' ? e($applicantName) : 'Applicant';
    return '<div class="modal fade" id="otp-verification-success-modal" tabindex="-1" '
        . 'aria-labelledby="otp-verification-success-title" aria-describedby="otp-verification-success-description" '
        . 'aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">'
        . '<div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content">'
        . '<div class="modal-header bg-success text-white">'
        . '<div><div class="small fw-semibold">DENR CARAGA FP-OMS</div>'
        . '<h2 class="h5 mb-0" id="otp-verification-success-title">'
        . '<i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i>Registration Received – Pending Validation</h2></div>'
        . '</div><div class="modal-body p-4" id="otp-verification-success-description">'
        . '<p class="text-success fw-semibold mb-2"><i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i>Email Successfully Verified!</p>'
        . '<p><strong>Greetings from the Department of Environment and Natural Resources!</strong></p>'
        . '<p>Dear <strong>' . $name . '</strong>,</p>'
        . '<p>This is to acknowledge that we have successfully received your applicant account registration submitted through the '
        . '<strong>DENR CARAGA Feasibility Permit Online Management System (FP-OMS)</strong>.</p>'
        . '<p>Please be informed that your registration is currently <strong>PENDING VALIDATION</strong> by the concerned '
        . '<strong>Forest Utilization Section (FUU)</strong>, through the assigned LPDD-FUS reviewing personnel. '
        . 'Your submitted information and supporting documents will be checked for completeness, accuracy, and compliance '
        . 'with applicable registration requirements.</p>'
        . '<p>Once validation is completed, you will receive another email regarding your account status. '
        . 'If additional information, corrections, or supporting documents are required, you will be notified accordingly.</p>'
        . '<p><strong>Successful registration does not automatically constitute account approval or issuance of a '
        . 'Feasibility Permit.</strong> You may sign in to FP-OMS to monitor your registration status.</p>'
        . '<p>Thank you for using DENR CARAGA FP-OMS. We appreciate your cooperation and commitment to '
        . 'responsible environmental management.</p>'
        . '<p class="mb-1">Best regards,</p>'
        . '<p class="mb-0"><strong>DENR CARAGA – Regional Office XIII</strong><br>'
        . 'Feasibility Permit Online Management System (FP-OMS)<br>'
        . 'Department of Environment and Natural Resources</p>'
        . $warning
        . '</div><div class="modal-footer">'
        . '<button class="btn btn-primary w-100" type="button" data-bs-dismiss="modal">Continue to Login</button>'
        . '</div></div></div></div>';
}

function view_login(array $errors = []): void
{
    if (current_user()) {
        $loggedIn = current_user();
        $nextPage = (string)($_GET['next'] ?? '');
        if (in_array($nextPage, ['review-queue','permit-initial-queue'], true)
            && has_role($loggedIn, 'lpdd_fus')) go(str_replace('-', '_', $nextPage));
        if ($nextPage === 'permit-ro-inbox' && has_role($loggedIn, 'ro_receiving')) go('permit_ro_inbox');
        if ($nextPage === 'permit-applications' && $loggedIn['user_kind'] === 'applicant'
            && $loggedIn['status'] === 'active') go('permit_applications');
        go('dashboard');
    }
    $requestedNext = (string)($_GET['next'] ?? $_POST['next'] ?? '');
    $next = in_array($requestedNext,
        ['review-queue','permit-initial-queue','permit-ro-inbox','permit-applications'], true)
        ? $requestedNext : '';
    $hint = (string)($_GET['hint'] ?? $_POST['hint'] ?? '');
    $prefilledEmail = reviewer_login_hint_email($hint);
    if ($prefilledEmail === null) $hint = '';
    $html = '<div class="row justify-content-center"><div class="col-md-7 col-lg-5"><div class="panel">'
        . errors_html($errors) . '<p class="text-secondary">Sign in to your applicant or DENR internal account.</p>'
        . '<form method="post" action="' . url('login') . '">' . csrf_field()
        . ($next !== '' ? '<input type="hidden" name="next" value="' . e($next) . '">' : '')
        . ($hint !== '' ? '<input type="hidden" name="hint" value="' . e($hint) . '">' : '')
        . ($prefilledEmail !== null ? field('email','Email',$prefilledEmail,'email',true,'autocomplete="username" maxlength="190" readonly') : field('email','Email',form_value('email'),'email',true,'autocomplete="username" maxlength="190"'))
        . field('password','Password','','password',true,'autocomplete="current-password"')
        . '<button class="btn btn-primary w-100" type="submit">Sign in</button>'
        . '<div class="text-end mt-2"><a class="small fw-semibold" href="' . url('forgot_password') . '">Forgot password?</a></div></form>'
        . '<hr><p class="mb-0 small">New applicant? <a href="' . url('register') . '">Register for an account</a>.</p>'
        . (initial_admin_setup_available()
            ? '<div class="alert alert-warning small mt-3 mb-0"><strong>Initial RICTU setup:</strong> No Super Administrator exists yet. <a href="' . url('setup_admin') . '">Create the first Super Administrator</a>.</div>'
            : '')
        . '<div class="install-app-card"><div class="install-copy"><span class="install-icon"><i class="bi bi-download"></i></span>'
        . '<div><strong>Install DENR FP-OMS</strong><small>Use FP-OMS like a desktop or mobile app</small></div></div>'
        . '<button type="button" id="installAppButton" class="btn btn-sm btn-outline-primary"><i class="bi bi-display me-1"></i>Install</button></div>'
        . '<p id="installAppHelp" class="install-help" role="status" aria-live="polite"></p>'
        . '</div></div></div>';
    // Consume the success state once so refreshing /login never reopens it.
    $verificationModal = $_SESSION['registration_verified_modal'] ?? null;
    unset($_SESSION['registration_verified_modal']);
    if (is_array($verificationModal)) {
        $html .= registration_verified_modal_html(
            !empty($verificationModal['ack_delivery_failed']),
            (string)($verificationModal['applicant_name'] ?? '')
        );
    }
    render_page('Sign In', $html);
}

function status_badge(string $status): string
{
    $colors = ['pending_email_verification'=>'info','pending_review'=>'warning','needs_correction'=>'danger','active'=>'success','suspended'=>'secondary'];
    return '<span class="badge text-bg-' . ($colors[$status] ?? 'secondary') . '">' . e(status_label($status)) . '</span>';
}

/**
 * Applicant-dashboard-only Annex 1 checklist. Uses the exact same requirement
 * names, icons, notes and conditional timing as the public landing page.
 * This is an informational checklist, not an uploaded/approved-documents state.
 */
function applicant_dashboard_requirements_html(bool $canSubmit): string
{
    $html = '<section class="panel applicant-fp-requirements mt-4" '
        . 'id="applicant-fp-requirements" aria-labelledby="applicant-fp-requirements-title">'
        . '<div class="applicant-fp-requirements-header">'
        . '<div><span class="applicant-fp-requirements-kicker">ANNEX 1 · DOCUMENTARY CHECKLIST</span>'
        . '<h2 class="h5 mt-1 mb-2" id="applicant-fp-requirements-title">'
        . 'Requirements for Feasibility Permit</h2>'
        . '<p class="small text-secondary mb-0">Review the ten Annex 1 requirements '
        . 'before submitting your Feasibility Permit application. '
        . 'Read the remarks carefully for requirements submitted during account registration '
        . 'or after the relevant evaluation or permit issuance.</p></div>'
        . '<span class="badge text-bg-success applicant-fp-requirements-count">'
        . '<i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>10 Requirements</span></div>'
        . '<div class="applicant-fp-requirements-notice" role="note">'
        . '<i class="bi bi-info-circle" aria-hidden="true"></i>'
        . '<span>Items below describe Annex 1 documentary requirements, '
        . 'not a record of documents already uploaded or approved. '
        . 'Applicable items are subject to LPDD-FUS review and DENR instructions.</span></div>'
        . landing_fp_requirements_html()
        . '<div class="applicant-fp-requirements-footer">'
        . '<p class="small text-secondary mb-0"><strong>Important:</strong> '
        . 'Proof of payment of the application fee is requested after Technical Staff '
        . 'evaluation of documentary completeness. The ECC/CNC and NCIP Clearance '
        . 'post-issuance deadlines are stated in the checklist above.</p>';
    if ($canSubmit) {
        $html .= '<a class="btn btn-primary btn-sm" href="' . e(url('permit_applications')) . '">'
            . '<i class="bi bi-folder2-open me-1" aria-hidden="true"></i>'
            . 'My FP Applications</a>';
    }
    return $html . '</div></section>';
}

/**
 * Compose the applicant dashboard independently from the full My Registration page.
 * The Annex 1 requirements are always the first panel; approved applicants
 * see only a brief account-activation confirmation instead of registration records.
 */
function applicant_dashboard_content_html(string $status, string $registrationHtml): string
{
    $html = applicant_dashboard_requirements_html($status === 'active');
    if ($status !== 'active') {
        return $html . $registrationHtml;
    }

    return $html . '<section class="panel mt-4" aria-labelledby="applicant-activation-title">'
        . '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">'
        . '<h2 class="h5 m-0" id="applicant-activation-title">'
        . '<i class="bi bi-patch-check-fill text-success me-2" aria-hidden="true"></i>'
        . 'Account Activated</h2>' . status_badge('active') . '</div>'
        . '<p class="text-secondary mb-3">Your account has been activated. '
        . 'You may now submit Feasibility Permit applications for LPDD-FUS initial review. '
        . 'Your complete registration record is still available under My Registration.</p>'
        . '<a class="btn btn-outline-primary btn-sm" href="' . e(url('my_registration')) . '">'
        . '<i class="bi bi-person-vcard me-1" aria-hidden="true"></i>'
        . 'View My Registration</a></section>';
}

function view_applicant_registration(array $user, array $errors = [], bool $showDashboardChecklist = false): void
{
    require_kind($user, 'applicant');
    $reg = registration_for_user((int)$user['id']);
    if (!$reg) show_error('Applicant registration data was not found.', 404);
    if ($showDashboardChecklist && ($user['status'] ?? '') === 'active') {
        render_page('Applicant Dashboard', applicant_dashboard_content_html('active', ''));
        return;
    }
    $latest = latest_review((int)$reg['id']);
    $html = '<div class="row g-4"><div class="col-lg-8"><div class="panel">' . errors_html($errors)
        . '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3"><h2 class="h5 m-0">Registration Details</h2>' . status_badge($user['status']) . '</div>'
        . '<dl class="row details"><dt class="col-sm-4">Applicant</dt><dd class="col-sm-8">' . e($reg['representative_name']) . '</dd>'
        . '<dt class="col-sm-4">Company</dt><dd class="col-sm-8">' . e($reg['company_name']) . '</dd>'
        . '<dt class="col-sm-4">Email</dt><dd class="col-sm-8">' . e($user['email']) . '</dd>'
        . '<dt class="col-sm-4">Contact</dt><dd class="col-sm-8">' . e($reg['contact_number']) . '</dd>'
        . '<dt class="col-sm-4">Revision</dt><dd class="col-sm-8">' . (int)$reg['revision'] . '</dd>'
        . '<dt class="col-sm-4">Last submitted</dt><dd class="col-sm-8">' . e($reg['submitted_at']) . '</dd></dl>';
    if ($latest) $html .= '<div class="alert ' . ($latest['decision'] === 'approved' ? 'alert-success' : 'alert-warning') . '"><strong>LPDD-FUS decision:</strong> ' . e(ucwords(str_replace('_',' ',$latest['decision'])))
        . '<div class="small mt-1">' . nl2br(e($latest['remarks'] ?: 'No additional remarks.')) . '</div></div>';
    $docs = registration_documents((int)$reg['id']);
    $html .= '<h3 class="h6">Submitted documents</h3>' . registration_documents_html($docs);
    if ($user['status'] === 'active') {
        $html .= '<div class="alert alert-success mb-0"><strong>Your account is activated.</strong> '
            . 'You may now submit an FP application and supporting documents for LPDD-FUS initial review. '
            . '<a class="btn btn-sm btn-success ms-2" href="' . e(url('permit_applications'))
            . '">My FP Applications</a></div>';
    } elseif ($user['status'] === 'pending_review') {
        $html .= '<div class="alert alert-info mb-0">Your registration is awaiting DENR CARAGA LPDD-FUS review. Log back in to check its status.</div>';
    }
    $html .= '</div></div><div class="col-lg-4"><div class="panel soft"><h3 class="h6">Processing note</h3><p class="small mb-0">Registration and activation are excluded from the 15-working-day FP permit-processing period.</p></div></div></div>';
    if ($user['status'] === 'needs_correction') {
        $html .= '<div class="panel mt-4"><h2 class="h5">Submit Corrected Registration</h2><p class="text-secondary">Edit your information and optionally add new IDs or supporting documents. Earlier uploads remain on file for review history.</p>'
            . '<form action="' . url('registration_update') . '" method="post" enctype="multipart/form-data">' . csrf_field()
            . registration_fields($reg, false)
            . '<div class="mt-4"><button class="btn btn-primary" type="submit">Resubmit for LPDD-FUS Review</button></div></form></div>'
            . '<script src="assets/registration-attachments.js?v=7" defer></script>';
    }
    if ($showDashboardChecklist) {
        $html = applicant_dashboard_content_html((string)($user['status'] ?? ''), $html);
    }
    render_page($showDashboardChecklist ? 'Applicant Dashboard' : 'My Registration', $html);
}

function view_review_queue(): void
{
    require_role('lpdd_fus');
    $queue = query_all("SELECT ar.id,ar.company_name,ar.representative_name,ar.submitted_at,ar.revision,u.email,u.status FROM applicant_registrations ar JOIN user_client u ON u.id=ar.client_id WHERE u.status='pending_review' ORDER BY ar.submitted_at ASC, ar.id ASC LIMIT 200");
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
    $reg = query_one('SELECT ar.*,u.email,u.status,u.activated_at FROM applicant_registrations ar JOIN user_client u ON u.id=ar.client_id WHERE ar.id=?',[$id]);
    if (!$reg) show_error('Registration not found.', 404);
    $html = '<div class="mb-3"><a href="' . url('review_queue') . '">← Back to review queue</a></div><div class="row g-4"><div class="col-lg-7"><div class="panel">' . errors_html($errors)
        . '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h2 class="h5">Registration #' . (int)$reg['id'] . '</h2>' . status_badge($reg['status']) . '</div>'
        . '<dl class="row details"><dt class="col-sm-4">Applicant</dt><dd class="col-sm-8">' . e($reg['representative_name']) . '</dd>'
        . '<dt class="col-sm-4">Position</dt><dd class="col-sm-8">' . e($reg['representative_position'] ?? '') . '</dd>'
        . '<dt class="col-sm-4">Company</dt><dd class="col-sm-8">' . e($reg['company_name']) . '</dd>'
        . '<dt class="col-sm-4">Company address</dt><dd class="col-sm-8">' . nl2br(e($reg['company_address'])) . '</dd>'
        . '<dt class="col-sm-4">Email</dt><dd class="col-sm-8">' . e($reg['email']) . '</dd>'
        . '<dt class="col-sm-4">Contact</dt><dd class="col-sm-8">' . e($reg['contact_number']) . '</dd>'
        . '<dt class="col-sm-4">Revision</dt><dd class="col-sm-8">' . (int)$reg['revision'] . '</dd></dl>'
        . '<h3 class="h6 mt-3">Submitted attachments and revisions</h3>'
        . registration_documents_html(registration_documents($id))
        . '</div></div><div class="col-lg-5"><div class="panel soft"><h2 class="h5">LPDD-FUS Decision</h2>';
    if ($reg['status'] === 'pending_review') {
        $html .= '<form method="post" action="' . e(url('review',['id'=>$id])) . '" '
            . 'data-decision-confirm-form data-confirm-kind="registration" '
            . 'data-confirm-reference="Registration #' . (int)$id . '" '
            . 'data-confirm-applicant="' . e((string)$reg['representative_name']) . '">'
            . csrf_field()
            . '<input type="hidden" name="decision" value="" data-confirm-decision-value>'
            . '<label class="form-label" for="remarks">Remarks / Corrections to address</label>'
            . '<textarea class="form-control mb-2" name="remarks" id="remarks" rows="5" maxlength="5000" '
            . 'placeholder="Required if returning for correction">'
            . e((string)($_POST['remarks'] ?? '')) . '</textarea>'
            . '<div class="alert alert-warning small d-none mb-3" role="alert" '
            . 'data-decision-confirm-validation></div>'
            . '<div class="d-flex gap-2 flex-wrap">'
            . '<button class="btn btn-success" type="button" data-confirm-decision="approved">'
            . 'Approve &amp; Activate</button>'
            . '<button class="btn btn-outline-danger" type="button" data-confirm-decision="needs_correction">'
            . 'Return for Correction</button></div>'
            . '<noscript><p class="alert alert-warning small mt-3 mb-0">'
            . 'Enable JavaScript to confirm and submit a review decision.</p></noscript>'
            . '</form>';
    } else $html .= '<p class="text-secondary mb-0">This registration is no longer awaiting review.</p>';
    $html .= '</div><div class="panel mt-3"><h3 class="h6">Previous review decisions</h3>';
    $history = query_all('SELECT rr.*,u.name AS reviewer FROM registration_reviews rr JOIN users u ON u.id=rr.reviewer_user_id WHERE rr.registration_id=? ORDER BY rr.id DESC',[$id]);
    if (!$history) $html .= '<p class="text-secondary small mb-0">No earlier reviews.</p>';
    foreach ($history as $event) $html .= '<div class="history"><strong>' . e(ucwords(str_replace('_',' ',$event['decision']))) . '</strong> · Revision ' . (int)$event['revision']
        . '<div class="small text-secondary">' . e($event['reviewer']) . ' · ' . e($event['reviewed_at']) . '</div><p class="small mb-0">' . nl2br(e($event['remarks'] ?? '')) . '</p></div>';
    $html .= '</div></div></div>'
        . ($reg['status'] === 'pending_review' ? decision_confirmation_modal_html() : '');
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
    $html = '<div class="row justify-content-center"><div class="col-md-9 col-lg-8"><div class="panel">'
        . errors_html($errors)
        . ($user['must_change_password']
            ? '<div class="alert alert-warning">Change your initial password to continue.</div>' : '')
        . '<p class="text-secondary small">Use a secure new password that follows the requirements below. '
        . 'Your current password is required to confirm this change.</p>'
        . '<form id="change-password-form" method="post" action="' . url('change_password') . '">' . csrf_field()
        . field('current_password','Current password','','password',true,
            'autocomplete="current-password"')
        . '<div class="row g-3 mb-3"><div class="col-md-6">'
        . '<label class="form-label" for="new_password">New password <span class="text-danger">*</span></label>'
        . '<div class="input-group"><input class="form-control" id="new_password" name="new_password" '
        . 'type="password" required autocomplete="new-password" minlength="12" maxlength="200" '
        . 'aria-describedby="change-password-rules change-password-feedback">'
        . '<button class="btn btn-outline-secondary" type="button" data-change-password-toggle="new_password" '
        . 'aria-controls="new_password" aria-pressed="false" aria-label="Show password" title="Show password">'
        . '<i class="bi bi-eye" aria-hidden="true"></i></button></div>'
        . '<div class="small fw-semibold mt-3 mb-1">Password requirements</div>'
        . '<ul class="list-unstyled small mb-0" id="change-password-rules" aria-label="Password validation requirements">'
        . '<li class="text-secondary mb-1" data-change-password-rule="minimum">'
        . '<i class="bi bi-circle me-2" aria-hidden="true"></i>At least 12 characters '
        . '<span class="visually-hidden password-rule-status">Not met</span></li>'
        . '<li class="text-secondary mb-1" data-change-password-rule="letter">'
        . '<i class="bi bi-circle me-2" aria-hidden="true"></i>At least one letter (A–Z) '
        . '<span class="visually-hidden password-rule-status">Not met</span></li>'
        . '<li class="text-secondary mb-1" data-change-password-rule="number">'
        . '<i class="bi bi-circle me-2" aria-hidden="true"></i>At least one number (0–9) '
        . '<span class="visually-hidden password-rule-status">Not met</span></li>'
        . '<li class="text-secondary mb-1" data-change-password-rule="maximum">'
        . '<i class="bi bi-circle me-2" aria-hidden="true"></i>Maximum 200 characters '
        . '<span class="visually-hidden password-rule-status">Not met</span></li>'
        . '<li class="text-secondary mb-1" data-change-password-rule="different">'
        . '<i class="bi bi-circle me-2" aria-hidden="true"></i>Different from current password '
        . '<span class="visually-hidden password-rule-status">Not met</span></li>'
        . '</ul><div id="change-password-feedback" class="small text-secondary mt-2" '
        . 'role="status" aria-live="polite">Enter a new password to check its requirements.</div>'
        . '</div><div class="col-md-6">'
        . '<label class="form-label" for="confirm_password">Confirm new password <span class="text-danger">*</span></label>'
        . '<div class="input-group"><input class="form-control" id="confirm_password" name="confirm_password" '
        . 'type="password" required autocomplete="new-password" minlength="12" maxlength="200" '
        . 'aria-describedby="change-confirm-feedback">'
        . '<button class="btn btn-outline-secondary" type="button" data-change-password-toggle="confirm_password" '
        . 'aria-controls="confirm_password" aria-pressed="false" aria-label="Show password" title="Show password">'
        . '<i class="bi bi-eye" aria-hidden="true"></i></button></div>'
        . '<div id="change-confirm-feedback" class="small text-secondary mt-2" '
        . 'role="status" aria-live="polite">Re-enter your new password to confirm.</div>'
        . '</div></div>'
        . '<button class="btn btn-primary" type="submit">Update Password</button></form>'
        . '</div></div></div>'
        . '<script src="assets/change-password.js?v=1" defer></script>';
    render_page('Change Password', $html);
}

function view_dashboard(): void
{
    $user = require_user();
    if ($user['user_kind'] === 'applicant') {
        view_applicant_registration($user, [], true);
        return;
    }
    $roles = user_roles((int)$user['id']);
    $rolesText = implode(', ', array_map(static fn(string $r): string => role_definitions()[$r][0] ?? $r, $roles));
    $html = '<div class="panel"><div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">'
        . '<div><h2 class="h5 mb-1">Welcome, ' . e($user['name']) . '</h2><p class="text-secondary mb-0">Assigned: ' . e($rolesText) . '</p></div>'
        . status_badge($user['status']) . '</div><hr><div class="row g-3">';
    if (has_role($user,'lpdd_fus')) {
        $pending = query_one("SELECT COUNT(*) AS n FROM user_client WHERE user_kind='applicant' AND status='pending_review'");
        $html .= '<div class="col-md-6"><a class="quick-action" href="' . url('review_queue') . '"><strong>Applicant Reviews</strong><span class="metric">' . (int)$pending['n'] . '</span><small>Awaiting LPDD-FUS action</small></a></div>';
    }
    if (step2_schema_ready() && has_role($user,'lpdd_fus')) {
        $pendingFp = query_one("SELECT COUNT(*) AS n FROM permit_step2_applications WHERE status='submitted'");
        $html .= '<div class="col-md-6"><a class="quick-action" href="' . e(url('permit_initial_queue'))
            . '"><strong>FP Initial Reviews – Step 2</strong><span class="metric">'
            . (int)($pendingFp['n'] ?? 0) . '</span><small>Awaiting initial documentary completeness check</small></a></div>';
    }
    if (step2_schema_ready() && has_role($user,'ro_receiving')) {
        $forwardedFp = query_one("SELECT COUNT(*) AS n FROM permit_step2_applications WHERE status='forwarded_to_ro'");
        $html .= '<div class="col-md-6"><a class="quick-action" href="' . e(url('permit_ro_inbox'))
            . '"><strong>RO Receiving Inbox</strong><span class="metric">'
            . (int)($forwardedFp['n'] ?? 0) . '</span><small>Applications forwarded after LPDD-FUS initial review</small></a></div>';
    }
    if (has_role($user,'super_admin')) $html .= '<div class="col-md-6"><a class="quick-action" href="' . url('user_management') . '"><strong>User Management</strong><span class="metric">RICTU</span><small>Create role-assigned accounts</small></a></div>';
    $html .= '</div><div class="alert alert-info mt-3 mb-0">Step 2 FP application submission, initial LPDD-FUS review and RO handoff are implemented. Formal receiving and document evaluation through permit release (Steps 3–11) remain future development.</div></div>';
    render_page('Dashboard', $html);
}
