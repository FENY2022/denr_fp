<?php
declare(strict_types=1);

// DB-free layout regression: all four groups share the same upload root while
// the applicant can view and upload requirements before typing personal data.
// Standalone DB-free rendering test: the real environment defines this in bootstrap.php.
function env_value(string $key, string $fallback = ''): string
{
    return $fallback;
}
require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/registration_requirements.php';
require_once __DIR__ . '/../app/registration_attachments.php';
require_once __DIR__ . '/../app/views.php';

function layout_assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('Registration section layout: '.$message);
}

$_POST = [];
foreach ([true,false] as $isNew) {
    $html = registration_fields(['organization_type'=>'corporation'],$isNew);
    $ordered = [
        'data-registration-section="1"',
        'name="organization_type"',
        'data-registration-section="2"',
        'id="registration-entity-requirements"',
        'id="supporting-document-rows"',
        'data-add-supporting',
        'data-registration-section="3"',
        'name="representative_name"',
        'name="company_name"',
        'data-registration-section="4"',
        'id="valid-id-rows"',
        'data-add-id'
    ];
    $previous = -1;
    foreach ($ordered as $token) {
        $offset = strpos($html,$token);
        layout_assert($offset !== false && $offset > $previous,
            ($isNew ? 'new' : 'correction').' section order for '.$token);
        $previous = $offset;
    }
    layout_assert(substr_count($html,'id="registration-attachments"')===1,
        'exactly one upload root (required by existing JS)');
    layout_assert(substr_count($html,'id="registration-supporting-draft-list"')<=1,
        'no duplicate Supporting Selected Files panel');
    layout_assert(substr_count($html,'id="registration-draft-list"')<=1,
        'no duplicate Valid ID Selected Files panel');
    layout_assert(substr_count($html,'id="registration-requirements-info"')===1,
        'category guide appears exactly once inside the uploader');
    layout_assert(str_contains($html,'data-registration-requirement-card="corporation"')
        && str_contains($html,'data-registration-requirement-card="government"'),
        'both requirement groups always visible above supporting uploads');
    layout_assert(!str_contains($html,'Go to Supporting Documents Upload'),
        'no unnecessary jump link');
    layout_assert(substr_count($html,'border-success opacity-50')===3,
        'strong divider separates sections 1-4');
    layout_assert(substr_count($html,'data-registration-step=')===4,
        'registration fields expose four wizard steps');
    layout_assert((bool)preg_match('/<div id="registration-attachments"[^>]*>/',$html),
        'shared root begins before supporting uploads');
    layout_assert($isNew ? str_contains($html,'name="password"')
        : !str_contains($html,'name="password"'),
        'new accounts retain passwords while correction does not request them');
}
$default = registration_attachment_form_fields(true);
layout_assert(str_contains($default,'id="registration-attachments"')
    && str_contains($default,'id="valid-id-rows"')
    && str_contains($default,'id="supporting-document-rows"'),
    'legacy all-in-one form helper still supported');
$views = (string)file_get_contents(__DIR__.'/../app/views.php');
layout_assert(str_contains($views,"registration_section_heading_html(5,'Review and Submit Registration')"),
    'new registration has final review/submit divider');
layout_assert(str_contains($views,'<section data-registration-step="5">')
    && str_contains($views,'registration-steps.js?v=3'),
    'new registration completes the five-step Previous/Next wizard');
layout_assert(str_contains($views,"registration_section_heading_html(5,'Review and Resubmit Registration')"),
    'correction flow has final resubmit divider');
echo "PASS: Numbered organization, supporting documents, applicant, Valid ID and submit sections.\n";
