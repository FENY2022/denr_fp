<?php
declare(strict_types=1);

const FPOMS_CHAT_FILE_MAX_BYTES = 26214400;

function messenger_kind(array $user): string
{
    return ($user['user_kind'] ?? '') === 'applicant' ? 'applicant' : 'staff';
}

function messenger_install_schema(): bool
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS fp_chat_threads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_type ENUM('direct','group') NOT NULL,
            direct_key VARCHAR(100) NULL,
            name VARCHAR(160) NULL,
            created_by_kind ENUM('staff','applicant') NOT NULL,
            created_by_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fp_chat_direct_key (direct_key),
            KEY idx_fp_chat_threads_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS fp_chat_thread_members (
            thread_id BIGINT UNSIGNED NOT NULL,
            principal_kind ENUM('staff','applicant') NOT NULL,
            principal_id BIGINT UNSIGNED NOT NULL,
            member_role ENUM('admin','member') NOT NULL DEFAULT 'member',
            last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            hidden_before_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            archived_at DATETIME NULL,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (thread_id,principal_kind,principal_id),
            KEY idx_fp_chat_member_principal (principal_kind,principal_id,archived_at),
            CONSTRAINT fk_fp_chat_member_thread
                FOREIGN KEY (thread_id) REFERENCES fp_chat_threads(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS fp_chat_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uploaded_by_kind ENUM('staff','applicant') NOT NULL,
            uploaded_by_id BIGINT UNSIGNED NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_path VARCHAR(500) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fp_chat_file_path (stored_path),
            KEY idx_fp_chat_file_uploader (uploaded_by_kind,uploaded_by_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS fp_chat_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            sender_kind ENUM('staff','applicant') NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL,
            message TEXT NULL,
            file_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_fp_chat_messages_thread (thread_id,id),
            KEY idx_fp_chat_messages_sender (sender_kind,sender_id,created_at),
            CONSTRAINT fk_fp_chat_message_thread
                FOREIGN KEY (thread_id) REFERENCES fp_chat_threads(id) ON DELETE CASCADE,
            CONSTRAINT fk_fp_chat_message_file
                FOREIGN KEY (file_id) REFERENCES fp_chat_files(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    try {
        foreach ($statements as $statement) db()->exec($statement);
        return true;
    } catch (Throwable $error) {
        error_log('FP-OMS Messenger schema initialization failed: '.get_class($error).' '.$error->getMessage());
        return false;
    }
}

function messenger_schema_available(): bool
{
    static $available = null;
    if ($available !== null) return $available;

    try {
        query_one('SELECT id FROM fp_chat_threads LIMIT 1');
        query_one('SELECT thread_id FROM fp_chat_thread_members LIMIT 1');
        query_one('SELECT id FROM fp_chat_files LIMIT 1');
        query_one('SELECT id FROM fp_chat_messages LIMIT 1');
        $available = true;
        return true;
    } catch (Throwable $error) {
        // First-run convenience: initialize only the Messenger-owned tables.
        $available = messenger_install_schema();
    }

    if ($available) {
        try {
            query_one('SELECT id FROM fp_chat_messages LIMIT 1');
        } catch (Throwable $error) {
            $available = false;
        }
    }
    return $available;
}

function messenger_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($payload, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    exit;
}

function messenger_require_schema(): void
{
    if (!messenger_schema_available()) {
        messenger_json([
            'error'=>'Messenger storage could not initialize. Ask the administrator to allow the FP-OMS database account to create the Messenger tables.'
        ],503);
    }
}

function messenger_require_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        messenger_json(['error'=>'Your session expired. Refresh the page and try again.'],419);
    }
}

function messenger_principal(string $kind, int $id): ?array
{
    if ($id < 1 || !in_array($kind,['staff','applicant'],true)) return null;
    if ($kind === 'applicant') {
        $row = query_one("SELECT id,name,status FROM user_client WHERE id=? AND user_kind='applicant' LIMIT 1",[$id]);
        if (!$row) return null;
        return [
            'kind'=>'applicant','id'=>(int)$row['id'],'name'=>(string)$row['name'],
            'status'=>(string)$row['status'],'detail'=>'Applicant'
        ];
    }
    $row = query_one("SELECT id,name,status,office_station FROM users WHERE id=? AND user_kind='internal' LIMIT 1",[$id]);
    if (!$row) return null;
    $detail = trim((string)($row['office_station'] ?? ''));
    return [
        'kind'=>'staff','id'=>(int)$row['id'],'name'=>(string)$row['name'],
        'status'=>(string)$row['status'],'detail'=>$detail !== '' ? $detail : 'DENR Personnel'
    ];
}

function messenger_initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $letters .= mb_strtoupper(mb_substr($part,0,1));
        if (mb_strlen($letters) >= 2) break;
    }
    return $letters !== '' ? $letters : 'U';
}

function messenger_member(array $user, int $threadId): ?array
{
    return query_one(
        'SELECT m.*,t.thread_type,t.name,t.direct_key,t.updated_at
         FROM fp_chat_thread_members m
         JOIN fp_chat_threads t ON t.id=m.thread_id
         WHERE m.thread_id=? AND m.principal_kind=? AND m.principal_id=? LIMIT 1',
        [$threadId,messenger_kind($user),(int)$user['id']]
    );
}

function messenger_thread_info(array $user, int $threadId): ?array
{
    $member = messenger_member($user,$threadId);
    if (!$member) return null;
    if ((string)$member['thread_type'] === 'group') {
        return [
            'id'=>$threadId,'type'=>'group',
            'label'=>(string)($member['name'] ?: 'Group Chat'),
            'member_role'=>(string)$member['member_role']
        ];
    }
    $other = query_one(
        'SELECT principal_kind,principal_id FROM fp_chat_thread_members
         WHERE thread_id=? AND NOT (principal_kind=? AND principal_id=?) LIMIT 1',
        [$threadId,messenger_kind($user),(int)$user['id']]
    );
    if (!$other) return null;
    $principal = messenger_principal((string)$other['principal_kind'],(int)$other['principal_id']);
    if (!$principal) return null;
    return [
        'id'=>$threadId,'type'=>'direct','label'=>$principal['name'],
        'other_kind'=>$principal['kind'],'other_id'=>$principal['id'],
        'member_role'=>(string)$member['member_role']
    ];
}

function messenger_direct_key(string $kindA, int $idA, string $kindB, int $idB): string
{
    $parts = [$kindA.':'.$idA,$kindB.':'.$idB];
    sort($parts,SORT_STRING);
    return implode('|',$parts);
}

function messenger_pair_allowed(array $user, string $targetKind, int $targetId): bool
{
    $selfKind = messenger_kind($user);
    if ($targetId < 1 || !in_array($targetKind,['staff','applicant'],true)) return false;
    if ($selfKind === $targetKind && (int)$user['id'] === $targetId) return false;
    if ($selfKind === 'applicant' && $targetKind === 'applicant') return false;
    $target = messenger_principal($targetKind,$targetId);
    return $target !== null && (string)$target['status'] === 'active';
}

function messenger_get_or_create_direct(array $user, string $targetKind, int $targetId): int
{
    if (!messenger_pair_allowed($user,$targetKind,$targetId)) {
        throw new RuntimeException('This account cannot be selected for a direct conversation.');
    }
    $selfKind = messenger_kind($user);
    $selfId = (int)$user['id'];
    $key = messenger_direct_key($selfKind,$selfId,$targetKind,$targetId);
    $existing = query_one("SELECT id FROM fp_chat_threads WHERE thread_type='direct' AND direct_key=? LIMIT 1",[$key]);
    if ($existing) return (int)$existing['id'];

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO fp_chat_threads
             (thread_type,direct_key,name,created_by_kind,created_by_id)
             VALUES ('direct',?,NULL,?,?)"
        )->execute([$key,$selfKind,$selfId]);
        $threadId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare(
            "INSERT INTO fp_chat_thread_members
             (thread_id,principal_kind,principal_id,member_role)
             VALUES (?,?,?,'member')"
        );
        $stmt->execute([$threadId,$selfKind,$selfId]);
        $stmt->execute([$threadId,$targetKind,$targetId]);
        $pdo->commit();
        return $threadId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $existing = query_one("SELECT id FROM fp_chat_threads WHERE thread_type='direct' AND direct_key=? LIMIT 1",[$key]);
        if ($existing) return (int)$existing['id'];
        throw $error;
    }
}

function messenger_search_principals(array $user, string $query): array
{
    $query = trim(mb_substr($query,0,80));
    $like = '%'.$query.'%';
    $selfKind = messenger_kind($user);
    $selfId = (int)$user['id'];
    $result = [];

    $staffSql = "SELECT id,name,office_station FROM users
                 WHERE user_kind='internal' AND status='active'
                   AND (?='' OR name LIKE ? OR office_station LIKE ?)
                 ORDER BY name LIMIT 30";
    foreach (query_all($staffSql,[$query,$like,$like]) as $row) {
        if ($selfKind === 'staff' && (int)$row['id'] === $selfId) continue;
        $detail = trim((string)($row['office_station'] ?? ''));
        $result[] = [
            'kind'=>'staff','id'=>(int)$row['id'],'name'=>(string)$row['name'],
            'detail'=>$detail !== '' ? $detail : 'DENR Personnel',
            'initials'=>messenger_initials((string)$row['name'])
        ];
    }

    if ($selfKind === 'staff') {
        $clientSql = "SELECT id,name FROM user_client
                      WHERE user_kind='applicant' AND status='active'
                        AND (?='' OR name LIKE ? OR email LIKE ?)
                      ORDER BY name LIMIT 30";
        foreach (query_all($clientSql,[$query,$like,$like]) as $row) {
            $result[] = [
                'kind'=>'applicant','id'=>(int)$row['id'],'name'=>(string)$row['name'],
                'detail'=>'Applicant','initials'=>messenger_initials((string)$row['name'])
            ];
        }
    }

    usort($result,static fn(array $a,array $b): int => strcasecmp((string)$a['name'],(string)$b['name']));
    return array_slice($result,0,40);
}

function messenger_conversations(array $user): array
{
    $kind = messenger_kind($user);
    $id = (int)$user['id'];
    $rows = query_all(
        "SELECT t.id,t.thread_type,t.name,t.updated_at,m.member_role,
                m.last_read_message_id,m.hidden_before_message_id,
                (SELECT MAX(mm.id) FROM fp_chat_messages mm
                 WHERE mm.thread_id=t.id AND mm.id>m.hidden_before_message_id) AS last_message_id
         FROM fp_chat_thread_members m
         JOIN fp_chat_threads t ON t.id=m.thread_id
         WHERE m.principal_kind=? AND m.principal_id=? AND m.archived_at IS NULL
         ORDER BY COALESCE(
             (SELECT MAX(mx.created_at) FROM fp_chat_messages mx
              WHERE mx.thread_id=t.id AND mx.id>m.hidden_before_message_id),
             t.updated_at
         ) DESC
         LIMIT 100",
        [$kind,$id]
    );
    $items = [];
    $totalUnread = 0;
    foreach ($rows as $row) {
        $threadId = (int)$row['id'];
        $info = messenger_thread_info($user,$threadId);
        if (!$info) continue;
        $last = null;
        if (!empty($row['last_message_id'])) {
            $last = query_one(
                'SELECT id,sender_kind,sender_id,message,file_id,created_at
                 FROM fp_chat_messages WHERE id=? LIMIT 1',
                [(int)$row['last_message_id']]
            );
        }
        $unreadRow = query_one(
            "SELECT COUNT(*) AS n FROM fp_chat_messages
             WHERE thread_id=? AND id>? AND id>?
               AND NOT (sender_kind=? AND sender_id=?)",
            [
                $threadId,(int)$row['last_read_message_id'],(int)$row['hidden_before_message_id'],
                $kind,$id
            ]
        );
        $unread = (int)($unreadRow['n'] ?? 0);
        $totalUnread += $unread;
        $preview = '';
        $lastTime = (string)$row['updated_at'];
        if ($last) {
            $preview = trim((string)($last['message'] ?? ''));
            if ($preview === '' && !empty($last['file_id'])) $preview = '[Attachment]';
            $lastTime = (string)$last['created_at'];
        } elseif ((string)$row['thread_type'] === 'group') {
            $preview = 'Group created';
        }
        $items[] = [
            'id'=>$threadId,'type'=>(string)$row['thread_type'],'label'=>(string)$info['label'],
            'initials'=>messenger_initials((string)$info['label']),
            'preview'=>$preview,'last_time'=>$lastTime,'unread'=>$unread,
            'member_role'=>(string)$row['member_role']
        ];
    }
    return ['conversations'=>$items,'unread_total'=>$totalUnread];
}

function messenger_messages(array $user, int $threadId): array
{
    $member = messenger_member($user,$threadId);
    if (!$member) throw new RuntimeException('Conversation not found.');
    $rows = query_all(
        'SELECT m.id,m.sender_kind,m.sender_id,m.message,m.file_id,m.created_at,
                f.original_name,f.file_size,f.mime_type
         FROM fp_chat_messages m
         LEFT JOIN fp_chat_files f ON f.id=m.file_id
         WHERE m.thread_id=? AND m.id>?
         ORDER BY m.id DESC LIMIT 100',
        [$threadId,(int)$member['hidden_before_message_id']]
    );
    $rows = array_reverse($rows);
    $messages = [];
    $maxId = 0;
    foreach ($rows as $row) {
        $sender = messenger_principal((string)$row['sender_kind'],(int)$row['sender_id']);
        $maxId = max($maxId,(int)$row['id']);
        $file = null;
        if (!empty($row['file_id'])) {
            $file = [
                'id'=>(int)$row['file_id'],
                'name'=>(string)$row['original_name'],
                'size'=>(int)$row['file_size'],
                'mime'=>(string)$row['mime_type'],
                'url'=>url('messenger_file',['id'=>(int)$row['file_id']])
            ];
        }
        $messages[] = [
            'id'=>(int)$row['id'],
            'sender_kind'=>(string)$row['sender_kind'],
            'sender_id'=>(int)$row['sender_id'],
            'sender_name'=>$sender ? (string)$sender['name'] : 'User',
            'message'=>(string)($row['message'] ?? ''),
            'created_at'=>(string)$row['created_at'],
            'file'=>$file
        ];
    }
    if ($maxId > 0) {
        db()->prepare(
            'UPDATE fp_chat_thread_members SET last_read_message_id=GREATEST(last_read_message_id,?)
             WHERE thread_id=? AND principal_kind=? AND principal_id=?'
        )->execute([$maxId,$threadId,messenger_kind($user),(int)$user['id']]);
    }
    return [
        'thread'=>messenger_thread_info($user,$threadId),
        'messages'=>$messages
    ];
}

function messenger_publish_to_thread(
    int $threadId, string $event, array $payload, ?array $exclude = null
): void {
    if (!fpoms_pusher_enabled()) return;
    $members = query_all(
        'SELECT principal_kind,principal_id FROM fp_chat_thread_members WHERE thread_id=?',
        [$threadId]
    );
    $config = fpoms_pusher_config();
    foreach ($members as $member) {
        $kind = (string)$member['principal_kind'];
        $id = (int)$member['principal_id'];
        if ($exclude && $kind === $exclude['kind'] && $id === $exclude['id']) continue;
        try {
            fpoms_pusher_send($config,fpoms_pusher_channel($kind,$id),$event,$payload);
        } catch (Throwable $error) {
            error_log('FP-OMS messenger realtime publish failed: '.get_class($error));
        }
    }
}

function messenger_store_file(array $user, array $upload): int
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The attachment upload did not complete.');
    }
    $size = (int)($upload['size'] ?? 0);
    if ($size < 1 || $size > FPOMS_CHAT_FILE_MAX_BYTES) {
        throw new RuntimeException('Attachment must be 25 MB or smaller.');
    }
    $original = basename((string)($upload['name'] ?? 'attachment'));
    $ext = strtolower(pathinfo($original,PATHINFO_EXTENSION));
    $allowed = [
        'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','pdf'=>'application/pdf'
    ];
    if (!isset($allowed[$ext])) throw new RuntimeException('Only PDF, PNG, JPG, and JPEG attachments are allowed.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file((string)$upload['tmp_name']);
    if ($mime !== $allowed[$ext]) throw new RuntimeException('The attachment type does not match its file extension.');

    $relativeDir = 'storage/private/chat/'.date('Y/m');
    $absoluteDir = PROJECT_ROOT.'/'.$relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir,0750,true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Unable to create secure messenger storage.');
    }
    $stored = bin2hex(random_bytes(18)).'.fpenc';
    $relative = $relativeDir.'/'.$stored;
    fpoms_file_store_upload((string)$upload['tmp_name'],PROJECT_ROOT.'/'.$relative);
    db()->prepare(
        'INSERT INTO fp_chat_files
         (uploaded_by_kind,uploaded_by_id,original_name,stored_path,file_size,mime_type)
         VALUES (?,?,?,?,?,?)'
    )->execute([
        messenger_kind($user),(int)$user['id'],$original,$relative,$size,$mime
    ]);
    return (int)db()->lastInsertId();
}

function messenger_send_message(array $user, int $threadId, string $message, ?array $upload): int
{
    $member = messenger_member($user,$threadId);
    if (!$member) throw new RuntimeException('Conversation not found.');
    $message = trim(mb_substr($message,0,4000));
    $hasUpload = is_array($upload) && (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
    if ($message === '' && !$hasUpload) throw new RuntimeException('Enter a message or attach a file.');

    $fileId = null;
    if ($hasUpload) $fileId = messenger_store_file($user,$upload);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO fp_chat_messages (thread_id,sender_kind,sender_id,message,file_id)
             VALUES (?,?,?,?,?)'
        )->execute([
            $threadId,messenger_kind($user),(int)$user['id'],
            $message !== '' ? $message : null,$fileId
        ]);
        $messageId = (int)$pdo->lastInsertId();
        $pdo->prepare(
            'UPDATE fp_chat_threads SET updated_at=NOW() WHERE id=?'
        )->execute([$threadId]);
        $pdo->prepare(
            'UPDATE fp_chat_thread_members SET archived_at=NULL WHERE thread_id=?'
        )->execute([$threadId]);
        $pdo->prepare(
            'UPDATE fp_chat_thread_members SET last_read_message_id=GREATEST(last_read_message_id,?)
             WHERE thread_id=? AND principal_kind=? AND principal_id=?'
        )->execute([$messageId,$threadId,messenger_kind($user),(int)$user['id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    messenger_publish_to_thread($threadId,'chat-message',[
        'thread_id'=>$threadId,'message_id'=>$messageId
    ],['kind'=>messenger_kind($user),'id'=>(int)$user['id']]);
    return $messageId;
}

function messenger_create_group(array $user, string $name, array $tokens): int
{
    if (messenger_kind($user) !== 'staff') {
        throw new RuntimeException('Only DENR personnel can create group conversations.');
    }
    $name = trim(mb_substr($name,0,160));
    if ($name === '') throw new RuntimeException('Enter a group name.');

    $members = [];
    foreach ($tokens as $token) {
        if (!is_string($token) || !preg_match('/^(staff|applicant):([1-9][0-9]*)$/D',$token,$m)) continue;
        $kind = $m[1];
        $id = (int)$m[2];
        if ($kind === 'staff' && $id === (int)$user['id']) continue;
        $principal = messenger_principal($kind,$id);
        if (!$principal || (string)$principal['status'] !== 'active') continue;
        $members[$kind.':'.$id] = [$kind,$id];
    }
    if (!$members) throw new RuntimeException('Select at least one group member.');
    if (count($members) > 50) throw new RuntimeException('A group can contain up to 50 selected members.');

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO fp_chat_threads
             (thread_type,direct_key,name,created_by_kind,created_by_id)
             VALUES ('group',NULL,?,'staff',?)"
        )->execute([$name,(int)$user['id']]);
        $threadId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare(
            'INSERT INTO fp_chat_thread_members
             (thread_id,principal_kind,principal_id,member_role)
             VALUES (?,?,?,?)'
        );
        $stmt->execute([$threadId,'staff',(int)$user['id'],'admin']);
        foreach ($members as [$kind,$id]) $stmt->execute([$threadId,$kind,$id,'member']);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    audit((int)$user['id'],'messenger.group_created','chat_thread',$threadId,['name'=>$name]);
    messenger_publish_to_thread($threadId,'chat-message',['thread_id'=>$threadId,'group_created'=>true],
        ['kind'=>'staff','id'=>(int)$user['id']]);
    return $threadId;
}

function messenger_thread_action(array $user, int $threadId, string $action): void
{
    $member = messenger_member($user,$threadId);
    if (!$member) throw new RuntimeException('Conversation not found.');
    $kind = messenger_kind($user);
    $id = (int)$user['id'];
    if ($action === 'archive') {
        db()->prepare(
            'UPDATE fp_chat_thread_members SET archived_at=NOW()
             WHERE thread_id=? AND principal_kind=? AND principal_id=?'
        )->execute([$threadId,$kind,$id]);
    } elseif ($action === 'delete') {
        $max = query_one('SELECT COALESCE(MAX(id),0) AS n FROM fp_chat_messages WHERE thread_id=?',[$threadId]);
        db()->prepare(
            'UPDATE fp_chat_thread_members
             SET hidden_before_message_id=?,last_read_message_id=GREATEST(last_read_message_id,?),archived_at=NOW()
             WHERE thread_id=? AND principal_kind=? AND principal_id=?'
        )->execute([(int)($max['n'] ?? 0),(int)($max['n'] ?? 0),$threadId,$kind,$id]);
    } else {
        throw new RuntimeException('Invalid conversation action.');
    }
    if ($kind === 'applicant') audit_client($id,'messenger.'.$action,'chat_thread',$threadId);
    else audit($id,'messenger.'.$action,'chat_thread',$threadId);
}

function messenger_api_conversations(array $user): never
{
    messenger_require_schema();
    messenger_json(messenger_conversations($user));
}

function messenger_api_users(array $user): never
{
    messenger_require_schema();
    $query = trim((string)($_GET['q'] ?? ''));
    messenger_json(['users'=>messenger_search_principals($user,$query)]);
}

function messenger_api_direct(array $user): never
{
    messenger_require_schema();
    messenger_require_csrf();
    $kind = safe_post('target_kind',20);
    $id = filter_var($_POST['target_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) messenger_json(['error'=>'Invalid user selection.'],400);
    try {
        $threadId = messenger_get_or_create_direct($user,$kind,(int)$id);
        messenger_json(['success'=>true,'thread'=>messenger_thread_info($user,$threadId)]);
    } catch (Throwable $error) {
        messenger_json(['error'=>$error->getMessage()],400);
    }
}

function messenger_api_messages(array $user): never
{
    messenger_require_schema();
    $id = filter_var($_GET['thread_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) messenger_json(['error'=>'Invalid conversation.'],400);
    try {
        messenger_json(messenger_messages($user,(int)$id));
    } catch (Throwable $error) {
        messenger_json(['error'=>$error->getMessage()],404);
    }
}

function messenger_api_send(array $user): never
{
    messenger_require_schema();
    messenger_require_csrf();
    $id = filter_var($_POST['thread_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) messenger_json(['error'=>'Invalid conversation.'],400);
    try {
        $messageId = messenger_send_message(
            $user,(int)$id,safe_post('message',4000),
            isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : null
        );
        messenger_json(['success'=>true,'message_id'=>$messageId]);
    } catch (Throwable $error) {
        messenger_json(['error'=>$error->getMessage()],400);
    }
}

function messenger_api_group_create(array $user): never
{
    messenger_require_schema();
    messenger_require_csrf();
    $tokens = $_POST['members'] ?? [];
    if (!is_array($tokens)) $tokens = [$tokens];
    try {
        $threadId = messenger_create_group($user,safe_post('name',160),$tokens);
        messenger_json(['success'=>true,'thread'=>messenger_thread_info($user,$threadId)]);
    } catch (Throwable $error) {
        messenger_json(['error'=>$error->getMessage()],400);
    }
}

function messenger_api_thread_action(array $user): never
{
    messenger_require_schema();
    messenger_require_csrf();
    $id = filter_var($_POST['thread_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) messenger_json(['error'=>'Invalid conversation.'],400);
    try {
        messenger_thread_action($user,(int)$id,safe_post('action',20));
        messenger_json(['success'=>true]);
    } catch (Throwable $error) {
        messenger_json(['error'=>$error->getMessage()],400);
    }
}

function messenger_api_typing(array $user): never
{
    messenger_require_schema();
    messenger_require_csrf();
    $id = filter_var($_POST['thread_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id || !messenger_member($user,(int)$id)) messenger_json(['error'=>'Invalid conversation.'],400);
    $typing = safe_post('is_typing',5) === '1';
    messenger_publish_to_thread((int)$id,'chat-typing',[
        'thread_id'=>(int)$id,
        'sender_kind'=>messenger_kind($user),
        'sender_id'=>(int)$user['id'],
        'is_typing'=>$typing
    ],['kind'=>messenger_kind($user),'id'=>(int)$user['id']]);
    messenger_json(['success'=>true]);
}

function messenger_send_file(array $user): never
{
    if (!messenger_schema_available()) show_error('Messenger storage is not initialized.',503);
    $fileId = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$fileId) show_error('Invalid attachment.',400);
    $row = query_one(
        'SELECT f.*,m.thread_id
         FROM fp_chat_files f
         JOIN fp_chat_messages m ON m.file_id=f.id
         WHERE f.id=? LIMIT 1',
        [(int)$fileId]
    );
    if (!$row || !messenger_member($user,(int)$row['thread_id'])) show_error('Attachment not found.',404);
    $path = PROJECT_ROOT.'/'.ltrim((string)$row['stored_path'],'/');
    if (!is_file($path)) show_error('Attachment is unavailable.',404);
    try {
        $bytes = fpoms_file_read($path);
    } catch (Throwable $error) {
        show_error('Attachment could not be decrypted.',500);
    }
    $name = (string)$row['original_name'];
    $ascii = preg_replace('/[^A-Za-z0-9._-]/','_',basename($name)) ?: 'attachment';
    header('Content-Type: '.(string)$row['mime_type']);
    header('Content-Length: '.strlen($bytes));
    header("Content-Disposition: inline; filename=\"".$ascii."\"; filename*=UTF-8''".rawurlencode($name));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}

function messenger_widget_html(array $user): string
{
    if (!messenger_schema_available()) return '';
    $config = [
        'kind'=>messenger_kind($user),'id'=>(int)$user['id'],'csrf'=>csrf_token(),
        'canCreateGroup'=>messenger_kind($user)==='staff',
        'conversations'=>url('messenger_conversations'),
        'users'=>url('messenger_users'),
        'direct'=>url('messenger_direct'),
        'messages'=>url('messenger_messages'),
        'send'=>url('messenger_send'),
        'groupCreate'=>url('messenger_group_create'),
        'threadAction'=>url('messenger_thread_action'),
        'typing'=>url('messenger_typing')
    ];
    return '<div class="fp-chat-root" data-fp-messenger>'
        . '<button class="fp-chat-fab" id="fpChatFab" type="button" aria-label="Open Messenger" aria-expanded="false">'
        . '<i class="bi bi-chat-dots-fill" aria-hidden="true"></i>'
        . '<span class="fp-chat-badge d-none" id="fpChatBadge">0</span></button>'
        . '<section class="fp-chat-panel d-none" id="fpChatPanel" aria-label="FP-OMS Messenger">'
        . '<header class="fp-chat-header"><div><strong>Messenger</strong><small>FP-OMS secure messaging</small></div>'
        . '<div class="fp-chat-header-actions">'
        . '<button type="button" id="fpChatNew" title="New message" aria-label="New message"><i class="bi bi-pencil-square"></i></button>'
        . (messenger_kind($user)==='staff'
            ? '<button type="button" id="fpChatNewGroup" title="New group" aria-label="New group"><i class="bi bi-people-fill"></i></button>' : '')
        . '<button type="button" id="fpChatClose" title="Close" aria-label="Close Messenger"><i class="bi bi-x-lg"></i></button>'
        . '</div></header>'
        . '<div class="fp-chat-status d-none" id="fpChatStatus" role="status"></div>'
        . '<div class="fp-chat-home" id="fpChatHome">'
        . '<div class="fp-chat-search"><i class="bi bi-search"></i><input type="search" id="fpChatConversationSearch" placeholder="Search conversations" aria-label="Search conversations"></div>'
        . '<div class="fp-chat-list" id="fpChatConversationList"></div></div>'
        . '<div class="fp-chat-new d-none" id="fpChatNewView">'
        . '<div class="fp-chat-subheader"><button type="button" class="fp-chat-back" data-chat-back><i class="bi bi-arrow-left"></i></button><strong>New Message</strong></div>'
        . '<div class="fp-chat-search"><i class="bi bi-search"></i><input type="search" id="fpChatUserSearch" placeholder="Search people" aria-label="Search people"></div>'
        . '<div class="fp-chat-list" id="fpChatUserList"></div></div>'
        . '<div class="fp-chat-group d-none" id="fpChatGroupView">'
        . '<div class="fp-chat-subheader"><button type="button" class="fp-chat-back" data-chat-back><i class="bi bi-arrow-left"></i></button><strong>Create Group</strong></div>'
        . '<div class="fp-chat-group-form"><label for="fpChatGroupName">Group name</label><input type="text" id="fpChatGroupName" maxlength="160" placeholder="e.g. FP Review Team">'
        . '<label for="fpChatGroupSearch">Add members</label><div class="fp-chat-search"><i class="bi bi-search"></i><input type="search" id="fpChatGroupSearch" placeholder="Search people" aria-label="Search group members"></div>'
        . '<div class="fp-chat-selected" id="fpChatSelectedMembers"></div><div class="fp-chat-list fp-chat-group-users" id="fpChatGroupUsers"></div>'
        . '<button class="btn btn-success w-100 mt-2" type="button" id="fpChatCreateGroup"><i class="bi bi-people-fill me-1"></i>Create Group</button></div></div>'
        . '<div class="fp-chat-thread d-none" id="fpChatThreadView">'
        . '<div class="fp-chat-thread-header"><button type="button" class="fp-chat-back" data-chat-back><i class="bi bi-arrow-left"></i></button>'
        . '<div class="fp-chat-thread-avatar" id="fpChatThreadAvatar">U</div><div class="fp-chat-thread-title"><strong id="fpChatThreadName">Conversation</strong><small id="fpChatThreadMeta">Secure FP-OMS chat</small></div>'
        . '<div class="dropdown"><button type="button" class="fp-chat-more" data-bs-toggle="dropdown" aria-label="Conversation options"><i class="bi bi-three-dots-vertical"></i></button>'
        . '<ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item" type="button" data-chat-action="archive"><i class="bi bi-archive me-2"></i>Archive</button></li>'
        . '<li><button class="dropdown-item text-danger" type="button" data-chat-action="delete"><i class="bi bi-trash me-2"></i>Delete for me</button></li></ul></div></div>'
        . '<div class="fp-chat-messages" id="fpChatMessages"></div>'
        . '<div class="fp-chat-typing d-none" id="fpChatTyping"><span></span><span></span><span></span><em>typing</em></div>'
        . '<div class="fp-chat-file-preview d-none" id="fpChatFilePreview"><i class="bi bi-paperclip"></i><span id="fpChatFileName"></span><button type="button" id="fpChatFileClear" aria-label="Remove attachment"><i class="bi bi-x"></i></button></div>'
        . '<form class="fp-chat-compose" id="fpChatForm"><input class="d-none" type="file" id="fpChatFile" accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg">'
        . '<button type="button" id="fpChatAttach" aria-label="Attach file" title="Attach PDF or image"><i class="bi bi-paperclip"></i></button>'
        . '<textarea id="fpChatMessage" rows="1" maxlength="4000" placeholder="Type a message..." aria-label="Message"></textarea>'
        . '<button class="fp-chat-send" type="submit" id="fpChatSend" aria-label="Send message"><i class="bi bi-send-fill"></i></button></form></div>'
        . '<script type="application/json" id="fp-chat-config">'
        . json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)
        . '</script></section></div>';
}
