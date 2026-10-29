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

$registrationDetails = '<section><h2>Registration Details</h2><p>Submitted documents</p></section>';
$activeDashboard = applicant_dashboard_content_html('active', $registrationDetails);
applicant_check(
    str_contains($activeDashboard, 'Requirements for Feasibility Permit')
        && str_contains($activeDashboard, 'Account Activated'),
    'active dashboard must show the requirements and activation confirmation'
);
applicant_check(
    strpos($activeDashboard, 'Requirements for Feasibility Permit')
        < strpos($activeDashboard, 'Account Activated'),
    'requirements must appear before the active-account confirmation'
);
applicant_check(
    !str_contains($activeDashboard, 'Registration Details')
        && !str_contains($activeDashboard, 'Submitted documents')
        && str_contains($activeDashboard, 'View My Registration'),
    'active dashboard must keep full registration details out of the dashboard and offer a My Registration link'
);
applicant_check(
    str_contains($activeDashboard, 'My FP Applications'),
    'active dashboard must retain its permit application shortcut'
);

foreach (['pending_review', 'needs_correction', 'suspended'] as $status) {
    $detail = $registrationDetails
        . ($status === 'needs_correction' ? '<section>Submit Corrected Registration</section>' : '');
    $dashboard = applicant_dashboard_content_html($status, $detail);
    applicant_check(
        strpos($dashboard, 'Requirements for Feasibility Permit')
            < strpos($dashboard, 'Registration Details'),
        $status . ' must show Annex 1 first and preserve registration details afterward'
    );
    applicant_check(
        !str_contains($dashboard, 'Account Activated')
            && !str_contains($dashboard, 'My FP Applications'),
        $status . ' must not show active-account actions'
    );
    if ($status === 'needs_correction') {
        applicant_check(
            str_contains($dashboard, 'Submit Corrected Registration'),
            'correction controls must remain present after the checklist'
        );
    }
}
echo "Applicant dashboard Annex 1 checklist and status-dependent layout passed.\n";
