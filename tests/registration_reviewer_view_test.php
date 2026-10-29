<?php
declare(strict_types=1);

function env_value(string $key, string $fallback = ''): string
{
    return $fallback;
}

require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/registration_requirements.php';
require_once __DIR__ . '/../app/registration_attachments.php';

function reviewer_view_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Registration reviewer view: ' . $message);
}

$html = registration_reviewer_documents_html([
    [
        'id'=>12, 'document_type'=>'valid_id', 'id_type'=>'passport',
        'requirement_type'=>null, 'original_name'=>'passport.png',
        'mime_type'=>'image/png', 'file_size'=>204800, 'revision'=>2,
    ],
    [
        'id'=>13, 'document_type'=>'supporting_document', 'id_type'=>null,
        'requirement_type'=>'certificate_registration', 'original_name'=>'<certificate>.pdf',
        'mime_type'=>'application/pdf', 'file_size'=>1572864, 'revision'=>2,
    ],
]);

reviewer_view_assert(str_contains($html, 'Valid Identification Cards')
    && str_contains($html, 'Supporting Documents'),
    'uploads are grouped into reviewer-friendly categories');
reviewer_view_assert(str_contains($html, 'Philippine Passport')
    && str_contains($html, 'Certificate of Registration'),
    'stored ID and requirement labels are shown');
reviewer_view_assert(str_contains($html, '200 KB')
    && str_contains($html, '1.5 MB')
    && str_contains($html, 'Latest submission'),
    'file metadata and latest revision are visible');
reviewer_view_assert(substr_count($html, 'data-document-preview') === 2
    && substr_count($html, '<i class="bi bi-download') === 2,
    'each uploaded file has preview and download actions');
reviewer_view_assert(!str_contains($html, '<certificate>'),
    'uploaded filenames remain escaped');

$views = (string)file_get_contents(__DIR__ . '/../app/views.php');
reviewer_view_assert(str_contains($views, 'reviewer-case-hero')
    && str_contains($views, 'registration_reviewer_documents_html($docs)'),
    'review case uses the enhanced summary and document view');

echo "PASS: Enhanced reviewer case summary and secure registration document cards.\n";
