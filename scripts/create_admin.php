<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../app/bootstrap.php';

function ask(string $label, bool $secret = false): string
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

function runtime_value(string $key, string $prompt, bool $secret = false): string
{
    $value = getenv($key);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }
    return ask($prompt, $secret);
}

try {
    // Bootstrap credentials are supplied only at runtime. Never hard-code them in source.
    $name = runtime_value('FPOMS_ADMIN_NAME', 'Administrator full name');
    $email = strtolower(runtime_value('FPOMS_ADMIN_EMAIL', 'Administrator work email'));
    $pass = runtime_value(
        'FPOMS_ADMIN_PASSWORD',
        'Initial password (12+ characters, letters and numbers)',
        true
    );

    if ($name === '' || strlen($name) > 180) {
        throw new RuntimeException('Administrator name is required and must not exceed 180 characters.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new RuntimeException('Administrator work email is not valid.');
    }
    if ($error = check_password_strength($pass)) {
        throw new RuntimeException($error);
    }

    $pdo = db();
    $pdo->beginTransaction();

    $role = query_one('SELECT id FROM roles WHERE code=?', ['super_admin']);
    if (!$role) {
        throw new RuntimeException('Super Administrator role is missing. First run: php scripts/seed_roles.php');
    }

    $existing = query_one('SELECT id,name,email,user_kind,status FROM users WHERE email=?', [$email]);
    if ($existing) {
        throw new RuntimeException(
            'A user already exists with this email (user ID ' . (int)$existing['id'] . '). '
            . 'No credentials or roles were changed.'
        );
    }

    $pdo->prepare(
        "INSERT INTO users
            (name,email,password_hash,user_kind,status,must_change_password,activated_at)
         VALUES
            (?,?,?,'internal','active',1,NOW())"
    )->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);

    $uid = (int)$pdo->lastInsertId();
    initialize_profile_account_fields($uid, 'internal');
    $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')
        ->execute([$uid, (int)$role['id']]);

    audit($uid, 'admin.bootstrapped', 'user', $uid, [
        'role' => 'super_admin',
        'provisioning' => 'scripts/create_admin.php'
    ]);

    $pdo->commit();

    fwrite(STDOUT, PHP_EOL);
    fwrite(STDOUT, "Super Administrator created successfully.\n");
    fwrite(STDOUT, "User ID: {$uid}\n");
    fwrite(STDOUT, "Name: {$name}\n");
    fwrite(STDOUT, "Email: {$email}\n");
    fwrite(STDOUT, "Role: Super Administrator (RICTU)\n");
    fwrite(STDOUT, "Status: Active\n");
    fwrite(STDOUT, "First login: password change required\n");
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Could not create admin: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
