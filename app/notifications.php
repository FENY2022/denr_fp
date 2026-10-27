<?php
declare(strict_types=1);

function create_notification(
    int $userId,
    string $title,
    string $message,
    string $icon = 'bell',
    string $level = 'info',
    ?string $routePage = null,
    array $routeQuery = [],
    string $ownerKind = 'internal'
): void {
    $title = trim($title);
    $message = trim($message);
    $icon = preg_match('/^[a-z0-9-]{1,50}$/', $icon) ? $icon : 'bell';
    $level = in_array($level, ['info','success','warning','danger'], true) ? $level : 'info';

    if ($userId < 1 || $title === '' || $message === '') return;

    if ($routePage !== null) {
        $routePage = str_replace('-', '_', trim($routePage));
        if (!preg_match('/^[a-z][a-z0-9_]{0,79}$/', $routePage)) $routePage = null;
    }

    $queryJson = $routeQuery ? json_encode($routeQuery, JSON_THROW_ON_ERROR) : null;
    $ownerColumn = $ownerKind === 'applicant' ? 'client_id' : 'user_id';
    try {
        db()->prepare(
            "INSERT INTO notifications ({$ownerColumn},title,message,icon,level,route_page,route_query_json) VALUES (?,?,?,?,?,?,?)"
        )->execute([
            $userId,
            substr($title, 0, 180),
            substr($message, 0, 1000),
            $icon,
            $level,
            $routePage,
            $queryJson
        ]);
    } catch (PDOException $e) {
        error_log('FP-OMS notification write skipped: ' . $e->getMessage());
    }
}

/** Write to user_client notifications even if client ID overlaps a staff ID. */
function create_client_notification(
    int $clientId, string $title, string $message, string $icon='bell',
    string $level='info', ?string $routePage=null, array $routeQuery=[]
): void {
    create_notification($clientId,$title,$message,$icon,$level,$routePage,$routeQuery,'applicant');
}

/** All current-user notification queries use an explicitly namespaced owner. */
function notification_owner_column(): string
{
    return isset($_SESSION['client_id']) && !isset($_SESSION['user_id'])
        ? 'client_id' : 'user_id';
}

function notify_role(
    string $roleCode,
    string $title,
    string $message,
    string $icon = 'bell',
    string $level = 'info',
    ?string $routePage = null,
    array $routeQuery = []
): void {
    $rows = query_all(
        "SELECT DISTINCT u.id
         FROM users u
         INNER JOIN user_roles ur ON ur.user_id=u.id
         INNER JOIN roles r ON r.id=ur.role_id
         WHERE r.code=? AND u.status <> 'suspended'",
        [$roleCode]
    );

    foreach ($rows as $row) {
        create_notification((int)$row['id'], $title, $message, $icon, $level, $routePage, $routeQuery);
    }
}

function notification_unread_count(int $userId): int
{
    try {
        $column = notification_owner_column();
        $row = query_one("SELECT COUNT(*) AS n FROM notifications WHERE {$column}=? AND read_at IS NULL", [$userId]);
        return (int)($row['n'] ?? 0);
    } catch (PDOException $e) {
        return 0;
    }
}

function latest_notifications(int $userId, int $limit = 5): array
{
    $limit = max(1, min(20, $limit));
    try {
        $column = notification_owner_column();
        return query_all(
            "SELECT * FROM notifications
             WHERE {$column}=?
             ORDER BY (read_at IS NULL) DESC, created_at DESC, id DESC
             LIMIT {$limit}",
            [$userId]
        );
    } catch (PDOException $e) {
        return [];
    }
}

function all_notifications(int $userId, int $limit = 100): array
{
    $limit = max(1, min(200, $limit));
    try {
        $column = notification_owner_column();
        return query_all(
            "SELECT * FROM notifications
             WHERE {$column}=?
             ORDER BY (read_at IS NULL) DESC, created_at DESC, id DESC
             LIMIT {$limit}",
            [$userId]
        );
    } catch (PDOException $e) {
        return [];
    }
}

function notification_destination(array $notification): string
{
    $page = trim((string)($notification['route_page'] ?? ''));
    if ($page === '') return url('notifications');

    $query = [];
    $raw = (string)($notification['route_query_json'] ?? '');
    if ($raw !== '') {
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) $query = $decoded;
        } catch (Throwable) {
            $query = [];
        }
    }

    try {
        return url($page, $query);
    } catch (Throwable) {
        return url('notifications');
    }
}

function notification_open(array $user, int $notificationId): never
{
    $column = ($user['user_kind'] ?? '') === 'applicant' ? 'client_id' : 'user_id';
    $notification = query_one("SELECT * FROM notifications WHERE id=? AND {$column}=?", [$notificationId, (int)$user['id']]);
    if (!$notification) show_error('Notification not found.', 404);

    if ($notification['read_at'] === null) {
        db()->prepare("UPDATE notifications SET read_at=NOW() WHERE id=? AND {$column}=?")
            ->execute([$notificationId, (int)$user['id']]);
    }

    header('Location: ' . notification_destination($notification), true, 303);
    exit;
}

function mark_all_notifications_read(array $user): void
{
    $column = ($user['user_kind'] ?? '') === 'applicant' ? 'client_id' : 'user_id';
    db()->prepare("UPDATE notifications SET read_at=NOW() WHERE {$column}=? AND read_at IS NULL")
        ->execute([(int)$user['id']]);
}

function notification_time_label(string $value): string
{
    try {
        $created = new DateTimeImmutable($value);
        $now = new DateTimeImmutable('now');
        $seconds = max(0, $now->getTimestamp() - $created->getTimestamp());

        if ($seconds < 60) return 'Just now';
        if ($seconds < 3600) return intdiv($seconds, 60) . ' min ago';
        if ($seconds < 86400) return intdiv($seconds, 3600) . ' hr ago';
        if ($seconds < 604800) return intdiv($seconds, 86400) . ' day' . (intdiv($seconds, 86400) === 1 ? '' : 's') . ' ago';
        return $created->format('M j, Y');
    } catch (Throwable) {
        return $value;
    }
}

function notification_dropdown_html(array $user): string
{
    $items = latest_notifications((int)$user['id'], 5);
    $unread = notification_unread_count((int)$user['id']);

    $html = '<div class="dropdown notification-dropdown">'
        . '<button class="notification-bell" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">'
        . '<i class="bi bi-bell-fill" aria-hidden="true"></i>'
        . ($unread > 0 ? '<span class="notification-badge">' . ($unread > 99 ? '99+' : $unread) . '</span>' : '')
        . '</button><div class="dropdown-menu dropdown-menu-end notification-menu">'
        . '<div class="notification-menu-header"><div><strong>Notifications</strong><small>' . ($unread > 0 ? $unread . ' unread' : 'You are all caught up') . '</small></div>';

    if ($unread > 0) {
        $html .= '<form method="post" action="' . e(url('notifications_read_all')) . '">' . csrf_field()
            . '<button class="btn btn-link btn-sm p-0" type="submit">Mark all read</button></form>';
    }

    $html .= '</div><div class="notification-list">';

    if (!$items) {
        $html .= '<div class="notification-empty"><i class="bi bi-bell-slash"></i><span>No notifications yet.</span></div>';
    } else {
        foreach ($items as $item) {
            $unreadClass = $item['read_at'] === null ? ' unread' : '';
            $html .= '<a class="notification-item' . $unreadClass . '" href="' . e(url('notification_open', ['id'=>(int)$item['id']])) . '">'
                . '<span class="notification-item-icon text-bg-' . e((string)$item['level']) . '"><i class="bi bi-' . e((string)$item['icon']) . '"></i></span>'
                . '<span class="notification-item-copy"><strong>' . e((string)$item['title']) . '</strong>'
                . '<span>' . e((string)$item['message']) . '</span>'
                . '<small>' . e(notification_time_label((string)$item['created_at'])) . '</small></span>'
                . ($item['read_at'] === null ? '<span class="notification-unread-dot" aria-label="Unread"></span>' : '')
                . '</a>';
        }
    }

    return $html . '</div><a class="notification-view-all" href="' . e(url('notifications')) . '">View All Notifications <i class="bi bi-arrow-right"></i></a></div></div>';
}

function view_notifications(): void
{
    $user = require_user();
    $items = all_notifications((int)$user['id']);

    $html = '<div class="panel notifications-page"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">'
        . '<div><h2 class="h5 mb-1">All Notifications</h2><p class="small text-secondary mb-0">Unread notifications are shown first.</p></div>';

    if (notification_unread_count((int)$user['id']) > 0) {
        $html .= '<form method="post" action="' . e(url('notifications_read_all')) . '">' . csrf_field()
            . '<button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-check2-all me-1"></i>Mark all read</button></form>';
    }

    $html .= '</div><div class="notification-page-list">';

    if (!$items) {
        $html .= '<div class="text-center text-secondary py-5"><i class="bi bi-bell-slash fs-2 d-block mb-2"></i>No notifications yet.</div>';
    } else {
        foreach ($items as $item) {
            $unreadClass = $item['read_at'] === null ? ' unread' : '';
            $html .= '<a class="notification-page-item' . $unreadClass . '" href="' . e(url('notification_open', ['id'=>(int)$item['id']])) . '">'
                . '<span class="notification-item-icon text-bg-' . e((string)$item['level']) . '"><i class="bi bi-' . e((string)$item['icon']) . '"></i></span>'
                . '<span class="notification-item-copy"><strong>' . e((string)$item['title']) . '</strong><span>' . e((string)$item['message']) . '</span>'
                . '<small>' . e(notification_time_label((string)$item['created_at'])) . '</small></span>'
                . ($item['read_at'] === null ? '<span class="badge text-bg-success">New</span>' : '')
                . '</a>';
        }
    }

    $html .= '</div></div>';
    render_page('Notifications', $html);
}
