<?php
/** Task list: only tasks the signed-in user takes part in (assigner, assignee or reviewer). Filters + pagination. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
comms_require();
$me = (int)Auth::id();
comms_run_scheduled();
$f = [
    'scope' => query('scope', 'all'), 'status' => query('status'), 'priority' => query('priority'), 'my_status' => query('my_status'), 'overdue' => query('overdue') === '1' ? 1 : 0,
    'q' => query('q'), 'from' => query('from'), 'to' => query('to'), 'awaiting_review' => query('awaiting_review') === '1' ? 1 : 0, 'review_overdue' => query('review_overdue') === '1' ? 1 : 0,
];
$page = max(1, (int)query('page', '1'));
$res = comms_task_list($me, $f, $page, 20);
$qs = static function (array $over = []) use ($f): string {
    $p = array_filter(array_merge($f, $over), static fn($v) => $v !== '' && $v !== 0 && $v !== null && $v !== 'all');
    return $p ? '?' . http_build_query($p) : '';
};

$pageTitle = 'Tasks';
$layout = 'app';
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<nav class="comms-tabs" aria-label="Communication sections">
    <a class="comms-tab" href="<?= e(base_url('modules/comms/index.php?tab=chat')) ?>">Chat</a>
    <a class="comms-tab is-active" href="<?= e(base_url('modules/comms/tasks.php')) ?>">Tasks</a>
    <a class="comms-tab" href="<?= e(base_url('modules/comms/index.php?tab=notifications')) ?>">Notifications</a>
</nav>
<div class="toolbar">
    <div class="toolbar__spacer" style="margin-left:0"></div>
    <?php if (can('comms.template_manage')): ?><a class="btn btn--ghost" href="<?= e(base_url('modules/comms/templates.php')) ?>"><span class="btn__label">Templates</span></a><?php endif; ?>
    <?php if (can('comms.task_create')): ?><a class="btn btn--primary" href="<?= e(base_url('modules/comms/task-new.php?new=1')) ?>"><?= icon('plus') ?><span class="btn__label">New task</span></a><?php endif; ?>
</div>
<form method="get" class="card comms-filters">
    <div class="comms-filters__grid">
        <input class="input" type="search" name="q" placeholder="Search title, number or description" value="<?= e($f['q']) ?>">
        <select class="select" name="scope">
            <?php foreach (['all' => 'All my tasks', 'mine' => 'Assigned to me', 'assigned_by_me' => 'Assigned by me', 'review' => 'I am the reviewer'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['scope'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <select class="select" name="status"><option value="">Any status</option>
            <?php foreach (['active' => 'Open', 'partially_completed' => 'Partially completed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <select class="select" name="priority"><option value="">Any priority</option>
            <?php foreach (COMMS_PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['priority'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <select class="select" name="my_status"><option value="">My status: any</option>
            <?php foreach (['pending_acceptance', 'accepted', 'in_progress', 'declined', 'submitted', 'rejected', 'approved'] as $k): ?><option value="<?= e($k) ?>"<?= $f['my_status'] === $k ? ' selected' : '' ?>><?= e(comms_assignee_status_label($k)) ?></option><?php endforeach; ?>
        </select>
        <label>Due from <input class="input" type="date" name="from" value="<?= e($f['from']) ?>"></label>
        <label>to <input class="input" type="date" name="to" value="<?= e($f['to']) ?>"></label>
        <label class="comms-check"><input type="checkbox" name="overdue" value="1"<?= $f['overdue'] ? ' checked' : '' ?>> Overdue only</label>
        <label class="comms-check"><input type="checkbox" name="awaiting_review" value="1"<?= $f['awaiting_review'] ? ' checked' : '' ?>> Waiting for my review</label>
        <label class="comms-check"><input type="checkbox" name="review_overdue" value="1"<?= $f['review_overdue'] ? ' checked' : '' ?>> Review overdue</label>
        <button class="btn btn--primary" type="submit"><span class="btn__label">Filter</span></button>
        <a class="btn btn--ghost" href="<?= e(base_url('modules/comms/tasks.php')) ?>">Clear</a>
    </div>
</form>
<?php if (!$res['rows']): ?>
    <div class="card"><div class="empty-state"><h3>No tasks found</h3><p class="comms-muted">You only see tasks you assigned, were assigned, or review.</p></div></div>
<?php else: ?>
<div class="card" style="padding:0">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Task</th><th>Priority</th><th>Due</th><th>Status</th><th>My status</th><th class="is-num">Progress</th><th>Assigner</th></tr></thead>
        <tbody>
        <?php foreach ($res['rows'] as $r): ?>
            <tr>
                <td class="is-wrap"><a href="<?= e(comms_task_url((int)$r['id'])) ?>"><strong><?= e($r['title']) ?></strong></a><span class="cell-sub"><?= e((string)$r['task_no']) ?> · <?= (int)$r['n_assignees'] ?> assignee<?= (int)$r['n_assignees'] === 1 ? '' : 's' ?><?= (int)$r['pending_reviews'] > 0 ? ' · <strong>' . (int)$r['pending_reviews'] . ' waiting for review</strong>' : '' ?></span></td>
                <td><span class="badge <?= e(comms_priority_badge($r['priority'])) ?>"><?= e(COMMS_PRIORITIES[$r['priority']]) ?></span></td>
                <td><?= e(comms_fmt_deadline($r['due_date'], $r['due_time'])) ?><?php if ($r['overdue']): ?> <span class="badge badge--blocked">Overdue</span><?php endif; ?></td>
                <td><span class="badge <?= e(comms_task_status_badge($r['status'])) ?>"><?= e(comms_task_status_label($r['status'])) ?></span></td>
                <td><?= $r['my_status'] !== null ? '<span class="badge ' . e(comms_assignee_status_badge($r['my_status'])) . '">' . e(comms_assignee_status_label($r['my_status'])) . '</span>' : '—' ?></td>
                <td class="is-num"><?= $r['my_progress'] !== null ? (int)$r['my_progress'] . '%' : ((int)$r['avg_progress'] . '% avg') ?></td>
                <td><?= e((string)($r['assigner'] ?? '—')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php if ($res['pages'] > 1): ?>
<nav class="pagination" aria-label="Pagination">
    <?php for ($p = max(1, $page - 3); $p <= min($res['pages'], $page + 3); $p++): ?>
        <?php if ($p === $page): ?><span class="is-current"><?= $p ?></span><?php else: ?><a href="<?= e(base_url('modules/comms/tasks.php') . $qs(['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
    <?php endfor; ?>
    <span class="comms-muted"><?= (int)$res['total'] ?> tasks</span>
</nav>
<?php endif; endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
