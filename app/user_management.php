<?php
declare(strict_types=1);

/**
 * RICTU-only internal user account lifecycle.
 * Data from the submitted designation form is staging/contact data, not a credential.
 */

function user_management_internal_roles(): array
{
    $roles = role_definitions();
    unset($roles['applicant']);
    return $roles;
}

function user_management_office_level(string $role): string
{
    return match ($role) {
        'cenro_twg' => 'CENRO',
        'penro_user' => 'PENRO',
        default => 'Regional Office',
    };
}

function user_management_valid_role(string $code): bool
{
    return isset(user_management_internal_roles()[$code]);
}

function user_management_role_id(string $code): ?int
{
    if (!user_management_valid_role($code)) return null;
    $row = query_one('SELECT id FROM roles WHERE code=? LIMIT 1', [$code]);
    return $row ? (int)$row['id'] : null;
}

function user_management_data(array $input): array
{
    return [
        'name' => trim((string)($input['name'] ?? '')),
        'email' => strtolower(trim((string)($input['email'] ?? ''))),
        'contact_number' => trim((string)($input['contact_number'] ?? '')),
        'office_station' => trim((string)($input['office_station'] ?? '')),
        'role' => trim((string)($input['role'] ?? '')),
        'status' => trim((string)($input['status'] ?? 'active')),
    ];
}

function user_management_validate(array $values): array
{
    $errors = [];
    $length = function_exists('mb_strlen')
        ? mb_strlen($values['name'], 'UTF-8') : strlen($values['name']);
    if ($length < 3 || $length > 180) $errors[] = 'Full name must have 3–180 characters.';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['email']) > 190) {
        $errors[] = 'A unique, valid individual login email address is required.';
    }
    if ($values['contact_number'] !== '' &&
        !preg_match('/^[0-9+().\-\s]{7,40}$/', $values['contact_number'])) {
        $errors[] = 'Contact number must use valid phone characters (7–40 characters).';
    }
    if ($values['office_station'] === '' || strlen($values['office_station']) > 160) {
        $errors[] = 'Office / Station is required (maximum 160 characters).';
    }
    if (!user_management_valid_role($values['role'])) $errors[] = 'Select an authorized internal user role.';
    if (!in_array($values['status'], ['active','suspended'], true)) {
        $errors[] = 'Invalid account status.';
    }
    return $errors;
}

function user_management_user(int $id): ?array
{
    if ($id < 1) return null;
    return query_one("SELECT * FROM users WHERE id=? AND user_kind='internal'", [$id]);
}

function user_management_accounts(string $query = '', string $role = '', string $status = ''): array
{
    $sql = "SELECT u.id,u.name,u.email,u.username,u.contact_number,u.office_station,
                   u.status,u.created_at,u.must_change_password,u.source_key,
                   u.reference_email,u.designation_notes,
                   (SELECT GROUP_CONCAT(r.label ORDER BY r.label SEPARATOR ', ')
                    FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id) AS role_names,
                   (SELECT r.code FROM user_roles ur
                    INNER JOIN roles r ON r.id=ur.role_id
                    WHERE ur.user_id=u.id ORDER BY r.id LIMIT 1) AS role_code
            FROM users u WHERE u.user_kind='internal'";
    $params = [];
    if ($query !== '') {
        $sql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.office_station LIKE ? OR u.reference_email LIKE ?)';
        $search = '%' . $query . '%';
        array_push($params,$search,$search,$search,$search);
    }
    if ($role !== '' && user_management_valid_role($role)) {
        $sql .= ' AND EXISTS (SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id
                              WHERE ur.user_id=u.id AND r.code=?)';
        $params[] = $role;
    }
    if (in_array($status, ['active','suspended','pending_setup'], true)) {
        $sql .= ' AND u.status=?';
        $params[] = $status;
    }
    $sql .= " ORDER BY (u.status='pending_setup') DESC,u.name ASC,u.id ASC LIMIT 300";
    return query_all($sql,$params);
}

function user_management_assign_role(PDO $pdo, int $userId, int $roleId): void
{
    $pdo->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$userId]);
    $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')->execute([$userId,$roleId]);
}

/**
 * For pending imported designations only, or as a manual RICTU creation.
 * Login emails are NOT sourced from shared office-contact emails.
 */
function user_management_create(array $admin, array $input): array
{
    // Manual account creation (pending imported personnel use user_management_activate).
    $values = user_management_data($input);
    $errors = user_management_validate($values);
    $password = (string)($input['password'] ?? '');
    if ($msg = check_password_strength($password)) $errors[] = $msg;
    if ($password !== (string)($input['confirm_password'] ?? '')) $errors[] = 'Initial passwords do not match.';
    if ($values['status'] !== 'active') $errors[] = 'New accounts must begin active.';
    if ($errors) return $errors;
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $identity = static fn(string $name): string =>
            strtolower((string)preg_replace('/[^a-z0-9]/i', '', $name));
        $existing = query_all("SELECT name,office_station FROM users WHERE user_kind='internal' FOR UPDATE");
        foreach ($existing as $other) {
            if ($identity((string)$other['name']) === $identity($values['name']) &&
                strcasecmp((string)($other['office_station'] ?? ''), $values['office_station']) === 0) {
                $pdo->rollBack();
                return ['This person already appears in User Management. Edit their existing or Pending Setup record instead.'];
            }
        }
        $roleId = user_management_role_id($values['role']);
        if (!$roleId) {
            $pdo->rollBack();
            return ['The role has not been installed. Run the role seeder.'];
        }
        $pdo->prepare("INSERT INTO users
                (name,email,password_hash,user_kind,office_level,contact_number,office_station,
                 status,must_change_password,activated_at)
            VALUES (?,?,?,'internal',?,?,?,'active',1,NOW())")
            ->execute([
                $values['name'],$values['email'],password_hash($password,PASSWORD_DEFAULT),
                user_management_office_level($values['role']),
                $values['contact_number'] ?: null,$values['office_station']
            ]);
        $id = (int)$pdo->lastInsertId();
        initialize_profile_account_fields($id,'internal');
        $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')->execute([$id,$roleId]);
        create_notification($id,'DENR FP-OMS Account Created',
            'Your internal account is ready. Change your initial password on first sign-in.',
            'person-check','success','change_password');
        audit((int)$admin['id'],'user_management.created','user',$id,
            ['role'=>$values['role'],'office'=>$values['office_station']]);
        $pdo->commit();
        return [];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') return ['This email is already in use. Assign an individual login email.'];
        error_log('User Management create failed: ' . $e->getMessage());
        return ['Unable to create the account. Review database migration and server logs.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('User Management create failed: ' . $e->getMessage());
        return ['Unable to create the account. Check server logs.'];
    }
}

/**
 * Activate an existing pending personnel row within users.
 * Never INSERT another user for a designation that already exists.
 */
function user_management_activate(array $admin, int $userId, array $input): array
{
    $values = user_management_data($input);
    $errors = user_management_validate($values);
    $password = (string)($input['password'] ?? '');
    if ($msg = check_password_strength($password)) $errors[] = $msg;
    if ($password !== (string)($input['confirm_password'] ?? '')) {
        $errors[] = 'Initial password and confirmation do not match.';
    }
    if ((string)($input['confirm_identity'] ?? '') !== '1') {
        $errors[] = 'Confirm the designated employee identity and unique login email before activation.';
    }
    if ($values['status'] !== 'active') $errors[] = 'A completed account must start active.';
    if ($errors) return $errors;
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $record = query_one("SELECT * FROM users WHERE id=? AND user_kind='internal' FOR UPDATE", [$userId]);
        if (!$record || $record['status'] !== 'pending_setup') {
            $pdo->rollBack();
            return ['This account is not awaiting setup. Reload User Management.'];
        }
        $roleId = user_management_role_id($values['role']);
        if (!$roleId) {
            $pdo->rollBack();
            return ['The assigned role has not been installed. Run the role seeder.'];
        }
        $duplicate = query_one('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1',
            [$values['email'],$userId]);
        if ($duplicate) {
            $pdo->rollBack();
            return ['That login email is already assigned to another user.'];
        }
        $pdo->prepare("UPDATE users SET name=?,email=?,password_hash=?,contact_number=?,
                     office_station=?,office_level=?,status='active',must_change_password=1,
                     activated_at=NOW() WHERE id=? AND status='pending_setup'")
            ->execute([
                $values['name'],$values['email'],password_hash($password,PASSWORD_DEFAULT),
                $values['contact_number'] ?: null,$values['office_station'],
                user_management_office_level($values['role']),$userId
            ]);
        initialize_profile_account_fields($userId,'internal');
        user_management_assign_role($pdo,$userId,$roleId);
        create_notification($userId,'DENR FP-OMS Account Activated',
            'Your account is now active. Change your temporary password when you sign in.',
            'person-check','success','change_password');
        audit((int)$admin['id'],'user_management.activated','user',$userId,[
            'role'=>$values['role'],
            'source_key'=>$record['source_key'] ?? null,
            'office'=>$values['office_station']
        ]);
        $pdo->commit();
        return [];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') return ['Login email is already used or the user was activated by another administrator.'];
        error_log('User Management activation failed: ' . $e->getMessage());
        return ['Unable to activate this user. Check server logs.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('User Management activation failed: ' . $e->getMessage());
        return ['Unable to activate this user. Check server logs.'];
    }
}

function user_management_update(array $admin, int $userId, array $input): array
{
    $values = user_management_data($input);
    $errors = user_management_validate($values);
    if ($errors) return $errors;

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $target = query_one("SELECT * FROM users WHERE id=? AND user_kind='internal' FOR UPDATE", [$userId]);
        if (!$target) {
            $pdo->rollBack();
            return ['Internal user account not found.'];
        }
        if ($target['status'] === 'pending_setup') {
            $pdo->rollBack();
            return ['Complete the pending account setup, including a password, before changing its status.'];
        }
        $originalRoles = user_roles($userId);
        if ((int)$admin['id'] === $userId &&
            ($values['role'] !== 'super_admin' || $values['status'] !== 'active')) {
            $pdo->rollBack();
            return ['You cannot remove your own administrator role or suspend your own account.'];
        }
        $wasAdmin = in_array('super_admin',$originalRoles,true);
        if ($wasAdmin && $target['status'] === 'active' &&
            ($values['role'] !== 'super_admin' || $values['status'] !== 'active')) {
            // Prevent accidentally removing the final active administrator.
            $adminRows = query_all(
                "SELECT u.id FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id
                 INNER JOIN roles r ON r.id=ur.role_id
                 WHERE r.code='super_admin' AND u.status='active' FOR UPDATE"
            );
            if (count($adminRows) <= 1) {
                $pdo->rollBack();
                return ['Cannot disable or demote the last active Super Administrator.'];
            }
        }
        $roleId = user_management_role_id($values['role']);
        if (!$roleId) {
            $pdo->rollBack();
            return ['Role is missing from the database. Run role seeding.'];
        }
        $pdo->prepare("UPDATE users SET name=?,email=?,contact_number=?,office_station=?,office_level=?,status=? WHERE id=? AND user_kind='internal'")
            ->execute([
                $values['name'],$values['email'],$values['contact_number'] ?: null,
                $values['office_station'],user_management_office_level($values['role']),
                $values['status'],$userId
            ]);
        user_management_assign_role($pdo, $userId, $roleId);
        audit((int)$admin['id'],'user_management.updated','user',$userId,[
            'name_changed'=>$values['name'] !== $target['name'],
            'email_changed'=>$values['email'] !== $target['email'],
            'role_before'=>$originalRoles,
            'role_after'=>$values['role'],
            'office_before'=>$target['office_station'],
            'office_after'=>$values['office_station'],
            'status_before'=>$target['status'],
            'status_after'=>$values['status']
        ]);
        $pdo->commit();
        return [];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') return ['Email address is already used by another account.'];
        error_log('FP-OMS managed user edit failed: ' . $e->getMessage());
        return ['Unable to update the user. Check the server logs.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS managed user edit failed: ' . $e->getMessage());
        return ['Unable to update the user. Check the server logs.'];
    }
}

/** Admin resets are explicit and force a subsequent password change; never show stored hashes. */
function user_management_reset_password(array $admin, int $userId, array $input): array
{
    if ((int)$admin['id'] === $userId) return ['Change your own password using Profile → Change Password.'];
    $password = (string)($input['new_password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');
    $errors = [];
    if ($message = check_password_strength($password)) $errors[] = $message;
    if ($password !== $confirm) $errors[] = 'Password confirmation does not match.';
    if ($errors) return $errors;

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $target = query_one("SELECT id FROM users WHERE id=? AND user_kind='internal' FOR UPDATE",[$userId]);
        if (!$target) {
            $pdo->rollBack();
            return ['Internal user account does not exist.'];
        }
        $pending = query_one("SELECT status FROM users WHERE id=? AND user_kind='internal'",[$userId]);
        if (($pending['status'] ?? '') === 'pending_setup') {
            $pdo->rollBack();
            return ['Use Set Up Account for designated personnel who have no login yet.'];
        }
        $pdo->prepare("UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?")
            ->execute([password_hash($password,PASSWORD_DEFAULT),$userId]);
        // Invalidate any outstanding password reset links without leaking reset tokens.
        $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')
            ->execute([$userId]);
        audit((int)$admin['id'],'user_management.password_reset','user',$userId);
        $pdo->commit();
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS admin password reset failed: ' . $e->getMessage());
        return ['Password reset could not be completed. Check server configuration.'];
    }
}
