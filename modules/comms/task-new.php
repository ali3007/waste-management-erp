<?php
/**
 * New task — two steps. Step 1: details. Step 2: a summary the creator confirms (or goes back to edit).
 * Nothing is saved and nobody is notified until the final "Confirm and assign". The reviewed values are held
 * in the session, so the confirm step cannot be altered by editing the page; everything is validated again on save.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
comms_require('comms.task_create');
$me = (int)Auth::id();

$templates = comms_template_list();
$reviewers = comms_eligible_users('comms.task_review', '', 200);
$convs = comms_my_conversations($me);
$errors = [];
$form = [];
$step = 1;
$draft = $_SESSION['comms_task_draft'] ?? null;

$readForm = static function (): array {
    return [
        'title' => (string)($_POST['title'] ?? ''), 'description' => (string)($_POST['description'] ?? ''), 'priority' => (string)($_POST['priority'] ?? 'medium'),
        'due_date' => (string)($_POST['due_date'] ?? ''), 'due_time' => (string)($_POST['due_time'] ?? ''), 'review_due_date' => (string)($_POST['review_due_date'] ?? ''),
        'review_due_time' => (string)($_POST['review_due_time'] ?? ''), 'reminder_minutes' => (string)($_POST['reminder_minutes'] ?? ''), 'evidence_required' => (string)($_POST['evidence_required'] ?? '0'),
        'assignees' => (array)($_POST['assignees'] ?? []), 'reviewer_id' => (string)($_POST['reviewer_id'] ?? ''), 'checklist' => array_values((array)($_POST['checklist'] ?? [])),
        'template_id' => (string)($_POST['template_id'] ?? ''), 'link_type' => (string)($_POST['link_type'] ?? ''), 'link_id' => (string)($_POST['link_id'] ?? ''), 'conversation_id' => (string)($_POST['conversation_id'] ?? ''),
    ];
};

if (is_post()) {
    Security::requireCsrf('modules/comms/task-new.php');
    $do = post('step');
    if ($do === 'review') {
        $form = $readForm();
        [$clean, $errors] = comms_task_validate($me, $form);
        if (!$errors) {
            $_SESSION['comms_task_draft'] = ['clean' => $clean, 'uuid' => bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(2)) . '-4' . substr(bin2hex(random_bytes(2)), 1) . '-8' . substr(bin2hex(random_bytes(2)), 1) . '-' . bin2hex(random_bytes(6)), 'at' => time()];
            $draft = $_SESSION['comms_task_draft'];
            $step = 2;
        }
    } elseif ($do === 'confirm') {
        if (!$draft || post('token') !== hash_hmac('sha256', $draft['uuid'], Security::csrfToken())) {
            flash('error', 'That summary is no longer valid. Please review the task again.');
            redirect('modules/comms/task-new.php');
        }
        $r = comms_task_create($me, $draft['clean'], $draft['uuid']);
        if ($r['ok']) {
            unset($_SESSION['comms_task_draft']);
            flash('success', 'Task ' . ($r['task_no'] ?? '') . ' created and assigned.');
            redirect('modules/comms/task-view.php?id=' . (int)$r['id']);
        }
        $form = $draft['clean'];
        $form['assignees'] = $draft['clean']['assignees'];
        $errors = $r['errors'] ?? ['title' => $r['message']];
        flash('error', 'Nothing was saved: ' . $r['message']);
    } elseif ($do === 'edit' && $draft) {
        $form = $draft['clean'];
    }
} elseif (query('new') === '1') {
    unset($_SESSION['comms_task_draft']);
    $draft = null;
}
if ($form === [] && !is_post() && $draft && query('resume') === '1') {
    $form = $draft['clean'];
}
if ($form === []) {
    $form = ['priority' => 'medium', 'evidence_required' => '0', 'assignees' => []];
}

$names = comms_user_names(array_merge(array_map('intval', (array)($form['assignees'] ?? [])), [(int)($form['reviewer_id'] ?? 0)]));
$people = [];
foreach ((array)($form['assignees'] ?? []) as $id) {
    $people[] = ['id' => (int)$id, 'name' => $names[(int)$id] ?? 'Employee'];
}
$linkLabel = '';
if (($form['link_type'] ?? '') !== '' && ($form['link_id'] ?? '') !== '') {
    $lr = comms_link_resolve($me, (string)$form['link_type'], (int)$form['link_id']);
    $linkLabel = $lr['label'] ?? '';
}
$GLOBALS['form'] = $form; $GLOBALS['errors'] = $errors; $GLOBALS['linkLabel'] = $linkLabel;
$mode = 'create';
$reviewerName = $names[(int)($form['reviewer_id'] ?? 0)] ?? '';

$pageTitle = 'New task';
$layout = 'app';
$extraScripts = ['comms-tasks.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<?php if ($step === 2 && $draft): $c = $draft['clean']; $hrs = comms_hours_to_deadline($c['due_date'], $c['due_time']); ?>
<section class="card">
    <h2 class="card__title">Step 2 of 2 — Review and confirm</h2>
    <p class="card__subtitle">Nothing has been saved yet. The assignees and the reviewer are notified only after you confirm.</p>
    <?php if ($hrs !== null && $hrs < 24): ?><div class="comms-banner">This deadline is less than 24 hours away.</div><?php endif; ?>
    <dl class="comms-summary">
        <dt>Title</dt><dd><?= e($c['title']) ?></dd>
        <dt>Description</dt><dd class="comms-pre"><?= e($c['description']) ?></dd>
        <dt>Priority</dt><dd><span class="badge <?= e(comms_priority_badge($c['priority'])) ?>"><?= e(COMMS_PRIORITIES[$c['priority']]) ?></span></dd>
        <dt>Deadline</dt><dd><?= e(comms_fmt_deadline($c['due_date'], $c['due_time'])) ?></dd>
        <dt>Review deadline</dt><dd><?= e($c['review_due_date'] ? comms_fmt_deadline($c['review_due_date'], $c['review_due_time']) : 'None') ?></dd>
        <dt>Advance reminder</dt><dd><?= e($c['reminder_minutes'] ? COMMS_REMINDER_CHOICES[$c['reminder_minutes']] : 'None') ?></dd>
        <dt>Assigned to</dt><dd><?= e(implode(', ', array_map(static fn($i) => $names[(int)$i] ?? 'Employee', $c['assignees']))) ?></dd>
        <dt>Reviewer</dt><dd><?= e($names[(int)$c['reviewer_id']] ?? '') ?></dd>
        <dt>Evidence</dt><dd><?= $c['evidence_required'] ? 'Required for completion' : 'Optional' ?></dd>
        <dt>Checklist</dt><dd>
            <?php if ($c['checklist']): ?><ol class="comms-ol"><?php foreach ($c['checklist'] as $it): ?><li><?= e($it['text']) ?> <small class="comms-muted"><?= $it['required'] ? '(required)' : '(optional)' ?></small></li><?php endforeach; ?></ol><?php else: ?>None — progress will be entered manually<?php endif; ?></dd>
        <dt>Linked record</dt><dd><?= e($linkLabel !== '' ? $linkLabel : 'None') ?></dd>
    </dl>
    <div class="modal__actions">
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="step" value="edit"><button type="submit" class="btn btn--ghost"><span class="btn__label">Back to edit</span></button></form>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="step" value="confirm"><input type="hidden" name="token" value="<?= e(hash_hmac('sha256', $draft['uuid'], Security::csrfToken())) ?>"><button type="submit" class="btn btn--primary" data-once><span class="btn__label">Confirm and assign</span></button></form>
    </div>
</section>
<?php else: ?>
<form method="post" class="card" novalidate>
    <?= csrf_field() ?><input type="hidden" name="step" value="review">
    <h2 class="card__title">Step 1 of 2 — Task details</h2>
    <p class="card__subtitle">You will see a summary to confirm before anything is saved.</p>
    <?php require __DIR__ . '/partials/task-form.php'; ?>
    <div class="modal__actions"><a class="btn btn--ghost" href="<?= e(base_url('modules/comms/tasks.php')) ?>">Cancel</a><button type="submit" class="btn btn--primary"><span class="btn__label">Review summary</span></button></div>
</form>
<?php endif; ?>
<?php clear_old(); require INCLUDES_PATH . '/footer.php'; ?>
