<?php
declare(strict_types=1);

/**
 * Client-only registration verification. The 6-digit value is never stored in
 * cleartext, logged, included in URLs, or exposed to staff review screens.
 * A pending client is not an authenticated FP-OMS user.
 */
function registration_otp_pending_client(): ?array
{
    $id = (int)($_SESSION['registration_pending_client_id'] ?? 0);
    if ($id < 1) return null;
    $row = query_one(
        "SELECT id,name,email,status FROM user_client
         WHERE id=? AND user_kind='applicant' AND status='pending_email_verification'",
        [$id]
    );
    if (!$row) unset($_SESSION['registration_pending_client_id']);
    return $row;
}

function registration_otp_generate(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/** Called inside the registration transaction before its COMMIT. */
function registration_otp_create_initial(PDO $pdo, int $clientId): string
{
    $code = registration_otp_generate();
    $pdo->prepare(
        'INSERT INTO registration_email_otps
         (client_id,otp_hash,issued_at,expires_at,failed_attempts,
          resend_window_started_at,sends_in_window,last_send_at)
         VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, NOW(), 1, NOW())'
    )->execute([$clientId, password_hash($code, PASSWORD_DEFAULT)]);
    return $code;
}

/** Only call after the OTP row and registration have committed. */
function registration_otp_deliver(int $clientId, string $email, string $name, string $code): bool
{
    try {
        $sent = send_registration_otp_email($email, $name, $code);
    } catch (Throwable $mailError) {
        error_log('FP-OMS registration OTP delivery failed: ' . $mailError->getMessage());
        $sent = false;
    }
    try {
        audit_client($clientId, 'registration.otp_issued', 'client', $clientId,
            ['delivery'=>$sent ? 'smtp' : 'not_sent']);
    } catch (Throwable $auditError) {
        error_log('FP-OMS OTP delivery audit failure: ' . $auditError->getMessage());
    }
    $_SESSION['registration_otp_delivery_failed'] = !$sent;
    return $sent;
}

/**
 * Send a new code after 60 seconds, at most 5 sends per rolling hour
 * (including the first). Each resend invalidates the preceding code.
 */
function registration_otp_resend(): array
{
    $client = registration_otp_pending_client();
    if (!$client) return ['The registration verification session is unavailable. Sign in to continue.'];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $locked = query_one(
            "SELECT id,email,name FROM user_client
             WHERE id=? AND user_kind='applicant' AND status='pending_email_verification' FOR UPDATE",
            [(int)$client['id']]
        );
        $otp = query_one(
            'SELECT *,
                TIMESTAMPDIFF(SECOND,last_send_at,NOW()) AS cooldown_seconds,
                TIMESTAMPDIFF(SECOND,resend_window_started_at,NOW()) AS window_seconds
             FROM registration_email_otps WHERE client_id=? FOR UPDATE',
            [(int)$client['id']]
        );
        if (!$locked || !$otp) {
            $pdo->rollBack();
            return ['This verification request is no longer available.'];
        }
        if ((int)$otp['cooldown_seconds'] < 60) {
            $pdo->rollBack();
            return ['Please wait at least 60 seconds before requesting another OTP.'];
        }
        $newWindow = (int)$otp['window_seconds'] >= 3600;
        $nextCount = $newWindow ? 1 : (int)$otp['sends_in_window'] + 1;
        if ($nextCount > 5) {
            $pdo->rollBack();
            return ['Hourly OTP limit reached. Please retry after one hour.'];
        }
        $code = registration_otp_generate();
        $pdo->prepare(
            'UPDATE registration_email_otps
             SET otp_hash=?, issued_at=NOW(), expires_at=DATE_ADD(NOW(), INTERVAL 10 MINUTE),
                 failed_attempts=0, last_send_at=NOW(), sends_in_window=?,
                 resend_window_started_at=IF(?,NOW(),resend_window_started_at)
             WHERE client_id=?'
        )->execute([
            password_hash($code, PASSWORD_DEFAULT),$nextCount,$newWindow ? 1 : 0,
            (int)$locked['id']
        ]);
        $pdo->commit();
        if (!registration_otp_deliver((int)$locked['id'], (string)$locked['email'], (string)$locked['name'], $code)) {
            return ['OTP email could not be delivered. Check your email settings or retry after 60 seconds.'];
        }
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS OTP resend failed: ' . $e->getMessage());
        return ['Unable to request another OTP. Please try again.'];
    }
}

/**
 * On success, move to FUU review BEFORE attempting acknowledgment SMTP.
 * No acknowledgment or FUU review notification is issued for invalid OTP.
 */
function registration_otp_verify(string $submitted): array
{
    $client = registration_otp_pending_client();
    if (!$client) return ['The registration verification session is unavailable. Sign in to continue.'];
    if (!preg_match('/^[0-9]{6}$/D', $submitted)) return ['Enter the six-digit code sent to your email.'];

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $locked = query_one(
            "SELECT id,email,name,status FROM user_client
             WHERE id=? AND user_kind='applicant' AND status='pending_email_verification' FOR UPDATE",
            [(int)$client['id']]
        );
        $otp = query_one(
            'SELECT *, (expires_at>NOW()) AS is_valid
             FROM registration_email_otps WHERE client_id=? FOR UPDATE',
            [(int)$client['id']]
        );
        if (!$locked || !$otp) {
            $pdo->rollBack();
            return ['This verification request is no longer available.'];
        }
        if ((int)$otp['is_valid'] !== 1) {
            $pdo->rollBack();
            return ['This OTP has expired. Request a new code.'];
        }
        if ((int)$otp['failed_attempts'] >= 5) {
            $pdo->rollBack();
            return ['Too many incorrect attempts. Request a new OTP.'];
        }
        if (!password_verify($submitted, (string)$otp['otp_hash'])) {
            $pdo->prepare(
                'UPDATE registration_email_otps SET failed_attempts=failed_attempts+1 WHERE client_id=?'
            )->execute([(int)$locked['id']]);
            $pdo->commit();
            return ['Incorrect OTP. Please check the code and try again.'];
        }

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

        try {
            notify_role(
                'lpdd_fus', 'New Applicant Registration',
                (string)$locked['name'] . ' submitted a verified registration for '
                    . (string)$reg['company_name'] . '.',
                'person-plus', 'info', 'review_queue'
            );
        } catch (Throwable $notifyError) {
            error_log('Post-OTP reviewer notification failure: ' . $notifyError->getMessage());
        }

        try {
            $sent = send_registration_acknowledgment_email(
                (string)$locked['email'],(string)$locked['name']
            );
            audit_client((int)$locked['id'], 'registration.email_acknowledgment',
                'registration', (int)$reg['id'], ['delivery'=>$sent?'smtp':'not_sent']);
        } catch (Throwable $mailError) {
            error_log('Post-OTP acknowledgment email failed: ' . $mailError->getMessage());
        }
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FP-OMS OTP verification failed: ' . $e->getMessage());
        return ['Verification could not be completed. Please retry.'];
    }
}
