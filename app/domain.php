<?php
declare(strict_types=1);

function check_password_strength(string $password): ?string
{
    if (strlen($password) < 12) return 'Password must be at least 12 characters.';
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must include letters and numbers.';
    }
    if (strlen($password) > 200) return 'Password is too long.';
    return null;
}

function registration_validation(array $data): array
{
    $errors = [];
    $constraints = [
        'representative_name' => 180, 'company_name' => 220, 'company_address' => 2000,
        'contact_number' => 40, 'representative_position' => 150
    ];
    foreach ($constraints as $field => $max) {
        $value = trim((string)($data[$field] ?? ''));
        if ($field !== 'representative_position' && $value === '') $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        if (strlen($value) > $max) $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is too long.';
    }
    if (!preg_match('/^[0-9+().\-\s]{7,40}$/', trim((string)($data['contact_number'] ?? '')))) {
        $errors[] = 'Contact number must be 7–40 characters and contain only phone characters.';
    }
    return $errors;
}

function status_label(string $status): string
{
    return match ($status) {
        'pending_review' => 'Pending LPDD-FUS Review',
        'needs_correction' => 'Corrections Required',
        'active' => 'Account Activated',
        'suspended' => 'Suspended',
        'pending_setup' => 'Pending Account Setup',
        default => 'Unknown'
    };
}

function role_definitions(): array
{
    return [
        'super_admin' => ['Super Administrator (RICTU)', 'System configuration and DENR internal-user provisioning'],
        'applicant' => ['Feasibility Permit Applicant', 'Registration, account corrections, and (later) FP applications'],
        'ro_receiving' => ['RO Receiving/Releasing (Admin)', 'Receive applications, forward and release approvals'],
        'lpdd_fus' => ['LPDD-FUS', 'Registration review, documentary evaluation and FP drafting'],
        'lpdd_chief' => ['LPDD Chief', 'Initial the memo, permit and TOR'],
        'ard_ts' => ['ARD-TS', 'Endorse and initial documents for RED'],
        'red' => ['Regional Executive Director', 'Final approval of FP/TOR and related documents'],
        'cenro_twg' => ['CENRO Technical Working Group', 'Field validation, CSW, GIS/shapefiles and certifications'],
        'penro_user' => ['PENRO User', 'Coordination with CENRO field offices'],
        'rrdc_member' => ['RRDC Member', 'Review and deliberation committee'],
        'rrdc_secretariat' => ['RRDC Secretariat', 'Meeting minutes, agreements and signed documents'],
        'gis_focal' => ['GIS Focal', 'Prepare final GIS maps and geographical information']
    ];
}
