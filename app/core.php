<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO MySQL extension is missing. Install php-mysql.');
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        env_value('DB_HOST', '127.0.0.1'), (int)env_value('DB_PORT', '3306'),
        env_value('DB_DATABASE', 'denr_fp_oms'));
    $pdo = new PDO($dsn, env_value('DB_USERNAME'), env_value('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $page = 'dashboard', array $query = []): string
{
    $slug = str_replace('_', '-', $page);
    if (!preg_match('/^[a-z][a-z0-9-]*$/', $slug)) throw new InvalidArgumentException('Invalid route');
    $queryString = $query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
    return $slug . $queryString;
}

function go(string $page, array $query = []): never
{
    header('Location: ' . url($page, $query), true, 303);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        render_page('Invalid Request', '<div class="alert alert-danger">Your form session expired or the security token was invalid. Refresh the page and try again.</div>');
        exit;
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

function client_ip(): string
{
    // Do not accept forwarded headers without an explicitly configured trusted proxy.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function audit(?int $actorId, string $action, string $type, ?int $id = null, array $metadata = []): void
{
    $stmt = db()->prepare('INSERT INTO audit_logs (actor_user_id,action,target_type,target_id,metadata_json,ip_address) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$actorId,$action,$type,$id,$metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,client_ip()]);
}

function query_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function query_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function safe_post(string $field, int $max = 220): string
{
    $val = trim((string)($_POST[$field] ?? ''));
    return substr($val, 0, $max + 1);
}

function show_error(string $message, int $status = 403): never
{
    http_response_code($status);
    render_page('Access Notice', '<div class="alert alert-warning">' . e($message) . '</div>');
    exit;
}

function user_by_id(int $id): ?array
{
    return query_one('SELECT * FROM users WHERE id = ?', [$id]);
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) return null;
    $user = user_by_id((int)$_SESSION['user_id']);
    if (!$user || $user['status'] === 'suspended') {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function require_user(): array
{
    $user = current_user();
    if ($user === null) go('login');
    return $user;
}

function user_roles(int $userId): array
{
    $rows = query_all('SELECT roles.code FROM roles JOIN user_roles ur ON ur.role_id=roles.id WHERE ur.user_id=?', [$userId]);
    return array_column($rows, 'code');
}

function has_role(array $user, string $code): bool
{
    return in_array($code, user_roles((int)$user['id']), true);
}

function require_role(string $role): array
{
    $user = require_user();
    if (!has_role($user, $role) || $user['status'] !== 'active') {
        show_error('You do not have permission to perform this operation.');
    }
    return $user;
}

function require_kind(array $user, string $kind): void
{
    if ($user['user_kind'] !== $kind) show_error('This function is not available to this account.');
}
