<?php
declare(strict_types=1);

// No external DB, Pusher network or real credentials. Pure contract/security checks.
require_once __DIR__.'/../app/realtime_settings.php';
require_once __DIR__.'/../app/pusher_realtime.php';

class FakeRealtimeDb
{
    public array $calls=[];
    public array $ddl=[];
    public function exec(string $sql): int { $this->ddl[]=$sql; return 0; }
    public function prepare(string $sql): FakeRealtimeStatement
    {
        return new FakeRealtimeStatement($this,$sql);
    }
}
class FakeRealtimeStatement
{
    public function __construct(private FakeRealtimeDb $db,private string $sql) {}
    public function execute(array $values=[]): bool
    {
        $this->db->calls[]=['sql'=>$this->sql,'values'=>$values];
        return true;
    }
}

$GLOBALS['test_db']=new FakeRealtimeDb();
$GLOBALS['test_row']=null;
$GLOBALS['test_render']='';
$GLOBALS['test_audits']=[];
function db(): FakeRealtimeDb { return $GLOBALS['test_db']; }
function query_one(string $sql,array $values=[]): ?array
{
    if (str_contains($sql,'fpoms_realtime_settings')) return $GLOBALS['test_row'];
    return null;
}
function fpoms_master_key(): string { return str_repeat('T',32); }
function env_value(string $key,string $fallback=''): string
{
    return [
        'PUSHER_ENABLED'=>'true','PUSHER_APP_ID'=>'123',
        'PUSHER_KEY'=>'legacypublickey','PUSHER_SECRET'=>'legacytestsecret',
        'PUSHER_CLUSTER'=>'ap1'
    ][$key]??$fallback;
}
function e(?string $value): string
{
    return htmlspecialchars($value??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="test">'; }
function url(string $page,array $args=[]): string { return str_replace('_','-',$page); }
function errors_html(array $errors): string { return implode('; ',array_map('e',$errors)); }
function require_role(string $role): array
{
    if ($role!=='super_admin') throw new RuntimeException('Role not enforced');
    return ['id'=>1,'name'=>'Super Admin'];
}
function render_page(string $title,string $content): void
{
    $GLOBALS['test_render']=$content;
}
function audit(?int $actor,string $action,string $kind,?int $id=null,array $extra=[]): void
{
    $GLOBALS['test_audits'][]=['action'=>$action,'metadata'=>$extra];
}
function realtime_assert(bool $value,string $message): void
{
    if (!$value) throw new RuntimeException('Realtime Settings test: '.$message);
}

// Encryption key derivation, authenticated GCM encryption and tamper checks.
$secret='ROTATED_TEST_SECRET_123456';
$a=fpoms_realtime_encrypt_secret($secret);
$b=fpoms_realtime_encrypt_secret($secret);
realtime_assert($a!==$b,'each encryption uses a fresh nonce');
realtime_assert(strpos($a,$secret)===false,'secret is never plaintext in storage');
realtime_assert(fpoms_realtime_decrypt_secret($a)===$secret,'encrypted secret round-trips');
$raw=base64_decode($a,true);
$raw[strlen($raw)-1]=chr(ord($raw[strlen($raw)-1])^1);
try {
    fpoms_realtime_decrypt_secret(base64_encode($raw));
    throw new RuntimeException('Tampered credential decrypted');
} catch (RuntimeException $ex) {
    realtime_assert(str_contains($ex->getMessage(),'integrity'),'tampered credential rejected');
}

realtime_assert(fpoms_realtime_validate('2208663','56a132864eec4a5608c1','ap1',$secret,true)===[],
    'valid Pusher settings');
realtime_assert(fpoms_realtime_validate('invalid','public_key','ap1',$secret,true)!==[],
    'invalid app ID rejected');
realtime_assert(fpoms_realtime_validate('2208663','public_key','ap1','',true)!==[],
    'enabled setup requires saved secret');
realtime_assert(fpoms_realtime_validate('2208663','public_key','ap1','short',true)!==[],
    'short secret rejected');

$errors=fpoms_realtime_save(['id'=>1],[
    'app_id'=>'2208663','app_key'=>'56a132864eec4a5608c1',
    'cluster'=>'ap1','app_secret'=>$secret,'enabled'=>'1'
]);
realtime_assert($errors===[],'admin can save realtime settings');
realtime_assert(count($GLOBALS['test_db']->ddl)>0,'settings table automatically installed');
$write=$GLOBALS['test_db']->calls[0]??[];
realtime_assert(isset($write['values'][3]) && $write['values'][3]!==$secret,
    'DB stores ciphertext, not secret');
realtime_assert(fpoms_realtime_decrypt_secret($write['values'][3])===$secret,
    'saved value is authenticated ciphertext');
realtime_assert(!str_contains(json_encode($GLOBALS['test_audits']),$secret),
    'audit metadata never contains secret');

$GLOBALS['test_row']=[
    'enabled'=>0,'app_id'=>'2208663','app_key'=>'56a132864eec4a5608c1',
    'secret_ciphertext'=>$write['values'][3],'cluster'=>'ap1',
    'last_tested_at'=>null,'last_test_ok'=>null,'last_test_note'=>null
];
$cfg=fpoms_realtime_effective_config();
realtime_assert($cfg['source']==='admin' && $cfg['secret']===$secret,
    'admin config loads and decrypts');
realtime_assert(!fpoms_pusher_enabled(),'saved disabled setting overrides enabled legacy env');
$GLOBALS['test_row']['enabled']=1;
realtime_assert(fpoms_pusher_enabled(),'enabled saved credentials activate Pusher');
realtime_assert(fpoms_pusher_client_config(['id'=>4,'user_kind'=>'internal'])['channel']
    ==='private-fpoms-staff-4','staff channel isolation retained');

view_realtime_settings();
realtime_assert(str_contains($GLOBALS['test_render'],'Realtime Notifications')
    && str_contains($GLOBALS['test_render'],'Save Settings')
    && str_contains($GLOBALS['test_render'],'Test Connection')
    && str_contains($GLOBALS['test_render'],'Connection Status'),
    'settings UI contains save, test and status controls');
realtime_assert(!str_contains($GLOBALS['test_render'],$secret),
    'saved secret must never appear in HTML');
realtime_assert(str_contains($GLOBALS['test_render'],'leave blank to keep existing'),
    'admin can retain current secret without exposing it');
realtime_assert(str_contains($GLOBALS['test_render'],'name="app_secret"'),
    'secret input is available for rotation');

$routes=file_get_contents(__DIR__.'/../public/index.php');
$views=file_get_contents(__DIR__.'/../app/views.php');
$notifications=file_get_contents(__DIR__.'/../app/notifications.php');
$step2=file_get_contents(__DIR__.'/../app/permit_step2.php');
$browser=file_get_contents(__DIR__.'/../public/assets/fpoms-pusher.js');
realtime_assert(str_contains($routes,"case 'realtime_settings':")
    && str_contains($routes,"case 'notifications_fragment':")
    && str_contains($routes,"require_role('super_admin')"),
    'settings route is role protected and notifications fragment is supported');
realtime_assert(str_contains($views,"shell_nav_item('realtime_settings'"),
    'realtime settings appears under Configuration');
realtime_assert(str_contains($notifications,'fpoms_pusher_notify_after_write'),
    'notifications are queued when inside a transaction');
realtime_assert(str_contains($step2,'fpoms_pusher_flush_pending();'),
    'Step 2 realtime events flush after transaction commit');
realtime_assert(str_contains($browser,"'pusher:subscription_succeeded'")
    && str_contains($browser,"'notification-created'")
    && str_contains($browser,'notificationsEndpoint'),
    'browser receives private events and updates the notification bell');
realtime_assert(!str_contains($browser,$secret),'public JavaScript never stores secret');
echo "PASS: encrypted Super Admin realtime settings, no env edits and safe notification wiring.\n";
