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

function view_profile(): void
{
    $user = require_user();
    $roles = user_roles((int)$user['id']);
    $definitions = role_definitions();
    $roleNames = array_map(static fn(string $role): string => $definitions[$role][0] ?? $role, $roles);
    $roleText = $roleNames ? implode(', ', $roleNames) : 'Unassigned';
    $accountType = $user['user_kind'] === 'applicant' ? 'External Applicant' : 'DENR Personnel';
    $initial = strtoupper(substr(trim((string)$user['name']), 0, 1)) ?: 'U';
    $registration = $user['user_kind'] === 'applicant' ? registration_for_user((int)$user['id']) : null;

    $html = '<section class="profile-hero">'
        . '<div class="profile-avatar-lg" aria-hidden="true">' . e($initial) . '</div>'
        . '<div class="profile-hero-copy"><h2>' . e((string)$user['name']) . '</h2>'
        . '<div class="profile-role">' . e($roleText) . '</div>'
        . '<div class="profile-meta"><span><i class="bi bi-person-badge"></i>' . e($accountType) . '</span>'
        . '<span><i class="bi bi-envelope"></i>' . e((string)$user['email']) . '</span></div></div>'
        . '<div class="profile-hero-actions"><a class="btn btn-outline-primary btn-sm" href="' . e(url('change_password')) . '">'
        . '<i class="bi bi-shield-lock me-1"></i>Change Password</a></div></section>';

    $html .= '<div class="profile-tabs"><span class="profile-tab active"><i class="bi bi-person-vcard me-1"></i>View Profile</span>'
        . '<a class="profile-tab" href="' . e(url('change_password')) . '"><i class="bi bi-key me-1"></i>Security</a></div>';

    $html .= '<section class="panel profile-information-card"><div class="profile-section-title"><i class="bi bi-info-circle"></i><h3>Account Information</h3></div>'
        . '<div class="profile-info-grid">'
        . '<div class="profile-info-item"><span class="profile-info-label">Full Name</span><div class="profile-info-value">' . e((string)$user['name']) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Email Address</span><div class="profile-info-value">' . e((string)$user['email']) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Account Type</span><div class="profile-info-value">' . e($accountType) . '</div></div>'
        . '<div class="profile-info-item"><span class="profile-info-label">Role</span><div class="profile-info-value">' . e($roleText) . '</div></div>'
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
