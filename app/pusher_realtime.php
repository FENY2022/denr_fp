<?php
declare(strict_types=1);

/** Pusher is optional; the database remains the source of truth. */
function fpoms_pusher_enabled(): bool
{
    return env_value('PUSHER_ENABLED','false')==='true'
        && env_value('PUSHER_APP_ID')!==''
        && env_value('PUSHER_KEY')!==''
        && env_value('PUSHER_SECRET')!==''
        && preg_match('/^[a-z0-9-]+$/',env_value('PUSHER_CLUSTER','ap1'))===1;
}
function fpoms_pusher_channel(string $kind,int $id): string
{
    return 'private-fpoms-'.($kind==='applicant'?'applicant':'staff').'-'.$id;
}
function fpoms_pusher_publish(string $kind,int $id): void
{
    if (!fpoms_pusher_enabled() || $id<1) return;
    $appId=env_value('PUSHER_APP_ID');
    $key=env_value('PUSHER_KEY');
    $secret=env_value('PUSHER_SECRET');
    $cluster=env_value('PUSHER_CLUSTER','ap1');
    $path='/apps/'.rawurlencode($appId).'/events';
    $body=json_encode(['name'=>'notification-created',
        'channels'=>[fpoms_pusher_channel($kind,$id)],
        'data'=>json_encode(['refresh'=>true],JSON_THROW_ON_ERROR)],JSON_THROW_ON_ERROR);
    $params=['auth_key'=>$key,'auth_timestamp'=>(string)time(),
        'auth_version'=>'1.0','body_md5'=>md5($body)];
    ksort($params);
    $query=http_build_query($params,'','&',PHP_QUERY_RFC3986);
    $signature=hash_hmac('sha256',"POST\n".$path."\n".$query,$secret);
    $url='https://api-'.$cluster.'.pusher.com'.$path.'?'.$query.'&auth_signature='.$signature;
    if (!function_exists('curl_init')) return;
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_CONNECTTIMEOUT=>2]);
    $result=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($result===false || $status<200 || $status>=300)
        error_log('FP-OMS Pusher delivery unavailable; stored notification retained.');
}
function fpoms_pusher_auth(array $user,string $channel,string $socket): ?array
{
    $kind=($user['user_kind']??'')==='applicant'?'applicant':'staff';
    if (!fpoms_pusher_enabled() || !preg_match('/^\d+\.\d+$/D',$socket)
        || $channel!==fpoms_pusher_channel($kind,(int)$user['id'])) return null;
    $key=env_value('PUSHER_KEY');
    $signature=hash_hmac('sha256',$socket.':'.$channel,env_value('PUSHER_SECRET'));
    return ['auth'=>$key.':'.$signature];
}
function fpoms_pusher_client_config(array $user): array
{
    if (!fpoms_pusher_enabled()) return [];
    return ['key'=>env_value('PUSHER_KEY'),'cluster'=>env_value('PUSHER_CLUSTER','ap1'),
        'channel'=>fpoms_pusher_channel(
            ($user['user_kind']??'')==='applicant'?'applicant':'staff',(int)$user['id']),
        'authEndpoint'=>url('pusher_auth')];
}
