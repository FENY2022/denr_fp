<?php
declare(strict_types=1);

function notice_modal_assert(bool $passed,string $label): void
{
    if (!$passed) throw new RuntimeException('Deficiency notice queue modal: '.$label);
}

function notice_modal_source(string $relative): string
{
    $source=file_get_contents(__DIR__.'/../'.$relative);
    if (!is_string($source)) throw new RuntimeException('Cannot read '.$relative);
    return $source;
}

$controller=notice_modal_source('app/deficiency_notice.php');
$application=notice_modal_source('app/permit_step2_views.php');
$views=notice_modal_source('app/views.php');
$routes=notice_modal_source('public/index.php');
$script=notice_modal_source('public/assets/deficiency-notice-queue.js');
$style=notice_modal_source('public/assets/deficiency-notice-queue.css');

notice_modal_assert(str_contains($controller,'id="notice-pdf-preview-modal"')
    && str_contains($controller,'data-notice-preview-url=')
    && str_contains($controller,"'embed'=>1")
    && str_contains($controller,"'raw'=>1"),
    'draft and signed PDF buttons open one reusable embedded viewer');
notice_modal_assert(str_contains($controller,'function fpoms_notice_pdf_preview_modal_html()')
    && str_contains($controller,'fpoms_notice_pdf_preview_modal_html();'),
    'queue uses a shared PDF preview modal component');
notice_modal_assert(str_contains($application,'data-bs-target="#notice-pdf-preview-modal"')
    && str_contains($application,"'embed'=>1")
    && str_contains($application,"'raw'=>1")
    && str_contains($application,'data-notice-auto-open')
    && str_contains($application,'fpoms_notice_pdf_preview_modal_html();'),
    'permit application auto-opens the released client PDF in the shared modal');
notice_modal_assert(str_contains($controller,"header('X-Frame-Options: SAMEORIGIN')")
    && str_contains($controller,"frame-ancestors 'self'"),
    'embedded draft PDF remains restricted to the same origin');
notice_modal_assert(str_contains($views,'assets/deficiency-notice-queue.css?v=1')
    && str_contains($views,'assets/deficiency-notice-queue.js?v=3'),
    'queue modal assets load only when the modal is present');
notice_modal_assert(str_contains($script,"show.bs.modal")
    && str_contains($script,'url.origin!==location.origin')
    && str_contains($script,"hidden.bs.modal"),
    'modal validates preview URLs and releases the iframe after closing');
notice_modal_assert(str_contains($controller,'if ($signOnly) return [];')
    && str_contains($controller,'data-notice-preview-app=')
    && str_contains($controller,'name="action" value="')
    && str_contains($routes,"'preview_signed'=>(int)\$id")
    && str_contains($script,'Modal.getOrCreateInstance(modal).show(autoTrigger)')
    && str_contains($script,"!query.has('view')"),
    'e-sign saves the PDF and automatically opens the saved artifact before final release');
notice_modal_assert(str_contains($style,'max-width:1500px')
    && str_contains($style,'height:94vh')
    && substr_count($style,'{')===substr_count($style,'}'),
    'viewer is extra large, responsive and has balanced CSS');

echo "Deficiency notice queue modal checks passed.\n";
