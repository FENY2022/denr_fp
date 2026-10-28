<?php
declare(strict_types=1);

// DB-free regression test for the applicant-only Annex 1 dashboard checklist.
require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/landing.php';
require_once __DIR__ . '/../app/views.php';

function applicant_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Applicant checklist failed: ' . $message);
}

$landingItems = landing_fp_requirements();
applicant_check(count($landingItems) === 10, 'shared Annex 1 data must have exactly ten requirements');

$pending = applicant_dashboard_requirements_html(false);
$active = applicant_dashboard_requirements_html(true);
$public = landing_fp_requirements_html();

foreach ([$pending, $active] as $html) {
    applicant_check(
        substr_count($html, 'class="fp-requirement-item"') === 10,
        'all ten requirements should be rendered'
    );
    applicant_check(
        substr_count($html, 'class="fp-requirement-icon"') === 10,
        'all ten unique icons should be rendered'
    );
    foreach ($landingItems as $requirement) {
        applicant_check(str_contains($html, e((string)$requirement['title'])), 'a requirement title is missing');
        applicant_check(
            str_contains($html, 'bi-' . e((string)$requirement['icon'])),
            'an icon does not match the public landing'
        );
    }
    applicant_check(str_contains($html, 'six (6) months'), 'ECC/CNC timeline is missing');
    applicant_check(str_contains($html, 'one (1) year'), 'NCIP timeline is missing');
    applicant_check(str_contains($html, 'After being evaluated as complete'), 'fee timing is missing');
    applicant_check(
        str_contains($html, 'not a record of documents already uploaded or approved'),
        'dashboard checklist is incorrectly portrayed as a completed upload state'
    );
    applicant_check(
        str_contains($html, 'id="applicant-fp-requirements"'),
        'checklist stylesheet selector is missing'
    );
}
applicant_check(!str_contains($pending, 'href="permit-applications"'), 'inactive applicant must not see Step 2 CTA');
applicant_check(str_contains($active, 'href="permit-applications"'), 'active applicant should see Step 2 CTA');
applicant_check(
    substr_count($public, 'class="fp-requirement-item"') ===
    substr_count($pending, 'class="fp-requirement-item"'),
    'landing and applicant checklist item counts must agree'
);
echo "Applicant dashboard Annex 1 checklist passed.\n";
