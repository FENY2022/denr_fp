<?php
declare(strict_types=1);

/** Values are persisted; do not change keys without a migration. */
function registration_organization_types(): array
{
    return [
        'individual'=>'Individual / Sole Proprietorship',
        'corporation'=>'Corporation / Cooperative / Association',
        'government'=>'National Government Agency / GOCC / Government Instrumentality',
    ];
}

function registration_requirement_choices(): array
{
    return [
        'other'=>'Other Supporting Document',
        'certificate_registration'=>'Certified True Copy of Certificate of Registration from the registering agency',
        'secretary_certification'=>'Secretary’s Certification authorizing the representative to transact with DENR on behalf of the company',
        'head_authorization'=>'Authorization to Apply for the FP from the Head of Agency',
    ];
}

function registration_requirement_label(?string $type): string
{
    return registration_requirement_choices()[$type ?? ''] ?? 'Other Supporting Document';
}

function registration_required_categories(string $organization): array
{
    return match ($organization) {
        'corporation'=>['certificate_registration','secretary_certification'],
        'government'=>['head_authorization'],
        default=>[],
    };
}

/**
 * Enforce mandatory document groups on the server, regardless of client-side
 * visibility or JavaScript. A category may contain multiple document files.
 */
/**
 * Always-visible applicant-type documentary guide. Do not hide sections with
 * JavaScript: first-time applicants need to see every rule before selecting a type.
 * Static copy comes from the same requirement labels as server-side validation.
 */
function registration_requirement_guide_html(): string
{
    $cert = htmlspecialchars(registration_requirement_label('certificate_registration'),
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $secretary = htmlspecialchars(registration_requirement_label('secretary_certification'),
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $authorization = htmlspecialchars(registration_requirement_label('head_authorization'),
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<section id="registration-entity-requirements" class="mb-4" '
        . 'data-registration-entity-guide aria-labelledby="registration-entity-guide-title">'
        . '<h3 id="registration-entity-guide-title" class="h6 mb-2">'
        . '<i class="bi bi-card-checklist text-success me-2" aria-hidden="true"></i>'
        . 'Documentary Requirements by Applicant Type</h3>'
        . '<p class="small text-secondary mb-3">All requirements are shown below before '
        . 'you select an applicant type. Only the documents for your selected type '
        . 'are mandatory, in addition to the existing Valid ID requirement.</p>'
        . '<div class="row g-3 mb-2">'
        . '<div class="col-lg-6"><div class="border rounded-3 p-3 h-100" '
        . 'data-registration-requirement-card="corporation">'
        . '<h4 class="h6">For Corporations, Cooperatives and Associations'
        . '<span class="badge text-bg-success ms-2 d-none" data-entity-guide-badge>'
        . 'Selected Type</span></h4>'
        . '<ol type="a" class="small ps-3 mb-0">'
        . '<li class="mb-2">' . $cert . '.</li>'
        . '<li>' . $secretary . '.</li></ol></div></div>'
        . '<div class="col-lg-6"><div class="border rounded-3 p-3 h-100" '
        . 'data-registration-requirement-card="government">'
        . '<h4 class="h6">For National Government Agencies, '
        . 'Government-Owned or -Controlled Corporations (GOCCs) '
        . 'and Government Instrumentalities'
        . '<span class="badge text-bg-success ms-2 d-none" data-entity-guide-badge>'
        . 'Selected Type</span></h4>'
        . '<p class="small mb-0">' . $authorization . '.</p>'
        . '</div></div></div>'
        . '<p class="small text-secondary mb-2">'
        . '<strong>Individual / Sole Proprietorship:</strong> Existing registration '
        . 'requirements apply; no new category-specific document is required.</p>'
        . '<p class="small text-secondary mb-2" role="status" '
        . 'data-registration-entity-guide-status>'
        . 'Select your Applicant / Organization Type above to highlight the '
        . 'requirements that apply to you.</p>'
        . '<p class="small text-secondary mb-0">Upload the required supporting '
        . 'documents directly below, in Section 2.</p>'
        . '</section>';
}

function registration_requirement_errors(string $organization,array $documents): array
{
    $errors = [];
    if (!isset(registration_organization_types()[$organization])) {
        return ['Select a valid Applicant / Organization Type.'];
    }
    $seen = [];
    foreach ($documents as $doc) {
        if (($doc['document_type'] ?? '') !== 'supporting_document') continue;
        $category = (string)($doc['requirement_type'] ?? 'other');
        if (isset(registration_requirement_choices()[$category])) $seen[$category] = true;
    }
    foreach (registration_required_categories($organization) as $required) {
        if (empty($seen[$required])) {
            $errors[] = 'Upload at least one file for: ' . registration_requirement_label($required) . '.';
        }
    }
    return $errors;
}

/** Check migration 018 before writing classified attachments to the database. */
function registration_requirements_schema_ready(): bool
{
    try {
        db()->query('SELECT organization_type FROM applicant_registrations LIMIT 0');
        db()->query('SELECT requirement_type FROM registration_documents LIMIT 0');
        db()->query('SELECT requirement_type FROM registration_draft_documents LIMIT 0');
        return true;
    } catch (Throwable $error) {
        error_log('Registration requirement migration preflight: '.$error->getMessage());
        return false;
    }
}
