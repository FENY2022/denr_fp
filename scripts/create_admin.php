<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../app/bootstrap.php';
function ask(string $label, bool $secret = false): string
{
    fwrite(STDOUT, $label . ': ');
    $mask = $secret && DIRECTORY_SEPARATOR === '/' && is_executable('/usr/bin/stty');
    if ($mask) @shell_exec('stty -echo');
    $answer = trim((string)fgets(STDIN));
    if ($mask) { @shell_exec('stty echo'); fwrite(STDOUT,PHP_EOL); }
    return $answer;
}
try {
    $name = ask('Administrator full name');
    $email = strtolower(ask('Administrator work email'));
    $pass = ask('Initial password (12+ characters, letters and numbers)',true);
    if (!$name || strlen($name) > 180 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new RuntimeException('Name/email not valid.');
    }
    if ($error = check_password_strength($pass)) throw new RuntimeException($error);
    $pdo = db();
    $pdo->beginTransaction();
    $r = query_one('SELECT id FROM roles WHERE code=?',['super_admin']);
    if (!$r) throw new RuntimeException('First run: php scripts/seed_roles.php');
    $pdo->prepare("INSERT INTO users (name,email,password_hash,user_kind,status,must_change_password,activated_at) VALUES (?,?,?,'internal','active',1,NOW())")
        ->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')->execute([$uid,(int)$r['id']]);
    audit($uid,'admin.bootstrapped','user',$uid);
    $pdo->commit();
    echo "Super Administrator created. Change initial password on first login.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'Could not create admin: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
