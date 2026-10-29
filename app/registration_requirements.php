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
