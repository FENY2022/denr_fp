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

function user_management_designation(int $id): ?array
{
    if ($id < 1) return null;
    return query_one('SELECT * FROM user_designations WHERE id=?', [$id]);
}

function user_management_user(int $id): ?array
{
    if ($id < 1) return null;
    return query_one("SELECT * FROM users WHERE id=? AND user_kind='internal'", [$id]);
}

function user_management_accounts(string $query = '', string $role = '', string $status = ''): array
{
    $sql = "SELECT u.id,u.name,u.email,u.username,u.contact_number,u.office_station,u.status,
                   u.created_at,u.must_change_password,
                   (SELECT GROUP_CONCAT(r.label ORDER BY r.label SEPARATOR ', ')
                    FROM user_roles ur JOIN roles r ON r.id=ur.role_id
                    WHERE ur.user_id=u.id) AS role_names
            FROM users u WHERE u.user_kind='internal'";
    $params = [];
    if ($query !== '') {
        $sql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.office_station LIKE ?)';
        $search = '%' . $query . '%';
        array_push($params, $search, $search, $search);
    }
    if ($role !== '' && user_management_valid_role($role)) {
        $sql .= ' AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
                      WHERE ur.user_id=u.id AND r.code=?)';
        $params[] = $role;
    }
    if (in_array($status, ['active','suspended'], true)) {
        $sql .= ' AND u.status=?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY u.name ASC,u.id ASC LIMIT 300';
    return query_all($sql, $params);
}

function user_management_designations(string $query = '', string $role = '', string $setup = ''): array
{
    $sql = 'SELECT d.*, u.name AS linked_name, u.email AS linked_email
            FROM user_designations d LEFT JOIN users u ON u.id=d.linked_user_id WHERE 1=1';
    $params = [];
    if ($query !== '') {
        $sql .= ' AND (d.full_name LIKE ? OR d.office_station LIKE ? OR d.reference_email LIKE ?)';
        $search = '%' . $query . '%';
        array_push($params, $search, $search, $search);
    }
    if ($role !== '' && user_management_valid_role($role)) {
        $sql .= ' AND d.suggested_role=?';
        $params[] = $role;
    }
    if ($setup === 'pending') $sql .= ' AND d.linked_user_id IS NULL';
    elseif ($setup === 'linked') $sql .= ' AND d.linked_user_id IS NOT NULL';
    $sql .= ' ORDER BY (d.linked_user_id IS NOT NULL) ASC,d.office_station ASC,d.full_name ASC LIMIT 300';
    return query_all($sql, $params);
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
    $values = user_management_data($input);
    $errors = user_management_validate($values);
    $password = (string)($input['password'] ?? '');
    $confirmation = (string)($input['confirm_password'] ?? '');
    if ($message = check_password_strength($password)) $errors[] = $message;
    if ($password !== $confirmation) $errors[] = 'Initial password and confirmation must match.';
    if ($values['status'] !== 'active') $errors[] = 'New accounts must start active.';
    $designationId = filter_var($input['designation_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
    if (isset($input['designation_id']) && (string)$input['designation_id'] !== '' &&
        (string)$input['designation_id'] !== '0' && !$designationId) $errors[] = 'Invalid designation ID.';
    if ($errors) return $errors;

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $designation = null;
        if ($designationId) {
            $designation = query_one('SELECT * FROM user_designations WHERE id=? FOR UPDATE', [$designationId]);
            if (!$designation || $designation['linked_user_id'] !== null) {
                $pdo->rollBack();
                return ['This pending designation was already assigned or no longer exists.'];
            }
        }
        // A matching internal identity may already have an account with another email.
        $existing = query_one("SELECT id FROM users WHERE user_kind='internal' AND LOWER(name)=LOWER(?) LIMIT 1", [$values['name']]);
        if ($existing) {
            $pdo->rollBack();
            return ['An internal account with this name already exists. Use Link Existing Account instead of creating a duplicate.'];
        }
        $roleId = user_management_role_id($values['role']);
        if (!$roleId) {
            $pdo->rollBack();
            return ['The selected role is not installed. Run the database migration and role seeder.'];
        }
        $stmt = $pdo->prepare("INSERT INTO users
            (name,email,password_hash,user_kind,status,must_change_password,activated_at,contact_number,office_station)
            VALUES (?,?,?,'internal','active',1,NOW(),?,?)");
        $stmt->execute([
            $values['name'],$values['email'],password_hash($password,PASSWORD_DEFAULT),
            $values['contact_number'] ?: null,$values['office_station']
        ]);
        $id = (int)$pdo->lastInsertId();
        initialize_profile_account_fields($id, 'internal');
        $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')->execute([$id,$roleId]);
        if ($designation) {
            $pdo->prepare('UPDATE user_designations SET linked_user_id=? WHERE id=? AND linked_user_id IS NULL')
                ->execute([$id,$designationId]);
        }
        audit((int)$admin['id'],'user_management.created','user',$id,[
            'role'=>$values['role'],'office'=>$values['office_station'],
            'source_designation_id'=>$designationId ?: null
        ]);
        $pdo->commit();
        return [];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') return ['This email or account is already registered. Use a different individual login email.'];
        error_log('FP-OMS managed user creation failed: ' . $e->getMessage());
        return ['Could not create this account. Check database migration and server logs.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS managed user creation failed: ' . $e->getMessage());
        return ['Could not create this account. Check server logs.'];
    }
}

/** Explicitly link a known existing user to a named designation without changing credentials. */
function user_management_link_existing(array $admin, int $designationId, int $existingUserId, bool $confirmed): array
{
    if (!$confirmed) return ['Confirm that this designation refers to the selected existing account.'];
    if ($designationId < 1 || $existingUserId < 1) return ['Select a valid designation and existing account.'];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $designation = query_one('SELECT * FROM user_designations WHERE id=? FOR UPDATE', [$designationId]);
        $user = query_one("SELECT id,name,user_kind FROM users WHERE id=? AND user_kind='internal' FOR UPDATE", [$existingUserId]);
        if (!$designation || $designation['linked_user_id'] !== null || !$user) {
            $pdo->rollBack();
            return ['Designation or internal account is invalid or already linked.'];
        }
        $already = query_one('SELECT id FROM user_designations WHERE linked_user_id=? LIMIT 1', [$existingUserId]);
        if ($already) {
            $pdo->rollBack();
            return ['This user account is already linked to another designation.'];
        }
        $normalize = static fn(string $name): string => strtolower((string)preg_replace('/[^a-z0-9]/i', '', $name));
        if ($normalize((string)$designation['full_name']) !== $normalize((string)$user['name'])) {
            $pdo->rollBack();
            return ['The account name does not match the designation. Verify the identity and correct the account name first.'];
        }
        $pdo->prepare('UPDATE user_designations SET linked_user_id=? WHERE id=? AND linked_user_id IS NULL')
            ->execute([$existingUserId, $designationId]);
        audit((int)$admin['id'],'user_management.designation_linked','user',$existingUserId,[
            'designation_id'=>$designationId
        ]);
        $pdo->commit();
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS existing account link failed: ' . $e->getMessage());
        return ['Unable to link the designation. Check server logs.'];
    }
}

/** Update an existing internal user's contact, office, unique email, role and status. */
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
        $pdo->prepare("UPDATE users SET name=?,email=?,contact_number=?,office_station=?,status=? WHERE id=? AND user_kind='internal'")
            ->execute([
                $values['name'],$values['email'],$values['contact_number'] ?: null,
                $values['office_station'],$values['status'],$userId
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
