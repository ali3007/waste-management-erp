<?php
/**
 * Communication workspace — landing page with the Chat / Tasks / Notifications tabs.
 * Phase 1 delivers the shell only: each tab says plainly what is not enabled yet rather than
 * showing invented numbers.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
comms_require();

$tabs = ['chat' => 'Chat', 'tasks' => 'Tasks', 'notifications' => 'Notifications'];
$tab = query('tab', 'chat');
if (!isset($tabs[$tab])) {
    $tab = 'chat';
}
$notes = [
        'tasks'         => ['Tasks are not enabled yet', 'Task creation, assignment, review and deadlines arrive in a later release of this module.'],
    'notifications' => ['No notifications yet', 'Messages, task assignments and reminders will appear here once those features are enabled.'],
];

$pageTitle = 'Communication';
$layout = 'app';
if ($tab === 'chat') {
    $extraScripts = ['comms-chat.js'];
}
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<nav class="comms-tabs" aria-label="Communication sections">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="comms-tab <?= $tab === $key ? 'is-active' : '' ?>" href="<?= e($key === 'tasks' ? base_url('modules/comms/tasks.php') : base_url('modules/comms/index.php?tab=' . $key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'chat'): ?>
    <?php require __DIR__ . '/partials/chat.php'; ?>
<?php else: ?>
<section class="card">
    <div class="empty-state">
        <h2 class="card__title"><?= e($notes[$tab][0]) ?></h2>
        <p class="card__subtitle"><?= e($notes[$tab][1]) ?></p>
    </div>
</section>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
