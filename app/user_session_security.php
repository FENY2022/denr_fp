<?php
declare(strict_types=1);

/**
 * Managed staff session revocation and login security logs.
 * Requires database/026_user_session_security.sql for managed features.
 */
function user_session_security_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        db()->query('SELECT session_version FROM users LIMIT 0');
        db()->query('SELECT id FROM account_login_logs LIMIT 0');
        return $ready = true;
    } catch (Throwable $error) {
        return $ready = false;
    }
}

function user_session_event_valid(string $event): bool
{
    return in_array($event, ['login_success','login_failed','logout','forced_logout'], true);
}

function user_session_user_agent(): string
{
    return substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown')),0,500);
}

function user_session_browser_label(string $agent): string
{
    $platform = str_contains($agent,'Windows') ? 'Windows'
        : (str_contains($agent,'Android') ? 'Android'
        : (preg_match('/iPhone|iPad|iPod/i',$agent) ? 'iOS'
        : (str_contains($agent,'Macintosh') ? 'macOS'
        : (str_contains($agent,'Linux') ? 'Linux' : 'Unknown OS'))));
    $browser = str_contains($agent,'Edg/') ? 'Edge'
        : (str_contains($agent,'Chrome/') ? 'Chrome'
        : (str_contains($agent,'Firefox/') ? 'Firefox'
        : (str_contains($agent,'Safari/') ? 'Safari' : 'Other Browser')));
    return $browser . ' / ' . $platform;
}

function user_session_log_event(
    ?int $userId, string $username, string $event,
    ?int $actorUserId = null, string $reason = ''
): void {
    if (!user_session_security_ready() || !user_session_event_valid($event)) return;
    $username = substr(trim(strtolower($username)),0,190);
    $reason = substr(trim($reason),0,500);
    try {
        db()->prepare(
            'INSERT INTO account_login_logs
             (user_id,username_snapshot,event_type,ip_address,user_agent,actor_user_id,reason)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $userId && $userId > 0 ? $userId : null,
            $username !== '' ? $username : null,
            $event,
            client_ip(),
            user_session_user_agent(),
            $actorUserId && $actorUserId > 0 ? $actorUserId : null,
            $reason !== '' ? $reason : null
        ]);
    } catch (Throwable $error) {
        error_log('FP-OMS account login log unavailable: ' . get_class($error));
    }
}

function user_session_log_user(
    int $userId, string $event, ?int $actorUserId = null, string $reason = ''
): void {
    if ($userId < 1) return;
    try {
        $row = query_one("SELECT username FROM users WHERE id=? AND user_kind='internal'",[$userId]);
        user_session_log_event($userId,(string)($row['username'] ?? ''),$event,$actorUserId,$reason);
    } catch (Throwable $error) {
        error_log('FP-OMS managed session event unavailable: ' . get_class($error));
    }
}

function user_session_validate_current(array $user): bool
{
    if (($user['user_kind'] ?? '') !== 'internal'
        || !array_key_exists('session_version',$user)) return true;

    $stored = $_SESSION['user_session_version'] ?? null;
    $current = (int)$user['session_version'];
    if ((is_int($stored) || (is_string($stored) && ctype_digit($stored)))
        && (int)$stored === $current) return true;

    unset($_SESSION['user_id'],$_SESSION['user_session_version']);
    $_SESSION['csrf_token']=bin2hex(random_bytes(32));
    flash('warning','Your FP-OMS session ended for security. Please sign in again.');
    return false;
}

function user_management_force_logout(array $admin,int $userId,string $reason): array
{
    $reason=trim($reason);
    if (!user_session_security_ready()) {
        return ['Session security is not installed. Apply database/026_user_session_security.sql first.'];
    }
    if ($userId<1) return ['Select a valid internal user account.'];
    if ((int)$admin['id']===$userId) {
        return ['You cannot force logout your own Super Administrator session.'];
    }
    if (mb_strlen($reason)<3 || mb_strlen($reason)>500) {
        return ['Enter a security reason between 3 and 500 characters.'];
    }

    $pdo=db();
    try {
        $pdo->beginTransaction();
        $target=query_one(
            "SELECT id,username,status FROM users
             WHERE id=? AND user_kind='internal' FOR UPDATE",[$userId]
        );
        if (!$target) {
            $pdo->rollBack();
            return ['Internal user account not found.'];
        }
        if (($target['status'] ?? '')==='pending_setup') {
            $pdo->rollBack();
            return ['Pending Setup personnel do not have an active login session.'];
        }
        $pdo->prepare('UPDATE users SET session_version=session_version+1 WHERE id=?')
            ->execute([$userId]);
        audit((int)$admin['id'],'user_management.force_logout','user',$userId,[
            'reason'=>$reason,'all_sessions'=>true
        ]);
        $pdo->commit();
        user_session_log_event(
            $userId,(string)($target['username'] ?? ''),'forced_logout',
            (int)$admin['id'],$reason
        );
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS force logout failed: '.$error->getMessage());
        return ['Unable to end this account session. Check the server logs.'];
    }
}

function user_management_login_logs(int $userId=0,string $event='',int $limit=300): array
{
    if (!user_session_security_ready()) return [];
    $limit=max(1,min($limit,500));
    $params=[];
    $sql="SELECT l.*,u.name AS account_name,u.username AS current_username,
                 a.name AS actor_name
          FROM account_login_logs l
          LEFT JOIN users u ON u.id=l.user_id
          LEFT JOIN users a ON a.id=l.actor_user_id
          WHERE 1=1";
    if ($userId>0) {
        $sql.=' AND l.user_id=?';
        $params[]=$userId;
    }
    if ($event!=='' && user_session_event_valid($event)) {
        $sql.=' AND l.event_type=?';
        $params[]=$event;
    }
    $sql.=' ORDER BY l.id DESC LIMIT '.$limit;
    return query_all($sql,$params);
}
