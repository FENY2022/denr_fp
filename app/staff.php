<?php
declare(strict_types=1);

function create_internal_user(array $admin, array $data): array
{
    $name = trim((string)($data['name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $role = trim((string)($data['role'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $errors = [];
    if ($name === '' || strlen($name) > 180) $errors[] = 'Full name is required (max 180 characters).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $errors[] = 'Valid work email is required.';
    if ($msg = check_password_strength($password)) $errors[] = $msg;
    if ($password !== (string)($data['confirm_password'] ?? '')) $errors[] = 'Initial password confirmation does not match.';
    $roles = role_definitions();
    if (!isset($roles[$role]) || $role === 'applicant') $errors[] = 'Choose a valid internal role.';
    if ($errors) return $errors;
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO users (name,email,password_hash,user_kind,status,must_change_password,activated_at) VALUES (?,?,?,'internal','active',1,NOW())");
        $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
        $uid = (int)$pdo->lastInsertId();
        $row = query_one('SELECT id FROM roles WHERE code=?',[$role]);
        if (!$row) throw new RuntimeException('Role not installed: ' . $role);
        $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')->execute([$uid,(int)$row['id']]);
        audit((int)$admin['id'],'staff.created','user',$uid,['role'=>$role]);
        create_notification(
            $uid,
            'DENR FP-OMS Account Created',
            'Your internal DENR FP-OMS account has been created. Change your initial password on first sign-in.',
            'person-check',
            'success',
            'change_password'
        );
        $pdo->commit();
        return [];
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($ex->getCode() === '23000') return ['An account already exists with this email.'];
        error_log('User provisioning failed: ' . $ex->getMessage());
        return ['Could not create user. Check the system logs.'];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('User provisioning failed: ' . $ex->getMessage());
        return ['Could not create user. Check the system logs.'];
    }
}
