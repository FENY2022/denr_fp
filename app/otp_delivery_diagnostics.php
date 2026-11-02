<?php
declare(strict_types=1);

/**
 * RICTU Super Admin diagnostics for shared-office email OTP delivery.
 * This is not exposed to the unauthenticated recovery pages.
 * Never display OTP codes, password hashes, SMTP passwords, or email secrets.
 */
function fpoms_otp_smtp_preflight(): array
{
    $enabled=strtolower(trim(env_value('MAIL_ENABLED','false')))==='true';
    $host=trim(env_value('MAIL_HOST','smtp.gmail.com'));
    $port=(int)env_value('MAIL_PORT','587');
    $encryption=strtolower(trim(env_value('MAIL_ENCRYPTION','tls')));
    $username=trim(env_value('MAIL_USERNAME'));
    $password=env_value('MAIL_PASSWORD');
    $from=trim(env_value('MAIL_FROM_ADDRESS',$username));
    $issues=[];
    if (!$enabled) $issues[]='MAIL_ENABLED is not true in the actual server .env';
    if ($host==='' || $port<1 || $port>65535) {
        $issues[]='MAIL_HOST or MAIL_PORT is invalid';
    }
    if (!in_array($encryption,['tls','ssl','none'],true)) {
        $issues[]='MAIL_ENCRYPTION is invalid';
    }
    if (!filter_var($from,FILTER_VALIDATE_EMAIL)) {
        $issues[]='MAIL_FROM_ADDRESS is missing or invalid';
    }
    if (($username==='')!==($password==='')) {
        $issues[]='MAIL_USERNAME and MAIL_PASSWORD must both be configured';
    }
    if (strcasecmp($host,'smtp.gmail.com')===0 && ($username==='' || $password==='')) {
        $issues[]='Gmail SMTP requires an App Password in MAIL_PASSWORD';
    }
    if (!extension_loaded('openssl') && $encryption!=='none') {
        $issues[]='PHP OpenSSL extension is missing';
    }
    return [
        'enabled'=>$enabled,
        'host'=>$host,
        'port'=>$port,
        'encryption'=>$encryption,
        'username_configured'=>$username!=='',
        'credentials_configured'=>$username!=='' && $password!=='',
        'sender_valid'=>filter_var($from,FILTER_VALIDATE_EMAIL)!==false,
        'ready'=>count($issues)===0,
        'issues'=>$issues
    ];
}

/**
 * @return array{email:string,stats:array,table_ready:bool,mail:array,recent_count:int,last_requested:string,last_state:string,reason:string,errors:array}
 */
function fpoms_otp_diagnostics_snapshot(string $email): array
{
    $email=shared_office_normalize_email($email);
    $mail=fpoms_otp_smtp_preflight();
    $snapshot=[
        'email'=>$email,'stats'=>['total'=>0,'active'=>0,'pending'=>0],
        'table_ready'=>false,'mail'=>$mail,'recent_count'=>0,
        'last_requested'=>'','last_state'=>'No recorded OTP','reason'=>'',
        'errors'=>[]
    ];
    if (!shared_office_email_valid($email)) {
        $snapshot['reason']='Enter a valid shared office email address to analyze.';
        return $snapshot;
    }
    try {
        $snapshot['stats']=shared_office_reference_stats($email);
    } catch (Throwable $error) {
        $snapshot['errors'][]='Could not inspect users/reference emails. Verify schema and database permissions.';
        error_log('FP-OMS OTP diagnostics account inspection failed: '.get_class($error));
    }

    try {
        $table=query_one(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1",
            ['shared_office_recovery_otps']
        );
        $snapshot['table_ready']=$table!==null;
        if ($snapshot['table_ready']) {
            $hash=shared_office_email_hash($email);
            $rate=query_one(
                'SELECT COUNT(*) AS n,MAX(created_at) AS last_sent
                 FROM shared_office_recovery_otps
                 WHERE email_hash=? AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)',
                [$hash]
            );
            $snapshot['recent_count']=(int)($rate['n']??0);
            $last=query_one(
                'SELECT id,created_at,expires_at,used_at,failed_attempts
                 FROM shared_office_recovery_otps
                 WHERE email_hash=? ORDER BY id DESC LIMIT 1',[$hash]
            );
            if ($last) {
                $snapshot['last_requested']=(string)$last['created_at'];
                // used_at is also set on failed SMTP, replacement and
                // successful consumption. Never infer delivery from it alone.
                $snapshot['last_state']=!empty($last['used_at'])
                    ? 'Consumed or invalidated — review PHP error log for SMTP_FAILED'
                    : ((string)$last['expires_at']!=='' &&
                       strtotime((string)$last['expires_at'])<=time()
                        ? 'Expired' : 'OTP recorded — SMTP delivery not guaranteed');
            }
        }
    } catch (Throwable $error) {
        $snapshot['errors'][]='OTP database inspection failed. Verify Migration 025.';
        error_log('FP-OMS OTP diagnostics table inspection failed: '.get_class($error));
    }

    $stats=$snapshot['stats'];
    if (!$snapshot['table_ready']) {
        $snapshot['reason']='Missing Migration 025: shared_office_recovery_otps table.';
    } elseif ($stats['total']<2) {
        $snapshot['reason']='Not shared: fewer than two active/pending DENR designations reference this email.';
    } elseif ($stats['active']<1) {
        $snapshot['reason']='No Active DENR accounts are eligible; Pending Setup accounts are never recoverable.';
    } elseif (!$mail['ready']) {
        $snapshot['reason']='SMTP configuration is incomplete or disabled; see the checks below.';
    } elseif ($snapshot['recent_count']>=3) {
        $snapshot['reason']='Rate limited: at least three office OTP requests in the last 15 minutes.';
    } else {
        $snapshot['reason']='Preflight passed. Confirm SMTP delivery by sending a test to the administrator email and checking the PHP error log.';
    }
    return $snapshot;
}

/** A safe real SMTP test to the authenticated Super Admin's OWN address. */
function fpoms_otp_admin_test_mail(array $admin): string
{
    if (($admin['user_kind']??'')==='applicant') return 'Only DENR staff administrators can test SMTP.';
    $to=trim((string)($admin['email']??''));
    if (!filter_var($to,FILTER_VALIDATE_EMAIL)) {
        return 'Your administrator account has no valid recovery email.';
    }
    $last=(int)($_SESSION['fpoms_otp_smtp_test_at']??0);
    if ($last>time()-120) {
        return 'SMTP test limited to one request every two minutes.';
    }
    $_SESSION['fpoms_otp_smtp_test_at']=time();
    $stamp=gmdate('Y-m-d H:i:s').' UTC';
    $html='<p>DENR CARAGA FP-OMS SMTP diagnostic test at '.e($stamp).'.</p>'
        . '<p>This message contains no password or OTP. It verifies whether the '
        . 'configured SMTP server accepted a message sent to the administrator.</p>';
    $accepted=smtp_send_html($to,'DENR FP-OMS — SMTP Test Email',$html);
    try {
        audit((int)$admin['id'],'settings.otp_smtp_test','settings',null,
            ['accepted_by_smtp'=>$accepted]);
    } catch (Throwable $error) {
        error_log('FP-OMS OTP SMTP test audit unavailable: '.get_class($error));
    }
    return $accepted
        ? 'SMTP accepted the test message. Check your administrator inbox; acceptance does not guarantee delivery.'
        : 'SMTP test FAILED. Review MAIL_ENABLED/SMTP credentials and the PHP/Apache error log.';
}

function view_otp_delivery_diagnostics(array $errors=[],string $notice=''): void
{
    $admin=require_role('super_admin');
    $email=(string)($_SESSION['fpoms_otp_diag_email']??'');
    $snapshot=$email!=='' ? fpoms_otp_diagnostics_snapshot($email) : null;
    $mail=fpoms_otp_smtp_preflight();
    $html='<div class="row g-4"><div class="col-xl-8"><div class="panel">'
        . '<h2 class="h5 mb-2"><i class="bi bi-envelope-exclamation me-2"></i>'
        . 'OTP Delivery Diagnostics</h2>'
        . '<p class="small text-secondary">Super Administrator only. Inspect shared office '
        . 'email eligibility, Migration 025, OTP request limits and SMTP configuration. '
        . 'No OTP or SMTP password is displayed.</p>'
        . errors_html($errors)
        . ($notice!==''?'<div class="alert alert-info" role="status">'.e($notice).'</div>':'')
        . '<form method="post" action="'.e(url('otp_delivery_diagnostics')).'">'
        . csrf_field().'<input type="hidden" name="operation" value="analyze">'
        . '<div class="mb-3"><label class="form-label" for="diag_office_email">'
        . 'Office Email to Diagnose</label>'
        . '<input class="form-control" id="diag_office_email" name="office_email" '
        . 'type="email" maxlength="190" required value="'.e($email).'"></div>'
        . '<button class="btn btn-primary" type="submit">'
        . '<i class="bi bi-search me-1"></i>Analyze OTP Delivery</button></form>';

    if ($snapshot!==null) {
        $stats=$snapshot['stats'];
        $html.='<hr><h3 class="h6">Office account eligibility</h3>'
            . '<div class="table-responsive"><table class="table table-sm align-middle">'
            . '<tbody>'
            . '<tr><th scope="row">Matching DENR designations</th><td>'
            .(int)$stats['total'].'</td></tr>'
            . '<tr><th scope="row">Active accounts eligible for selection</th><td>'
            .(int)$stats['active'].'</td></tr>'
            . '<tr><th scope="row">Pending Setup (counted as shared, not selectable)</th><td>'
            .(int)$stats['pending'].'</td></tr>'
            . '<tr><th scope="row">Migration 025</th><td>'
            .($snapshot['table_ready']?'Installed':'Missing — import database/025_shared_office_recovery.sql')
            .'</td></tr>'
            . '<tr><th scope="row">OTP attempts in last 15 minutes</th><td>'
            .(int)$snapshot['recent_count'].' / 3 per mailbox</td></tr>'
            . '<tr><th scope="row">Last OTP record</th><td>'
            .e($snapshot['last_requested']!==''?$snapshot['last_requested']:'None').'</td></tr>'
            . '<tr><th scope="row">Last OTP state</th><td>'
            .e($snapshot['last_state']).'</td></tr>'
            . '</tbody></table></div><p class="mb-2"><strong>Assessment: </strong>'
            .e($snapshot['reason']).'</p>';
        foreach ($snapshot['errors'] as $error) {
            $html.='<div class="alert alert-warning small">'.e((string)$error).'</div>';
        }
        $html.='<p class="small text-secondary">Some requests can also be blocked by '
            .'per-IP limits or a 60-second resend cooldown. SMTP acceptance must be '
            .'confirmed through server logs or the test email below.</p>';
    }
    $html.='</div></div><div class="col-xl-4"><div class="panel">'
        . '<h3 class="h6"><i class="bi bi-envelope-check me-2"></i>SMTP Readiness</h3>'
        . '<p><strong>Status:</strong> '
        .($mail['ready']?'<span class="text-success">Configuration appears ready</span>'
            :'<span class="text-danger">Configuration issue</span>').'</p>'
        . '<p class="small mb-1">MAIL_ENABLED: '.($mail['enabled']?'true':'false').'</p>'
        . '<p class="small mb-1">Host: '.e($mail['host']).'</p>'
        . '<p class="small mb-1">Port: '.(int)$mail['port'].'</p>'
        . '<p class="small mb-1">Encryption: '.e($mail['encryption']).'</p>'
        . '<p class="small mb-1">SMTP credentials: '
        .($mail['credentials_configured']?'Configured':'Missing').'</p>';
    foreach ($mail['issues'] as $issue) {
        $html.='<div class="alert alert-warning small py-2 mb-2">'.e((string)$issue).'</div>';
    }
    $html.='<hr><form method="post" action="'.e(url('otp_delivery_diagnostics')).'">'
        .csrf_field().'<input type="hidden" name="operation" value="test_smtp">'
        .'<button class="btn btn-outline-success w-100" type="submit">'
        .'<i class="bi bi-send-check me-1"></i>Send SMTP Test to My Email</button>'
        .'</form><p class="small text-secondary mt-2 mb-0">'
        .'Tests to your own Super Admin email only. One attempt every two minutes. '
        .'A successful SMTP send does not prove inbox delivery. '
        .'Check Apache/PHP error logs for FP-OMS shared office OTP SMTP_FAILED '
        .'(logged using an email hash prefix, not the address).</p>'
        .'</div></div></div>';
    render_page('OTP Delivery Diagnostics',$html);
}
