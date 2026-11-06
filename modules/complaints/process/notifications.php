<?php
/**
 * JSON for the notification bell.
 *   GET  ?action=summary   → unread count + the latest notifications
 *   POST action=read (id)  → mark one notification read (own only)
 *   POST action=all        → mark all of the user's notifications read
 * A user only ever sees and changes their OWN notifications.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    json_response(['ok' => false], 503);
}
Auth::requireLogin();
if (!can('complaint.view')) {
    json_response(['ok' => true, 'unread' => 0, 'items' => []]);
}

$uid = (int)Auth::id();

/** "5 min ago" style text. */
$ago = static function (string $when): string {
    $s = max(0, time() - strtotime($when));
    if ($s < 60) { return 'just now'; }
    if ($s < 3600) { return intdiv($s, 60) . ' min ago'; }
    if ($s < 86400) { return intdiv($s, 3600) . ' h ago'; }
    if ($s < 604800) { return intdiv($s, 86400) . ' d ago'; }
    return date('d M Y', strtotime($when));
};

if (is_post()) {
    if (!Security::verifyCsrf()) {
        json_response(['ok' => false, 'message' => 'Your session expired. Reload the page and try again.'], 419);
    }
    $now = cmp_now();
    if (post('action') === 'all') {
        Database::execute('UPDATE complaint_notifications SET is_read = 1, read_at = :n WHERE user_id = :u AND is_read = 0', ['n' => $now, 'u' => $uid]);
    } elseif (post('action') === 'read') {
        Database::execute('UPDATE complaint_notifications SET is_read = 1, read_at = :n WHERE id = :id AND user_id = :u AND is_read = 0', ['n' => $now, 'id' => (int)post('id'), 'u' => $uid]);
    }
}

$unread = (int)Database::fetchValue('SELECT COUNT(*) FROM complaint_notifications WHERE user_id = :u AND is_read = 0', ['u' => $uid]);
$rows = Database::fetchAll(
    'SELECT id, title, message, is_read, created_at FROM complaint_notifications WHERE user_id = :u ORDER BY id DESC LIMIT 8',
    ['u' => $uid]
);
json_response([
    'ok' => true, 'unread' => $unread,
    'items' => array_map(static fn($r) => [
        'id' => (int)$r['id'], 'title' => $r['title'], 'message' => $r['message'], 'read' => (bool)$r['is_read'],
        'ago' => $ago((string)$r['created_at']), 'url' => base_url('modules/complaints/notification-open.php?id=' . (int)$r['id']),
    ], $rows),
    'all_url' => base_url('modules/complaints/notifications.php'),
]);
