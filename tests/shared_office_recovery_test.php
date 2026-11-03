<?php
declare(strict_types=1);

// No live SMTP, users, database, or credentials needed.
require_once __DIR__.'/../app/shared_office_recovery.php';
require_once __DIR__.'/../app/password_reset.php';

$GLOBALS['mock_records']=[
    ['id'=>4,'name'=>'Staff One','username'=>'staffone4',
        'email'=>'individual.one@example.invalid','office_station'=>'RO Records'],
    ['id'=>5,'name'=>'Staff Two','username'=>'stafftwo5',
        'email'=>'individual.two@example.invalid','office_station'=>'RO Records']
];
$GLOBALS['mock_otp']=null;
$GLOBALS['mock_email']=null;
$GLOBALS['mock_staff_otp']=null;
$GLOBALS['render']='';
class OfficeRecoveryDb
{
    private bool $inTransaction=false;
    public function beginTransaction(): void { $this->inTransaction=true; }
    public function inTransaction(): bool { return $this->inTransaction; }
    public function rollBack(): void { $this->inTransaction=false; }
    public function commit(): void { $this->inTransaction=false; }
    public function lastInsertId(): string { return '37'; }
    public function prepare(string $sql): OfficeRecoveryStatement {
        return new OfficeRecoveryStatement($sql);
    }
}
class OfficeRecoveryStatement
{
    public function __construct(private string $sql) {}
    public function execute(array $args=[]): bool
    {
        if (str_contains($this->sql,'INSERT INTO shared_office_recovery_otps')) {
            $GLOBALS['mock_otp']=[
                'id'=>37,'email_hash'=>$args[0],'code_hash'=>$args[1],
                'expires_at'=>date('Y-m-d H:i:s',time()+600),
                'failed_attempts'=>0,'used_at'=>null
            ];
        }
        if (str_contains($this->sql,'SET failed_attempts=failed_attempts+1')) {
            $GLOBALS['mock_otp']['failed_attempts']++;
        }
        if (str_contains($this->sql,'SET used_at=NOW()')) {
            $GLOBALS['mock_otp']['used_at']=date('Y-m-d H:i:s');
        }
        return true;
    }
}
function db(): OfficeRecoveryDb
{
    static $db;
    return $db??=new OfficeRecoveryDb();
}
function query_one(string $sql,array $args=[]): ?array
{
    if (str_contains($sql,'COUNT(*) AS total') && str_contains($sql,'FROM users u')) {
        $address=(string)($args[0]??'');
        if ($address==='individual.one@example.invalid') {
            return ['total'=>1,'active'=>1,'pending'=>0];
        }
        if ($address==='unknown@example.invalid') {
            return ['total'=>0,'active'=>0,'pending'=>0];
        }
        if ($address==='pending.only@example.invalid') {
            return ['total'=>3,'active'=>0,'pending'=>3];
        }
        // One activated staff account + one Pending Setup designation
        // still constitutes a shared office mailbox.
        return ['total'=>2,'active'=>1,'pending'=>1];
    }
    if (str_contains($sql,'COUNT(*) AS n,MAX(id) AS staff_id')) {
        return ['n'=>1,'staff_id'=>str_contains($args[0],'two')?5:4];
    }
    if (str_contains($sql,'COUNT(*) AS n,MAX(created_at) AS last_sent')) {
        return ['n'=>0,'last_sent'=>null];
    }
    if (str_contains($sql,'COUNT(*) AS n FROM shared_office_recovery_otps')) return ['n'=>0];
    if (str_contains($sql,'FROM shared_office_recovery_otps')
        && str_contains($sql,'ORDER BY id DESC')) {
        return $GLOBALS['mock_otp'] !== null
            && $GLOBALS['mock_otp']['used_at']===null
            ? $GLOBALS['mock_otp'] : null;
    }
    if (str_contains($sql,'FROM user_client') || str_contains($sql,'id<>?')) return null;
    throw new RuntimeException('Unexpected SQL in mock: '.$sql);
}
function query_all(string $sql,array $args=[]): array
{
    if (str_contains($sql,'FROM users u WHERE')) return $GLOBALS['mock_records'];
    throw new RuntimeException('Unexpected list SQL in mock: '.$sql);
}
function client_ip(): string { return '127.0.0.1'; }
function e(?string $v): string { return htmlspecialchars($v??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function csrf_field(): string { return '<input name="csrf_token" value="test">'; }
function field(string $name,string $label,string $value,string $type,bool $required,string $attributes=''): string {
    return '<label>'.e($label).'<input name="'.e($name).'" '.$attributes.'></label>';
}
function errors_html(array $errors): string { return implode(', ',$errors); }
function current_user(): ?array { return null; }
function go(string $route): never { throw new RuntimeException('Redirect '.$route); }
function url(string $page): string { return str_replace('_','-',$page); }
function render_page(string $title,string $html): void { $GLOBALS['render']=$html; }
function smtp_send_html(string $to,string $subject,string $html): bool
{
    preg_match('/\b[0-9]{6}\b/',$html,$match);
    $GLOBALS['mock_email']=['to'=>$to,'subject'=>$subject,'code'=>$match[0]??''];
    return true;
}
function staff_otp_request(string $email,string $purpose='password'): void
{
    $GLOBALS['mock_staff_otp']=['to'=>$email,'purpose'=>$purpose];
}
function office_assert(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException('Shared-office recovery: '.$label);
}

$_SESSION=[];
view_shared_office_recovery();
office_assert(str_contains($GLOBALS['render'],'Shared Office Email Recovery'),
    'shared office entry form exists');
office_assert(!str_contains($GLOBALS['render'],'Staff One')
    && !str_contains($GLOBALS['render'],'staffone4'),
    'initial form does not enumerate identities');
$_SESSION['shared_office_pending_email']='records.r13@example.invalid';
$_SESSION['shared_office_purpose']='password';
view_shared_office_verify();
office_assert(!str_contains($GLOBALS['render'],'Staff One')
    && !str_contains($GLOBALS['render'],'staffone4'),
    'unverified mailbox does not enumerate identities');
shared_office_request('records.r13@example.invalid');
office_assert($GLOBALS['mock_email']['to']==='records.r13@example.invalid',
    'office OTP delivered only to shared mailbox');
office_assert(strlen($GLOBALS['mock_email']['code'])===6,'six digit challenge generated');
office_assert(!str_contains((string)$GLOBALS['mock_otp']['code_hash'],$GLOBALS['mock_email']['code']),
    'office OTP hashed, not stored raw');
office_assert(password_verify('office:'.$GLOBALS['mock_email']['code'],
    $GLOBALS['mock_otp']['code_hash']),'office OTP has purpose separation');
$incorrect=$GLOBALS['mock_email']['code']==='999999'?'000000':'999999';
office_assert(!shared_office_verify('records.r13@example.invalid',$incorrect),
    'invalid OTP fails verification');
office_assert($GLOBALS['mock_otp']['failed_attempts']>=1,'failed attempt recorded');

// Keep mock session regeneration independent of headers and PHP session setup.
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
office_assert(shared_office_verify('records.r13@example.invalid',
    $GLOBALS['mock_email']['code']),'valid office OTP verifies');
office_assert(!shared_office_verify('records.r13@example.invalid',
    $GLOBALS['mock_email']['code']),'consumed office OTP cannot be replayed');
view_shared_office_accounts();
office_assert(str_contains($GLOBALS['render'],'Staff One')
    && str_contains($GLOBALS['render'],'Staff Two')
    && str_contains($GLOBALS['render'],'staffone4'),
    'account choices are rendered only after verified office email');
office_assert(str_contains($GLOBALS['render'],'shared-office-confirm-modal')
    && str_contains($GLOBALS['render'],'data-office-confirm-submit'),
    'account selection requires confirmation modal');
office_assert(!str_contains($GLOBALS['render'],'individual.one@example.invalid'),
    'personal recovery email is not revealed to office holder');
office_assert(shared_office_choose_account(999,'password')!==[],
    'unverified account ID rejected');
office_assert(shared_office_choose_account(4,'password')===[],
    'verified office account can select its own separate OTP flow');
office_assert($GLOBALS['mock_staff_otp']===['to'=>'individual.one@example.invalid',
    'purpose'=>'password'],'OTP requested to selected personal email, not office');
office_assert(shared_office_choose_account(5,'password')!==[],
    'office verification grant consumed after selecting once');

// A staff account listing the verified office address as its only recovery
// email receives a short account-specific password reset grant.
$_SESSION['shared_office_verified_email']='records.r13@example.invalid';
$_SESSION['shared_office_verified_expires_at']=time()+600;
$GLOBALS['mock_records'][1]['email']='records.r13@example.invalid';
office_assert(shared_office_choose_account(5,'password')===[],
    'verified shared office mailbox allows selected account password reset');
office_assert(($_SESSION['staff_password_reset_user_id']??0)===5
    && ($_SESSION['staff_password_reset_expires_at']??0)>time(),
    'office-only recovery creates a short selected-account reset grant');
$GLOBALS['mock_records'][1]['email']='individual.two@example.invalid';

// Only one Active account can remain selectable even though another
// Pending Setup designation makes this office email genuinely shared.
$_SESSION['shared_office_verified_email']='records.r13@example.invalid';
$_SESSION['shared_office_verified_expires_at']=time()+600;
$GLOBALS['mock_records']=array_slice($GLOBALS['mock_records'],0,1);
view_shared_office_accounts();
office_assert(str_contains($GLOBALS['render'],'Staff One')
    && !str_contains($GLOBALS['render'],'Staff Two'),
    'only one Active account displayed when other shared designation is Pending Setup');
office_assert(shared_office_choose_account(4,'password')===[],
    'single Active account is selectable after shared office OTP');
$GLOBALS['mock_records'][]=[
    'id'=>5,'name'=>'Staff Two','username'=>'stafftwo5',
    'email'=>'individual.two@example.invalid','office_station'=>'RO Records'
];

$_SESSION['shared_office_verified_email']='records.r13@example.invalid';
$_SESSION['shared_office_verified_expires_at']=time()+600;
$_SESSION['shared_office_purpose']='username';
office_assert(shared_office_choose_account(5,'username')===[],
    'separate username OTP flow can be selected');
office_assert($GLOBALS['mock_staff_otp']===['to'=>'individual.two@example.invalid',
    'purpose'=>'username'],'username OTP to selected individual account');
$_SESSION['shared_office_verified_email']='records.r13@example.invalid';
$_SESSION['shared_office_verified_expires_at']=time()-1;
office_assert(shared_office_choose_account(4,'password')!==[],
    'expired verification grant denied');

$routes=file_get_contents(__DIR__.'/../public/index.php');
$passwordView=file_get_contents(__DIR__.'/../app/password_reset.php');
$otp=file_get_contents(__DIR__.'/../app/staff_password_otp.php');
$bootstrap=file_get_contents(__DIR__.'/../app/bootstrap.php');
$js=file_get_contents(__DIR__.'/../public/assets/shared-office-recovery.js');
foreach (['shared_office_recovery','shared_office_verify','shared_office_accounts'] as $route) {
    office_assert(str_contains($routes,"case '".$route."':"),
        'router includes '.$route);
}
office_assert(str_contains($passwordView,'Shared DENR office emails are detected automatically')
    && str_contains($passwordView,'Shared office emails are recognized automatically'),
    'password and username forms explain automatic office detection');
office_assert(!str_contains($passwordView,"url('shared_office_recovery')"),
    'no extra manual shared office choice is required');
office_assert(str_contains($otp,'staff_recovery_unique_account'),
    'legacy staff OTP refuses arbitrary LIMIT 1 account');
office_assert(str_contains($otp,'staff_recovery_email_exclusive'),
    'verify paths check exclusive personal recovery mailbox');
office_assert(str_contains($bootstrap,"/shared_office_recovery.php"),
    'shared-office recovery service loaded');
office_assert(str_contains($js,'form.requestSubmit()')
    && str_contains($js,'getOrCreateInstance'),
    'selection confirms with Bootstrap modal');
// The ordinary Forgot Password / Forgot Username submission detects shared
// references automatically, before initiating individual staff/client flows.
$_SESSION=[];
$GLOBALS['mock_email']=null;
office_assert(shared_office_reference_stats('records.r13@example.invalid')['total']===2
    && shared_office_reference_stats('records.r13@example.invalid')['active']===1
    && shared_office_reference_stats('records.r13@example.invalid')['pending']===1,
    'shared email counts pending and active designations together');
office_assert(shared_office_auto_start('records.r13@example.invalid','password'),
    'shared mailbox auto-detected for password recovery');
office_assert(($_SESSION['shared_office_pending_email']??'')==='records.r13@example.invalid'
    && ($_SESSION['shared_office_purpose']??'')==='password',
    'shared office verification context saved without exposing accounts');
office_assert($GLOBALS['mock_email']['to']==='records.r13@example.invalid',
    'automatic branch sends OTP to office mailbox');
office_assert(shared_office_auto_start('records.r13@example.invalid','username'),
    'shared mailbox auto-detected for username recovery');
office_assert(($_SESSION['shared_office_purpose']??'')==='username',
    'username purpose carried into account selector');
// Both flows present identical public feedback until the OTP is verified.
$_SESSION['auto_recovery_email']='records.r13@example.invalid';
$_SESSION['auto_recovery_purpose']='password';
$_SESSION['auto_recovery_mode']='office';
view_auto_recovery_verify();
$officeMarkup=$GLOBALS['render'];
office_assert(!str_contains($officeMarkup,'Staff One')
    && !str_contains($officeMarkup,'staffone4'),
    'generic verification form never reveals account list');
office_assert(substr_count($officeMarkup,'data-otp-digit')===6
    && str_contains($officeMarkup,'Send New OTP'),
    'generic verification form renders six OTP boxes and resend control');
$_SESSION['auto_recovery_mode']='individual';
view_auto_recovery_verify();
office_assert($officeMarkup===$GLOBALS['render'],
    'unverified office and individual recovery responses are identical');

$_SESSION=[];
office_assert(!shared_office_auto_start('individual.one@example.invalid','password'),
    'single individual recovery email stays on normal OTP flow');
office_assert(!shared_office_auto_start('unknown@example.invalid','password'),
    'unregistered email remains on generic normal recovery path');
office_assert(!shared_office_auto_start('pending.only@example.invalid','password'),
    'all-pending shared designations have no recoverable active staff account');
office_assert(!shared_office_auto_start('not-an-email','password'),
    'malformed email cannot start shared recovery');
office_assert(!shared_office_auto_start('records.r13@example.invalid','invalid'),
    'invalid recovery purpose rejected');
office_assert(empty($_SESSION['shared_office_pending_email']),
    'nonshared requests do not create office-selection session');

$pwRoute=strstr($routes,"case 'forgot_password':");
$userRoute=strstr($routes,"case 'forgot_username':");
office_assert($pwRoute!==false && $userRoute!==false
    && strpos($pwRoute,"shared_office_auto_start(\$email,'password')")
        < strpos($pwRoute,'staff_otp_request($email)'),
    'password recovery detects shared office before direct individual OTP');
office_assert(strpos($userRoute,"shared_office_auto_start(\$email,'username')")
        < strpos($userRoute,"staff_otp_request(\$email,'username')"),
    'username recovery detects shared office before direct individual OTP');
office_assert(str_contains($pwRoute,"\$_SESSION['auto_recovery_mode']")
    && str_contains($userRoute,"\$_SESSION['auto_recovery_mode']"),
    'both flows remember mode in session, never in a public query string');
office_assert(str_contains($routes,"case 'auto_recovery_verify':")
    && str_contains($routes,'shared_office_verify($email,$code)')
    && str_contains($routes,'staff_otp_verify_password($email,$code)')
    && str_contains($routes,'staff_otp_verify_username($email,$code)'),
    'one verification route dispatches OTP by server-side recovery mode');
office_assert(str_contains($routes,"safe_post('action',20)==='resend'")
    && str_contains($routes,"Please wait '.\$wait.' seconds")
    && str_contains($routes,"auto_recovery_last_requested_at")
    && str_contains($passwordView,'Send New OTP')
    && str_contains($passwordView,'requested after 60 seconds')
    && str_contains($passwordView,"recovery_otp_input('Six-Digit Recovery OTP')"),
    'generic verification route enforces visible resend cooldown and six-box OTP entry');
office_assert(substr_count($routes,"go('auto_recovery_verify');")===3,
    'both recovery forms and resend return to generic verification route');
echo "PASS: shared office OTP, account selection, reset fallback and personal OTP flow.\n";
