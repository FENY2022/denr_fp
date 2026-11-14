<?php
declare(strict_types=1);

// DB-free regression checks: these do not require a RED certificate.
require_once __DIR__.'/../app/pnpki_desktop_agent.php';

function agent_assert(bool $condition,string $label): void
{
    if (!$condition) throw new RuntimeException('Desktop PNPKI Agent: '.$label);
}

$draft="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
$signed=$draft.str_repeat("0",220)
    ."\n/ByteRange [0 123 456 789] /Contents <0123456789>\n%%EOF\n";
agent_assert(pnpki_agent_signed_pdf_matches($draft,$signed),
    'accept an incremental signed-PDF update with the original draft intact');
agent_assert(!pnpki_agent_signed_pdf_matches($draft,'OTHER'.$signed),
    'reject a PDF that replaces the draft content');
agent_assert(!pnpki_agent_signed_pdf_matches($draft,$draft.str_repeat('0',230)."\n%%EOF\n"),
    'reject PDFs without signature placeholders');
agent_assert(!pnpki_agent_signed_pdf_matches($draft,$draft),
    'reject unmodified PDF input');
agent_assert(!pnpki_agent_signed_pdf_matches($draft,$draft.str_repeat('0',10485761)),
    'reject signed payloads above 10 MB');

$migration=(string)file_get_contents(__DIR__.'/../database/046_pnpki_desktop_agent.sql');
$agent=(string)file_get_contents(__DIR__.'/../desktop_agent/agent.py');
$php=(string)file_get_contents(__DIR__.'/../app/pnpki_desktop_agent.php');
$router=(string)file_get_contents(__DIR__.'/../public/index.php');
$memo=(string)file_get_contents(__DIR__.'/../app/cenro_memo.php');
$notice=(string)file_get_contents(__DIR__.'/../app/payment_notice.php');
$upload=(string)file_get_contents(__DIR__.'/../app/pnpki_authorized_upload.php');
agent_assert(str_contains($migration,'enabled TINYINT(1) NOT NULL DEFAULT 0')
    && str_contains($migration,'VALUES (1,0)')
    && !str_contains($migration,'DROP TABLE'),
    'feature OFF by default and additive schema');
agent_assert(str_contains($php,"DELETE FROM fpoms_desktop_agent_tokens")
    && str_contains($php,"status='cancelled'")
    && str_contains($php,'pnpki_agent_update_enabled'),
    'switch OFF revokes tokens, cancels queued jobs and leaves signed records intact');
agent_assert(str_contains($php,'random_bytes(32)')
    && str_contains($php,'hash(\'sha256\',$secret)')
    && str_contains($php,'expires_at>NOW()')
    && str_contains($php,"pnpki_authorized_red"),
    'agent token is strong, hashed, expiring and RED-bound');
agent_assert(str_contains($php,'pnpki_agent_secure_request')
    && str_contains($agent,'assert_secure_base'),
    'transport requires HTTPS with opt-in loopback dev exception');
agent_assert(str_contains($router,"case 'pnpki_desktop_agent_api':")
    && str_contains($router,"case 'pnpki_desktop_agent':")
    && str_contains($router,'check_csrf();'),
    'API and CSRF-protected administrative forms are wired');
agent_assert(str_contains($memo,"pnpki_agent_controls_html(\$user,\$id,'cenro_memo')")
    && str_contains($notice,"pnpki_agent_controls_html(\$red,\$id,'payment_notice')")
    && str_contains($memo,'?string $agentBytes=null')
    && str_contains($upload,'?string $agentBytes=null'),
    'both Template 2 and Template 3 have reversible agent entry points');
agent_assert(str_contains($agent,'getpass.getpass')
    && str_contains($agent,'SimpleSigner.load_pkcs12')
    && str_contains($agent,'IncrementalPdfFileWriter')
    && str_contains($agent,'urllib.request.urlopen'),
    'local .p12 approval and memory-only PDF transport implemented');
agent_assert(str_contains($php,"pnpki_agent_job_draft")
    && str_contains($php,"hash_equals")
    && str_contains($php,"pnpki_agent_signed_pdf_matches"),
    'live draft hash and incremental signed PDF are checked before ingest');
echo "PASS: desktop PNPKI signer opt-in, rollback, token and signed-PDF contracts.\n";
