<?php
declare(strict_types=1);

/** User Management screens, exclusively accessible to RICTU Super Administrators. */
function user_management_role_options(string $chosen): string
{
    $out = '<option value="">Choose User Role</option>';
    foreach (user_management_internal_roles() as $code => $role) {
        $out .= '<option value="' . e($code) . '"' . ($code === $chosen ? ' selected' : '') . '>' . e($role[0]) . '</option>';
    }
    return $out;
}

function user_management_tabs(string $active): string
{
    $options = [
        ['accounts', 'Internal Accounts', 'people'],
        ['designations', 'Designated Personnel', 'person-lines-fill'],
        ['create', 'Add User', 'person-plus']
    ];
    $html = '<nav class="nav nav-pills flex-wrap gap-2 mb-4" aria-label="User Management sections">';
    foreach ($options as [$tab,$label,$icon]) {
        $target = $tab === 'create' ? url('user_create') : url('user_management', ['tab'=>$tab]);
        $html .= '<a class="nav-link' . ($active === $tab ? ' active' : '') . '" href="' . e($target) . '">'
            . '<i class="bi bi-' . e($icon) . ' me-1" aria-hidden="true"></i>' . e($label) . '</a>';
    }
    return $html . '</nav>';
}

function user_management_status_badge(string $status): string
{
    $labels = ['active'=>'Active','suspended'=>'Suspended'];
    $colors = ['active'=>'success','suspended'=>'secondary'];
    return '<span class="badge text-bg-' . ($colors[$status] ?? 'secondary') . '">' . e($labels[$status] ?? $status) . '</span>';
}

function user_management_field(string $name, string $label, string $value, bool $required = false, string $extra = '', string $type = 'text'): string
{
    return '<div class="mb-3"><label class="form-label" for="' . e($name) . '">' . e($label)
        . ($required ? ' <span class="text-danger">*</span>' : '') . '</label>'
        . '<input class="form-control" type="' . e($type) . '" id="' . e($name) . '" name="' . e($name) . '" value="' . e($value) . '"'
        . ($required ? ' required' : '') . ' ' . $extra . '></div>';
}

function user_management_form_fields(array $values, bool $editing = false): string
{
    $html = '<div class="row g-3"><div class="col-md-6">'
        . user_management_field('name','Full Name',(string)($values['name'] ?? ''),true,'maxlength="180"')
        . '</div><div class="col-md-6">'
        . user_management_field('email','Individual Login Email',(string)($values['email'] ?? ''),true,'maxlength="190" autocomplete="off"','email')
        . '</div><div class="col-md-6">'
        . user_management_field('contact_number','Contact Number',(string)($values['contact_number'] ?? ''),false,'maxlength="40"','tel')
        . '</div><div class="col-md-6">'
        . user_management_field('office_station','Office / Station',(string)($values['office_station'] ?? ''),true,'maxlength="160"')
        . '</div><div class="col-md-6"><label class="form-label" for="role">User Role <span class="text-danger">*</span></label>'
        . '<select class="form-select" id="role" name="role" required>'
        . user_management_role_options((string)($values['role'] ?? '')) . '</select></div>';
    if ($editing) {
        $current = (string)($values['status'] ?? 'active');
        $html .= '<div class="col-md-6"><label class="form-label" for="status">Account Status</label>'
            . '<select class="form-select" id="status" name="status">'
            . '<option value="active"' . ($current === 'active' ? ' selected' : '') . '>Active</option>'
            . '<option value="suspended"' . ($current === 'suspended' ? ' selected' : '') . '>Suspended</option>'
            . '</select></div>';
    }
    return $html . '</div>';
}

function view_user_management(array $errors = []): void
{
    require_role('super_admin');
    $tab = (string)($_GET['tab'] ?? 'accounts');
    if (!in_array($tab, ['accounts','designations'], true)) $tab = 'accounts';
    $q = substr(trim((string)($_GET['q'] ?? '')), 0, 120);
    $role = trim((string)($_GET['role'] ?? ''));
    $state = trim((string)($_GET['state'] ?? ''));

    $pending = query_one('SELECT COUNT(*) AS n FROM user_designations WHERE linked_user_id IS NULL');
    $accounts = query_one("SELECT COUNT(*) AS n FROM users WHERE user_kind='internal'");
    $html = errors_html($errors)
        . '<div class="panel mb-4"><div class="d-flex justify-content-between flex-wrap gap-3 align-items-start">'
        . '<div><h2 class="h5 mb-1">Configuration / User Management</h2>'
        . '<p class="small text-secondary mb-0">Manage employee accounts, profile information, office assignments, roles and password resets. RICTU access only.</p></div>'
        . '<div class="d-flex gap-2 flex-wrap"><span class="badge text-bg-light border text-dark p-2">'
        . (int)($accounts['n'] ?? 0) . ' Internal Accounts</span>'
        . '<span class="badge text-bg-warning p-2">' . (int)($pending['n'] ?? 0) . ' Pending Setup</span></div></div></div>'
        . user_management_tabs($tab)
        . '<div class="panel"><form method="get" action="' . e(url('user_management')) . '" class="row g-2 mb-3">'
        . '<input type="hidden" name="tab" value="' . e($tab) . '">'
        . '<div class="col-lg-5"><label class="visually-hidden" for="user-management-search">Search name, email, or office</label>'
        . '<input class="form-control" id="user-management-search" name="q" value="' . e($q) . '" placeholder="Search name, email or office"></div>'
        . '<div class="col-lg-4"><label class="visually-hidden" for="filter-role">Filter role</label>'
        . '<select class="form-select" id="filter-role" name="role"><option value="">All Roles</option>';
    foreach (user_management_internal_roles() as $code=>$r) {
        $html .= '<option value="' . e($code) . '"' . ($role === $code ? ' selected' : '') . '>' . e($r[0]) . '</option>';
    }
    $html .= '</select></div><div class="col-lg-3 d-flex gap-2">'
        . '<button type="submit" class="btn btn-primary flex-grow-1">Filter</button>'
        . '<a class="btn btn-outline-secondary" href="' . e(url('user_management',['tab'=>$tab])) . '">Clear</a></div></form>';

    if ($tab === 'accounts') {
        $entries = user_management_accounts($q, $role, $state);
        $html .= '<div class="d-flex justify-content-between align-items-center gap-2 mb-3">'
            . '<h3 class="h6 mb-0">Existing Internal Accounts</h3>'
            . '<a class="btn btn-sm btn-outline-primary" href="' . e(url('user_create')) . '">'
            . '<i class="bi bi-person-plus me-1"></i>Add User</a></div>'
            . '<div class="table-responsive"><table class="table table-hover table-sm align-middle">'
            . '<thead><tr><th>Name</th><th>Individual Login Email</th><th>Office / Station</th><th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($entries as $user) {
            $html .= '<tr><td><strong>' . e((string)$user['name']) . '</strong>'
                . '<div class="small text-secondary">' . e((string)($user['username'] ?? '')) . '</div></td>'
                . '<td>' . e((string)$user['email']) . '</td>'
                . '<td>' . e((string)($user['office_station'] ?? 'Not specified')) . '</td>'
                . '<td>' . e((string)($user['role_names'] ?? 'Unassigned')) . '</td>'
                . '<td>' . user_management_status_badge((string)$user['status']) . '</td>'
                . '<td><a class="btn btn-sm btn-outline-primary" href="' . e(url('user_edit',['id'=>(int)$user['id']])) . '">'
                . '<i class="bi bi-pencil-square me-1"></i>Edit</a></td></tr>';
        }
        if (!$entries) $html .= '<tr><td colspan="6" class="text-center text-secondary py-4">No matching internal accounts found.</td></tr>';
        $html .= '</tbody></table></div>';
    } else {
        $entries = user_management_designations($q,$role,$state);
        $html .= '<div class="alert alert-info small"><strong>Imported designation form (October 21, 2026):</strong> '
            . 'Names below are pending setup records, not automatically enabled user logins. '
            . 'Office contact emails may be shared; a separate, unique individual email and initial password are required. '
            . 'Check incomplete names before setting up an account.</div>'
            . '<div class="table-responsive"><table class="table table-hover table-sm align-middle">'
            . '<thead><tr><th>Designated Name</th><th>Office / Station</th><th>Office Contact / Reference</th><th>Suggested Role</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($entries as $d) {
            $labels = user_management_internal_roles();
            $roleLabel = $labels[$d['suggested_role']][0] ?? $d['suggested_role'];
            $html .= '<tr><td><strong>' . e((string)$d['full_name']) . '</strong>'
                . (!empty($d['notes']) ? '<div class="small text-secondary">' . e((string)$d['notes']) . '</div>' : '') . '</td>'
                . '<td>' . e((string)$d['office_station']) . '</td>'
                . '<td>' . e((string)($d['reference_email'] ?? '—'))
                . (!empty($d['contact_number']) ? '<div class="small">' . e((string)$d['contact_number']) . '</div>' : '')
                . '</td><td>' . e((string)$roleLabel) . '</td>';
            if ($d['linked_user_id'] === null) {
                $html .= '<td><span class="badge text-bg-warning">Pending Setup</span></td>'
                    . '<td><a class="btn btn-sm btn-outline-primary" href="' . e(url('user_create',['designation'=>(int)$d['id']])) . '">Set Up</a></td>';
            } else {
                $html .= '<td><span class="badge text-bg-success">Linked</span></td>'
                    . '<td><a class="btn btn-sm btn-outline-secondary" href="' . e(url('user_edit',['id'=>(int)$d['linked_user_id']])) . '">Manage</a></td>';
            }
            $html .= '</tr>';
        }
        if (!$entries) $html .= '<tr><td colspan="6" class="text-center text-secondary py-4">No matching designations found.</td></tr>';
        $html .= '</tbody></table></div>';
    }
    $html .= '<p class="small text-secondary mb-0 mt-3">Only authorized RICTU personnel may create, edit or reset internal accounts. Existing applicant registrations are managed separately.</p></div>';
    render_page('User Management', $html);
}

function view_user_create(array $errors = []): void
{
    require_role('super_admin');
    $designationId = (int)($_GET['designation'] ?? ($_POST['designation_id'] ?? 0));
    $designation = $designationId > 0 ? user_management_designation($designationId) : null;
    if ($designationId && !$designation) show_error('This designation does not exist.',404);
    if ($designation && $designation['linked_user_id'] !== null) {
        flash('info', 'This designation already has an account. Edit the linked account instead.');
        go('user_edit',['id'=>(int)$designation['linked_user_id']]);
    }
    $values = [
        'name'=>$designation['full_name'] ?? '',
        'email'=>'',
        'contact_number'=>$designation['contact_number'] ?? '',
        'office_station'=>$designation['office_station'] ?? '',
        'role'=>$designation['suggested_role'] ?? '',
        'status'=>'active'
    ];
    if ($_POST) $values = array_merge($values,user_management_data($_POST));

    $html = '<div class="mb-3"><a href="' . e(url('user_management',['tab'=>'designations'])) . '">← Back to User Management</a></div>'
        . user_management_tabs('create')
        . '<div class="row g-4"><div class="col-lg-8"><div class="panel">'
        . errors_html($errors)
        . '<h2 class="h5 mb-2">Create Internal User Account</h2>'
        . '<p class="text-secondary small">Only authorized personnel should receive accounts. The initial password must be changed at first login.</p>';
    if ($designation) {
        $html .= '<div class="alert alert-info small"><strong>Pending designation:</strong> '
            . e((string)$designation['full_name']) . ' — ' . e((string)$designation['office_station'])
            . '<div>Office contact reference: ' . e((string)($designation['reference_email'] ?? 'Not specified')) . '</div>'
            . '<div><strong>Do not use a shared office email as another person’s login.</strong> Enter a unique individual address.</div>'
            . (!empty($designation['notes']) ? '<div class="mt-1">' . e((string)$designation['notes']) . '</div>' : '')
            . '</div>';
    }
    $html .= '<form method="post" action="' . e(url('user_create',$designation ? ['designation'=>$designationId] : [])) . '">'
        . csrf_field()
        . '<input type="hidden" name="designation_id" value="' . (int)$designationId . '">'
        . user_management_form_fields($values)
        . '<input type="hidden" name="status" value="active">'
        . '<hr><h3 class="h6">Initial Password</h3>'
        . user_management_field('password','Initial Password','',true,'autocomplete="new-password" minlength="12" maxlength="200"','password')
        . user_management_field('confirm_password','Confirm Password','',true,'autocomplete="new-password" minlength="12" maxlength="200"','password')
        . '<p class="form-text">Minimum 12 characters with letters and numbers. Share this temporary password only through an approved secure channel.</p>'
        . '<button type="submit" class="btn btn-primary"><i class="bi bi-person-check me-1"></i>Create Account</button>'
        . '</form></div></div>'
        . '<div class="col-lg-4"><div class="panel soft"><h3 class="h6">Security Notes</h3>'
        . '<p class="small mb-2">The account is activated only when you submit this form successfully. No shared passwords or default passwords are generated for the imported names.</p>'
        . '<p class="small mb-0">Password hashes are stored securely; new users must change their initial password upon first sign-in.</p></div>';

    if ($designation) {
        $existing = user_management_accounts();
        $html .= '<div class="panel mt-3"><h3 class="h6">Already Has an Account?</h3>'
            . '<p class="small text-secondary">If this person is already registered, link their existing account instead of creating another login. Names must match after punctuation and spacing are ignored.</p>'
            . '<form method="post" action="' . e(url('user_management')) . '">'
            . csrf_field()
            . '<input type="hidden" name="action" value="link_existing">'
            . '<input type="hidden" name="designation_id" value="' . (int)$designationId . '">'
            . '<label class="form-label" for="existing_user_id">Existing Internal Account</label>'
            . '<select class="form-select mb-2" id="existing_user_id" name="existing_user_id" required>'
            . '<option value="">Choose matching account</option>';
        foreach ($existing as $account) {
            $html .= '<option value="' . (int)$account['id'] . '">'
                . e((string)$account['name']) . ' (' . e((string)$account['email']) . ')</option>';
        }
        $html .= '</select><div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="confirm_identity" name="confirm_identity" value="1" required>'
            . '<label class="form-check-label small" for="confirm_identity">I verified that this account belongs to the designated person.</label></div>'
            . '<button type="submit" class="btn btn-outline-primary">Link Existing Account</button></form></div>';
    }
    $html .= '</div></div>';
    render_page('Create User', $html);
}

function view_user_edit(int $id, array $errors = []): void
{
    $admin = require_role('super_admin');
    $user = user_management_user($id);
    if (!$user) show_error('Internal account does not exist.',404);
    $roles = user_roles($id);
    $values = [
        'name'=>(string)$user['name'],
        'email'=>(string)$user['email'],
        'contact_number'=>(string)($user['contact_number'] ?? ''),
        'office_station'=>(string)($user['office_station'] ?? ''),
        'role'=>($roles[0] ?? ''),
        'status'=>(string)$user['status']
    ];
    if ($_POST && ($_POST['action'] ?? '') === 'update') $values = user_management_data($_POST);

    $html = '<div class="mb-3"><a href="' . e(url('user_management')) . '">← Back to User Management</a></div>'
        . '<div class="row g-4"><div class="col-lg-8"><div class="panel">'
        . errors_html($errors)
        . '<div class="d-flex justify-content-between align-items-center gap-2 mb-3">'
        . '<h2 class="h5 mb-0">Edit User Profile</h2>'
        . user_management_status_badge((string)$user['status']) . '</div>'
        . '<p class="small text-secondary">Username: <strong>' . e((string)($user['username'] ?? 'Not assigned')) . '</strong> · Account ID: ' . $id . '</p>'
        . '<form method="post" action="' . e(url('user_edit',['id'=>$id])) . '">'
        . csrf_field() . '<input type="hidden" name="action" value="update">'
        . user_management_form_fields($values,true);
    if ((int)$admin['id'] === $id) {
        $html .= '<div class="alert alert-warning small">For security, you cannot suspend your own account or remove your own Super Administrator role.</div>';
    }
    if (count($roles) > 1) {
        $html .= '<div class="alert alert-warning small">This account currently has several assigned roles. Saving replaces all existing roles with the one selected above.</div>';
    }
    $html .= '<button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>'
        . '</form></div>';

    if ((int)$admin['id'] !== $id) {
        $html .= '<div class="panel mt-4"><h3 class="h5">Administrator Password Reset</h3>'
            . '<p class="small text-secondary">Enter a new temporary password. The employee must change it at next login. Password values are never shown again or sent in email.</p>'
            . '<form method="post" action="' . e(url('user_edit',['id'=>$id])) . '">'
            . csrf_field() . '<input type="hidden" name="action" value="reset_password">'
            . user_management_field('new_password','New Temporary Password','',true,'autocomplete="new-password" minlength="12" maxlength="200"','password')
            . user_management_field('confirm_password','Confirm Temporary Password','',true,'autocomplete="new-password" minlength="12" maxlength="200"','password')
            . '<button type="submit" class="btn btn-outline-danger"><i class="bi bi-key me-1"></i>Reset Password</button>'
            . '</form></div>';
    } else {
        $html .= '<div class="alert alert-info mt-3">To change your own password, use <a href="' . e(url('change_password')) . '">Change Password</a>.</div>';
    }

    $designations = query_all('SELECT full_name,office_station,reference_email FROM user_designations WHERE linked_user_id=?', [$id]);
    $html .= '</div><div class="col-lg-4"><div class="panel soft"><h3 class="h6">Assigned Account</h3>'
        . '<p class="small mb-2"><strong>Role:</strong> ' . e(implode(', ', array_map(
            static fn(string $code): string => role_definitions()[$code][0] ?? $code, $roles
        ))) . '</p>'
        . '<p class="small mb-2"><strong>Office:</strong> ' . e((string)($user['office_station'] ?? 'Not specified')) . '</p>'
        . '<p class="small mb-2"><strong>Last Login:</strong> ' . e(profile_display_date($user['last_login_at'] ?? null)) . '</p>'
        . '<p class="small mb-2"><strong>Must Change Password:</strong> ' . ((int)$user['must_change_password'] ? 'Yes' : 'No') . '</p>'
        . '<p class="small mb-0"><strong>Profile Photo:</strong> Managed by the account holder on their personal Profile page.</p>'
        . '</div>';
    foreach ($designations as $d) {
        $html .= '<div class="panel mt-3"><h3 class="h6">Imported Designation</h3>'
            . '<p class="small mb-1">' . e((string)$d['full_name']) . ' · ' . e((string)$d['office_station']) . '</p>'
            . '<p class="small mb-0 text-secondary">Office contact: ' . e((string)($d['reference_email'] ?? '—')) . '</p></div>';
    }
    $html .= '</div></div>';
    render_page('Edit User', $html);
}
