<?php
declare(strict_types=1);

/**
 * Annex A payment stage: applicant pays at the DENR Regional Office Cashier,
 * then presents the Official Receipt to LPDD-FUS. Only an active LPDD-FUS account can
 * record payment verification and upload the protected receipt.
 */

function permit_payment_schema_ready(): bool
{
    try {
        db()->query('SELECT application_id,status,receipt_number,verified_at FROM permit_payment_verifications LIMIT 0');
        return true;
    } catch (Throwable $e) {
        error_log('FP-OMS payment migration 028 required: '.$e->getMessage());
        return false;
    }
}

function permit_payment_require_schema(): void
{
    if (!permit_payment_schema_ready()) {
        show_error('Apply database migration 028 to enable LPDD-FUS payment verification.',503);
    }
}

function permit_payment_require_lpdd_fus(): array
{
    $staff=require_role('lpdd_fus');
    permit_payment_require_schema();
    return $staff;
}

function permit_payment_record(int $applicationId): ?array
{
    if (!permit_payment_schema_ready()) return null;
    return query_one(
        'SELECT p.*,u.name AS verifier_name
         FROM permit_payment_verifications p
         LEFT JOIN users u ON u.id=p.verified_by_user_id
         WHERE p.application_id=?',[$applicationId]
    );
}

function permit_payment_available_for_application(array $app): bool
{
    if ((string)($app['status']??'')==='step4_complete') return true;
    return permit_flow_is_new($app)
        && fpoms_payment_notice_is_released((int)$app['id'],(int)$app['revision']);
}

function permit_payment_instructions(int $applicationId): string
{
    return 'Your Feasibility Permit application #'.$applicationId
        .' is complete and sufficient in documentary requirements. '
        .'Please proceed to the DENR CARAGA Regional Office Cashier '
        .'to pay the required application fee according to official payment instructions. '
        .'After paying at the Cashier, please proceed directly to the LPDD-FUS '
        .'and present your Official Receipt (OR) for payment verification. '
        .'CENRO will notify you separately to submit hard copies for field validation. '
        .'The authorized LPDD-FUS personnel will verify the payment details and upload the OR in FP-OMS. '
        .'Payment remains Awaiting LPDD-FUS Verification until the authorized LPDD-FUS account confirms it.';
}

function permit_payment_input_errors(array $input): array
{
    $errors=[];
    $or=trim((string)($input['receipt_number']??''));
    $amount=str_replace(',','',trim((string)($input['amount_paid']??'')));
    $date=trim((string)($input['payment_date']??''));
    $notes=trim((string)($input['notes']??''));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/ .#]{1,89}$/D',$or)) {
        $errors[]='Enter a valid Cashier Official Receipt number (2–90 characters).';
    }
    if (!preg_match('/^\d{1,11}(?:\.\d{1,2})?$/D',$amount)
        || (float)$amount<=0 || (float)$amount>99999999999.99) {
        $errors[]='Enter the actual positive amount paid in PHP (up to two decimals).';
    }
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    $dateErrors=DateTimeImmutable::getLastErrors();
    if (!$parsed || $parsed->format('Y-m-d')!==$date
        || ($dateErrors!==false && ($dateErrors['warning_count']>0 || $dateErrors['error_count']>0))
        || $date>date('Y-m-d')) {
        $errors[]='Enter a valid payment date not later than today.';
    }
    if (strlen($notes)>2000) $errors[]='Verification notes must not exceed 2,000 characters.';
    return $errors;
}

function permit_payment_verify(array $lpddFus,int $applicationId,array $input,array $files): array
{
    if (($lpddFus['user_kind']??'')!=='internal' || ($lpddFus['status']??'')!=='active'
        || !has_role($lpddFus,'lpdd_fus')) {
        return ['An active LPDD-FUS account is required to verify payments.'];
    }
    if (!permit_payment_schema_ready()) return ['Apply database migration 028 first.'];
    if (($input['confirmation']??'')!=='verify_payment') {
        return ['Please confirm that the Official Receipt has been checked against Cashier payment details.'];
    }
    $errors=permit_payment_input_errors($input);
    $upload=upload_details($files['official_receipt']??null,true);
    if (isset($upload['error'])) $errors[]='Official Receipt: '.$upload['error'];
    elseif (!isset($upload['file'])) $errors[]='Upload the Cashier-issued Official Receipt.';
    if ($errors) return $errors;
    $amount=str_replace(',','',trim((string)$input['amount_paid']));

    $pdo=db();
    $stored=null;
    $app=null;
    try {
        $pdo->beginTransaction();
        $app=query_one(
            'SELECT a.id,a.status,a.revision,a.flow_version,a.client_id,c.email AS applicant_email,
                    c.name AS applicant_name
             FROM permit_step2_applications a
             JOIN user_client c ON c.id=a.client_id
             WHERE a.id=? FOR UPDATE',[$applicationId]
        );
        if (!$app || !permit_payment_available_for_application($app)) {
            $pdo->rollBack();
            return ['Payment may be verified only after the official RED-signed advice-to-pay notice has been released.'];
        }

        // Serialize repeated submissions and reject duplicate verification.
        $existing=query_one(
            'SELECT id,status FROM permit_payment_verifications WHERE application_id=? FOR UPDATE',
            [$applicationId]
        );
        if ($existing && (string)$existing['status']==='verified') {
            $pdo->rollBack();
            return ['Payment has already been verified. Refresh to view the Official Receipt.'];
        }
        if (!$existing) {
            $pdo->prepare("INSERT INTO permit_payment_verifications
                (application_id,status) VALUES (?,'awaiting_payment')")->execute([$applicationId]);
        }

        $relative='fp_payments/'.$applicationId.'/'.bin2hex(random_bytes(18)).'.'.$upload['extension'];
        $stored=PROJECT_ROOT.'/storage/'.$relative;
        if (!is_dir(dirname($stored)) && !mkdir(dirname($stored),0700,true)
            && !is_dir(dirname($stored))) {
            throw new RuntimeException('Could not prepare private payment document directory.');
        }
        fpoms_file_store_upload((string)$upload['file']['tmp_name'],$stored);
        @chmod($stored,0600);
        $digest=fpoms_file_plaintext_sha256($stored);

        $stmt=$pdo->prepare(
            "UPDATE permit_payment_verifications
             SET status='verified',receipt_number=?,amount_paid=?,payment_date=?,
                 original_name=?,stored_path=?,mime_type=?,file_size=?,sha256=?,
                 verified_by_user_id=?,verified_at=NOW(),notes=?
             WHERE application_id=? AND status='awaiting_payment'"
        );
        $stmt->execute([
            trim((string)$input['receipt_number']),
            number_format((float)$amount,2,'.',''),
            trim((string)$input['payment_date']),
            (string)$upload['name'],$relative,(string)$upload['mime'],
            (int)$upload['size'],$digest,(int)$lpddFus['id'],
            trim((string)($input['notes']??'')) ?: null,$applicationId
        ]);
        if ($stmt->rowCount()!==1) throw new RuntimeException('Concurrent payment verification blocked.');

        $pdo->prepare(
            'INSERT INTO permit_step2_events
                (application_id,actor_user_id,action,revision,remarks)
             VALUES (?,? ,\'payment_verified_lpdd_fus\',?,?)'
        )->execute([
            $applicationId,(int)$lpddFus['id'],(int)$app['revision'],
            'LPDD-FUS verified Cashier payment; OR No. '.trim((string)$input['receipt_number'])
        ]);
        audit((int)$lpddFus['id'],'permit.payment.verified','permit_step2_application',$applicationId,[
            'receipt_number'=>trim((string)$input['receipt_number']),
            'payment_date'=>trim((string)$input['payment_date']),
            'amount_paid'=>number_format((float)$amount,2,'.',''),
            'file_sha256'=>$digest
        ]);
        $pdo->commit();
        fpoms_pusher_flush_pending();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($stored!==null && is_file($stored)) @unlink($stored);
        error_log('FP-OMS LPDD-FUS payment verification error: '.$e->getMessage());
        return ['Payment verification could not be saved. Check migration 028 and private storage permissions.'];
    }

    $name=(string)($app['applicant_name']??'Applicant');
    $or=trim((string)$input['receipt_number']);
    $message='LPDD-FUS verified payment for FP application #'.$applicationId
        .' (OR No. '.$or.'). Your payment status is now Payment Verified.';
    try {
        create_client_notification((int)$app['client_id'],'Payment Verified by LPDD-FUS',
            $message,'receipt','success','permit_application',['id'=>$applicationId]);
        notify_role('lpdd_fus','FP Payment Verified by LPDD-FUS',
            $message,'cash-coin','success','permit_application',['id'=>$applicationId]);
    } catch (Throwable $e) {
        error_log('FP-OMS payment notification failed: '.$e->getMessage());
    }
    $email=(string)($app['applicant_email']??'');
    if (filter_var($email,FILTER_VALIDATE_EMAIL)) {
        $body='<p>Your FP application <strong>#'.$applicationId.'</strong> has '
            .'received official payment verification from the LPDD-FUS.</p>'
            .'<p><strong>Payment status:</strong> Payment Verified<br>'
            .'<strong>Official Receipt No.:</strong> '.e($or).'<br>'
            .'<strong>Payment date:</strong> '.e((string)$input['payment_date']).'</p>'
            .'<p>You may log in to FP-OMS to view the payment record and secure receipt.</p>';
        try {
            smtp_send_html($email,'FP Application #'.$applicationId.' – LPDD-FUS Payment Verified',
                registration_email_layout('Payment Verified by LPDD-FUS',$name,$body),
                $message);
        } catch (Throwable $e) {
            error_log('FP-OMS payment confirmation email failed: '.$e->getMessage());
        }
    }
    return [];
}

/** Download/preview the encrypted OR only with valid case-specific access. */
function permit_payment_send_receipt(array $viewer,int $applicationId): never
{
    permit_payment_require_schema();
    $app=step2_application($applicationId);
    $record=permit_payment_record($applicationId);
    if (!$app || !$record || (string)$record['status']!=='verified'
        || trim((string)$record['stored_path'])==='') show_error('Receipt not found.',404);

    $mine=($viewer['user_kind']??'')==='applicant'
        && (int)$viewer['id']===(int)$app['client_id'];
    $staff=($viewer['user_kind']??'')==='internal' && ($viewer['status']??'')==='active'
        && (has_role($viewer,'lpdd_fus') || has_role($viewer,'lpdd_fus'));
    if (!$mine && !$staff) show_error('Receipt not found or access denied.',404);

    $base=realpath(PROJECT_ROOT.'/storage/fp_payments');
    $path=realpath(PROJECT_ROOT.'/storage/'.(string)$record['stored_path']);
    if (!$base || !$path || !str_starts_with($path,$base.DIRECTORY_SEPARATOR)
        || !is_file($path)) show_error('Receipt document not found.',404);
    if (!hash_equals((string)$record['sha256'],fpoms_file_plaintext_sha256($path))) {
        show_error('Receipt integrity verification failed.',500);
    }
    $mime=(string)$record['mime_type'];
    if (!in_array($mime,['application/pdf','image/jpeg','image/png'],true)) {
        show_error('Receipt type not permitted.',415);
    }
    $preview=(string)($_GET['preview']??'')==='1';
    if ($preview) {
        header('X-Frame-Options: SAMEORIGIN');
        header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'self\'; sandbox');
    }
    $contents=fpoms_file_read($path);
    header('Content-Type: '.$mime);
    header('Content-Length: '.strlen($contents));
    header('Cache-Control: private, no-store');
    header('Content-Disposition: '.($preview?'inline':'attachment')
        .'; filename="official-receipt"; filename*=UTF-8\'\''
        .rawurlencode((string)$record['original_name']));
    echo $contents;
    exit;
}
