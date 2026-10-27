<?php
declare(strict_types=1);

// Pure PHP regression tests; do not require a configured MySQL database.
require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/registration_attachments.php';
require_once __DIR__ . '/../app/registration_drafts.php';

function assert_draft(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Registration draft failure: ' . $message);
}

$_SESSION = [];
assert_draft(registration_draft_hash(false) === null, 'Missing draft is not created for read-only requests');
$first = registration_draft_hash();
assert_draft(is_string($first) && strlen($first) === 64, 'New registration draft receives a hashed secret');
assert_draft($first === registration_draft_hash(false), 'Draft session key remains stable on reload');
assert_draft(!hash_equals($first, (string)$_SESSION['registration_draft_token']),
    'Raw browser-independent session secret is not written as database key');
assert_draft(registration_draft_count([
    ['document_type'=>'valid_id'],['document_type'=>'valid_id'],['document_type'=>'supporting_document']
], 'valid_id') === 2, 'Only chosen document categories count toward limits');

assert_draft(!registration_draft_has_unstaged_files([
    'id_files'=>['error'=>[0=>UPLOAD_ERR_NO_FILE]]
]), 'Empty form file selections are ignored');
assert_draft(registration_draft_has_unstaged_files([
    'supporting_files'=>['error'=>[0=>UPLOAD_ERR_OK]]
]), 'New file selected after drafts were saved is not silently skipped');
assert_draft(registration_draft_real_path('../../.env') === null,
    'Draft cleanup cannot escape the storage folder');
assert_draft(registration_draft_real_path('registrations/1/id.jpg') === null,
    'Draft functions reject permanent registrations');

unset($_SESSION['registration_draft_token']);
$html = registration_draft_list_html();
assert_draft(str_contains($html, 'id="registration-draft-list"'),
    'Refresh-persistent draft section renders even with no files');
assert_draft(str_contains($html, 'No saved uploads yet'),
    'Empty state tells users that uploads have not been saved');
assert_draft(str_contains($html, 'expire after 24 hours'),
    'Drafts are disclosed as temporary');

$draftSource = file_get_contents(__DIR__ . '/../app/registration_drafts.php');
$router = file_get_contents(__DIR__ . '/../public/index.php');
$js = file_get_contents(__DIR__ . '/../public/assets/registration-attachments.js');
$registration = file_get_contents(__DIR__ . '/../app/registration.php');
$migration = file_get_contents(__DIR__ . '/../database/012_registration_draft_uploads.sql');

assert_draft(str_contains($draftSource, 'move_uploaded_file'),
    'Drafts are saved only from verified upload buffers');
assert_draft(str_contains($draftSource, "hash_file('sha256'"),
    'Drafts are hashed for integrity');
assert_draft(str_contains($draftSource, 'registration_draft_consume'),
    'Successful registration cleans up staged originals');
assert_draft(str_contains($router, "case 'register_draft_upload':")
    && str_contains($router, "case 'register_draft_delete':"),
    'Draft upload and deletion routes are present');
assert_draft(str_contains($js, 'savedList') && str_contains($js, 'postDraft('),
    'Browser stages files immediately and shows server-backed list');
assert_draft(str_contains($js, 'pendingUploads > 0'),
    'Form cannot submit while draft upload is in progress');
assert_draft(str_contains($registration, 'registration_draft_submission()'),
    'Final registration commits saved drafts');
assert_draft(str_contains($registration, 'registration_draft_consume()'),
    'Staged originals are consumed after the transaction');
assert_draft(str_contains($migration, 'expires_at DATETIME NOT NULL'),
    'Expiry is persisted in the database');
assert_draft(str_contains($migration, 'draft_hash CHAR(64)'),
    'Draft records are session-scoped without exposing secrets');

echo "PASS: refresh-safe registration draft identity, validation, form integration and expiry checks.\n";
