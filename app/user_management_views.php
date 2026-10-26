<?php
declare(strict_types=1);

/** RICTU-only pages for the consolidated internal users table. */
function user_management_role_options(string $chosen): string
{
    $html = '<option value="">Select User Role</option>';
    foreach (user_management_internal_roles() as $code => $definition) {
        $html .= '<option value="' . e($code) . '"' . ($chosen === $code ? ' selected' : '') . '>'
            . e($definition[0]) . '</option>';
    }
    return $html;
}

function user_management_tabs(string $active): string
{
    $tabs = [
        ['accounts','All Users','people'],
        ['create','Add User','person-plus']
    ];
    $html = '<nav class="nav nav-pills flex-wrap gap-2 mb-4" aria-label="User Management">';
    foreach ($tabs as [$code,$label,$icon]) {
        $link = $code === 'create' ? url('user_create') : url('user_management');
        $html .= '<a class="nav-link' . ($active === $code ? ' active' : '')
            . '" href="' . e($link) . '"><i class="bi bi-' . e($icon)
            . ' me-1" aria-hidden="true"></i>' . e($label) . '</a>';
    }
    return $html . '</nav>';
}

function user_management_status_badge(string $status): string
{
    $colors = ['active'=>'success','suspended'=>'secondary','pending_setup'=>'warning'];
    $labels = ['active'=>'Active','suspended'=>'Suspended','pending_setup'=>'Pending Setup'];
    return '<span class="badge text-bg-' . ($colors[$status] ?? 'secondary')
        . '">' . e($labels[$status] ?? $status) . '</span>';
}

function user_management_field(
    string $name, string $label, string $value, bool $required = false,
    string $extra = '', string $type = 'text'
): string {
    return '<div class="mb-3"><label class="form-label" for="' . e($name) . '">'
        . e($label) . ($required ? ' <span class="text-danger">*</span>' : '')
        . '</label><input class="form-control" type="' . e($type)
        . '" id="' . e($name) . '" name="' . e($name)
        . '" value="' . e($value) . '"' . ($required ? ' required' : '')
        . ' ' . $extra . '></div>';
}

function user_management_form_fields(array $values, bool $editing = false): string
{
    $html = '<div class="row g-3"><div class="col-md-6">'
        . user_management_field('name','Full Name',(string)($values['name'] ?? ''),true,'maxlength="180"')
        . '</div><div class="col-md-6">'
        . user_management_field('email','Individual Login Email',(string)($values['email'] ?? ''),true,
            'maxlength="190" autocomplete="off"','email')
        . '</div><div class="col-md-6">'
        . user_management_field('contact_number','Contact Number',(string)($values['contact_number'] ?? ''),
            false,'maxlength="40"','tel')
        . '</div><div class="col-md-6">'
        . user_management_field('office_station','Office / Station',(string)($values['office_station'] ?? ''),
            true,'maxlength="160"')
        . '</div><div class="col-md-6"><label class="form-label" for="role">User Role <span class="text-danger">*</span></label>'
        . '<select class="form-select" name="role" id="role" required>'
        . user_management_role_options((string)($values['role'] ?? '')) . '</select></div>';
    if ($editing) {
        $status = (string)($values['status'] ?? 'active');
        $html .= '<div class="col-md-6"><label class="form-label" for="status">Account Status</label>'
            . '<select class="form-select" name="status" id="status">'
            . '<option value="active"' . ($status === 'active' ? ' selected' : '') . '>Active</option>'
            . '<option value="suspended"' . ($status === 'suspended' ? ' selected' : '') . '>Suspended</option>'
            . '</select></div>';
    }
    return $html . '</div>';
}

/**
 * One Bootstrap modal serves every pending staff row.
 * The browser pre-fills fields from escaped data attributes; POST is validated by
 * user_management_activate() on the server and reopens this modal if validation fails.
 */
function user_management_setup_modal(array $errors = [], int $reopenUserId = 0): string
{
    $previous = $reopenUserId > 0 && !empty($_POST)
        ? user_management_data($_POST)
        : ['name'=>'','email'=>'','contact_number'=>'','office_station'=>'','role'=>'','status'=>'active'];
    $reopenUser = $reopenUserId > 0 ? user_management_user($reopenUserId) : null;
    $reopen = $reopenUser && $reopenUser['status'] === 'pending_setup';
    $url = url('user_edit', ['id'=>$reopen ? $reopenUserId : 0]);
    $html = '<div class="modal fade user-setup-modal" id="userSetupModal" tabindex="-1" '
        . 'aria-labelledby="userSetupModalLabel" aria-hidden="true" '
        . 'data-reopen="' . ($reopen ? '1' : '0') . '">'
        . '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">'
        . '<div class="modal-content">'
        . '<div class="modal-header">'
        . '<div><h2 class="modal-title h5 mb-1" id="userSetupModalLabel">Set Up User Account</h2>'
        . '<p class="text-secondary small mb-0">Complete and verify the employee information before activation.</p></div>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
        . '</div>'
        . '<form id="userSetupForm" method="post" action="' . e($url) . '" autocomplete="off">'
        . '<div class="modal-body">' . csrf_field()
        . '<input type="hidden" name="action" value="activate">'
        . '<input type="hidden" name="setup_origin" value="user_management_modal">'
        . '<input type="hidden" name="status" value="active">'
        . ($reopen ? errors_html($errors) : '')
        . '<div class="alert alert-warning small"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>'
        . 'The employee is <strong>Pending Setup</strong> and cannot log in yet. '
        . 'Use a <strong>unique individual login email</strong>, not a shared office contact address.</div>'
        . '<div class="row g-3">'
        . '<div class="col-12 col-md-6">' . user_management_field(
            'name','Full Name',(string)$previous['name'],true,'maxlength="180" autocomplete="off"'
        ) . '</div>'
        . '<div class="col-12 col-md-6">' . user_management_field(
            'email','Individual Login Email',(string)$previous['email'],true,
            'maxlength="190" autocomplete="off" placeholder="employee@denr.gov.ph"','email'
        ) . '</div>'
        . '<div class="col-12 col-md-6">' . user_management_field(
            'contact_number','Contact Number',(string)$previous['contact_number'],
            false,'maxlength="40" placeholder="09XX-XXX-XXXX"','tel'
        ) . '</div>'
        . '<div class="col-12 col-md-6">' . user_management_field(
            'office_station','Office / Station',(string)$previous['office_station'],
            true,'maxlength="160"'
        ) . '</div>'
        . '<div class="col-12"><label class="form-label" for="role">User Role <span class="text-danger">*</span></label>'
        . '<select class="form-select" id="role" name="role" required>'
        . user_management_role_options((string)$previous['role'])
        . '</select></div></div>'
        . '<div class="user-setup-reference border rounded-3 p-3 small mt-2">'
        . '<div class="fw-semibold mb-1">Personnel reference information</div>'
        . '<div>Office contact: <span data-office-reference>'
        . e($reopen ? (string)($reopenUser['reference_email'] ?? '') : '')
        . '</span></div>'
        . '<div class="mt-1" data-personnel-notes>'
        . e($reopen ? (string)($reopenUser['designation_notes'] ?? '') : '')
        . '</div></div>'
        . '<hr><h3 class="h6">Password Setup</h3>'
        . '<div class="row g-3">'
        . '<div class="col-12 col-md-6"><label class="form-label" for="setupPassword">'
        . 'Temporary Password <span class="text-danger">*</span></label>'
        . '<div class="input-group">'
        . '<input class="form-control" id="setupPassword" name="password" type="password" '
        . 'autocomplete="new-password" minlength="12" maxlength="200" required>'
        . '<button type="button" class="btn btn-outline-secondary" data-toggle-setup-password="setupPassword" '
        . 'aria-label="Show temporary password" aria-pressed="false" title="Show password">'
        . '<i class="bi bi-eye" aria-hidden="true"></i></button></div>'
        . '<div class="form-text" data-setup-password-feedback>'
        . '12–200 characters, with at least one letter and one number.</div></div>'
        . '<div class="col-12 col-md-6"><label class="form-label" for="setupPasswordConfirm">'
        . 'Confirm Temporary Password <span class="text-danger">*</span></label>'
        . '<div class="input-group">'
        . '<input class="form-control" id="setupPasswordConfirm" name="confirm_password" type="password" '
        . 'autocomplete="new-password" minlength="12" maxlength="200" required>'
        . '<button type="button" class="btn btn-outline-secondary" data-toggle-setup-password="setupPasswordConfirm" '
        . 'aria-label="Show confirmation password" aria-pressed="false" title="Show password">'
        . '<i class="bi bi-eye" aria-hidden="true"></i></button></div>'
        . '<div class="form-text" data-setup-confirm-feedback>'
        . 'Enter the same temporary password.</div></div></div>'
        . '<div class="form-check mt-3">'
        . '<input class="form-check-input" type="checkbox" id="setupConfirmIdentity" '
        . 'name="confirm_identity" value="1" required>'
        . '<label class="form-check-label small" for="setupConfirmIdentity">'
        . 'I verified the employee identity and their individual login email address.'
        . '</label></div>'
        . '<p class="small text-secondary mt-3 mb-0">The temporary password must be shared through an '
        . 'approved secure channel. The employee must change it at first login.</p>'
        . '</div>'
        . '<div class="modal-footer">'
        . '<a class="btn btn-outline-secondary me-auto" id="setupFullPageLink" href="'
        . e(url('user_edit',['id'=>$reopen ? $reopenUserId : 0]))
        . '">Full-page setup</a>'
        . '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button type="submit" class="btn btn-success" id="setupActivateButton" disabled>'
        . '<i class="bi bi-person-check me-1" aria-hidden="true"></i>Activate Account</button>'
        . '</div></form></div></div></div>'
        . '<script src="assets/user-setup-modal.js?v=1" defer></script>';
    return $html;
}


/**
 * Reusable Edit User modal for active/suspended internal employees.
 * Each action uses its own CSRF-protected POST form and the existing user_edit route.
 * A failed form reopens the correct tab; passwords are never reflected in HTML.
 */

/** Preserve POST field names while keeping modal-specific labels and IDs unique. */
function user_management_edit_field(
    string $name, string $label, string $value, bool $required = false,
    string $extra = '', string $type = 'text'
): string {
    $html = user_management_field($name, $label, $value, $required, $extra, $type);
    $newId = 'edit_user_' . $name;
    return str_replace(
        ['for="' . e($name) . '"', 'id="' . e($name) . '"'],
        ['for="' . e($newId) . '"', 'id="' . e($newId) . '"'],
        $html
    );
}

function user_management_edit_modal(
    array $errors = [], int $reopenUserId = 0, string $reopenAction = ''
): string {
    $reopenUser = $reopenUserId > 0 ? user_management_user($reopenUserId) : null;
    $reopen = $reopenUser && in_array($reopenUser['status'], ['active','suspended'], true);
    $values = $reopen && $reopenAction === 'update' && !empty($_POST)
        ? user_management_data($_POST)
        : [
            'name'=>'','email'=>'','contact_number'=>'',
            'office_station'=>'','role'=>'','status'=>'active'
        ];
    $targetUrl = url('user_edit',['id'=>$reopen ? $reopenUserId : 0]);
    $admin = $reopen ? require_role('super_admin') : null;
    $self = $reopen && (int)$admin['id'] === (int)$reopenUserId;
    $showProfileErrors = $reopen && $reopenAction === 'update';
    $showResetErrors = $reopen && $reopenAction === 'reset_password';
    $html = '<div class="modal fade user-edit-modal" id="userEditModal" tabindex="-1" '
        . 'aria-labelledby="userEditModalLabel" aria-hidden="true" '
        . 'data-reopen="' . ($reopen ? '1' : '0') . '" '
        . 'data-reopen-action="' . e($reopen ? $reopenAction : '') . '" '
        . 'data-reopen-self="' . ($self ? '1' : '0') . '">'
        . '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">'
        . '<div class="modal-content">'
        . '<div class="modal-header">'
        . '<div><h2 class="modal-title h5 mb-1" id="userEditModalLabel">Edit User Account</h2>'
        . '<p class="text-secondary small mb-0">Update employee account information and permissions.</p></div>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
        . '</div><div class="modal-body">'
        . '<div class="d-flex align-items-center gap-2 flex-wrap mb-3">'
        . '<span class="badge text-bg-light border text-dark">User ID: <span data-edit-user-id>'
        . ($reopen ? (int)$reopenUserId : '') . '</span></span>'
        . '<span class="small text-secondary">Username: <span data-edit-user-username>'
        . e($reopen ? (string)($reopenUser['username'] ?? '') : '') . '</span></span>'
        . '</div>'
        . '<ul class="nav nav-tabs mb-3" id="editUserTabs" role="tablist">'
        . '<li class="nav-item" role="presentation"><button class="nav-link active" type="button" '
        . 'id="editUserProfileTab" data-bs-toggle="tab" data-bs-target="#editUserProfilePane" '
        . 'role="tab" aria-controls="editUserProfilePane" aria-selected="true">'
        . '<i class="bi bi-person-lines-fill me-1" aria-hidden="true"></i>Edit Profile</button></li>'
        . '<li class="nav-item" role="presentation" data-edit-reset-tab'
        . ($self ? ' hidden' : '') . '><button class="nav-link" type="button" '
        . 'id="editUserPasswordTab" data-bs-toggle="tab" data-bs-target="#editUserPasswordPane" '
        . 'role="tab" aria-controls="editUserPasswordPane" aria-selected="false">'
        . '<i class="bi bi-key me-1" aria-hidden="true"></i>Reset Password</button></li>'
        . '</ul><div class="tab-content">'
        . '<div class="tab-pane fade show active" id="editUserProfilePane" '
        . 'role="tabpanel" aria-labelledby="editUserProfileTab" tabindex="0">'
        . '<form method="post" id="editUserProfileForm" action="' . e($targetUrl) . '">'
        . csrf_field()
        . '<input type="hidden" name="action" value="update">'
        . '<input type="hidden" name="edit_origin" value="user_management_modal">'
        . ($showProfileErrors ? errors_html($errors) : '')
        . '<div class="row g-3">'
        . '<div class="col-12 col-md-6">' . user_management_edit_field(
            'name','Full Name',(string)$values['name'],true,
            'maxlength="180" autocomplete="name"'
        ) . '</div>'
        . '<div class="col-12 col-md-6">' . user_management_edit_field(
            'email','Individual Login Email',(string)$values['email'],true,
            'maxlength="190" autocomplete="off"','email'
        ) . '</div>'
        . '<div class="col-12 col-md-6">' . user_management_edit_field(
            'contact_number','Contact Number',(string)$values['contact_number'],false,
            'maxlength="40"','tel'
        ) . '</div>'
        . '<div class="col-12 col-md-6">' . user_management_edit_field(
            'office_station','Office / Station',(string)$values['office_station'],true,
            'maxlength="160"'
        ) . '</div>'
        . '<div class="col-12 col-md-6"><label class="form-label" for="editUserRole">'
        . 'User Role <span class="text-danger">*</span></label>'
        . '<select class="form-select" id="editUserRole" name="role" required>'
        . user_management_role_options((string)$values['role']) . '</select></div>'
        . '<div class="col-12 col-md-6"><label class="form-label" for="editUserStatus">Account Status</label>'
        . '<select class="form-select" id="editUserStatus" name="status">'
        . '<option value="active"' . ($values['status'] === 'active' ? ' selected' : '') . '>Active</option>'
        . '<option value="suspended"' . ($values['status'] === 'suspended' ? ' selected' : '') . '>Suspended</option>'
        . '</select></div></div>'
        . '<div class="alert alert-warning small mt-3 mb-0" data-edit-self-notice'
        . ($self ? '' : ' hidden') . '>'
        . 'You cannot suspend your own account or remove your own Super Administrator role.</div>'
        . '<div class="alert alert-info small mt-3 mb-0" data-edit-multi-role-notice hidden>'
        . 'This account has multiple roles. Saving will replace them with the selected primary role.</div>'
        . '<div class="d-flex flex-wrap justify-content-end gap-2 mt-4">'
        . '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button type="submit" class="btn btn-primary">'
        . '<i class="bi bi-save me-1" aria-hidden="true"></i>Save Changes</button>'
        . '</div></form></div>'
        . '<div class="tab-pane fade" id="editUserPasswordPane" '
        . 'role="tabpanel" aria-labelledby="editUserPasswordTab" tabindex="0">'
        . '<form method="post" id="editUserPasswordForm" action="' . e($targetUrl) . '" '
        . 'autocomplete="off">'
        . csrf_field()
        . '<input type="hidden" name="action" value="reset_password">'
        . '<input type="hidden" name="edit_origin" value="user_management_modal">'
        . ($showResetErrors ? errors_html($errors) : '')
        . '<div class="alert alert-warning small">'
        . 'Resetting a password immediately replaces the current password. '
        . 'Share the temporary password only through an approved secure channel. '
        . 'The employee will be required to change it at their next login.</div>'
        . '<div class="row g-3"><div class="col-12 col-md-6">'
        . '<label class="form-label" for="editUserNewPassword">New Temporary Password '
        . '<span class="text-danger">*</span></label>'
        . '<div class="input-group"><input class="form-control" id="editUserNewPassword" '
        . 'name="new_password" type="password" autocomplete="new-password" '
        . 'minlength="12" maxlength="200" required>'
        . '<button class="btn btn-outline-secondary" type="button" '
        . 'data-toggle-edit-password="editUserNewPassword" aria-pressed="false" '
        . 'aria-label="Show temporary password" title="Show password">'
        . '<i class="bi bi-eye" aria-hidden="true"></i></button></div>'
        . '<div class="form-text" data-edit-password-feedback>'
        . '12–200 characters with at least one letter and one number.</div>'
        . '</div><div class="col-12 col-md-6">'
        . '<label class="form-label" for="editUserConfirmPassword">Confirm Temporary Password '
        . '<span class="text-danger">*</span></label>'
        . '<div class="input-group"><input class="form-control" id="editUserConfirmPassword" '
        . 'name="confirm_password" type="password" autocomplete="new-password" '
        . 'minlength="12" maxlength="200" required>'
        . '<button class="btn btn-outline-secondary" type="button" '
        . 'data-toggle-edit-password="editUserConfirmPassword" aria-pressed="false" '
        . 'aria-label="Show confirmation password" title="Show password">'
        . '<i class="bi bi-eye" aria-hidden="true"></i></button></div>'
        . '<div class="form-text" data-edit-confirm-feedback>Re-enter the temporary password.</div>'
        . '</div></div>'
        . '<div class="d-flex flex-wrap justify-content-end gap-2 mt-4">'
        . '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button type="submit" class="btn btn-outline-danger" id="editUserResetButton" disabled>'
        . '<i class="bi bi-key me-1" aria-hidden="true"></i>Reset Password</button>'
        . '</div></form></div></div>'
        . '<p class="small text-secondary mt-3 mb-0">Changes are logged for auditing. '
        . 'Employee profile updates and password resets are separate operations.</p>'
        . '</div></div></div></div>'
        . '<script src="assets/user-edit-modal.js?v=1" defer></script>';
    return $html;
}

function view_user_management(
    array $errors = [], int $reopenUserId = 0,
    int $reopenEditUserId = 0, string $reopenEditAction = ''
): void
{
    $admin = require_role('super_admin');
    $query = substr(trim((string)($_GET['q'] ?? '')),0,120);
    $role = trim((string)($_GET['role'] ?? ''));
    $status = trim((string)($_GET['state'] ?? ''));
    $totals = query_one("SELECT COUNT(*) AS total,
                       SUM(status='pending_setup') AS pending_count,
                       SUM(status='active') AS active_count
                       FROM users WHERE user_kind='internal'") ?: [];
    $records = user_management_accounts($query,$role,$status);

    $html = ($reopenUserId > 0 || $reopenEditUserId > 0 ? '' : errors_html($errors))
        . '<div class="panel mb-4"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">'
        . '<div><h2 class="h5 mb-1">Configuration / User Management</h2>'
        . '<p class="text-secondary small mb-0">Single master list from the users table. '
        . 'Pending personnel cannot sign in until an administrator assigns an individual email and password.</p></div>'
        . '<div class="d-flex gap-2 flex-wrap">'
        . '<span class="badge text-bg-light border text-dark p-2">' . (int)($totals['total'] ?? 0) . ' Users</span>'
        . '<span class="badge text-bg-warning p-2">' . (int)($totals['pending_count'] ?? 0) . ' Pending Setup</span>'
        . '<span class="badge text-bg-success p-2">' . (int)($totals['active_count'] ?? 0) . ' Active</span>'
        . '</div></div></div>'
        . user_management_tabs('accounts')
        . '<div class="panel"><form method="get" action="' . e(url('user_management'))
        . '" class="row g-2 mb-3">'
        . '<div class="col-lg-4"><label class="visually-hidden" for="user-management-search">Search users</label>'
        . '<input class="form-control" id="user-management-search" name="q" placeholder="Name, office or reference email" value="'
        . e($query) . '"></div>'
        . '<div class="col-lg-3"><label class="visually-hidden" for="filter-role">Filter role</label>'
        . '<select class="form-select" id="filter-role" name="role"><option value="">All Roles</option>';
    foreach (user_management_internal_roles() as $code=>$definition) {
        $html .= '<option value="' . e($code) . '"' . ($role === $code ? ' selected' : '')
            . '>' . e($definition[0]) . '</option>';
    }
    $html .= '</select></div>'
        . '<div class="col-lg-2"><label class="visually-hidden" for="filter-state">Status</label>'
        . '<select class="form-select" id="filter-state" name="state">';
    foreach ([''=>'All Statuses','pending_setup'=>'Pending Setup','active'=>'Active','suspended'=>'Suspended'] as $value=>$label) {
        $html .= '<option value="' . e($value) . '"' . ($value === $status ? ' selected' : '') . '>'
            . e($label) . '</option>';
    }
    $html .= '</select></div><div class="col-lg-3 d-flex gap-2">'
        . '<button class="btn btn-primary flex-grow-1" type="submit">Filter</button>'
        . '<a class="btn btn-outline-secondary" href="' . e(url('user_management')) . '">Clear</a></div>'
        . '</form>'
        . '<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">'
        . '<h3 class="h6 mb-0">Internal Accounts and Designated Personnel</h3>'
        . '<a class="btn btn-sm btn-outline-primary" href="' . e(url('user_create')) . '">'
        . '<i class="bi bi-person-plus me-1"></i>Add User</a></div>'
        . '<div class="alert alert-info small">The names from the uploaded FP-OMS user-role form are in the same table as accounts. '
        . 'Reference/office emails may be shared and are <strong>not</strong> used for login. '
        . 'Click Set Up to assign a unique login email and temporary password to a pending employee.</div>'
        . '<div class="table-responsive"><table class="table table-hover table-sm align-middle">'
        . '<thead><tr><th>Full Name</th><th>Login Email</th><th>Office / Contact</th>'
        . '<th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody>';
    foreach ($records as $person) {
        $pending = $person['status'] === 'pending_setup';
        $html .= '<tr><td><strong>' . e((string)$person['name']) . '</strong>'
            . (!empty($person['designation_notes']) ? '<div class="small text-secondary">'
                . e((string)$person['designation_notes']) . '</div>' : '')
            . '</td><td>' . ($pending ? '<span class="text-secondary">Not assigned</span>'
                : e((string)($person['email'] ?? '—')))
            . (!empty($person['reference_email']) ? '<div class="small text-secondary">Office reference: '
                . e((string)$person['reference_email']) . '</div>' : '')
            . '</td><td>' . e((string)($person['office_station'] ?? '—'))
            . (!empty($person['contact_number']) ? '<div class="small text-secondary">'
                . e((string)$person['contact_number']) . '</div>' : '') . '</td>'
            . '<td>' . e((string)($person['role_names'] ?? 'Unassigned')) . '</td>'
            . '<td>' . user_management_status_badge((string)$person['status']) . '</td>';
        if ($pending) {
            // Data attributes contain display-only personnel fields, escaped for HTML attribute context.
            // Login email/password remain empty until an administrator submits the form.
            $html .= '<td><a class="btn btn-sm btn-primary" role="button" '
                . 'href="' . e(url('user_edit',['id'=>(int)$person['id']])) . '" '
                . 'data-bs-toggle="modal" data-bs-target="#userSetupModal" '
                . 'data-user-setup-trigger data-user-id="' . (int)$person['id'] . '" '
                . 'data-user-name="' . e((string)$person['name']) . '" '
                . 'data-user-office="' . e((string)($person['office_station'] ?? '')) . '" '
                . 'data-user-contact="' . e((string)($person['contact_number'] ?? '')) . '" '
                . 'data-user-role="' . e((string)($person['role_code'] ?? '')) . '" '
                . 'data-user-reference="' . e((string)($person['reference_email'] ?? '')) . '" '
                . 'data-user-notes="' . e((string)($person['designation_notes'] ?? '')) . '" '
                . 'data-setup-url="' . e(url('user_edit',['id'=>(int)$person['id']])) . '">'
                . '<i class="bi bi-person-gear me-1" aria-hidden="true"></i>Set Up</a></td></tr>';
        } else {
            $html .= '<td><a class="btn btn-sm btn-outline-primary" role="button" '
                . 'href="' . e(url('user_edit',['id'=>(int)$person['id']])) . '" '
                . 'data-bs-toggle="modal" data-bs-target="#userEditModal" '
                . 'data-user-edit-trigger data-user-id="' . (int)$person['id'] . '" '
                . 'data-user-name="' . e((string)$person['name']) . '" '
                . 'data-user-email="' . e((string)($person['email'] ?? '')) . '" '
                . 'data-user-contact="' . e((string)($person['contact_number'] ?? '')) . '" '
                . 'data-user-office="' . e((string)($person['office_station'] ?? '')) . '" '
                . 'data-user-role="' . e((string)($person['role_code'] ?? '')) . '" '
                . 'data-user-roles="' . e((string)($person['role_names'] ?? '')) . '" '
                . 'data-user-status="' . e((string)$person['status']) . '" '
                . 'data-user-username="' . e((string)($person['username'] ?? '')) . '" '
                . 'data-can-reset="' . ((int)$person['id'] === (int)$admin['id'] ? '0' : '1') . '" '
                . 'data-is-self="' . ((int)$person['id'] === (int)$admin['id'] ? '1' : '0') . '" '
                . 'data-edit-url="' . e(url('user_edit',['id'=>(int)$person['id']])) . '">'
                . '<i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edit</a></td></tr>';
        }
    }
    if (!$records) $html .= '<tr><td colspan="6" class="text-center text-secondary py-4">No matching users found.</td></tr>';
    $html .= '</tbody></table></div>'
        . '<p class="text-secondary small mb-0">Changes to user roles, credentials, and account status are logged. '
        . 'Applicant registrations and accounts are managed separately.</p></div>';
    $html .= user_management_setup_modal($reopenUserId > 0 ? $errors : [], $reopenUserId);
    $html .= user_management_edit_modal($reopenEditUserId > 0 ? $errors : [], $reopenEditUserId, $reopenEditAction);
    render_page('User Management',$html);
}

/** Manual account creation for personnel who are not already listed as Pending Setup. */
function view_user_create(array $errors = []): void
{
    require_role('super_admin');
    $values = $_POST ? user_management_data($_POST) : [
        'name'=>'','email'=>'','contact_number'=>'','office_station'=>'',
        'role'=>'','status'=>'active'
    ];
    $html = '<div class="mb-3"><a href="' . e(url('user_management')) . '">← Back to User Management</a></div>'
        . user_management_tabs('create')
        . '<div class="row g-4"><div class="col-lg-8"><div class="panel">'
        . errors_html($errors)
        . '<h2 class="h5 mb-2">Add Internal User</h2>'
        . '<p class="text-secondary small">For an employee already in the user list, use <strong>Set Up</strong> '
        . 'on their pending record instead of creating a duplicate.</p>'
        . '<form method="post" action="' . e(url('user_create')) . '">'
        . csrf_field() . '<input type="hidden" name="status" value="active">'
        . user_management_form_fields($values,false)
        . '<hr><h3 class="h6">Initial Password</h3>'
        . user_management_field('password','Initial Password','',true,
            'autocomplete="new-password" minlength="12" maxlength="200"','password')
        . user_management_field('confirm_password','Confirm Initial Password','',true,
            'autocomplete="new-password" minlength="12" maxlength="200"','password')
        . '<p class="form-text">Use 12–200 characters, with letters and numbers. '
        . 'Share the temporary password securely, not by ordinary email.</p>'
        . '<button type="submit" class="btn btn-primary">Create Account</button></form></div></div>'
        . '<div class="col-lg-4"><div class="panel soft"><h3 class="h6">Account Security</h3>'
        . '<p class="small mb-0">All new accounts must change their temporary password on first login. '
        . 'The administrator cannot view password hashes. User activity is recorded in the audit trail.</p>'
        . '</div></div></div>';
    render_page('Add User',$html);
}

function view_user_edit(int $id, array $errors = []): void
{
    $admin = require_role('super_admin');
    $user = user_management_user($id);
    if (!$user) show_error('Internal user account not found.',404);
    $pending = $user['status'] === 'pending_setup';
    $assignedRoles = user_roles($id);
    $values = [
        'name'=>(string)$user['name'],
        'email'=>(string)($user['email'] ?? ''),
        'contact_number'=>(string)($user['contact_number'] ?? ''),
        'office_station'=>(string)($user['office_station'] ?? ''),
        'role'=>($assignedRoles[0] ?? ''),
        'status'=>$pending ? 'active' : (string)$user['status']
    ];
    if ($_POST && in_array((string)($_POST['action'] ?? ''), ['update','activate'],true)) {
        $values = user_management_data($_POST);
    }
    $html = '<div class="mb-3"><a href="' . e(url('user_management')) . '">← Back to User Management</a></div>'
        . '<div class="row g-4"><div class="col-lg-8"><div class="panel">'
        . errors_html($errors)
        . '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">'
        . '<h2 class="h5 mb-0">' . ($pending ? 'Set Up User Account' : 'Edit User Profile') . '</h2>'
        . user_management_status_badge((string)$user['status']) . '</div>'
        . '<p class="small text-secondary">User ID: ' . $id
        . ' · Username: ' . e((string)($user['username'] ?? 'Not yet assigned')) . '</p>';
    if ($pending) {
        $html .= '<div class="alert alert-warning small"><strong>Pending Setup:</strong> This person is already recorded '
            . 'in the users table, but cannot sign in. Set a unique individual login email, verify their identity, '
            . 'and assign a temporary password to activate this same record.</div>';
    }
    $html .= '<form method="post" action="' . e(url('user_edit',['id'=>$id])) . '">'
        . csrf_field() . '<input type="hidden" name="action" value="'
        . ($pending ? 'activate' : 'update') . '">'
        . user_management_form_fields($values,!$pending);
    if ($pending) {
        $html .= '<input type="hidden" name="status" value="active">'
            . '<hr><h3 class="h6">Set Initial Password</h3>'
            . user_management_field('password','Temporary Password','',true,
                'autocomplete="new-password" minlength="12" maxlength="200"','password')
            . user_management_field('confirm_password','Confirm Temporary Password','',true,
                'autocomplete="new-password" minlength="12" maxlength="200"','password')
            . '<div class="form-check mb-3">'
            . '<input class="form-check-input" type="checkbox" name="confirm_identity" id="confirm_identity" value="1" required>'
            . '<label class="form-check-label" for="confirm_identity">I verified the employee identity '
            . 'and their individual login email address.</label></div>'
            . '<button class="btn btn-success" type="submit"><i class="bi bi-person-check me-1"></i>'
            . 'Activate Account</button>';
    } else {
        if ((int)$admin['id'] === $id) {
            $html .= '<div class="alert alert-warning small">You cannot remove your own Super Administrator '
                . 'role or suspend your own account.</div>';
        }
        if (count($assignedRoles) > 1) {
            $html .= '<div class="alert alert-warning small">This account has multiple roles. '
                . 'Saving will replace them with the selected role.</div>';
        }
        $html .= '<button class="btn btn-primary" type="submit">'
            . '<i class="bi bi-save me-1"></i>Save Changes</button>';
    }
    $html .= '</form></div>';

    if (!$pending && (int)$admin['id'] !== $id) {
        $html .= '<div class="panel mt-4"><h3 class="h5">Reset Temporary Password</h3>'
            . '<p class="small text-secondary">A password reset forces the employee to change '
            . 'the temporary password at next sign-in.</p>'
            . '<form method="post" action="' . e(url('user_edit',['id'=>$id])) . '">'
            . csrf_field() . '<input type="hidden" name="action" value="reset_password">'
            . user_management_field('new_password','New Temporary Password','',true,
                'autocomplete="new-password" minlength="12" maxlength="200"','password')
            . user_management_field('confirm_password','Confirm Temporary Password','',true,
                'autocomplete="new-password" minlength="12" maxlength="200"','password')
            . '<button type="submit" class="btn btn-outline-danger"><i class="bi bi-key me-1"></i>'
            . 'Reset Password</button></form></div>';
    } elseif (!$pending) {
        $html .= '<div class="alert alert-info mt-3">To change your own password, '
            . 'use <a href="' . e(url('change_password')) . '">Change Password</a>.</div>';
    }
    $html .= '</div><div class="col-lg-4">'
        . '<div class="panel soft"><h3 class="h6">Personnel Information</h3>'
        . '<p class="small mb-2"><strong>Office:</strong> '
        . e((string)($user['office_station'] ?? 'Not specified')) . '</p>'
        . '<p class="small mb-2"><strong>Role:</strong> '
        . e(implode(', ',array_map(
            static fn(string $r): string => role_definitions()[$r][0] ?? $r,$assignedRoles
        ))) . '</p>'
        . '<p class="small mb-2"><strong>Office Contact / Reference:</strong> '
        . e((string)($user['reference_email'] ?? '—')) . '</p>'
        . '<p class="small mb-2"><strong>Notes:</strong> '
        . e((string)($user['designation_notes'] ?? '—')) . '</p>'
        . '<p class="small mb-2"><strong>Source Code:</strong> '
        . e((string)($user['source_key'] ?? 'Manual user')) . '</p>'
        . '<p class="small mb-0"><strong>Last Login:</strong> '
        . e(profile_display_date($user['last_login_at'] ?? null)) . '</p>'
        . '</div></div></div>';
    render_page($pending ? 'Set Up User' : 'Edit User',$html);
}
