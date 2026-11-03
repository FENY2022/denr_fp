<?php
declare(strict_types=1);
require_once __DIR__.'/../app/pusher_realtime.php';
function assert_rt(bool $ok,string $why): void {if (!$ok) throw new RuntimeException($why);}
function env_value(string $key,string $fallback=''): string {
    $values=['PUSHER_ENABLED'=>'true','PUSHER_APP_ID'=>'2208663',
        'PUSHER_KEY'=>'public_test_key','PUSHER_SECRET'=>'test_secret_not_real',
        'PUSHER_CLUSTER'=>'ap1'];
    return $values[$key]??$fallback;
}
function url(string $page): string {return '/'.$page;}
assert_rt(fpoms_pusher_enabled(),'enabled');
assert_rt(fpoms_pusher_channel('staff',4)==='private-fpoms-staff-4','staff channel');
assert_rt(fpoms_pusher_channel('applicant',4)==='private-fpoms-applicant-4','applicant isolation');
$staff=['id'=>4,'user_kind'=>'internal'];
$client=['id'=>4,'user_kind'=>'applicant'];
assert_rt(fpoms_pusher_auth($staff,'private-fpoms-applicant-4','1.2')===null,'staff cannot auth applicant');
assert_rt(fpoms_pusher_auth($client,'private-fpoms-staff-4','1.2')===null,'applicant cannot auth staff');
assert_rt(fpoms_pusher_auth($staff,'private-fpoms-staff-5','1.2')===null,'staff cannot auth another staff');
assert_rt(fpoms_pusher_auth($staff,'private-fpoms-staff-4','invalid')===null,'socket validation');
$valid=fpoms_pusher_auth($staff,'private-fpoms-staff-4','1.2');
assert_rt($valid!==null && hash_equals('public_test_key:'.hash_hmac('sha256',
    '1.2:private-fpoms-staff-4','test_secret_not_real'),$valid['auth']),'signed auth');
$bootstrap=file_get_contents(__DIR__.'/../app/bootstrap.php');
$views=file_get_contents(__DIR__.'/../app/views.php');
$routes=file_get_contents(__DIR__.'/../public/index.php');
$notifs=file_get_contents(__DIR__.'/../app/notifications.php');
$js=file_get_contents(__DIR__.'/../public/assets/fpoms-pusher.js');
assert_rt(str_contains($bootstrap,'wss://*.pusher.com'),'CSP websocket');
assert_rt(str_contains($routes,"case 'pusher_auth':")
    && str_contains($routes,'check_csrf()'),'auth requires session and CSRF');
assert_rt(str_contains($views,'fpoms-pusher-config'),'client config');
assert_rt(str_contains($notifs,'fpoms_pusher_notify_after_write($ownerKind, $userId'),'transaction-safe notification trigger');
assert_rt(str_contains($js,"channel.bind('notification-created'"),'event listener');
assert_rt(str_contains($js,"document.getElementById('realtime-test-form')")
    && str_contains($js,"'X-Requested-With': 'XMLHttpRequest'"),'AJAX connection test keeps subscription alive');
assert_rt(!str_contains($js,'test_secret_not_real'),'no secret in frontend');
echo "PASS: Pusher realtime channel isolation and wiring.\n";
