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

    $staff = query_one("SELECT * FROM users WHERE email=? AND user_kind='internal'", [$email]);
    $client = query_one("SELECT * FROM user_client WHERE email=? AND user_kind='applicant'", [$email]);

    // Never resolve an ambiguous email to the other person's identity.
    if ($staff && $client) {
        db()->prepare('INSERT INTO login_attempts (email_hash,ip_address,was_successful) VALUES (?,?,0)')
            ->execute([$hash,$ip]);
        error_log('FP-OMS duplicate email across DENR and client account tables: ' . $hash);
        return false;
    }

    $isClient = $client !== null;
    $user = $staff ?? $client;
    $success = $user && !in_array($user['status'], ['suspended', 'pending_setup'], true)
        && is_string($user['password_hash']) && $user['password_hash'] !== ''
        && password_verify($password, $user['password_hash']);
    db()->prepare('INSERT INTO login_attempts (email_hash,ip_address,was_successful) VALUES (?,?,?)')
        ->execute([$hash,$ip,(int)(bool)$success]);
    if (!$success) return false;
    session_regenerate_id(true);
    unset($_SESSION['user_id'], $_SESSION['client_id']);
    $_SESSION[$isClient ? 'client_id' : 'user_id'] = (int)$user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $table = $isClient ? 'user_client' : 'users';
    db()->prepare("UPDATE {$table} SET last_login_at=NOW() WHERE id=?")->execute([(int)$user['id']]);
    if ($isClient) audit_client((int)$user['id'], 'auth.login', 'client', (int)$user['id']);
    else audit((int)$user['id'], 'auth.login', 'user', (int)$user['id']);
    return true;
}

function logout_user(): void
{
    $clientId = (int)($_SESSION['client_id'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($clientId > 0) audit_client($clientId, 'auth.logout', 'client', $clientId);
    elseif ($userId > 0) audit($userId, 'auth.logout', 'user', $userId);
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
    $table = principal_table($user);
    db()->prepare("UPDATE {$table} SET password_hash=?, must_change_password=0 WHERE id=?")
        ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
    session_regenerate_id(true);
    if ($table === 'user_client') {
        audit_client((int)$user['id'], 'auth.password_changed', 'client', (int)$user['id']);
    } else {
        audit((int)$user['id'], 'auth.password_changed', 'user', (int)$user['id']);
    }
    return [];
}
