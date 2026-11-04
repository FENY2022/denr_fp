<?php
declare(strict_types=1);

/**
 * Named Annex 1 FP supporting-document categories. These are upload options;
 * an asterisk is part of the provided checklist, not a server-side conclusion
 * about applicability to every application.
 */
function step2_requirement_types(): array
{
    return [
        'letter_of_intent' => 'Letter of Intent',
        'annex_1a' => 'Duly accomplished and notarized application form (Annex 1A)',
        'annex_1b' => 'Feasibility Study Proposal (Annex 1B)',
        'ecc_cnc' => 'Environmental Compliance Certificate (ECC) / Certificate of Non-Coverage (CNC)*',
        'ncip_clearance' => 'NCIP Clearance or proof of application for NCIP Clearance, stamped received by NCIP**',
        'doe_esc_coa' => 'Energy Service Contract (ESC) or Certificate of Authority (COA) from DOE',
    ];
}

/** Annex 1 project classification. Other requires a named type and DOE #10. */
function step2_project_types(): array
{
    return [
        'solar'=>'Solar',
        'biomass'=>'Biomass',
        'other'=>'Other'
    ];
}

function step2_project_label(string $type, ?string $other=''): string
{
    if ($type==='other') {
        $name=trim((string)$other);
        return $name!=='' ? 'Other – '.$name : 'Other (not specified)';
    }
    return step2_project_types()[$type] ?? 'Not recorded (legacy application)';
}

function step2_project_type_schema_ready(): bool
{
    try {
        db()->query('SELECT project_type,project_type_other FROM permit_step2_applications LIMIT 0');
        db()->query('SELECT project_type,project_type_other FROM permit_step2_form_drafts LIMIT 0');
        return true;
    } catch (Throwable $e) {
        error_log('FP-OMS Annex 1 migration 030 required: '.$e->getMessage());
        return false;
    }
}

function step2_project_requires_doe(string $type): bool
{
    return $type==='other';
}

function step2_project_doe_exempt(string $type): bool
{
    return in_array($type,['solar','biomass'],true);
}

/** Not every Annex 1 item is due at the initial application stage. */
function step2_requirement_note(string $type, string $projectType=''): string
{
    return match ($type) {
        'ecc_cnc'=>'ECC/CNC shall be secured within six (6) months after FP issuance.',
        'ncip_clearance'=>'NCIP Clearance shall be secured within one (1) year after FP issuance; stamped proof of application is an accepted checklist alternative.',
        'doe_esc_coa'=>step2_project_doe_exempt($projectType)
            ? 'Not Applicable: Solar and Biomass are exempt from Annex 1 requirement #10.'
            : ($projectType==='other'
                ? 'Required for Other project types: upload DOE ESC or COA.'
                : 'Solar and Biomass are exempt; other project types must provide DOE ESC or COA.'),
        default=>''
    };
}

/** Optional extra attachments are not a seventh mandatory assessment. */
function step2_upload_categories(): array
{
    return step2_requirement_types() + ['other'=>'Other Attached Document (Optional)'];
}

function step2_requirement_label(?string $type): string
{
    $choices = step2_requirement_types();
    if ($type === 'other') return 'Other Attached Document';
    return $choices[$type ?? ''] ?? 'Unclassified Supporting Document';
}

function step2_requirement_valid(string $value): bool
{
    return isset(step2_upload_categories()[$value]);
}

function step2_requirement_icon(string $type): string
{
    return match ($type) {
        'letter_of_intent' => 'envelope-paper',
        'annex_1a' => 'file-earmark-text',
        'annex_1b' => 'journal-text',
        'ecc_cnc' => 'shield-check',
        'ncip_clearance' => 'people',
        'doe_esc_coa' => 'lightning-charge',
        'other' => 'paperclip',
        default => 'file-earmark',
    };
}

/** Migration 019 must be installed for categorized uploads to persist. */
function step2_category_schema_ready(): bool
{
    try {
        db()->query('SELECT requirement_type FROM permit_step2_documents LIMIT 0');
        db()->query('SELECT requirement_type FROM permit_step2_draft_documents LIMIT 0');
        return true;
    } catch (Throwable $error) {
        error_log('FP-OMS migration 019 missing: '.$error->getMessage());
        return false;
    }
}

/** Displays current persisted category counts, without presuming content validity. */
function step2_requirement_checklist_html(array $documents): string
{
    $count = [];
    foreach ($documents as $document) {
        $type = (string)($document['requirement_type'] ?? 'other');
        $count[$type] = ($count[$type] ?? 0) + 1;
    }
    $html = '<div class="border rounded-3 p-3 mb-3 step2-requirements-review" data-step2-requirements-review>'
        . '<h3 class="h6 text-success d-flex align-items-center gap-2">'
        . '<i class="bi bi-clipboard2-check" aria-hidden="true"></i>FP Documentary Requirements</h3>'
        . '<p class="small text-secondary d-flex align-items-start gap-2">'
        . '<i class="bi bi-info-circle mt-1 flex-shrink-0" aria-hidden="true"></i><span>Based on uploaded file categories. '
        . 'LPDD-FUS still checks document contents and whether * / ** requirements apply.</span></p>'
        . '<ul class="list-group step2-requirements-list">';
    foreach (step2_requirement_types() as $key => $label) {
        $n = (int)($count[$key] ?? 0);
        $html .= '<li class="list-group-item step2-requirement-review-item">'
            . '<span class="step2-requirement-review-label"><i class="bi bi-'
            . e(step2_requirement_icon($key)) . '" aria-hidden="true"></i><span>' . e($label) . '</span></span>'
            . '<span class="badge step2-requirement-review-status '
            . ($n ? 'text-bg-success' : 'text-bg-secondary') . '"><i class="bi bi-'
            . ($n ? 'check-circle-fill' : 'dash-circle') . '" aria-hidden="true"></i>'
            . ($n ? 'Uploaded: '.$n : 'Not uploaded') . '</span></li>';
    }
    $html .= '</ul>';
    if (!empty($count['other'])) {
        $html .= '<p class="alert alert-info small mt-3 mb-0">'
            . (int)$count['other'] . ' Other Attached Document(s) retained. '
            . 'These are supplemental uploads, not a substitute for a named requirement.</p>';
    }
    return $html . '</div>';
}
