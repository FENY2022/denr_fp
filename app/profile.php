<?php
declare(strict_types=1);

function profile_display_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '—';

    try {
        return (new DateTimeImmutable($value))->format('F j, Y · g:i A');
    } catch (Throwable) {
        return $value;
    }
}

function profile_status_badge(string $status): string
{
    $labels = [
        'active' => ['success', 'Active'],
        'pending_review' => ['warning', 'Pending Review'],
        'needs_correction' => ['danger', 'Corrections Required'],
        'suspended' => ['secondary', 'Suspended'],
    ];
    [$color, $label] = $labels[$status] ?? ['secondary', ucwords(str_replace('_', ' ', $status))];
    return '<span class="badge text-bg-' . e($color) . '">' . e($label) . '</span>';
}

function profile_context(array $user): array
{
    $roles = user_roles((int)$user['id']);
    $definitions = role_definitions();
    $roleNames = array_map(static fn(string $role): string => $definitions[$role][0] ?? $role, $roles);

    return [
        'roles' => $roles,
        'role_text' => $roleNames ? implode(', ', $roleNames) : 'Unassigned',
        'account_type' => $user['user_kind'] === 'applicant' ? 'External Applicant' : 'DENR Personnel',
        'initial' => strtoupper(substr(trim((string)$user['name']), 0, 1)) ?: 'U',
    ];
}

function profile_hero_html(array $user, array $context): string
{
    return '<section class="profile-hero">'
        . '<div class="profile-avatar-lg" aria-hidden="true">' . e((string)$context['initial']) . '</div>'
        . '<div class="profile-hero-copy"><h2>' . e((string)$user['name']) . '</h2>'
        . '<div class="profile-role">' . e((string)$context['role_text']) . '</div>'
        . '<div class="profile-meta"><span><i class="bi bi-person-badge"></i>' . e((string)$context['account_type']) . '</span>'
        . '<span><i class="bi bi-envelope"></i>' . e((string)$user['email']) . '</span></div></div>'
        . '<div class="profile-hero-actions"><a class="btn btn-outline-primary btn-sm" href="' . e(url('change_password')) . '">'
        . '<i class="bi bi-shield-lock me-1"></i>Change Password</a></div></section>';
}

function profile_tabs_html(string $active): string
{
    $viewClass = $active === 'view' ? ' active' : '';
    $editClass = $active === 'edit' ? ' active' : '';

    return '<div class="profile-tabs">'
        . '<a class="profile-tab' . $viewClass . '" href="' . e(url('profile')) . '"><i class="bi bi-person-vcard me-1"></i>View Profile</a>'
        . '<a class="profile-tab' . $editClass . '" href="' . e(url('edit_profile')) . '"><i class="bi bi-pencil-square me-1"></i>Edit Profile</a>'
        . '</div>';
}

function update_profile(array $user, array $data): array
{
    $name = trim((string)($data['name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $errors = [];

    if ($name === '' || strlen($name) > 180) {
        $errors[] = 'Full name is required and must not exceed 180 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors[] = 'Enter a valid email address.';
    }

    if (!$errors) {
        $duplicate = query_one('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1', [$email, (int)$user['id']]);
        if ($duplicate) {
            $errors[] = 'Another account already uses this email address.';
        }
    }

    if ($errors) return $errors;

    $oldName = (string)$user['name'];
    $oldEmail = strtolower((string)$user['email']);

    if ($name === $oldName && $email === $oldEmail) {
        return [];
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        $locked = query_one('SELECT id,user_kind,name,email FROM users WHERE id=? FOR UPDATE', [(int)$user['id']]);
        if (!$locked) {
            throw new RuntimeException('User account was not found.');
        }

        $pdo->prepare('UPDATE users SET name=?,email=? WHERE id=?')
            ->execute([$name, $email, (int)$user['id']]);

        if ((string)$locked['user_kind'] === 'applicant') {
            $pdo->prepare('UPDATE applicant_registrations SET representative_name=? WHERE user_id=?')
                ->execute([$name, (int)$user['id']]);
        }

        audit((int)$user['id'], 'profile.updated', 'user', (int)$user['id'], [
            'name_changed' => $name !== (string)$locked['name'],
            'email_changed' => $email !== strtolower((string)$locked['email']),
        ]);

        $pdo->commit();
        return [];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

        if ($e->getCode() === '23000') {
            return ['Another account already uses this email address.'];
        }

        error_log('Profile update failed: ' . $e->getMessage());
        return ['Profile changes could not be saved. Please try again.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Profile update failed: ' . $e->getMessage());
        return ['Profile changes could not be saved. Please try again.'];
    }
}

function view_profile(): void
{
    $user = require_user();
    $context = profile_context($user);
    $registration = $user['user_kind'] === 'applicant' ? registration_for_user((int)$user['id']) : null;

    $html = profile_hero_html($user, $context)
        . profile_tabs_html('view');

    $html .= '<section class="panel profile-information-card"><div class="profile-section-title"><i class="bi bi-info-circle"></i><h3>Account Information</h3></div>'
        . '<div class="profile-info-grid">'
        . '<div class="profile-info-item"><span class="profile-info-label">Full Name</span><div class="profile-info-value">' . e((string)$user['name']) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Email Address</span><div class="profile-info-value">' . e((string)$user['email']) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Account Type</span><div class="profile-info-value">' . e((string)$context['account_type']) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Role</span><div class="profile-info-value">' . e((string)$context['role_text']) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Account Status</span><div class="profile-info-value">' . profile_status_badge((string)$user['status']) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Account ID</span><div class="profile-info-value">#' . (int)$user['id'] . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Last Login</span><div class="profile-info-value">' . e(profile_display_date($user['last_login_at'] ?? null)) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Activated</span><div class="profile-info-value">' . e(profile_display_date($user['activated_at'] ?? null)) . '</div></div>';

    if (array_key_exists('created_at', $user)) {
        $html .= '<div class="profile-info-item"><span class="profile-info-label">Account Created</span><div class="profile-info-value">' . e(profile_display_date($user['created_at'] ?? null)) . '</div></div>';
    }

    $html .= '</div></section>';

    if ($registration) {
        $html .= '<section class="panel profile-information-card mt-4"><div class="profile-section-title"><i class="bi bi-building"></i><h3>Applicant Information</h3></div>'
            . '<div class="profile-info-grid">'
            . '<div class="profile-info-item"><span class="profile-info-label">Company / Proponent</span><div class="profile-info-value">' . e((string)$registration['company_name']) . '</div></div>'
            . '<div class="profile-info-item"><span class="profile-info-label">Contact Number</span><div class="profile-info-value">' . e((string)$registration['contact_number']) . '</div></div>'
            . '<div class="profile-info-item profile-info-wide"><span class="profile-info-label">Company Address</span><div class="profile-info-value">' . e((string)$registration['company_address']) . '</div></div>'
            . '</div></section>';
    }

    render_page('Profile', $html);
}

function view_edit_profile(array $errors = []): void
{
    $user = require_user();
    $context = profile_context($user);

    $name = form_value('name', ['name' => (string)$user['name']]);
    $email = form_value('email', ['email' => (string)$user['email']]);

    $html = profile_hero_html($user, $context)
        . profile_tabs_html('edit')
        . '<section class="panel profile-information-card"><div class="profile-section-title"><i class="bi bi-pencil-square"></i><h3>Edit Profile Information</h3></div>'
        . '<div class="profile-edit-body">' . errors_html($errors)
        . '<form method="post" action="' . e(url('edit_profile')) . '">' . csrf_field()
        . '<div class="row g-3">'
        . '<div class="col-lg-6">' . field('name', 'Full Name', $name, 'text', true, 'maxlength="180" autocomplete="name"') . '</div>'
        . '<div class="col-lg-6">' . field('email', 'Email Address', $email, 'email', true, 'maxlength="190" autocomplete="email"') . '</div>'
        . '<div class="col-lg-6"><label class="form-label">Role</label><div class="profile-readonly-field">' . e((string)$context['role_text']) . '</div></div>'
        . '<div class="col-lg-6"><label class="form-label">Account Status</label><div class="profile-readonly-field">' . profile_status_badge((string)$user['status']) . '</div></div>'
        . '<div class="col-lg-6"><label class="form-label">Account Type</label><div class="profile-readonly-field">' . e((string)$context['account_type']) . '</div></div>'
        . '<div class="col-lg-6"><label class="form-label">Account ID</label><div class="profile-readonly-field">#' . (int)$user['id'] . '</div></div>'
        . '</div>'
        . ($user['user_kind'] === 'applicant'
            ? '<div class="alert alert-info small mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Changing your full name here also updates the representative name in your applicant registration. Company and contact details remain under My Registration.</div>'
            : '')
        . '<div class="profile-edit-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Save Changes</button>'
        . '<a class="btn btn-outline-secondary" href="' . e(url('profile')) . '">Cancel</a></div>'
        . '</form></div></section>';

    render_page('Edit Profile', $html);
}
