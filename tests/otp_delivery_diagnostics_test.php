<?php
declare(strict_types=1);

// Database-free Super Administrator diagnostics regression tests.
// No live SMTP credentials or email recipients are used.
require_once __DIR__.'/../app/shared_office_recovery.php';
require_once __DIR__.'/../app/otp_delivery_diagnostics.php';

$GLOBALS['diag_env']=[
    'MAIL_ENABLED'=>'true','MAIL_HOST'=>'smtp.gmail.com','MAIL_PORT'=>'587',
    'MAIL_ENCRYPTION'=>'tls','MAIL_USERNAME'=>'test-sender@example.invalid',
    'MAIL_PASSWORD'=>'fake-test-secret-not-real',
    'MAIL_FROM_ADDRESS'=>'test-sender@example.invalid'
];
$GLOBALS['diag_email_send']=true;
$GLOBALS['diag_sent_to']='';
$GLOBALS['diag_mail_count']=0;
$GLOBALS['diag_render']='';
$GLOBALS['diag_table']=true;
$GLOBALS['diag_stats']=['total'=>3,'active'=>1,'pending'=>2];
$GLOBALS['diag_recent']=2;
$GLOBALS['diag_audits']=[];
$_SESSION=[];

function env_value(string $name,string $fallback=''): string
{
    return $GLOBALS['diag_env'][$name]??$fallback;
}
function query_one(string $sql,array $values=[]): ?array
{
    if (str_contains($sql,'COUNT(*) AS total')) return $GLOBALS['diag_stats'];
    if (str_contains($sql,'information_schema.TABLES')) {
        return $GLOBALS['diag_table']?['name'=>'shared_office_recovery_otps']:null;
    }
    if (str_contains($sql,'COUNT(*) AS n,MAX(created_at)')) {
        return ['n'=>$GLOBALS['diag_recent'],'last_sent'=>null];
    }
    if (str_contains($sql,'SELECT id,created_at,expires_at,used_at,failed_attempts')) {
        return [
            'id'=>14,'created_at'=>'2026-11-02 10:15:00',
            'expires_at'=>'2026-11-02 10:25:00',
            'used_at'=>null,'failed_attempts'=>0
        ];
    }
    throw new RuntimeException('Unexpected SQL: '.$sql);
}
function smtp_send_html(string $to,string $subject,string $html): bool
{
    $GLOBALS['diag_sent_to']=$to;
    $GLOBALS['diag_mail_count']++;
    if (!str_contains($subject,'SMTP Test Email')) {
        throw new RuntimeException('Only admin test emails allowed');
    }
    return $GLOBALS['diag_email_send'];
}
function audit(?int $actorId,string $action,string $type,?int $id=null,array $metadata=[]): void
{
    $GLOBALS['diag_audits'][]=['actor'=>$actorId,'action'=>$action,
        'type'=>$type,'metadata'=>$metadata];
}
function require_role(string $role): array
{
    if ($role!=='super_admin') throw new RuntimeException('Admin only');
    return ['id'=>2,'name'=>'DENR Admin','email'=>'admin@example.invalid',
        'status'=>'active','user_kind'=>'internal'];
}
function url(string $page): string {return str_replace('_','-',$page);}
function csrf_field(): string {return '<input type="hidden" name="csrf_token" value="test">';}
function errors_html(array $errors): string
{
    return implode('; ',array_map('e',$errors));
}
function e(?string $value): string
{
    return htmlspecialchars($value??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function render_page(string $title,string $html): void {$GLOBALS['diag_render']=$html;}
function diag_assert(bool $ok,string $reason): void
{
    if (!$ok) throw new RuntimeException('OTP diagnostics: '.$reason);
}

$stats=shared_office_reference_stats('records.r13@example.invalid');
diag_assert($stats['total']===3 && $stats['pending']===2 && $stats['active']===1,
    'shared references include pending staff designations');
diag_assert(shared_office_is_recoverable($stats),'one active plus pending staff can recover');
diag_assert(!shared_office_is_recoverable(['total'=>5,'active'=>0,'pending'=>5]),
    'all-pending accounts cannot recover');
diag_assert(!shared_office_is_recoverable(['total'=>1,'active'=>1,'pending'=>0]),
    'single unshared staff account is not office recovery');
$preflight=fpoms_otp_smtp_preflight();
diag_assert($preflight['ready'],'valid SMTP configuration passes preflight');
diag_assert(!str_contains(json_encode($preflight),'fake-test-secret-not-real'),
    'SMTP password never appears in diagnostics');

$snapshot=fpoms_otp_diagnostics_snapshot('records.r13@example.invalid');
diag_assert($snapshot['table_ready'] && $snapshot['recent_count']===2,
    'installed migration and rate tracked');
diag_assert($snapshot['stats']['pending']===2,'diagnostics count Pending Setup');
diag_assert(str_contains($snapshot['reason'],'Preflight passed'),
    'ready state is not confused with confirmed delivery');
$GLOBALS['diag_recent']=3;
$snapshot=fpoms_otp_diagnostics_snapshot('records.r13@example.invalid');
diag_assert(str_contains($snapshot['reason'],'Rate limited'),
    '3 office challenges in 15 minutes shown as rate limited');
$GLOBALS['diag_recent']=0;
$GLOBALS['diag_table']=false;
$snapshot=fpoms_otp_diagnostics_snapshot('records.r13@example.invalid');
diag_assert(str_contains($snapshot['reason'],'Missing Migration 025'),
    'missing database migration detected without querying absent table');
$GLOBALS['diag_table']=true;
$GLOBALS['diag_stats']=['total'=>3,'active'=>0,'pending'=>3];
$snapshot=fpoms_otp_diagnostics_snapshot('records.r13@example.invalid');
diag_assert(str_contains($snapshot['reason'],'No Active'),
    'all-pending reference detected as ineligible');
$GLOBALS['diag_stats']=['total'=>3,'active'=>1,'pending'=>2];

$GLOBALS['diag_env']['MAIL_ENABLED']='false';
$preflight=fpoms_otp_smtp_preflight();
diag_assert(!$preflight['ready']
    && str_contains(implode(',',$preflight['issues']),'MAIL_ENABLED'),
    'disabled SMTP is shown to admin');
$snapshot=fpoms_otp_diagnostics_snapshot('records.r13@example.invalid');
diag_assert(str_contains($snapshot['reason'],'SMTP configuration'),
    'SMTP disabled blocks readiness');
$GLOBALS['diag_env']['MAIL_ENABLED']='true';

$_SESSION['fpoms_otp_diag_email']='records.r13@example.invalid';
view_otp_delivery_diagnostics();
diag_assert(str_contains($GLOBALS['diag_render'],'OTP Delivery Diagnostics')
    && str_contains($GLOBALS['diag_render'],'Matching DENR designations')
    && str_contains($GLOBALS['diag_render'],'Send SMTP Test to My Email')
    && str_contains($GLOBALS['diag_render'],'Pending Setup'),
    'Super Admin page displays checks and test action');
diag_assert(!str_contains($GLOBALS['diag_render'],'fake-test-secret-not-real'),
    'credential never rendered in HTML');
diag_assert(!str_contains($GLOBALS['diag_render'],'$2y$'),
    'OTP hashes never rendered');

$admin=require_role('super_admin');
$notice=fpoms_otp_admin_test_mail($admin);
diag_assert(str_contains($notice,'SMTP accepted')
    && $GLOBALS['diag_sent_to']==='admin@example.invalid'
    && $GLOBALS['diag_mail_count']===1,
    'SMTP test only sends to logged in Super Admin');
$again=fpoms_otp_admin_test_mail($admin);
diag_assert(str_contains($again,'limited') && $GLOBALS['diag_mail_count']===1,
    'SMTP test rate limit enforced');
unset($_SESSION['fpoms_otp_smtp_test_at']);
$GLOBALS['diag_email_send']=false;
diag_assert(str_contains(fpoms_otp_admin_test_mail($admin),'FAILED'),
    'SMTP failure is diagnosed');
diag_assert(!str_contains(json_encode($GLOBALS['diag_audits']),'fake-test-secret-not-real'),
    'SMTP audit never contains credentials');

$routes=file_get_contents(__DIR__.'/../public/index.php');
$views=file_get_contents(__DIR__.'/../app/views.php');
$bootstrap=file_get_contents(__DIR__.'/../app/bootstrap.php');
$service=file_get_contents(__DIR__.'/../app/shared_office_recovery.php');
diag_assert(str_contains($routes,"case 'otp_delivery_diagnostics':")
    && str_contains($routes,"require_role('super_admin')"),
    'admin-only route');
diag_assert(str_contains($routes,"check_csrf()")
    && str_contains($routes,"case 'otp_delivery_diagnostics':"),
    'diagnostics POST subject to CSRF');
diag_assert(str_contains($views,"shell_nav_item('otp_delivery_diagnostics'"),
    'Super Admin Configuration navigation added');
diag_assert(str_contains($bootstrap,"/otp_delivery_diagnostics.php"),
    'diagnostics service loaded');
diag_assert(str_contains($service,'SMTP_DISABLED')
    && str_contains($service,'SMTP_FAILED')
    && str_contains($service,'SMTP_ACCEPTED'),
    'structured sanitized mail transport logs');
echo "PASS: pending account shared detection, restricted diagnostics, SMTP preflight and test protections.\n";
