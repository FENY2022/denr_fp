<?php
declare(strict_types=1);

// Database-free regression for the public Annex 1 checklist.
// No session, SMTP or database is required to render this display-only content.
require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/landing.php';

$items = landing_fp_requirements();
if (count($items) !== 10) {
    throw new RuntimeException('The Annex 1 checklist must contain exactly ten numbered requirements.');
}

$expected = [
    'Letter of Intent',
    'Duly accomplished and notarized application form (Annex 1A)',
    'Proof of payment of application fee',
    'GIS Generated map of the subject area for feasibility study',
    'For corporation, cooperatives and association',
    'For National Government Agencies, Government Owned and Controlled Corporations (GOCCs) and its instrumentalities',
    'Feasibility Study Proposal (Annex 1B)',
    'Environmental Compliance Certificate (ECC)/Certificate of Non-Coverage (CNC)',
    'NCIP Clearance or proof of application for NCIP Clearance, stamped received by NCIP',
    'Energy Service Contract (ESC) or Certificate of Authority (COA) from DOE',
];
foreach ($expected as $index => $title) {
    if ($items[$index]['title'] !== $title) {
        throw new RuntimeException('Annex 1 requirement #' . ($index + 1) . ' is missing or out of order.');
    }
}

$expectedIcons = [
    'envelope-paper', 'file-earmark-check', 'receipt', 'geo-alt',
    'building-check', 'bank', 'journal-text', 'tree', 'people',
    'lightning-charge',
];
foreach ($expectedIcons as $index => $icon) {
    if (($items[$index]['icon'] ?? '') !== $icon) {
        throw new RuntimeException('Annex 1 requirement #' . ($index + 1) . ' has an incorrect icon.');
    }
}

if (!str_contains((string)$items[2]['note'], 'After being evaluated as complete by the Technical Staff')
    || $items[4]['note'] !== 'During Account Registration.'
    || $items[5]['note'] !== 'During Account Registration.'
    || !str_contains((string)$items[7]['note'], 'six (6) months')
    || !str_contains((string)$items[8]['note'], 'one (1) year')
) {
    throw new RuntimeException('The payment, account registration, or post-FP timing notes do not match Annex 1.');
}

$html = landing_fp_requirements_html();
if (substr_count($html, 'class="fp-requirement-item"') !== 10
    || substr_count($html, 'class="fp-requirement-icon"') !== 10
    || !str_contains($html, 'aria-label="Annex 1 - Feasibility Permit documentary checklist"')
    || !str_contains($html, 'Secretary’s Certification')
    || !str_contains($html, 'Authorization to apply for the FP from the Head of Agency')
) {
    throw new RuntimeException('The public checklist HTML is incomplete.');
}

echo "Landing Annex 1 checklist passed: 10 exact requirements, correct special remarks, 10 distinct icons, and full HTML.\n";
