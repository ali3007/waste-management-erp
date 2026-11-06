<?php
/** Mark one of the user's notifications read, then open its complaint. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Auth::requireLogin();

$row = Database::fetch('SELECT id, complaint_id FROM complaint_notifications WHERE id = :id AND user_id = :u', ['id' => (int)query('id'), 'u' => (int)Auth::id()]);
if ($row === null) {
    redirect('modules/complaints/notifications.php');
}
Database::execute('UPDATE complaint_notifications SET is_read = 1, read_at = :n WHERE id = :id', ['n' => cmp_now(), 'id' => (int)$row['id']]);
redirect('modules/complaints/complaint-view.php?id=' . (int)$row['complaint_id']);
