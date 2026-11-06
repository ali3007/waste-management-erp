<?php
/** All of the signed-in user's complaint notifications. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('complaint.view');

$uid = (int)Auth::id();
if (is_post()) {
    Security::requireCsrf('modules/complaints/notifications.php');
    Database::execute('UPDATE complaint_notifications SET is_read = 1, read_at = :n WHERE user_id = :u AND is_read = 0', ['n' => cmp_now(), 'u' => $uid]);
    flash('success', 'All notifications marked as read.');
    redirect('modules/complaints/notifications.php');
}

$page = max(1, (int)query('page', '1'));
$perPage = 25;
$total = (int)Database::fetchValue('SELECT COUNT(*) FROM complaint_notifications WHERE user_id = :u', ['u' => $uid]);
$page = min($page, max(1, (int)ceil($total / $perPage)));
$rows = Database::fetchAll(
    'SELECT * FROM complaint_notifications WHERE user_id = :u ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
    ['u' => $uid]
);
$unread = (int)Database::fetchValue('SELECT COUNT(*) FROM complaint_notifications WHERE user_id = :u AND is_read = 0', ['u' => $uid]);

$pageTitle = 'Notifications';
$layout = 'app';
require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="toolbar">
    <div class="toolbar__spacer" style="margin-left:0"></div>
    <?php if ($unread > 0): ?>
        <form method="post"><?= csrf_field() ?><button type="submit" class="btn btn--ghost"><?= icon('check-big') ?><span class="btn__label">Mark all as read (<?= $unread ?>)</span></button></form>
    <?php endif; ?>
</div>

<?php if (!$rows): ?>
    <div class="card"><div class="empty-state"><?= icon('bell') ?><h3 style="margin-bottom:6px">No notifications</h3><p style="margin:0">You'll be notified here when a complaint is assigned to your UC.</p></div></div>
<?php else: ?>
    <div class="card" style="padding:0;overflow:hidden">
        <?php foreach ($rows as $n): ?>
            <a class="cmp-notif <?= $n['is_read'] ? '' : 'is-unread' ?>" href="<?= e(base_url('modules/complaints/notification-open.php?id=' . (int)$n['id'])) ?>">
                <strong><?= e((string)$n['title']) ?></strong>
                <span><?= e((string)$n['message']) ?></span>
                <small><?= e(format_datetime((string)$n['created_at'])) ?></small>
            </a>
        <?php endforeach; ?>
    </div>
    <?= cmp_render_pagination($page, $total, $perPage, static fn(int $p): string => base_url('modules/complaints/notifications.php?page=' . $p)) ?>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
