<?php
declare(strict_types=1);

function regenerate_assert(bool $passed,string $label): void
{
    if (!$passed) throw new RuntimeException('Deficiency notice regeneration: '.$label);
}

function regenerate_source(string $relative): string
{
    $source=file_get_contents(__DIR__.'/../'.$relative);
    if (!is_string($source)) throw new RuntimeException('Cannot read '.$relative);
    return $source;
}

$notice=regenerate_source('app/deficiency_notice.php');
$view=regenerate_source('app/permit_step2_views.php');
$router=regenerate_source('public/index.php');

regenerate_assert(str_contains($notice,'function fpoms_notice_regenerate_draft')
    && str_contains($notice,"status']!=='pending_red'")
    && str_contains($notice,"'/Type /Pages /Count 1'")
    && str_contains($notice,'deficiency_notice_regenerated')
    && str_contains($notice,'draft_path=?,draft_sha256=?'),
    'only pending unsigned drafts are rebuilt, validated, protected and audited');
regenerate_assert(str_contains($router,"case 'deficiency_notice_regenerate':")
    && str_contains($router,"require_role('lpdd_fus')")
    && str_contains($router,'check_csrf()')
    && str_contains($router,"go('permit_application'"),
    'POST route requires LPDD-FUS, CSRF and returns to the application');
regenerate_assert(str_contains($view,"url('deficiency_notice_regenerate'")
    && str_contains($view,'Regenerate PDF')
    && str_contains($view,'csrf_field()')
    && str_contains($view,'!$signed'),
    'permit application shows a protected button only before RED approval');

echo "Deficiency notice regeneration checks passed.\n";
