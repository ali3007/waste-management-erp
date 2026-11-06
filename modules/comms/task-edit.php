<?php
/** Edit a task (assigner with comms.task_edit). Records old/new values and notifies affected employees. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
comms_require();
$me = (int)Auth::id();
$id = (int)query('id', (string)post('id'));
$t = comms_task_get($me, $id);
if ($t === null || !comms_task_can_edit($me, $t)) {
    http_response_code(403);
    $pageTitle = 'Not available';
    $layout = 'app';
    require INCLUDES_PATH . '/header.php';
    echo '<div class="card"><div class="empty-state"><h3>This task cannot be edited</h3><p>It may be completed or cancelled, or you may not be its assigner.</p><a class="btn btn--ghost" href="' . e(base_url('modules/comms/tasks.php')) . '">Back to tasks</a></div></div>';
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$cl = comms_checklist_current($id);
$assigneeIds = array_map(static fn($r) => (int)$r['user_id'], Database::fetchAll('SELECT user_id FROM comms_task_assignees WHERE task_id = :t AND removed_at IS NULL', ['t' => $id]));
$form = [
    'title' => $t['title'], 'description' => $t['description'], 'priority' => $t['priority'], 'due_date' => $t['due_date'], 'due_time' => (string)$t['due_time'],
    'review_due_date' => (string)$t['review_due_date'], 'review_due_time' => (string)$t['review_due_time'], 'reminder_minutes' => (string)$t['reminder_minutes'],
    'evidence_required' => (string)$t['evidence_required'], 'assignees' => $assigneeIds, 'checklist' => $cl['items'] ?? [], 'template_id' => (string)$t['template_id'],
    'link_type' => (string)$t['link_type'], 'link_id' => (string)$t['link_id'], 'conversation_id' => (string)$t['conversation_id'],
];
$errors = [];
if (is_post()) {
    Security::requireCsrf('modules/comms/task-edit.php?id=' . $id);
    $form = [
        'title' => (string)($_POST['title'] ?? ''), 'description' => (string)($_POST['description'] ?? ''), 'priority' => (string)($_POST['priority'] ?? ''), 'due_date' => (string)($_POST['due_date'] ?? ''),
        'due_time' => (string)($_POST['due_time'] ?? ''), 'review_due_date' => (string)($_POST['review_due_date'] ?? ''), 'review_due_time' => (string)($_POST['review_due_time'] ?? ''),
        'reminder_minutes' => (string)($_POST['reminder_minutes'] ?? ''), 'evidence_required' => (string)($_POST['evidence_required'] ?? '0'), 'assignees' => (array)($_POST['assignees'] ?? []),
        'checklist' => array_values((array)($_POST['checklist'] ?? [])), 'template_id' => (string)$t['template_id'], 'link_type' => (string)($_POST['link_type'] ?? ''), 'link_id' => (string)($_POST['link_id'] ?? ''),
        'conversation_id' => (string)($_POST['conversation_id'] ?? ''),
    ];
    $r = comms_task_edit($me, $id, $form);
    if ($r['ok']) {
        flash('success', !empty($r['changed']) ? 'Task updated. Affected employees were notified.' : 'No changes to save.');
        redirect('modules/comms/task-view.php?id=' . $id);
    }
    $errors = $r['errors'] ?? ['title' => $r['message']];
    flash('error', $r['message']);
}
$names = comms_user_names(array_merge(array_map('intval', (array)$form['assignees']), [(int)$t['reviewer_id']]));
$people = array_map(static fn($i) => ['id' => (int)$i, 'name' => $names[(int)$i] ?? 'Employee'], (array)$form['assignees']);
$lr = $form['link_type'] !== '' && $form['link_id'] !== '' ? comms_link_resolve($me, $form['link_type'], (int)$form['link_id']) : null;
$GLOBALS['form'] = $form; $GLOBALS['errors'] = $errors; $GLOBALS['linkLabel'] = $lr['label'] ?? '';
$mode = 'edit'; $templates = []; $reviewers = []; $convs = comms_my_conversations($me); $reviewerName = $names[(int)$t['reviewer_id']] ?? '';

$pageTitle = 'Edit ' . comms_task_label($t);
$layout = 'app';
$extraScripts = ['comms-tasks.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<form method="post" class="card" novalidate data-confirm="Save these changes? Affected employees will be notified.">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
    <h2 class="card__title">Edit task <?= e(comms_task_label($t)) ?></h2>
    <p class="card__subtitle">Every change is recorded with the old and new values. Changing the checklist creates a new version.</p>
    <?php require __DIR__ . '/partials/task-form.php'; ?>
    <div class="modal__actions"><a class="btn btn--ghost" href="<?= e(comms_task_url($id)) ?>">Cancel</a><button type="submit" class="btn btn--primary"><span class="btn__label">Save changes</span></button></div>
</form>
<?php require INCLUDES_PATH . '/footer.php'; ?>
