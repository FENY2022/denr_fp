<?php
declare(strict_types=1);

function authenticate(string $email, string $password): bool
{
    $email = strtolower(trim($email));
    $hash = hash('sha256', $email);
    $ip = client_ip();
    $blockedEmail = query_one('SELECT COUNT(*) AS n FROM login_attempts WHERE was_successful=0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND email_hash=?', [$hash]);
    $blockedIp = query_one('SELECT COUNT(*) AS n FROM login_attempts WHERE was_successful=0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND ip_address=?', [$ip]);
    if ((int)($blockedEmail['n'] ?? 0) >= 8 || (int)($blockedIp['n'] ?? 0) >= 40) return false;

    $user = query_one('SELECT * FROM users WHERE email=?', [$email]);
    $success = $user && $user['status'] !== 'suspended' && password_verify($password, $user['password_hash']);
    db()->prepare('INSERT INTO login_attempts (email_hash,ip_address,was_successful) VALUES (?,?,?)')->execute([$hash,$ip,(int)(bool)$success]);
    if (!$success) return false;
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([(int)$user['id']]);
    audit((int)$user['id'], 'auth.login', 'user', (int)$user['id']);
    return true;
}

function logout_user(): void
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId > 0) audit($userId, 'auth.logout', 'user', $userId);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function change_password(array $user, string $current, string $new): array
{
    $errors = [];
    if (!password_verify($current, $user['password_hash'])) $errors[] = 'Current password is incorrect.';
    if ($msg = check_password_strength($new)) $errors[] = $msg;
    if ($new === $current) $errors[] = 'Your new password must differ from the old one.';
    if ($errors) return $errors;
    db()->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
    session_regenerate_id(true);
    audit((int)$user['id'], 'auth.password_changed', 'user', (int)$user['id']);
    return [];
}
