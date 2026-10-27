<?php
declare(strict_types=1);

/** Legacy table registration_email_otps stores hashed single-use email link tokens. */
function registration_otp_pending_client(): ?array
{
    $id = (int)($_SESSION['registration_pending_client_id'] ?? 0);
    if ($id < 1) return null;
    $row = query_one("SELECT id,name,email,status FROM user_client WHERE id=? AND user_kind='applicant' AND status='pending_email_verification'", [$id]);
    if (!$row) unset($_SESSION['registration_pending_client_id']);
    return $row;
}

function registration_otp_generate(): string
{
    return bin2hex(random_bytes(32));
}

function registration_otp_create_initial(PDO $pdo, int $clientId): string
{
    $token = registration_otp_generate();
    $pdo->prepare('INSERT INTO registration_email_otps
        (client_id,otp_hash,issued_at,expires_at,failed_attempts,resend_window_started_at,sends_in_window,last_send_at)
        VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE), 0, NOW(), 1, NOW())')
        ->execute([$clientId, hash('sha256', $token)]);
    return $token;
}

function registration_verification_url(string $token): ?string
{
    $base = rtrim(trim(env_value('APP_URL')), '/');
    $parts = parse_url($base);
    if (!$parts || !in_array($parts['scheme'] ?? '', ['https','http'], true)
        || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) return null;
    $host = strtolower((string)$parts['host']);
    $local = in_array($host, ['localhost','127.0.0.1','::1'], true);
    if (($parts['scheme'] ?? '') !== 'https' && !$local) return null;
    return $base . '/confirm-registration-email?token=' . rawurlencode($token);
}

function registration_otp_deliver(int $clientId, string $email, string $name, string $token): bool
{
    $link = registration_verification_url($token);
    $sent = false;
    try {
        if ($link !== null) $sent = send_registration_verification_link_email($email, $name, $link);
        else error_log('FP-OMS email verification link unavailable: configure APP_URL.');
    } catch (Throwable $e) {
        error_log('FP-OMS verification link delivery failed: ' . $e->getMessage());
    }
    try {
        audit_client($clientId, 'registration.verification_link_issued', 'client', $clientId,
            ['delivery'=>$sent ? 'smtp' : 'not_sent']);
    } catch (Throwable $e) {
        error_log('FP-OMS verification email audit failure: ' . $e->getMessage());
    }
    $_SESSION['registration_otp_delivery_failed'] = !$sent;
    return $sent;
}

function registration_otp_resend(): array
{
    $client = registration_otp_pending_client();
    if (!$client) return ['Verification session unavailable. Sign in to continue.'];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $locked = query_one("SELECT id,email,name FROM user_client WHERE id=? AND user_kind='applicant' AND status='pending_email_verification' FOR UPDATE", [(int)$client['id']]);
        $row = query_one('SELECT *, TIMESTAMPDIFF(SECOND,last_send_at,NOW()) cooldown_seconds,
            TIMESTAMPDIFF(SECOND,resend_window_started_at,NOW()) window_seconds
            FROM registration_email_otps WHERE client_id=? FOR UPDATE', [(int)$client['id']]);
        if (!$locked || !$row) { $pdo->rollBack(); return ['Verification request unavailable.']; }
        if ((int)$row['cooldown_seconds'] < 60) { $pdo->rollBack(); return ['Please wait 60 seconds before resending.']; }
        $newWindow = (int)$row['window_seconds'] >= 3600;
        $count = $newWindow ? 1 : (int)$row['sends_in_window'] + 1;
        if ($count > 5) { $pdo->rollBack(); return ['Hourly verification email limit reached. Try again later.']; }
        $token = registration_otp_generate();
        $pdo->prepare('UPDATE registration_email_otps SET otp_hash=?, issued_at=NOW(),
            expires_at=DATE_ADD(NOW(), INTERVAL 30 MINUTE), failed_attempts=0,
            last_send_at=NOW(), sends_in_window=?,
            resend_window_started_at=IF(?,NOW(),resend_window_started_at) WHERE client_id=?')
            ->execute([hash('sha256',$token),$count,$newWindow ? 1 : 0,(int)$locked['id']]);
        $pdo->commit();
        if (!registration_otp_deliver((int)$locked['id'],(string)$locked['email'],(string)$locked['name'],$token)) {
            return ['Verification email could not be delivered. Check the email settings and try again.'];
        }
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS verification email resend failed: ' . $e->getMessage());
        return ['Unable to resend verification email.'];
    }
}

/** Verification link works across devices without relying on the registration session. */
function registration_otp_verify(string $submitted): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $submitted)) return ['Invalid verification link. Request a new email.'];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $tokenHash = hash('sha256', $submitted);
        $otp = query_one('SELECT *, (expires_at>NOW()) AS is_valid
            FROM registration_email_otps WHERE otp_hash=? FOR UPDATE', [$tokenHash]);
        if (!$otp || (int)$otp['is_valid'] !== 1) {
            $pdo->rollBack();
            return ['This verification link is invalid, expired, or already used. Sign in to request another.'];
        }
        $locked = query_one("SELECT id,email,name,status FROM user_client
            WHERE id=? AND user_kind='applicant' AND status='pending_email_verification' FOR UPDATE", [(int)$otp['client_id']]);
        if (!$locked) { $pdo->rollBack(); return ['This registration is already verified or unavailable.']; }
        $pdo->prepare(
            "UPDATE user_client
             SET status='pending_review',email_verified_at=NOW()
             WHERE id=? AND status='pending_email_verification'"
        )->execute([(int)$locked['id']]);
        $pdo->prepare('DELETE FROM registration_email_otps WHERE client_id=?')
            ->execute([(int)$locked['id']]);
        $reg = query_one('SELECT id,company_name FROM applicant_registrations WHERE client_id=?',[(int)$locked['id']]);
        if (!$reg) throw new RuntimeException('Verified client registration is missing.');
        audit_client((int)$locked['id'],'registration.email_verified','registration',(int)$reg['id']);
        $pdo->commit();
        unset($_SESSION['registration_pending_client_id'], $_SESSION['registration_otp_delivery_failed']);

        // The client acknowledgment is the FIRST post-verification notification.
        // Verification has committed; attempt SMTP synchronously before notifying Forest Utilization Section (FUU) reviewers.
        // A transport failure does not undo verification and is shown in the modal.
        $acknowledgmentSent = false;
        try {
            $acknowledgmentSent = send_registration_acknowledgment_email(
                (string)$locked['email'],(string)$locked['name']
            );
            audit_client((int)$locked['id'], 'registration.email_acknowledgment',
                'registration', (int)$reg['id'],
                ['delivery'=>$acknowledgmentSent ? 'smtp' : 'not_sent']);
        } catch (Throwable $mailError) {
            error_log('Post-verification acknowledgment email failed: ' . $mailError->getMessage());
        }
        try {
            notify_role(
                'lpdd_fus', 'New Applicant Registration',
                (string)$locked['name'] . ' submitted a verified registration for '
                    . (string)$reg['company_name'] . '.',
                'person-plus', 'info', 'review_queue'
            );
        } catch (Throwable $notifyError) {
            error_log('Post-verification reviewer notification failure: ' . $notifyError->getMessage());
        }

        // Reviewer emails are best-effort and must never reverse successful OTP verification.
        try {
            send_lpdd_fus_registration_alerts((int)$reg['id'],
                (string)$locked['name'], (string)$reg['company_name']);
        } catch (Throwable $reviewerMailError) {
            error_log('Post-verification LPDD-FUS email notification failure: ' . $reviewerMailError->getMessage());
        }

        $_SESSION['registration_ack_delivery_failed'] = !$acknowledgmentSent;
        $_SESSION['registration_verified_applicant_name'] = (string)$locked['name'];
        return [];
