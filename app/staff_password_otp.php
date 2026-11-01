<?php
declare(strict_types=1);

/** Staff-only email OTP recovery. Applicant reset links remain separate. */
function staff_otp_send_email(array $user, string $code, string $purpose = 'password'): bool
{
    if (!in_array($purpose,['username','password'],true)) return false;
    $recoverUsername = $purpose === 'username';
    $name = e((string)$user['name']);
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;'
        . 'background:#f4f7f5;color:#17352a;padding:24px">'
        . '<div style="max-width:540px;margin:auto;background:#fff;'
        . 'border:1px solid #cdded4;border-radius:12px;padding:26px">'
        . '<h2 style="color:#075c32">DENR CARAGA FP-OMS</h2>'
        . '<p>Hello ' . $name . ',</p>'
        . '<p>' . ($recoverUsername
            ? 'Your DENR username recovery verification code is:'
            : 'Your staff password recovery verification code is:') . '</p>'
        . '<p style="font-size:30px;font-weight:bold;letter-spacing:7px">'
        . e($code) . '</p>'
        . '<p>This code expires after <strong>10 minutes</strong> and can '
        . 'be used only once. Do not share it with anyone.</p>'
        . '<p>If you did not request this, ignore this message.</p>'
        . '</div></body></html>';
    return smtp_send_html((string)$user['email'],
        $recoverUsername ? 'DENR FP-OMS — Username Recovery OTP'
            : 'DENR FP-OMS — Password Recovery OTP', $html);
}

/**
 * No indication of whether an email is associated with an account.
 * Limit attempts by IP and user; never log or persist the raw six digits.
 */
function staff_otp_request(string $email, string $purpose = 'password'): void
{
    $email = strtolower(trim($email));
    if (!in_array($purpose,['password','username'],true)) return;
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190) return;
    $ip = client_ip();
    try {
        $rate = query_one(
            'SELECT COUNT(*) AS n FROM staff_password_reset_otps
             WHERE requested_ip=? AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)',
            [$ip]
        );
        if ((int)($rate['n'] ?? 0)>=20) return;
        $user = query_one(
            "SELECT id,name,email,username FROM users WHERE email=? AND user_kind='internal'
             AND status='active' LIMIT 1",[$email]
        );
        if (!$user || ($purpose==='username' && trim((string)($user['username'] ?? ''))==='')) return;
        // Refuse ambiguous mailboxes shared with a client identity.
        if (query_one("SELECT id FROM user_client WHERE email=? LIMIT 1",[$email])) return;
        $recent = query_one(
            'SELECT COUNT(*) AS n,MAX(created_at) AS last_sent
             FROM staff_password_reset_otps
             WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)',
            [(int)$user['id']]
        );
        if ((int)($recent['n'] ?? 0)>=3) return;
        $last = (string)($recent['last_sent'] ?? '');
        if ($last !== '' && strtotime($last) > time()-60) return;
        $code = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
        // Hash domain separation: neither purpose may redeem the other's OTP.
        // Existing password OTP hashes (raw code) remain backward compatible.
        $hash = password_hash(
            $purpose === 'username' ? 'username:'.$code : $code,
            PASSWORD_DEFAULT
        );
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE staff_password_reset_otps SET used_at=NOW()
                       WHERE user_id=? AND used_at IS NULL')
            ->execute([(int)$user['id']]);
        $pdo->prepare('INSERT INTO staff_password_reset_otps
                       (user_id,code_hash,requested_ip,expires_at)
                       VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))')
            ->execute([(int)$user['id'],$hash,$ip]);
        $otpId = (int)$pdo->lastInsertId();
        $pdo->commit();
        if (!staff_otp_send_email($user,$code,$purpose)) {
            // A failed delivery must not leave a usable code on the server.
            $pdo->prepare('UPDATE staff_password_reset_otps SET used_at=NOW()
                           WHERE id=? AND used_at IS NULL')->execute([$otpId]);
        }
    } catch (Throwable $err) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS staff password OTP could not be issued: '.$err->getMessage());
    }
}

function staff_otp_reset(string $email,string $code,string $new,string $confirm): array
{
    if ($new!==$confirm) return ['Password confirmation does not match.'];
    if ($error=check_password_strength($new)) return [$error];
    if (preg_match('/^[0-9]{6}$/D',$code)!==1)
        return ['Enter the six-digit verification code sent to your email.'];
    $pdo=db();
    try {
        $pdo->beginTransaction();
        $user=query_one(
            "SELECT id FROM users WHERE email=? AND user_kind='internal'
             AND status='active' FOR UPDATE", [strtolower(trim($email))]
        );
        if (!$user) {
            $pdo->rollBack();
            return ['Invalid or expired verification code.'];
        }
        $id=(int)$user['id'];
        $row=query_one(
            'SELECT * FROM staff_password_reset_otps
             WHERE user_id=? AND used_at IS NULL
             ORDER BY id DESC LIMIT 1 FOR UPDATE',[$id]
        );
        if (!$row || strtotime((string)$row['expires_at'])<=time()
            || (int)$row['failed_attempts']>=5) {
            $pdo->rollBack();
            return ['Invalid or expired verification code. Request a new OTP.'];
        }
        if (!password_verify($code,(string)$row['code_hash'])) {
            $pdo->prepare('UPDATE staff_password_reset_otps SET failed_attempts=failed_attempts+1
                           WHERE id=?')->execute([(int)$row['id']]);
            $pdo->commit();
            return ['Invalid or expired verification code.'];
        }
        $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?')
            ->execute([password_hash($new,PASSWORD_DEFAULT),$id]);
        $pdo->prepare('UPDATE staff_password_reset_otps SET used_at=NOW()
                       WHERE user_id=? AND used_at IS NULL')->execute([$id]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW()
                       WHERE user_id=? AND used_at IS NULL')->execute([$id]);
        audit($id,'auth.staff_password_otp_reset','user',$id);
        $pdo->commit();
        // Invalidate current session if that account was already signed in.
        if ((int)($_SESSION['user_id'] ?? 0)===$id) unset($_SESSION['user_id']);
        unset($_SESSION['staff_reset_pending_email']);
        session_regenerate_id(true);
        $_SESSION['csrf_token']=bin2hex(random_bytes(32));
        return [];
    } catch (Throwable $err) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS staff password OTP reset failed: '.$err->getMessage());
        return ['Unable to complete password recovery. Please try again.'];
    }
}

/** Recovery page is generic so it never identifies an existing mailbox. */
function view_staff_otp_reset(array $errors=[]): void
{
    if (current_user()) go('dashboard');
    $email=(string)($_SESSION['staff_reset_pending_email'] ?? '');
    if ($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) go('forgot_password');
    $html='<div class="row justify-content-center"><div class="col-md-7 col-lg-5">'
        . '<div class="panel">'.errors_html($errors)
        . '<h2 class="h5"><i class="bi bi-envelope-check me-2"></i>'
        . 'DENR Staff Password Recovery</h2>'
        . '<p class="small text-secondary">If your email belongs to an active DENR '
        . 'account, enter the 6-digit code sent to it. The code expires in 10 minutes. '
        . 'Applicants should use the password reset link sent to their email.</p>'
        . '<form method="post" action="'.e(url('staff_reset_password')).'">'
        . csrf_field()
        . field('otp','Email Verification Code','','text',true,
            'inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"')
        . field('password','New Password','','password',true,
            'autocomplete="new-password" minlength="12" maxlength="200"')
        . field('confirm_password','Confirm New Password','','password',true,
            'autocomplete="new-password" minlength="12" maxlength="200"')
        . '<p class="small text-secondary">Minimum 12 characters, including letters and numbers.</p>'
        . '<button class="btn btn-primary w-100" type="submit">Verify OTP and Reset Password</button>'
        . '</form><hr>'
        . '<a class="small" href="'.e(url('forgot_password')).'">'
        . 'Request a new code / return to recovery</a>'
        . '</div></div></div>';
    render_page('Staff Password Recovery',$html);
}

/**
 * Username verification never updates a password, and consumes the OTP only
 * after a successful match. SQL row locks and the same five-attempt limit
 * protect against concurrent code replay. No username is disclosed unless
 * the user proves control of the registered recovery mailbox.
 *
 * @return array{username:string}|null
 */
function staff_otp_verify_username(string $email,string $code): ?array
{
    $email = strtolower(trim($email));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190
        || preg_match('/^[0-9]{6}$/D',$code)!==1) return null;

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $user = query_one(
            "SELECT id,username FROM users
             WHERE email=? AND user_kind='internal' AND status='active' FOR UPDATE",
            [$email]
        );
        if (!$user || trim((string)($user['username'] ?? ''))===''
            || query_one("SELECT id FROM user_client WHERE email=? LIMIT 1",[$email])) {
            $pdo->rollBack();
            return null;
        }
        $row = query_one(
            'SELECT id,code_hash,expires_at,failed_attempts
             FROM staff_password_reset_otps
             WHERE user_id=? AND used_at IS NULL
             ORDER BY id DESC LIMIT 1 FOR UPDATE',
            [(int)$user['id']]
        );
        if (!$row || strtotime((string)$row['expires_at'])<=time()
            || (int)$row['failed_attempts']>=5) {
            $pdo->rollBack();
            return null;
        }
        if (!password_verify('username:'.$code,(string)$row['code_hash'])) {
            $pdo->prepare(
                'UPDATE staff_password_reset_otps
                 SET failed_attempts=failed_attempts+1 WHERE id=?'
            )->execute([(int)$row['id']]);
            $pdo->commit();
            return null;
        }
        $pdo->prepare('UPDATE staff_password_reset_otps SET used_at=NOW()
                       WHERE id=? AND used_at IS NULL')
            ->execute([(int)$row['id']]);
        audit((int)$user['id'],'auth.staff_username_recovered','user',(int)$user['id']);
        $pdo->commit();
        return ['username'=>(string)$user['username']];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS staff username recovery failed: '.$error->getMessage());
        return null;
    }
}

/** OTP entry is independent from password reset; safe for unknown accounts. */
function view_staff_username_verification(array $errors=[]): void
{
    if (current_user()) go('dashboard');
    $email = (string)($_SESSION['staff_username_pending_email'] ?? '');
    if ($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) go('forgot_username');

    $html = '<div class="row justify-content-center"><div class="col-md-7 col-lg-5">'
        . '<div class="panel">' . errors_html($errors)
        . '<h2 class="h5"><i class="bi bi-person-check me-2"></i>Verify Recovery Email</h2>'
        . '<p class="text-secondary small">If the address belongs to an active DENR '
        . 'account, a six-digit verification code has been sent. It expires in '
        . '10 minutes. Enter the code to securely reveal your username.</p>'
        . '<form method="post" action="' . e(url('verify_username')) . '">'
        . csrf_field()
        . field('otp','Six-Digit Email OTP','','text',true,
            'inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"')
        . '<button class="btn btn-primary w-100" type="submit">'
        . 'Verify Code &amp; Recover Username</button></form>'
        . '<hr><div class="d-flex justify-content-between flex-wrap gap-2">'
        . '<a class="small" href="' . e(url('forgot_username')) . '">Request New Code</a>'
        . '<a class="small" href="' . e(url('login')) . '">Back to Sign In</a>'
        . '</div></div></div></div>';
    render_page('Verify Username Recovery',$html);
}

/** A recovered username is shown once from the server-side session, never URL. */
function view_staff_username_recovered(): void
{
    if (current_user()) go('dashboard');
    $username = (string)($_SESSION['staff_recovered_username'] ?? '');
    unset($_SESSION['staff_recovered_username']);
    if ($username==='' || !staff_username_valid($username)) go('forgot_username');

    $html = '<div class="row justify-content-center"><div class="col-md-7 col-lg-5">'
        . '<div class="panel text-center">'
        . '<i class="bi bi-patch-check-fill fs-2 text-success" aria-hidden="true"></i>'
        . '<h2 class="h5 mt-2">Username Recovered</h2>'
        . '<p class="small text-secondary">Your email ownership has been verified. '
        . 'Use this DENR username with your password to sign in.</p>'
        . '<div class="border rounded-3 p-3 bg-light">'
        . '<div class="small text-secondary mb-1">Your DENR Username</div>'
        . '<strong class="fs-5 user-select-all">' . e($username) . '</strong></div>'
        . '<a class="btn btn-primary w-100 mt-3" href="' . e(url('login')) . '">'
        . 'Continue to Sign In</a>'
        . '<a class="small d-block mt-3" href="' . e(url('forgot_password')) . '">'
        . 'Forgot your password too?</a></div></div></div>';
    render_page('Username Recovered',$html);
}
