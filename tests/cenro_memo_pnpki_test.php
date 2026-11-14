<?php
declare(strict_types=1);
require_once __DIR__.'/../app/cenro_memo_pdf.php';
function memo_assert(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException('Template No. 3 PNPKI: '.$label);
}
function memo_source(string $path): string {
    $source=file_get_contents(__DIR__.'/../'.$path);
    if (!is_string($source)) throw new RuntimeException('Missing '.$path);
    return $source;
}
$memo=memo_source('app/cenro_memo.php');
$flow=memo_source('app/permit_flow.php');
$views=memo_source('app/permit_flow_views.php');
$router=memo_source('public/index.php');
$callback=memo_source('app/pnpki_bridge.php');
$migration=memo_source('database/044_cenro_memo_pnpki.sql');
memo_assert(str_contains($migration,'CREATE TABLE IF NOT EXISTS fpoms_cenro_memo_signings')
    && str_contains($migration,'remote_doc_id')
    && str_contains($migration,'released_artifact_id'), 'separate audited memo schema');
memo_assert(str_contains($memo,'function cenro_memo_start(')
    && str_contains($memo,'function cenro_memo_upload(')
    && str_contains($memo,'function cenro_memo_submit(')
    && str_contains($memo,'function cenro_memo_reconcile(')
    && str_contains($memo,"'document_kind'=>'cenro_memo'")
    && str_contains($memo,"'action'=>'status'")
    && str_contains($memo,'signed_path')
    && str_contains($memo,'fpoms_file_write_encrypted_bytes'), 'authorized RED signing and protected idempotent Records handoff');
memo_assert(str_contains($flow,'cenro_memo_release_required(')
    && str_contains($flow,'cenro_memo_is_released(')
    && str_contains($views,'$cenroMemoPending')
    && str_contains($views,'cenro_memo_panel_html'), 'Step 4 blocked until Records release');
memo_assert(str_contains($router,"case 'permit_cenro_memo_pdf':")
    && str_contains($router,"case 'permit_cenro_memo':")
    && str_contains($router,'check_csrf();')
    && str_contains($callback,'cenro_memo_reconcile('), 'secured routes and webhook routing');
$sample=[
    'company_name'=>'LNM LUMBER',
    'project_title'=>'Sample Renewable Energy Project',
    'project_area_hectares'=>'24.5',
    'project_location'=>'Agusan del Norte, Philippines',
    'concerned_penro'=>'Agusan del Norte',
    'concerned_cenro'=>'Nasipit'
];
$pdf=FpomsCenroMemoPdf::render($sample,[], 'November 14, 2026', true);
memo_assert(str_starts_with($pdf,'%PDF-')
    && str_contains($pdf,'MEMORANDUM')
    && str_contains($pdf,'MA. THERESA J. ALLEN')
    && str_contains($pdf,'LNM LUMBER')
    && str_contains($pdf,'CENRO')
    && str_contains($pdf,'%%EOF'), 'Template No. 3 generates an A4 signable PDF');
echo "PASS: Template No. 3 PDF and PNPKI_2 Records release contracts.\n";
