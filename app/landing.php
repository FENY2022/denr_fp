<?php
declare(strict_types=1);

function view_landing(): void
{
    $user = current_user();
    $submission = ($_GET['registration'] ?? '') === 'success'
        && isset($_SESSION['registration_submission'])
        && is_array($_SESSION['registration_submission'])
        ? $_SESSION['registration_submission'] : null;
    if ($submission !== null) unset($_SESSION['registration_submission']);
    $isLoggedIn = is_array($user);
    $primaryHref = $isLoggedIn ? url('dashboard') : url('register');
    $primaryLabel = $isLoggedIn ? 'Go to Dashboard' : 'Apply / Register';
    $secondaryHref = $isLoggedIn ? url('my_registration') : url('login');
    $secondaryLabel = $isLoggedIn && ($user['user_kind'] ?? '') === 'applicant' ? 'My Registration' : ($isLoggedIn ? 'Open Account' : 'Sign In');
    if ($isLoggedIn && ($user['user_kind'] ?? '') !== 'applicant') {
        $secondaryHref = url('dashboard');
        $secondaryLabel = 'Open Workspace';
    }
    $userName = $isLoggedIn ? e((string)($user['name'] ?? 'User')) : '';
    $appName = e(env_value('APP_NAME', 'DENR FP-OMS'));

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="description" content="DENR Caraga Region XIII Feasibility Permit Online Management System">'
        . '<meta name="theme-color" content="#075c32">'
        . '<title>Feasibility Permit Online Management System | DENR Caraga</title>'
        . '<link rel="icon" href="assets/fpoms-icon.svg" type="image/svg+xml">'
        . '<link rel="manifest" href="manifest.webmanifest">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
        . '<link href="assets/style.css" rel="stylesheet">'
        . '<link href="assets/landing.css?v=2" rel="stylesheet">'
        . '<script src="assets/app-shell.js" defer></script></head><body class="landing-body">';

    echo '<nav class="navbar navbar-expand-lg landing-nav sticky-top"><div class="container-xl">'
        . '<a class="navbar-brand landing-brand" href="' . e(url('landing')) . '">'
        . '<span class="landing-brand-mark"><img src="assets/fpoms-icon.svg" alt="DENR FP-OMS"></span>'
        . '<span><strong>DENR FP-OMS</strong><small>Caraga Region XIII</small></span></a>'
        . '<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#landingNav" aria-controls="landingNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>'
        . '<div class="collapse navbar-collapse" id="landingNav"><ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">'
        . '<li class="nav-item"><a class="nav-link active" href="#home">Home</a></li>'
        . '<li class="nav-item"><a class="nav-link" href="#about">About</a></li>'
        . '<li class="nav-item"><a class="nav-link" href="#process">Process</a></li>'
        . '<li class="nav-item"><a class="nav-link" href="#requirements">Requirements</a></li>'
        . '<li class="nav-item"><a class="nav-link" href="#users">Users</a></li>'
        . '<li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>'
        . '</ul><div class="landing-nav-actions ms-lg-3">'
        . ($isLoggedIn
            ? '<span class="landing-user-welcome d-none d-xl-inline">Hi, ' . $userName . '</span><a class="btn btn-landing-primary btn-sm" href="' . e(url('dashboard')) . '"><i class="bi bi-grid me-1"></i> Dashboard</a>'
            : '<a class="btn btn-link landing-signin" href="' . e(url('login')) . '">Sign In</a><a class="btn btn-landing-primary btn-sm" href="' . e(url('register')) . '">Apply / Register</a>')
        . '</div></div></div></nav>';

    echo '<main><section class="landing-hero" id="home"><div class="hero-grid"></div><div class="container-xl position-relative"><div class="row align-items-center g-5">'
        . '<div class="col-lg-7"><div class="hero-kicker"><i class="bi bi-tree-fill"></i> Department of Environment and Natural Resources · Caraga Region XIII</div>'
        . '<h1>Feasibility Permit <span>Online Management System</span></h1>'
        . '<p class="hero-lead">A secure digital platform for applicant registration, document submission, review, field validation, deliberation, approval, tracking, and release of Feasibility Permits for qualified energy projects within forestlands.</p>'
        . '<div class="hero-actions"><a class="btn btn-hero-primary btn-lg" href="' . e($primaryHref) . '"><i class="bi bi-file-earmark-check me-2"></i>' . e($primaryLabel) . '</a>'
        . '<a class="btn btn-hero-secondary btn-lg" href="' . e($secondaryHref) . '"><i class="bi bi-box-arrow-in-right me-2"></i>' . e($secondaryLabel) . '</a></div>'
        . '<div class="hero-trust"><span><i class="bi bi-shield-check"></i> Role-based access</span><span><i class="bi bi-clock-history"></i> Application tracking</span><span><i class="bi bi-file-earmark-lock"></i> Controlled records</span></div></div>'
        . '<div class="col-lg-5"><div class="hero-visual"><div class="hero-visual-head"><span class="hero-mini-logo"><img src="assets/fpoms-icon.svg" alt=""></span><div><strong>DENR FP-OMS</strong><small>Permit Processing Workspace</small></div><span class="status-live"><i class="bi bi-circle-fill"></i> Online</span></div>'
        . '<div class="hero-progress-card"><div class="hero-progress-title"><span>Permit Processing Flow</span><span>Digital</span></div>'
        . '<div class="flow-line"><span class="flow-dot active"><i class="bi bi-person-plus"></i></span><div><strong>Applicant Registration</strong><small>Create and validate account</small></div></div>'
        . '<div class="flow-line"><span class="flow-dot active"><i class="bi bi-files"></i></span><div><strong>Document Review</strong><small>Check completeness and sufficiency</small></div></div>'
        . '<div class="flow-line"><span class="flow-dot"><i class="bi bi-geo-alt"></i></span><div><strong>Field Validation</strong><small>CENRO/TWG assessment</small></div></div>'
        . '<div class="flow-line"><span class="flow-dot"><i class="bi bi-people"></i></span><div><strong>Review & Approval</strong><small>Regional evaluation and decision</small></div></div>'
        . '<div class="flow-line mb-0"><span class="flow-dot"><i class="bi bi-patch-check"></i></span><div><strong>Permit Release</strong><small>Approved FP and records</small></div></div></div>'
        . '<div class="install-app-card landing-install"><div class="install-copy"><span class="install-icon"><i class="bi bi-download"></i></span><div><strong>Install DENR FP-OMS</strong><small>Use it like an app on supported devices</small></div></div><button type="button" id="installAppButton" class="btn btn-sm btn-outline-light"><i class="bi bi-display me-1"></i>Install</button></div><p id="installAppHelp" class="landing-install-help" role="status" aria-live="polite"></p>'
        . '</div></div></div></section>';

    echo '<section class="landing-section" id="about"><div class="container-xl"><div class="section-heading text-center"><span class="section-kicker">ABOUT THE SYSTEM</span><h2>One digital workspace for Feasibility Permit processing</h2><p>FP-OMS organizes the permit journey from applicant onboarding to DENR review and final release while keeping actions traceable by user role.</p></div>'
        . '<div class="row g-4 mt-2"><div class="col-md-4"><article class="feature-card"><span class="feature-icon"><i class="bi bi-person-vcard"></i></span><h3>Applicant Portal</h3><p>Register once, submit required information and documents, receive deficiency notices, and monitor application progress from one account.</p></article></div>'
        . '<div class="col-md-4"><article class="feature-card"><span class="feature-icon blue"><i class="bi bi-diagram-3"></i></span><h3>Guided Workflow</h3><p>Routes each application through the responsible DENR offices and review stages based on the approved Feasibility Permit process.</p></article></div>'
        . '<div class="col-md-4"><article class="feature-card"><span class="feature-icon"><i class="bi bi-journal-check"></i></span><h3>Accountability</h3><p>Role-based access, review history, and controlled document handling help maintain a clear record of processing actions.</p></article></div></div></div></section>';

    echo '<section class="landing-section section-soft" id="process"><div class="container-xl"><div class="section-heading"><span class="section-kicker">PROCESS OVERVIEW</span><h2>From registration to permit release</h2><p>The online workflow follows the official DENR processing stages while keeping the applicant informed as the application advances.</p></div>'
        . '<div class="process-grid">'
        . process_card('01','person-plus','Register','Create an applicant account and submit registration information for validation.')
        . process_card('02','file-earmark-arrow-up','Submit','Upload the Feasibility Permit application and prescribed supporting documents.')
        . process_card('03','clipboard-check','Evaluate','LPDD-FUS reviews documentary completeness and sufficiency and coordinates required actions.')
        . process_card('04','geo-alt','Validate','The concerned field office conducts field validation and assessment and submits technical outputs.')
        . process_card('05','people','Deliberate','The Regional Review and Deliberation Committee reviews the Feasibility Study Proposal.')
        . process_card('06','patch-check','Approve & Release','The authorized approving official acts on the permit and the approved records are released and archived.')
        . '</div></div></section>';

    echo '<section class="landing-section" id="requirements"><div class="container-xl"><div class="row g-5 align-items-center"><div class="col-lg-5"><div class="section-heading mb-0"><span class="section-kicker">APPLICATION PREPARATION</span><h2>Prepare the core application documents</h2><p>The system will guide applicants through the documentary requirements defined by DENR policy and the prescribed Feasibility Permit templates.</p></div>'
        . '<div class="requirement-note"><i class="bi bi-info-circle"></i><span>Final documentary acceptance remains subject to current DENR rules, evaluation, and official instructions.</span></div></div>'
        . '<div class="col-lg-7"><div class="requirements-card"><div class="requirement-item"><i class="bi bi-envelope-paper"></i><div><strong>Letter of Intent</strong><small>Signed by the company representative.</small></div></div>'
        . '<div class="requirement-item"><i class="bi bi-file-earmark-text"></i><div><strong>Application Form</strong><small>Properly accomplished and duly notarized.</small></div></div>'
        . '<div class="requirement-item"><i class="bi bi-receipt"></i><div><strong>Application Fee</strong><small>Proof of payment in accordance with the applicable FP guidelines.</small></div></div>'
        . '<div class="requirement-item"><i class="bi bi-building-check"></i><div><strong>Company Registration & Authority</strong><small>SEC/CDA registration and authority of the representative to transact with DENR.</small></div></div>'
        . '<div class="requirement-item"><i class="bi bi-journal-text"></i><div><strong>Feasibility Study Proposal</strong><small>Prepared using the prescribed DENR template.</small></div></div>'
        . '<div class="requirement-item"><i class="bi bi-lightning-charge"></i><div><strong>DOE Authority</strong><small>Applicable Energy Service Contract or Certificate of Authority.</small></div></div>'
        . '</div></div></div></div></section>';

    echo '<section class="landing-section section-soft" id="users"><div class="container-xl"><div class="section-heading text-center"><span class="section-kicker">SYSTEM USERS</span><h2>Built for applicants and DENR processing offices</h2><p>Each group sees only the functions needed for its responsibility in the Feasibility Permit workflow.</p></div>'
        . '<div class="user-role-grid"><div class="role-card"><i class="bi bi-person-workspace"></i><strong>Applicants</strong><span>Registration, submission, corrections, and status tracking</span></div>'
        . '<div class="role-card"><i class="bi bi-geo"></i><strong>CENRO / TWG</strong><span>Field validation, assessment, maps, shapefiles, and technical outputs</span></div>'
        . '<div class="role-card"><i class="bi bi-building"></i><strong>LPDD-FUS</strong><span>Completeness review, evaluation, drafting, and workflow processing</span></div>'
        . '<div class="role-card"><i class="bi bi-person-check"></i><strong>LPDD Chief & ARD-TS</strong><span>Review, initials, endorsement, and technical supervision</span></div>'
        . '<div class="role-card"><i class="bi bi-people-fill"></i><strong>RRDC</strong><span>Regional review and deliberation of the Feasibility Study Proposal</span></div>'
        . '<div class="role-card"><i class="bi bi-patch-check-fill"></i><strong>RED & Records</strong><span>Final approval, releasing, and archiving of approved permit records</span></div>'
        . '<div class="role-card"><i class="bi bi-shield-lock"></i><strong>RICTU</strong><span>System administration, user access, permissions, and maintenance</span></div></div></div></section>';

    echo '<section class="landing-cta"><div class="container-xl"><div class="cta-panel"><div><span class="section-kicker light">GET STARTED</span><h2>' . ($isLoggedIn ? 'Continue working in DENR FP-OMS.' : 'Ready to start your Feasibility Permit application?') . '</h2><p>' . ($isLoggedIn ? 'Open your dashboard to continue your assigned workflow.' : 'Create your applicant account and begin with the registration and account-validation step.') . '</p></div>'
        . '<div class="cta-actions"><a class="btn btn-light btn-lg" href="' . e($primaryHref) . '">' . e($primaryLabel) . ' <i class="bi bi-arrow-right ms-1"></i></a>'
        . (!$isLoggedIn ? '<a class="btn btn-outline-light btn-lg" href="' . e(url('login')) . '">Existing user? Sign in</a>' : '') . '</div></div></div></section></main>';

    echo '<footer class="landing-footer" id="contact"><div class="container-xl"><div class="row g-4"><div class="col-lg-5"><div class="footer-brand"><span class="landing-brand-mark"><img src="assets/fpoms-icon.svg" alt=""></span><div><strong>' . $appName . '</strong><small>Feasibility Permit Online Management System</small></div></div><p>Department of Environment and Natural Resources<br>Caraga Region XIII</p></div>'
        . '<div class="col-sm-6 col-lg-3"><h3>Office</h3><p>DENR-XIII<br>Ambago, Butuan City, Philippines</p></div>'
        . '<div class="col-sm-6 col-lg-4"><h3>Contact</h3><p><i class="bi bi-telephone me-2"></i>0981-017-3551<br><i class="bi bi-envelope me-2"></i>r13@denr.gov.ph</p></div></div>'
        . '<div class="footer-bottom"><span>&copy; ' . date('Y') . ' DENR Caraga Region XIII. All rights reserved.</span><span>FP-OMS · Digital Permit Processing</span></div></div></footer>';

    if ($submission !== null) {
        $email = e((string)($submission['email'] ?? 'your email address'));
        $emailSent = !empty($submission['email_sent']);
        echo '<div class="modal fade registration-success-modal" id="registration-success-modal" tabindex="-1" aria-labelledby="registration-success-title" aria-hidden="true">'
            . '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
            . '<div class="modal-body text-center"><span class="registration-success-icon"><i class="bi bi-check-lg" aria-hidden="true"></i></span>'
            . '<div class="registration-success-kicker">Registration Submitted</div>'
            . '<h2 id="registration-success-title">Check Your Email</h2>'
            . '<p>Your registration has been submitted. ' . ($emailSent
                ? 'An acknowledgment email has been sent to <strong>' . $email . '</strong>.'
                : 'Your acknowledgment email to <strong>' . $email . '</strong> may be delayed.')
            . ' LPDD-FUS will review your registration and notify you of its status.</p>'
            . '<div class="registration-success-note"><i class="bi bi-info-circle-fill" aria-hidden="true"></i><span>'
            . ($emailSent
                ? 'If you do not see the email in your inbox, please check your spam or junk folder.'
                : 'Your registration was saved successfully even though the email could not be delivered right now.')
            . '</span></div>'
            . '<a class="btn btn-primary registration-success-home" href="' . e(url('landing')) . '">Return to Home</a>'
            . '</div></div></div></div>';
    }

    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    if ($submission !== null) {
        echo '<script>try{localStorage.removeItem("fpoms.registration.form.v1")}catch(e){};'
            . 'new bootstrap.Modal(document.getElementById("registration-success-modal"),{backdrop:"static",keyboard:false}).show();</script>';
    }
    echo '</body></html>';
}

function process_card(string $number, string $icon, string $title, string $text): string
{
    return '<article class="process-card"><span class="process-number">' . e($number) . '</span><span class="process-icon"><i class="bi bi-' . e($icon) . '"></i></span><h3>' . e($title) . '</h3><p>' . e($text) . '</p></article>';
}
