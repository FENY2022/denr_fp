<?php
declare(strict_types=1);

/**
 * Shared-office recovery never grants access using an office mailbox alone.
 * First verify that mailbox (only then list staff), then require a separate
 * unique personal recovery mailbox to recover the selected account.
 *
 * Requires database/025_shared_office_recovery.sql.
 */
function shared_office_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function shared_office_email_valid(string $email): bool
{
    return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function shared_office_email_hash(string $email): string
{
    return hash('sha256', shared_office_normalize_email($email));
}

/** No user identities disclosed by initial mailbox lookup. */
function shared_office_match_count(string $email): int
{
    $row = query_one(
        "SELECT COUNT(*) AS n FROM users u
         WHERE u.user_kind='internal' AND u.status='active'
           AND (LOWER(TRIM(COALESCE(u.reference_email,'')))=?
             OR LOWER(TRIM(COALESCE(u.email,'')))=?)",
        [$email, $email]
    );
    return (int)($row['n'] ?? 0);
}

function shared_office_candidates(string $email): array
{
    return query_all(
        "SELECT u.id,u.name,u.username,u.email,u.office_station
         FROM users u WHERE u.user_kind='internal' AND u.status='active'
           AND (LOWER(TRIM(COALESCE(u.reference_email,'')))=?
             OR LOWER(TRIM(COALESCE(u.email,'')))=?)
         ORDER BY u.name ASC,u.id ASC",
        [$email, $email]
    );
}

/**
 * An individual recovery address must be unique across both principal tables
 * AND must not be a reference mailbox associated with another staff member.
 * This check also hardens direct forgot-password/forgot-username OTP requests.
 */
function staff_recovery_email_exclusive(string $email, int $targetId): bool
{
    $email = shared_office_normalize_email($email);
    if ($targetId < 1 || !shared_office_email_valid($email)) return false;
    $row = query_one(
        "SELECT COUNT(*) AS n,MAX(id) AS staff_id FROM users
         WHERE user_kind='internal' AND LOWER(TRIM(email))=?",
        [$email]
    );
    if ((int)($row['n'] ?? 0) !== 1
        || (int)($row['staff_id'] ?? 0) !== $targetId) return false;
    $other = query_one(
        "SELECT id FROM users WHERE user_kind='internal' AND id<>?
         AND (LOWER(TRIM(COALESCE(reference_email,'')))=?
           OR LOWER(TRIM(COALESCE(email,'')))=?) LIMIT 1",
        [$targetId,$email,$email]
    );
    if ($other) return false;
    // A staff recovery email cannot also identify an applicant.
    return query_one("SELECT id FROM user_client WHERE email=? LIMIT 1", [$email]) === null;
}

/** A narrow lookup: never select the first of multiple matching accounts. */
function staff_recovery_unique_account(string $email): ?array
{
    $email = shared_office_normalize_email($email);
    if (!shared_office_email_valid($email)) return null;
    $users = query_all(
        "SELECT id,name,username,email FROM users
         WHERE user_kind='internal' AND status='active'
           AND LOWER(TRIM(email))=? LIMIT 2",
        [$email]
    );
    if (count($users) !== 1) return null;
    $user = $users[0];
    return staff_recovery_email_exclusive($email,(int)$user['id']) ? $user : null;
}

/**
 * Request a separate six-digit OTP to the shared OFFICE mailbox.
 * Sends only if at least two active DENR identities reference the address.
 * Errors remain generic to prevent account/email enumeration.
 */
function shared_office_request(string $email): void
{
    $email = shared_office_normalize_email($email);
    if (!shared_office_email_valid($email)) return;
    $hash = shared_office_email_hash($email);
    $ip = client_ip();
    try {
        $ipRate = query_one(
            'SELECT COUNT(*) AS n FROM shared_office_recovery_otps
             WHERE requested_ip=? AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)',
            [$ip]
        );
        if ((int)($ipRate['n'] ?? 0) >= 20) return;
        $rate = query_one(
            'SELECT COUNT(*) AS n,MAX(created_at) AS last_sent
             FROM shared_office_recovery_otps
             WHERE email_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)',
            [$hash]
        );
        if ((int)($rate['n'] ?? 0) >= 3) return;
        $last = (string)($rate['last_sent'] ?? '');
        if ($last !== '' && strtotime($last) > time() - 60) return;
        if (shared_office_match_count($email) < 2) return;
        if (query_one("SELECT id FROM user_client WHERE email=? LIMIT 1",[$email])) return;

        $otp = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE shared_office_recovery_otps
                       SET used_at=NOW() WHERE email_hash=? AND used_at IS NULL')
            ->execute([$hash]);
        $pdo->prepare(
            'INSERT INTO shared_office_recovery_otps
             (email_hash,code_hash,requested_ip,expires_at)
             VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))'
        )->execute([$hash,password_hash('office:'.$otp,PASSWORD_DEFAULT),$ip]);
        $otpId = (int)$pdo->lastInsertId();
        $pdo->commit();

        $body = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#17352a">'
            . '<h2>DENR CARAGA FP-OMS</h2><p>Shared office mailbox verification code:</p>'
            . '<p style="font-size:30px;font-weight:bold;letter-spacing:6px">' . e($otp) . '</p>'
            . '<p>This code expires in 10 minutes. It only permits viewing account choices; '
            . 'it does not grant account access or password reset permission.</p>'
            . '<p>If you did not request this, ignore this email.</p></body></html>';
        if (!smtp_send_html($email,'DENR FP-OMS — Shared Office Verification OTP',$body)) {
            $pdo->prepare('UPDATE shared_office_recovery_otps SET used_at=NOW()
                           WHERE id=? AND used_at IS NULL')->execute([$otpId]);
        }
    } catch (Throwable $error) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS shared office OTP unavailable: '.get_class($error));
    }
}

/** Verify, consume and authorize exactly one short-lived account-choice session. */
function shared_office_verify(string $email,string $otp): bool
{
    $email = shared_office_normalize_email($email);
    if (!shared_office_email_valid($email) ||
        preg_match('/^[0-9]{6}$/D',$otp)!==1) return false;
    try {
        $hash = shared_office_email_hash($email);
        $pdo = db();
        $pdo->beginTransaction();
        $row = query_one(
            'SELECT * FROM shared_office_recovery_otps
             WHERE email_hash=? AND used_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE',
            [$hash]
        );
        if (!$row || strtotime((string)$row['expires_at']) <= time()
            || (int)$row['failed_attempts'] >= 5
            || shared_office_match_count($email) < 2) {
            $pdo->rollBack();
            return false;
        }
        if (!password_verify('office:'.$otp,(string)$row['code_hash'])) {
            $pdo->prepare(
                'UPDATE shared_office_recovery_otps
                 SET failed_attempts=failed_attempts+1 WHERE id=?'
            )->execute([(int)$row['id']]);
            $pdo->commit();
            return false;
        }
        $pdo->prepare(
            'UPDATE shared_office_recovery_otps SET used_at=NOW()
             WHERE id=? AND used_at IS NULL'
        )->execute([(int)$row['id']]);
        $pdo->commit();
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['shared_office_verified_email'] = $email;
        $_SESSION['shared_office_verified_expires_at'] = time()+600;
        unset($_SESSION['shared_office_pending_email']);
        return true;
    } catch (Throwable $error) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS shared office OTP verification unavailable: '.get_class($error));
        return false;
    }
}

/** Verified mailbox grant can never be supplied by an untrusted query string. */
function shared_office_verified_email(): string
{
    $email = (string)($_SESSION['shared_office_verified_email'] ?? '');
    $until = (int)($_SESSION['shared_office_verified_expires_at'] ?? 0);
    if ($until < time() || !shared_office_email_valid($email)) {
        unset($_SESSION['shared_office_verified_email'],
            $_SESSION['shared_office_verified_expires_at']);
        return '';
    }
    return $email;
}

/**
 * Account choice never resets a password. It initiates an ordinary OTP to
 * the selected identity's DISTINCT and EXCLUSIVE personal recovery email.
 */
function shared_office_choose_account(int $id,string $purpose): array
{
    $officeEmail = shared_office_verified_email();
    if ($officeEmail==='' || $id<1 || !in_array($purpose,['password','username'],true)) {
        return ['Your office verification expired. Request a new code.'];
    }
    $rows = shared_office_candidates($officeEmail);
    $selected = null;
    if (count($rows) < 2) {
        return ['Office account verification expired. Request a new code.'];
    }
    foreach ($rows as $row) {
        if ((int)$row['id'] === $id) $selected = $row;
    }
    if ($selected === null) return ['Select one of the verified office accounts.'];
    $personal = shared_office_normalize_email((string)($selected['email'] ?? ''));
    if ($personal === $officeEmail ||
        !staff_recovery_email_exclusive($personal,$id)) {
        return ['This account has no separate verified personal recovery email. '
            . 'Contact your RICTU Super Administrator to update it and verify your identity.'];
    }

    // Consume the office mailbox grant before moving into separate personal OTP.
    unset($_SESSION['shared_office_verified_email'],
        $_SESSION['shared_office_verified_expires_at'],
        $_SESSION['shared_office_pending_email']);
    if ($purpose==='username') {
        unset($_SESSION['staff_recovered_username']);
        $_SESSION['staff_username_pending_email']=$personal;
        staff_otp_request($personal,'username');
    } else {
        unset($_SESSION['staff_password_reset_user_id'],
            $_SESSION['staff_password_reset_expires_at']);
        $_SESSION['staff_reset_pending_email']=$personal;
        staff_otp_request($personal,'password');
    }
    return [];
}

function view_shared_office_recovery(): void
{
    if (current_user()) go('dashboard');
    $html = '<div class="row justify-content-center"><div class="col-lg-6">'
        . '<div class="panel"><h2 class="h5 mb-2">'
        . '<i class="bi bi-people me-2"></i>Shared Office Email Recovery</h2>'
        . '<p class="small text-secondary">If several DENR staff accounts use the same '
        . 'office email reference, you can verify access to that mailbox first, '
        . 'then choose the intended staff account. A separate individual recovery '
        . 'email is still required for the final OTP.</p>'
        . '<form method="post" action="'.e(url('shared_office_recovery')).'" data-inline-submit-loading>'
        . csrf_field()
        . field('office_email','Shared Office Email','','email',true,
            'autocomplete="email" maxlength="190"')
        . '<input type="hidden" name="action" value="request">'
        . '<div class="mb-3"><label class="form-label" for="shared_purpose">'
        . 'What do you need to recover?</label><select class="form-select" '
        . 'name="purpose" id="shared_purpose">'
        . '<option value="password">Password</option>'
        . '<option value="username">Username</option></select></div>'
        . '<button class="btn btn-primary w-100" type="submit">'
        . 'Send Office Verification OTP</button></form>'
        . '<p class="small text-secondary mt-3">For security, we do not reveal '
        . 'whether an email or account exists. The code is valid for 10 minutes.</p>'
        . '<hr><a href="'.e(url('forgot_password')).'" class="small">'
        . '← Back to Account Recovery</a></div></div></div>';
    render_page('Shared Office Recovery',$html);
}

function view_shared_office_verify(array $errors=[]): void
{
    if (current_user()) go('dashboard');
    if (empty($_SESSION['shared_office_pending_email'])) go('shared_office_recovery');
    $html='<div class="row justify-content-center"><div class="col-lg-5"><div class="panel">'
        . errors_html($errors)
        . '<h2 class="h5"><i class="bi bi-envelope-check me-2"></i>'
        . 'Verify Office Email</h2>'
        . '<p class="small text-secondary">If this email belongs to several active DENR '
        . 'accounts, a six-digit code has been sent to the office mailbox. '
        . 'Verify it before the system displays account choices.</p>'
        . '<form method="post" action="'.e(url('shared_office_verify')).'">'
        . csrf_field()
        . field('otp','Six-Digit Office Email OTP','','text',true,
            'inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code"')
        . '<button class="btn btn-primary w-100" type="submit">'
        . 'Verify Office Email</button></form>'
        . '<hr><a href="'.e(url('shared_office_recovery')).'" class="small">'
        . 'Request New Code</a></div></div></div>';
    render_page('Verify Shared Office Email',$html);
}

function view_shared_office_accounts(array $errors=[]): void
{
    if (current_user()) go('dashboard');
    $email = shared_office_verified_email();
    if ($email==='') go('shared_office_recovery');
    $rows = shared_office_candidates($email);
    if (count($rows)<2) go('shared_office_recovery');
    $purpose = (string)($_SESSION['shared_office_purpose'] ?? 'password');
    $html = '<div class="row justify-content-center"><div class="col-lg-7">'
        . '<div class="panel">'.errors_html($errors)
        . '<h2 class="h5"><i class="bi bi-person-check me-2"></i>'
        . 'Select Your DENR Account</h2>'
        . '<div class="alert alert-success small">Office email access verified. '
        . 'Choose your account below. This verification alone cannot reset any password.</div>'
        . '<form method="post" action="'.e(url('shared_office_accounts')).'" '
        . 'id="shared-office-account-form">'
        . csrf_field()
        . '<input type="hidden" name="purpose" value="'.e($purpose).'">'
        . '<div class="d-grid gap-2 mb-3">';
    foreach ($rows as $row) {
        $name = trim((string)($row['name'] ?? 'DENR personnel'));
        $username = (string)($row['username'] ?? '');
        $office = (string)($row['office_station'] ?? '');
        $html .= '<label class="border rounded-3 p-3 d-flex align-items-start gap-3">'
            . '<input type="radio" name="staff_id" class="form-check-input mt-1" '
            . 'value="'.(int)$row['id'].'" required data-office-account-choice '
            . 'data-account-label="'.e($name).'">'
            . '<span class="d-block"><strong>'.e($name).'</strong>'
            . '<span class="d-block text-secondary small">Username: '.e($username)
            . '</span><span class="d-block text-secondary small">Office: '
            . e($office).'</span></span></label>';
    }
    $html .= '</div><button type="button" class="btn btn-primary w-100" '
        . 'data-office-open-confirm>Select This Account</button>'
        . '</form><p class="small text-secondary mt-3 mb-0">A new OTP will be sent '
        . 'only to the selected account’s separate personal recovery email. '
        . 'If that address is missing or shared, contact the RICTU Super Administrator.</p>'
        . '<hr><a class="small" href="'.e(url('shared_office_recovery')).'">'
        . 'Start Over</a></div></div></div>'
        . '<div class="modal fade" id="shared-office-confirm-modal" tabindex="-1" '
        . 'aria-labelledby="shared-office-confirm-title" aria-hidden="true">'
        . '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h2 id="shared-office-confirm-title" class="modal-title h5">'
        . 'Confirm Account Selection</h2>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" '
        . 'aria-label="Close"></button></div>'
        . '<div class="modal-body"><p>Continue recovery for '
        . '<strong id="shared-office-confirm-name"></strong>?</p>'
        . '<p class="small text-secondary">You will still need a separate '
        . 'individual recovery email OTP.</p></div>'
        . '<div class="modal-footer"><button type="button" '
        . 'class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button type="button" class="btn btn-primary" '
        . 'data-office-confirm-submit>Confirm &amp; Send OTP</button>'
        . '</div></div></div></div>'
        . '<script src="assets/shared-office-recovery.js?v=1" defer></script>';
    render_page('Select Your Account',$html);
}
