<?php
declare(strict_types=1);

$view = (string)file_get_contents(__DIR__.'/../app/permit_step2_views.php');
$style = (string)file_get_contents(__DIR__.'/../public/assets/style.css');
$script = (string)file_get_contents(__DIR__.'/../public/assets/permit-step2-sections.js');
$draftScript = (string)file_get_contents(__DIR__.'/../public/assets/permit-step2-drafts.js');

function step2_section_assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('Permit section layout: '.$message);
}

step2_section_assert(str_contains($view,'step2_form_section_navigation_html()'),
    'section navigator helper is present');
step2_section_assert(substr_count($view,'aria-labelledby="step2-section-title-') === 4,
    'all four form sections have accessible labels');
step2_section_assert(substr_count($view,'id="step2-section-') >= 4,
    'all form sections expose anchor targets');
step2_section_assert(str_contains($view,"4=>['Review and Submit','check-circle']"),
    'navigator includes the final review section');
step2_section_assert(str_contains($style,'.step2-section-nav ol{')
    && str_contains($style,'grid-template-columns:repeat(4,minmax(0,1fr))'),
    'desktop section navigator uses a four-column overview');
step2_section_assert(str_contains($style,'.step2-section-nav ol{grid-template-columns:1fr 1fr}'),
    'section navigator adapts on mobile');
step2_section_assert(str_contains($view,'assets/permit-step2-sections.js?v=1'),
    'section wizard script is loaded by the permit form');
step2_section_assert(str_contains($script,"previous.innerHTML")
    && str_contains($script,"next.innerHTML")
    && str_contains($script,"showSection(0, false)"),
    'wizard provides Previous and Next controls and starts at section one');
step2_section_assert(str_contains($script,"field.checkValidity()")
    && str_contains($script,"form.addEventListener('invalid'"),
    'forward navigation validates fields and reveals hidden invalid sections');
step2_section_assert(str_contains($draftScript,"'step2:submission-invalid'")
    && str_contains($script,"'step2:submission-invalid'"),
    'document validation returns the applicant to the supporting documents section');

echo "PASS: Permit application has four accessible, responsive sections.\n";
