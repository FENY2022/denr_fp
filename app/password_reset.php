<?php
declare(strict_types=1);

function password_reset_url(string $token): string
{
    $base = rtrim(env_value('APP_URL'), '/');
    if ($base === '') {
        $base = '.';
    }
    $path = url('reset_password', ['token' => $token]);
    return $base === '.' ? $path : $base . '/' . ltrim($path, './');
}

function send_password_reset_email(array $user, string $resetUrl): bool
{
    if (strtolower(env_value('MAIL_ENABLED', 'false')) !== 'true') {
        return false;
    }

    $from = trim(env_value('MAIL_FROM_ADDRESS'));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $name = trim(env_value('MAIL_FROM_NAME', 'DENR FP-OMS'));
    $safeName = str_replace(["\r", "\n"], '', $name);
    $subject = 'DENR FP-OMS Password Reset';
    $body = "A password reset was requested for your DENR FP-OMS account.\n\n"
        . "Reset your password using this link:\n" . $resetUrl . "\n\n"
        . "This link expires in 30 minutes and can only be used once.\n"
        . "If you did not request this reset, you may ignore this message.\n";
    $headers = [
        'From: ' . $safeName . ' <' . $from . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: DENR FP-OMS'
    ];

    return @mail((string)$user['email'], $subject, $body, implode("\r\n", $headers));
}

function request_password_reset(string $email): void
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        return;
    }

    $user = query_one('SELECT id,name,email,status FROM users WHERE email=?', [$email]);
    if (!$user || $user['status'] === 'suspended') {
        return;
    }

    $recent = query_one(
        'SELECT COUNT(*) AS n FROM password_reset_tokens WHERE user_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)',
        [(int)$user['id']]
    );
    if ((int)($recent['n'] ?? 0) >= 3) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    db()->prepare(
        'INSERT INTO password_reset_tokens (user_id,token_hash,requested_ip,expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
    )->execute([(int)$user['id'], $hash, client_ip()]);

    $sent = send_password_reset_email($user, password_reset_url($token));
    audit((int)$user['id'], 'auth.password_reset_requested', 'user', (int)$user['id'], [
        'delivery' => $sent ? 'mail' : 'not_sent'
    ]);
}

function valid_password_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    return query_one(
        "SELECT prt.id AS reset_id,prt.user_id,u.name,u.email
         FROM password_reset_tokens prt
         INNER JOIN users u ON u.id=prt.user_id
         WHERE prt.token_hash=?
           AND prt.used_at IS NULL
           AND prt.expires_at > NOW()
           AND u.status <> 'suspended'
         LIMIT 1",
        [hash('sha256', $token)]
    );
}

function complete_password_reset(string $token, string $password, string $confirm): array
{
    $errors = [];
    if ($password !== $confirm) {
        $errors[] = 'Password confirmation does not match.';
    }
    if ($error = check_password_strength($password)) {
        $errors[] = $error;
    }
    if ($errors) {
        return $errors;
    }

    $reset = valid_password_reset($token);
    if (!$reset) {
        return ['This password reset link is invalid or has expired. Request a new reset link.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['user_id']]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')
            ->execute([(int)$reset['user_id']]);
        audit((int)$reset['user_id'], 'auth.password_reset_completed', 'user', (int)$reset['user_id']);
        $pdo->commit();

        if ((int)($_SESSION['user_id'] ?? 0) === (int)$reset['user_id']) {
            unset($_SESSION['user_id']);
        }
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function view_forgot_password(): void
{
    if (current_user()) {
        go('dashboard');
    }

    $html = '<div class="row justify-content-center"><div class="col-md-7 col-lg-5"><div class="panel">'
        . '<div class="d-flex align-items-center gap-3 mb-3"><span class="install-icon"><i class="bi bi-key"></i></span>'
        . '<div><h2 class="h5 mb-1">Forgot Password</h2><p class="text-secondary small mb-0">Request a secure password reset link.</p></div></div>'
        . '<form method="post" action="' . url('forgot_password') . '">' . csrf_field()
        . field('email','Account email',form_value('email'),'email',true,'autocomplete="email" maxlength="190"')
        . '<button class="btn btn-primary w-100" type="submit">Send Reset Link</button></form>'
        . '<p class="small text-secondary mt-3 mb-0">For security, the system will show the same confirmation whether or not the email is registered.</p>'
        . '<hr><a class="small" href="' . url('login') . '">← Back to Sign In</a>'
        . '</div></div></div>';

    render_page('Forgot Password', $html);
}

function view_reset_password(string $token, array $errors = []): void
{
    if (current_user()) {
        go('dashboard');
    }

    $reset = valid_password_reset($token);
    if (!$reset) {
        $html = '<div class="row justify-content-center"><div class="col-md-7 col-lg-5"><div class="panel">'
            . '<div class="alert alert-warning mb-3">This password reset link is invalid or has expired.</div>'
            . '<a class="btn btn-primary w-100" href="' . url('forgot_password') . '">Request a New Reset Link</a>'
            . '</div></div></div>';
        render_page('Reset Password', $html);
        return;
    }

    $html = '<div class="row justify-content-center"><div class="col-md-7 col-lg-5"><div class="panel">'
        . errors_html($errors)
        . '<div class="d-flex align-items-center gap-3 mb-3"><span class="install-icon"><i class="bi bi-shield-lock"></i></span>'
        . '<div><h2 class="h5 mb-1">Set New Password</h2><p class="text-secondary small mb-0">' . e((string)$reset['email']) . '</p></div></div>'
        . '<form method="post" action="' . url('reset_password', ['token' => $token]) . '">' . csrf_field()
        . field('password','New password','','password',true,'autocomplete="new-password" minlength="12"')
        . field('confirm_password','Confirm new password','','password',true,'autocomplete="new-password" minlength="12"')
        . '<div class="form-text mb-3">Use at least 12 characters with letters and numbers.</div>'
        . '<button class="btn btn-primary w-100" type="submit">Reset Password</button></form>'
        . '</div></div></div>';

    render_page('Reset Password', $html);
}
