<?php
declare(strict_types=1);

// Standalone DB-free rendering test: the real environment defines this in bootstrap.php.
function env_value(string $key, string $fallback = ''): string
{
    return $fallback;
}
require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/registration_requirements.php';
require_once __DIR__ . '/../app/registration_attachments.php';
require_once __DIR__ . '/../app/views.php';

function review_check(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException('Registration review: '.$label);
}

$modal = registration_review_confirmation_modal_html();
$form = registration_fields(['organization_type'=>'corporation'],true);
$views = (string)file_get_contents(__DIR__.'/../app/views.php');
$route = (string)file_get_contents(__DIR__.'/../public/index.php');
$drafts = (string)file_get_contents(__DIR__.'/../app/registration_drafts.php');
$script = (string)file_get_contents(__DIR__.'/../public/assets/registration-review-confirm.js');

review_check(str_contains($modal,'id="registration-review-confirm-modal"'),
    'review summary modal exists');
review_check(str_contains($modal,'Review &amp; Confirm Applicant Registration'),
    'review title describes final confirmation');
foreach (['data-review-organization','data-review-fields','data-review-supporting',
    'data-review-ids','data-review-email','data-review-email-check',
    'data-review-confirm','data-review-preview-area','data-review-preview-media'] as $value) {
    review_check(str_contains($modal,$value),'required review control '.$value);
}
review_check(str_contains($modal,'Important Email Verification Reminder'),
    'modal explains accessible email before submission');
review_check(str_contains($modal,'I confirm that the email address I provided'),
    'email acknowledgment is specific');
review_check(str_contains($modal,'data-review-confirm disabled'),
    'final button initially disabled');
review_check(str_contains($modal,'Back to Edit'),
    'editing remains possible');
review_check(!str_contains($modal,'name="password"')
    && !str_contains($modal,'password_confirm'),
    'passwords absent from review contents');

review_check(str_contains($form,'Use an active email address you can access.'),
    'email field contains pre-submission warning');
review_check(str_contains($views,'data-registration-reviewed')
    && str_contains($views,'data-registration-email-acknowledged'),
    'review and email acknowledgments are posted only after applicant selection');
review_check(str_contains($views,'data-registration-review-open')
    && str_contains($views,'registration_review_confirmation_modal_html()')
    && str_contains($views,'registration-review-confirm.js?v=1')
    && str_contains($views,'registration-review-confirm.css?v=1'),
    'new applicant page loads modal, button, external script and stylesheet');

$gate = strpos($route,"registration_review_confirmed");
$create = strpos($route,'register_applicant($values');
review_check($gate !== false && $create !== false && $gate < $create,
    'server checks review before creating registration');
review_check(str_contains($route,"registration_email_acknowledged"),
    'server separately requires email-access acknowledgment');
review_check(str_contains($route,"case 'register_draft_preview':")
    && str_contains($route,'registration_draft_send_preview((int)$draftId)'),
    'private review preview route exists');
review_check(str_contains($drafts,'function registration_draft_send_preview(int $id): never')
    && str_contains($drafts,'registration_draft_hash(false)')
    && str_contains($drafts,'draft_hash=? AND expires_at>NOW()')
    && str_contains($drafts,'registration_draft_real_path(')
    && str_contains($drafts,"hash_file('sha256'"),
    'file previews enforce existing private draft ownership, expiry, path and integrity');
review_check(str_contains($drafts,'X-Frame-Options: SAMEORIGIN')
    && str_contains($drafts,"frame-ancestors 'self'")
    && str_contains($drafts,'Cache-Control: private, no-store'),
    'private preview has restrictive framing and caching');

review_check(str_contains($script,"form.addEventListener('submit'")
    && str_contains($script,'event.defaultPrevented')
    && str_contains($script,'form.requestSubmit(submitButton)'),
    'review runs after existing field/upload validation and reconfirms safely');
review_check(!str_contains($script,'form.submit('),
    'form.submit bypass of browser validation is forbidden');
review_check(str_contains($script,"reviewed.value = 'confirmed'")
    && str_contains($script,"acknowledged.value = '1'"),
    'final values only assigned after checkbox confirmation');
review_check(str_contains($script,'renderDocuments(')
    && str_contains($script,'register-draft-preview?id='),
    'review lists real saved documents with same-session preview');

echo "PASS: Applicant review, accessible email acknowledgment, secure file previews and guarded POST.\n";
