<?php
declare(strict_types=1);

// Pure view checks without a DB, user session or real credentials.
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
    return str_replace('_','-',$page) . ($query ? '?' . http_build_query($query) : '');
}
function errors_html(array $errors): string
{
    return implode(', ',array_map('e',$errors));
}
function assert_edit_modal(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('Edit modal test failed: ' . $label);
}

$html = user_management_edit_modal();
assert_edit_modal(str_contains($html, 'id="userEditModal"'), 'Reusable Edit User modal exists');
assert_edit_modal(str_contains($html, 'id="editUserProfileForm"'), 'Profile form exists');
assert_edit_modal(str_contains($html, 'id="editUserPasswordForm"'), 'Separate password reset form exists');
assert_edit_modal(substr_count($html, 'name="csrf_token"') === 2, 'Each action has a CSRF token');
assert_edit_modal(str_contains($html, 'name="action" value="update"'), 'Profile action is update');
assert_edit_modal(str_contains($html, 'name="action" value="reset_password"'), 'Password reset action is distinct');
assert_edit_modal(substr_count($html, 'name="edit_origin" value="user_management_modal"') === 2,
    'Modal origin is attached to both forms');
assert_edit_modal(str_contains($html, 'name="name"'), 'Full name uses expected backend name');
assert_edit_modal(str_contains($html, 'name="email"'), 'Email uses expected backend name');
assert_edit_modal(str_contains($html, 'name="contact_number"'), 'Contact number uses expected backend name');
assert_edit_modal(str_contains($html, 'name="office_station"'), 'Office uses expected backend name');
assert_edit_modal(str_contains($html, 'name="role"'), 'Role assignment exists');
assert_edit_modal(str_contains($html, 'name="status"'), 'Account status selection exists');
assert_edit_modal(str_contains($html, 'id="edit_user_name"'), 'Unique input ID avoids Set Up collision');
assert_edit_modal(str_contains($html, 'name="new_password"'), 'Reset new password exists');
assert_edit_modal(str_contains($html, 'name="confirm_password"'), 'Password reset confirmation exists');
assert_edit_modal(substr_count($html, 'data-toggle-edit-password') === 2, 'Password visibility buttons exist');
assert_edit_modal(str_contains($html, 'data-edit-self-notice'), 'Admin self-protection notice exists');
assert_edit_modal(str_contains($html, 'data-edit-reset-tab'), 'Password tab can be hidden for current admin');
assert_edit_modal(str_contains($html, 'user-edit-modal.js?v=1'), 'Edit modal JavaScript linked');
assert_edit_modal(str_contains($html, 'modal-dialog-scrollable'), 'Small viewport scrolling enabled');
assert_edit_modal(str_contains($html, 'data-reopen="0"'), 'Modal does not open automatically');
assert_edit_modal(!str_contains($html, 'value="password_hash"'), 'Password hashes not rendered');

$field = user_management_edit_field('name', 'Employee Name', '" onmouseover="alert(1)',true);
assert_edit_modal(str_contains($field,'name="name"'), 'Backend field name preserved');
assert_edit_modal(str_contains($field,'id="edit_user_name"'), 'Unique input ID preserved');
assert_edit_modal(!str_contains($field,'name="edit_user_name"'), 'Backend field name not changed');
assert_edit_modal(str_contains($field,'&quot; onmouseover=&quot;alert(1)'), 'Attribute input escaped');

echo "PASS: Edit User popup profile and password forms, role/status fields and HTML escaping.\n";
