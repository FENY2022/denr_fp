<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../app/bootstrap.php';

function reset_ask(string $label, bool $secret = false): string
{
    fwrite(STDOUT, $label . ': ');
    $mask = $secret && DIRECTORY_SEPARATOR === '/' && is_executable('/usr/bin/stty');
    if ($mask) @shell_exec('stty -echo');
    $answer = trim((string)fgets(STDIN));
    if ($mask) {
        @shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return $answer;
}

try {
    $email = strtolower(reset_ask('Account email'));
    $password = reset_ask('New password (12+ characters, letters and numbers)', true);
    $confirm = reset_ask('Confirm new password', true);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Email is not valid.');
    }
    if ($password !== $confirm) {
        throw new RuntimeException('Password confirmation does not match.');
    }
    if ($error = check_password_strength($password)) {
        throw new RuntimeException($error);
    }

    $user = query_one('SELECT id,name,email FROM users WHERE email=?', [$email]);
    if (!$user) {
        throw new RuntimeException('No user account was found for this email.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
    $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')
        ->execute([(int)$user['id']]);
    audit((int)$user['id'], 'auth.password_reset_cli', 'user', (int)$user['id']);
    $pdo->commit();

    fwrite(STDOUT, "Password reset completed for {$user['email']}.\n");
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Password reset failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
