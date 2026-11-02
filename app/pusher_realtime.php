<?php
declare(strict_types=1);

/**
 * Optional Pusher Channels transport.
 * DB-backed Super Admin settings win over legacy .env values.
 * A missing DB table falls back without impacting saved notifications.
 */
function fpoms_pusher_config(): array
{
    if (function_exists('fpoms_realtime_effective_config')) {
        return fpoms_realtime_effective_config();
    }
    return [
        'enabled'=>env_value('PUSHER_ENABLED','false')==='true',
        'app_id'=>env_value('PUSHER_APP_ID'),
        'key'=>env_value('PUSHER_KEY'),
        'secret'=>env_value('PUSHER_SECRET'),
        'cluster'=>env_value('PUSHER_CLUSTER','ap1'),
        'source'=>'environment',
    ];
}

function fpoms_pusher_valid_config(array $config): bool
{
    return !empty($config['enabled'])
        && preg_match('/^[0-9]{1,18}$/D',(string)($config['app_id']??''))===1
        && preg_match('/^[A-Za-z0-9_-]{8,128}$/D',(string)($config['key']??''))===1
        && (string)($config['secret']??'')!==''
        && preg_match('/^[a-z0-9-]{2,20}$/D',(string)($config['cluster']??''))===1;
}

function fpoms_pusher_enabled(): bool
{
    return fpoms_pusher_valid_config(fpoms_pusher_config());
}

function fpoms_pusher_channel(string $kind,int $id): string
{
    return 'private-fpoms-'.($kind==='applicant'?'applicant':'staff').'-'.$id;
}

/**
 * Publish one small event through signed HTTPS.
 * The return value contains only sanitized status information, no keys/secrets.
 */
function fpoms_pusher_send(array $config,string $channel,string $event,array $payload=[]): array
{
    if (!preg_match('/^private-fpoms-(?:staff|applicant)-[1-9][0-9]*$/D',$channel)
        || preg_match('/^[a-z][a-z0-9-]{1,70}$/D',$event)!==1) {
        return ['success'=>false,'message'=>'Invalid event or private channel.'];
    }
    if (!function_exists('curl_init')) {
        return ['success'=>false,'message'=>'PHP cURL extension is not enabled.'];
    }
    $key=(string)($config['key']??'');
    $secret=(string)($config['secret']??'');
    $appId=(string)($config['app_id']??'');
    $cluster=(string)($config['cluster']??'');
    if (!preg_match('/^[0-9]{1,18}$/D',$appId)
        || !preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$key)
        || $secret==='' || !preg_match('/^[a-z0-9-]{2,20}$/D',$cluster)) {
        return ['success'=>false,'message'=>'Pusher credentials are incomplete or invalid.'];
    }
    try {
        $body=json_encode([
            'name'=>$event,'channels'=>[$channel],
            'data'=>json_encode($payload,JSON_THROW_ON_ERROR)
        ],JSON_THROW_ON_ERROR);
        $path='/apps/'.$appId.'/events';
        $params=['auth_key'=>$key,'auth_timestamp'=>(string)time(),
            'auth_version'=>'1.0','body_md5'=>md5($body)];
        ksort($params);
        $query=http_build_query($params,'','&',PHP_QUERY_RFC3986);
        $signature=hash_hmac('sha256',"POST\n".$path."\n".$query,$secret);
        $url='https://api-'.$cluster.'.pusher.com'.$path.'?'.$query
            .'&auth_signature='.$signature;
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        ]);
        $result=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $curlError=curl_errno($ch);
        curl_close($ch);
        if ($result===false || $curlError!==0) {
            return ['success'=>false,'message'=>'Cannot connect to Pusher. Check outbound HTTPS, DNS and SSL certificates.'];
        }
        if ($status>=200 && $status<300) return [
            'success'=>true,'message'=>'Pusher accepted the event (HTTP '.$status.').'
        ];
        if ($status===401 || $status===403) return [
            'success'=>false,'message'=>'Pusher rejected the credentials (HTTP '.$status.'). Rotate/check your App Secret.'
        ];
        if ($status===404) return [
            'success'=>false,'message'=>'Pusher app or cluster could not be found (HTTP 404).'
        ];
        return ['success'=>false,'message'=>'Pusher API responded with HTTP '.$status.'.'];
    } catch (Throwable $error) {
        error_log('FP-OMS Pusher request failed: '.get_class($error));
        return ['success'=>false,'message'=>'Pusher request could not be completed.'];
    }
}

function fpoms_pusher_publish(string $kind,int $id): void
{
    try {
        if ($id<1) return;
        $config=fpoms_pusher_config();
        if (!fpoms_pusher_valid_config($config)) return;
        $result=fpoms_pusher_send($config,fpoms_pusher_channel($kind,$id),
            'notification-created',['refresh'=>true]);
        if (!$result['success']) {
            error_log('FP-OMS realtime publish skipped: '.(string)$result['message']);
        }
    } catch (Throwable $error) {
        // A Pusher outage must never interfere with the database notification.
        error_log('FP-OMS realtime publish exception: '.get_class($error));
    }
}

/**
 * Events created inside a DB transaction are deferred until commit.
 * At shutdown, verify the notification row still exists; rolled back rows
 * can never trigger a realtime event. Other flows publish immediately.
 */
function fpoms_pusher_notify_after_write(string $kind,int $userId,int $notificationId): void
{
    if ($userId<1 || $notificationId<1) return;
    if (!fpoms_pusher_enabled()) return;
    if (db()->inTransaction()) {
        $GLOBALS['fpoms_pusher_pending'][]=[
            'kind'=>$kind,'user'=>$userId,'notification'=>$notificationId
        ];
        if (empty($GLOBALS['fpoms_pusher_flush_registered'])) {
            $GLOBALS['fpoms_pusher_flush_registered']=true;
            register_shutdown_function('fpoms_pusher_flush_pending');
        }
        return;
    }
    fpoms_pusher_publish($kind,$userId);
}

function fpoms_pusher_flush_pending(): void
{
    $pending=$GLOBALS['fpoms_pusher_pending']??[];
    $GLOBALS['fpoms_pusher_pending']=[];
    if (!$pending) return;
    try {
        if (db()->inTransaction()) return; // Transaction never committed.
        foreach ($pending as $item) {
            $kind=(string)$item['kind'];
            $column=$kind==='applicant'?'client_id':'user_id';
            $row=query_one('SELECT id FROM notifications WHERE id=? AND '.$column.'=?',
                [(int)$item['notification'],(int)$item['user']]);
            if ($row) fpoms_pusher_publish($kind,(int)$item['user']);
        }
    } catch (Throwable $error) {
        error_log('FP-OMS pending realtime delivery unavailable: '.get_class($error));
    }
}

function fpoms_pusher_auth(array $user,string $channel,string $socket): ?array
{
    $kind=($user['user_kind']??'')==='applicant'?'applicant':'staff';
    $config=fpoms_pusher_config();
    if (!fpoms_pusher_valid_config($config)
        || !preg_match('/^\d+\.\d+$/D',$socket)
        || $channel!==fpoms_pusher_channel($kind,(int)$user['id'])) return null;
    $signature=hash_hmac('sha256',$socket.':'.$channel,(string)$config['secret']);
    return ['auth'=>(string)$config['key'].':'.$signature];
}

function fpoms_pusher_client_config(array $user): array
{
    $config=fpoms_pusher_config();
    if (!fpoms_pusher_valid_config($config)) return [];
    return ['key'=>(string)$config['key'],'cluster'=>(string)$config['cluster'],
        'channel'=>fpoms_pusher_channel(
            ($user['user_kind']??'')==='applicant'?'applicant':'staff',(int)$user['id']),
        'authEndpoint'=>url('pusher_auth'),
        'notificationsEndpoint'=>url('notifications_fragment')];
}
