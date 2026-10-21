<?php
declare(strict_types=1);

function super_admin_exists(): bool
{
    $row = query_one(
        "SELECT u.id
         FROM users u
         INNER JOIN user_roles ur ON ur.user_id=u.id
         INNER JOIN roles r ON r.id=ur.role_id
         WHERE r.code='super_admin'
         LIMIT 1"
    );
    return $row !== null;
}

function initial_admin_setup_available(): bool
{
    return !super_admin_exists();
}

function create_initial_super_admin(array $input): array
{
    if (super_admin_exists()) {
        return ['Initial setup is already complete. A Super Administrator account already exists.'];
    }

    $configuredKey = env_value('FPOMS_SETUP_KEY');
    if ($configuredKey === '') {
        return ['Initial administrator setup is disabled until FPOMS_SETUP_KEY is configured in the server .env file.'];
    }

    $name = trim((string)($input['name'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');
    $setupKey = (string)($input['setup_key'] ?? '');

    $errors = [];
    if ($name === '' || strlen($name) > 180) {
        $errors[] = 'Administrator full name is required and must not exceed 180 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors[] = 'Enter a valid administrator work email.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Initial password confirmation does not match.';
    }
    if ($error = check_password_strength($password)) {
        $errors[] = $error;
    }
    if ($setupKey === '' || !hash_equals($configuredKey, $setupKey)) {
        $errors[] = 'The one-time setup key is invalid.';
    }
    if ($email !== '' && query_one('SELECT id FROM users WHERE email=?', [$email])) {
        $errors[] = 'A user account already exists with this email address.';
    }
    if ($errors) {
        return $errors;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $role = query_one('SELECT id FROM roles WHERE code=?', ['super_admin']);
        if (!$role) {
            throw new RuntimeException('The super_admin role is missing. Run scripts/seed_roles.php first.');
        }

        // Re-check inside the transaction to reduce the chance of a duplicate bootstrap.
        $existingAdmin = query_one(
            "SELECT u.id
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id=u.id
             INNER JOIN roles r ON r.id=ur.role_id
             WHERE r.code='super_admin'
             LIMIT 1"
        );
        if ($existingAdmin) {
            throw new RuntimeException('Initial setup was completed by another request. No additional administrator was created.');
        }

        $pdo->prepare(
            "INSERT INTO users
                (name,email,password_hash,user_kind,status,must_change_password,activated_at)
             VALUES
                (?,?,?,'internal','active',1,NOW())"
        )->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

        $userId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')
            ->execute([$userId, (int)$role['id']]);

        audit($userId, 'admin.bootstrapped_web', 'user', $userId, [
            'role' => 'super_admin',
            'method' => 'initial_setup'
        ]);

        $pdo->commit();
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [$e->getMessage()];
    }
}

function view_initial_admin_setup(array $errors = []): void
{
    if (current_user()) {
        go('dashboard');
    }
    if (super_admin_exists()) {
        show_error('Initial Super Administrator setup is already complete.', 404);
    }

    $configured = env_value('FPOMS_SETUP_KEY') !== '';
    $notice = $configured
        ? '<div class="alert alert-info small"><i class="bi bi-shield-lock me-1"></i>This page works only until the first RICTU Super Administrator is created. Enter the one-time setup key configured by RICTU on the server.</div>'
        : '<div class="alert alert-warning small"><strong>Setup key required.</strong> Add <code>FPOMS_SETUP_KEY</code> to the server <code>.env</code> first, then reload this page.</div>';

    $html = '<div class="row justify-content-center"><div class="col-lg-7 col-xl-6"><div class="panel">'
        . errors_html($errors)
        . '<div class="d-flex align-items-center gap-3 mb-3"><span class="install-icon"><i class="bi bi-person-gear"></i></span>'
        . '<div><h2 class="h5 mb-1">Create RICTU Super Administrator</h2><p class="text-secondary small mb-0">One-time system bootstrap for DENR FP-OMS.</p></div></div>'
        . $notice
        . '<form method="post" action="' . url('setup_admin') . '" autocomplete="off">' . csrf_field()
        . field('name', 'Administrator full name', form_value('name'), 'text', true, 'maxlength="180"')
        . field('email', 'Official / work email', form_value('email'), 'email', true, 'autocomplete="email" maxlength="190"')
        . field('setup_key', 'One-time setup key', '', 'password', true, 'autocomplete="off" maxlength="255"')
        . field('password', 'Initial password', '', 'password', true, 'autocomplete="new-password" minlength="12"')
        . field('confirm_password', 'Confirm initial password', '', 'password', true, 'autocomplete="new-password" minlength="12"')
        . '<div class="form-text mb-3">Use at least 12 characters with letters and numbers. The administrator will be required to change this password on first login.</div>'
        . '<button class="btn btn-primary w-100" type="submit"' . ($configured ? '' : ' disabled') . '><i class="bi bi-shield-check me-1"></i>Create Super Administrator</button>'
        . '</form><hr><p class="small text-secondary mb-0">After successful creation, this setup route automatically closes and the account can sign in through <a href="' . url('login') . '">Sign In</a>.</p>'
        . '</div></div></div>';

    render_page('Initial Super Administrator Setup', $html);
}
