<?php
declare(strict_types=1);

// Pure view regression checks; no database, credentials or external services needed.
require_once __DIR__ . '/../app/domain.php';
require_once __DIR__ . '/../app/user_management.php';
require_once __DIR__ . '/../app/user_management_views.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="test-token">';
}
function url(string $page = 'dashboard', array $query = []): string
{
    $slug = str_replace('_','-',$page);
    return $slug . ($query ? '?' . http_build_query($query) : '');
}
function errors_html(array $errors): string
{
    return implode(', ',array_map('e',$errors));
}
function assert_modal(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Set Up modal test failed: ' . $message);
}

$html = user_management_setup_modal();
assert_modal(str_contains($html, 'id="userSetupModal"'), 'One reusable Bootstrap modal exists');
assert_modal(str_contains($html, 'data-reopen="0"'), 'Default view does not auto-open modal');
assert_modal(str_contains($html, 'id="userSetupForm"'), 'Modal includes an activation form');
assert_modal(str_contains($html, 'name="action" value="activate"'), 'Activation action is submitted');
assert_modal(str_contains($html, 'name="setup_origin" value="user_management_modal"'), 'Modal submissions identified');
assert_modal(str_contains($html, 'name="csrf_token"'), 'CSRF field exists');
assert_modal(str_contains($html, 'name="status" value="active"'), 'Activation status is set');
assert_modal(str_contains($html, 'name="confirm_identity"'), 'Identity verification is required');
assert_modal(str_contains($html, 'id="setupPassword"'), 'Password entry exists');
assert_modal(str_contains($html, 'id="setupPasswordConfirm"'), 'Password confirmation exists');
assert_modal(substr_count($html,'data-toggle-setup-password') === 2, 'Both password toggles exist');
assert_modal(str_contains($html, 'user-setup-modal.js?v=1'), 'Modal behavior script is loaded');
assert_modal(str_contains($html, 'option value="cenro_twg"'), 'Assigned role list is available');
assert_modal(str_contains($html, 'modal-dialog-scrollable'), 'Modal supports smaller viewports');
assert_modal(str_contains($html, 'Share'), 'Temporary password security notice exists');

$payload = user_management_field(
    'employee_name', 'Full Name', 'A "B" <script>alert(1)</script>', true
);
assert_modal(!str_contains($payload,'<script>'), 'Employee field escapes HTML');
assert_modal(str_contains($payload,'&lt;script&gt;'), 'Employee field encodes unsafe HTML');
assert_modal(str_contains($payload,'&quot;B&quot;'), 'Employee field encodes quotes');

echo "PASS: User Set Up modal structure, CSRF, identity and password controls, and HTML escaping.\n";
