<?php
declare(strict_types=1);

// DB-free regression: Registration #28 example contains four "other"
// supporting files and one driver's license. Do not infer categories from names.
require_once __DIR__ . '/../app/registration_attachments.php';
require_once __DIR__ . '/../app/registration_requirements.php';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function url(string $page, array $params = []): string
{
    return $page . ($params ? '?' . http_build_query($params) : '');
}
function review_assert(bool $condition,string $message): void
{
    if (!$condition) throw new RuntimeException('Registration upload review: '.$message);
}

$sample = [];
foreach ([60,59,58,57,56] as $id) {
    $sample[] = [
        'id'=>$id,
        'document_type'=>$id === 57 ? 'valid_id' : 'supporting_document',
        'requirement_type'=>'other',
        'id_type'=>$id === 57 ? 'drivers_license' : null,
        'original_name'=>'Client actual file '.$id.'.pdf',
        'mime_type'=>'application/pdf',
        'file_size'=>125000,
        'revision'=>1
    ];
}
$html=registration_reviewer_documents_html($sample);
$summary=registration_reviewer_requirements_html('corporation',$sample);
$compact = registration_documents_html($sample);
foreach ([$html,$compact] as $modalVersion) {
    review_assert(str_contains($modalVersion, 'data-document-previous disabled'),
        'Previous control exists and starts disabled');
    review_assert(str_contains($modalVersion, 'data-document-next disabled'),
        'Next control exists and starts disabled');
    review_assert(str_contains($modalVersion, 'data-document-counter'),
        'Document position counter is rendered in every preview modal');
}

review_assert(substr_count($html,'data-submitted-document-id=')===5,
    'each of the five submitted rows is displayed exactly once');
review_assert(substr_count($html,'data-document-preview ')===5,
    'every submitted file has a preview');
review_assert(substr_count($html,'bi-download')===5,
    'every submitted file has a download link');
foreach ([56,57,58,59,60] as $id) {
    review_assert(str_contains($html,'data-submitted-document-id="'.$id.'"'),
        'stored document '.$id.' is not hidden');
    review_assert(str_contains($html,'Client actual file '.$id.'.pdf'),
        'the original filename is retained for '.$id);
    review_assert(str_contains($html,'id='.$id),
        'preview/download URLs use real submitted document ids');
}
review_assert(str_contains($html, "Driver's License"),
    'stored ID type remains driver license');
review_assert(str_contains($html,'Stored category: other')
    && str_contains($html,'Other Supporting Document'),
    'historical files retain the exact stored Other category');
review_assert(str_contains($summary,'4 Other/Unclassified'),
    'all four unclassified supporting uploads are called out');
review_assert(substr_count($summary,'Missing category')===2,
    'legacy Other docs do not silently become mandatory corporation docs');
review_assert(!str_contains($html,'No files uploaded in this category.'),
    'both ID and supporting sections show their actual rows');

$proper = [
    ['id'=>61,'document_type'=>'supporting_document',
     'requirement_type'=>'certificate_registration','original_name'=>'cert.pdf',
     'mime_type'=>'application/pdf','file_size'=>500,'revision'=>2],
    ['id'=>62,'document_type'=>'supporting_document',
     'requirement_type'=>'secretary_certification','original_name'=>'secretary.pdf',
     'mime_type'=>'application/pdf','file_size'=>500,'revision'=>2]
];
$properSummary=registration_reviewer_requirements_html('corporation',$proper);
$properCards=registration_reviewer_documents_html($proper);
review_assert(substr_count($properSummary,'Submitted: 1')===2,
    'both properly tagged corporate document types are present');
review_assert(!str_contains($properSummary,'Missing category'),
    'corporation checklist detects both requirements');
review_assert(str_contains($properCards,'Stored category: secretary_certification')
    && str_contains($properCards,'Stored category: certificate_registration'),
    'submitted requirement metadata is shown without rewriting');

$government=registration_reviewer_requirements_html('government',[
    ['document_type'=>'supporting_document','requirement_type'=>'head_authorization']
]);
review_assert(str_contains($government,'Submitted: 1'),
    'Head of Agency authorization is recognized for government applicants');

$views=(string)file_get_contents(__DIR__.'/../app/views.php');
$registration=(string)file_get_contents(__DIR__.'/../app/registration.php');
$drafts=(string)file_get_contents(__DIR__.'/../app/registration_drafts.php');
$uploader=(string)file_get_contents(__DIR__.'/../public/assets/registration-attachments.js');
$guide=(string)file_get_contents(__DIR__.'/../public/assets/registration-requirements.js');
$preview=(string)file_get_contents(__DIR__.'/../public/assets/registration-document-preview.js');
review_assert(str_contains($views,'registration_reviewer_requirements_html(')
    && str_contains($views,'registration_reviewer_documents_html($currentSupporting')
    && str_contains($views,'registration_reviewer_documents_html($currentIds')
    && str_contains($views,'registration_reviewer_documents_html($previousSupporting')
    && str_contains($views,'registration_reviewer_documents_html($previousIds'),
    'review page lists Supporting Documents and Valid IDs separately by revision');
review_assert(str_contains($registration,'SELECT * FROM registration_documents WHERE registration_id=?')
    && str_contains($registration,'$attachment[\'requirement_type\']'),
    'permanent submitted docs are queried by registration and category is stored');
review_assert(str_contains($drafts,'requirement_type')
    && str_contains($drafts,'$category = (string)('),
    'draft upload persists the category supplied by the applicant');
review_assert(!str_contains($guide,'chooseForNewRow(')
    && !str_contains($guide,"select.value=(required[type.value]"),
    'client-side auto-labeling cannot silently change upload category');
review_assert(str_contains($uploader,'[data-supporting-category]')
    && str_contains($uploader,"data.set('requirement_type',category)"),
    'selected supporting-document category accompanies file upload');
review_assert(!str_contains($preview,'.style.'),
    'review preview zoom obeys strict CSS Content Security Policy');
echo "PASS: every client uploaded document remains visible with its stored category and preview.\n";
