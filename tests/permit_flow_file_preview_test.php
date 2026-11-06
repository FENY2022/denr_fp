<?php
declare(strict_types=1);

function flow_preview_assert(bool $passed,string $label): void
{
    if (!$passed) throw new RuntimeException('Workflow file preview: '.$label);
}

$controller=file_get_contents(__DIR__.'/../app/permit_flow.php');
$css=file_get_contents(__DIR__.'/../public/assets/permit-flow-file-preview.css');
if (!is_string($controller) || !is_string($css)) {
    throw new RuntimeException('Cannot read workflow file preview sources.');
}

flow_preview_assert(str_contains($controller,"\$icon('printer')")
    && str_contains($controller,"\$icon('pdf')")
    && str_contains($controller,"\$icon('download')")
    && str_contains($controller,'class="flow-file-icon"'),
    'print tabs use self-contained printer, PDF and download icons');
flow_preview_assert(!str_contains($controller,
    'bootstrap-icons@1.11.3/font/bootstrap-icons.min.css'),
    'preview icons no longer depend on the external icon font');
flow_preview_assert(str_contains($controller,'rel="icon" type="image/svg+xml"')
    && str_contains($controller,'assets/fpoms-icon.svg?v=24')
    && str_contains($controller,'assets/fpoms-icon-192.png?v=24'),
    'standalone document preview has the DENR FP-OMS browser-tab favicon');
flow_preview_assert(str_contains($css,'.flow-file-icon')
    && str_contains($css,'stroke:currentColor')
    && str_contains($css,'@media print')
    && substr_count($css,'{')===substr_count($css,'}'),
    'SVG icons are styled and the print stylesheet is syntactically balanced');

echo "Workflow file preview icon checks passed.\n";
