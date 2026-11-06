<?php
/**
 * Task detail. Visible only to the assigner, the assigned employees and the reviewer.
 * Each employee has their own status, progress and checklist; everyone on the task sees everyone's progress.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
comms_require();
$me = (int)Auth::id();
comms_run_scheduled();   // fire any due reminders / overdue notices (cheap; at most once a minute system-wide)
$id = (int)query('id');
$d = comms_task_detail($me, $id);
if ($d === null) {
    http_response_code(404);
    $pageTitle = 'Task not found';
    $layout = 'app';
    require INCLUDES_PATH . '/header.php';
    echo '<div class="card"><div class="empty-state"><h3>Task not found</h3><p class="comms-muted">It does not exist, or you are not the assigner, an assignee or the reviewer.</p><a class="btn btn--ghost" href="' . e(base_url('modules/comms/tasks.php')) . '">Back to tasks</a></div></div>';
    require INCLUDES_PATH . '/footer.php';
    exit;
}
$t = $d['task'];
$my = $d['my'];
$action = base_url('modules/comms/process/task-action.php');
$hasChecklist = $d['checklist'] !== null;
$myState = null;
foreach ($d['assignees'] as $a) { if ($a['is_me']) { $myState = $a; } }
$overdue = in_array($t['status'], ['active', 'partially_completed'], true) && comms_deadline_is_past($t['due_date'], $t['due_time']);
$hrs = comms_hours_to_deadline($t['due_date'], $t['due_time']);
$canWork = $my !== null && comms_can_work($my) && can('comms.task_progress');
$csrf = static fn(): string => csrf_field();

$pageTitle = comms_task_label($t) . ' · ' . $t['title'];
$layout = 'app';
$extraScripts = ['comms-tasks.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<div class="toolbar">
    <a class="btn btn--ghost" href="<?= e(base_url('modules/comms/tasks.php')) ?>">← All tasks</a>
    <div class="toolbar__spacer"></div>
    <?php if ($d['can_edit']): ?><a class="btn btn--ghost" href="<?= e(base_url('modules/comms/task-edit.php?id=' . $id)) ?>"><span class="btn__label">Edit task</span></a><?php endif; ?>
</div>

<section class="card">
    <div class="comms-taskhead">
        <div>
            <small class="comms-muted"><?= e(comms_task_label($t)) ?></small>
            <h2 class="card__title"><?= e($t['title']) ?></h2>
        </div>
        <div class="comms-badges">
            <span class="badge <?= e(comms_priority_badge($t['priority'])) ?>"><?= e(COMMS_PRIORITIES[$t['priority']]) ?></span>
            <span class="badge <?= e(comms_task_status_badge($t['status'])) ?>"><?= e(comms_task_status_label($t['status'])) ?></span>
            <?php if ($overdue): ?><span class="badge badge--blocked">Overdue</span><?php endif; ?>
        </div>
    </div>
    <?php if ($t['status'] === 'cancelled'): ?><div class="comms-banner">This task was cancelled on <?= e(comms_fmt_ts((int)strtotime((string)$t['cancelled_at']), 'd M Y, h:i A')) ?>. Reason: <?= e((string)$t['cancel_reason']) ?></div><?php endif; ?>
    <?php if ($d['reviewer_flagged']): ?><div class="comms-banner"><strong>Reviewer unavailable.</strong> The reviewer can no longer review tasks. Submissions are waiting; a new reviewer must be selected manually<?= $d['can_reassign'] ? ' (see below)' : ' by the assigner' ?>.</div><?php endif; ?>
    <?php if ($d['review_overdue']): ?><div class="comms-banner">The review deadline has passed and a submission is still waiting.</div><?php endif; ?>
    <?php if (!$overdue && $hrs !== null && $hrs >= 0 && $hrs < 24 && in_array($t['status'], ['active', 'partially_completed'], true)): ?><div class="comms-banner">Deadline is less than 24 hours away.</div><?php endif; ?>
    <p class="comms-pre"><?= e($t['description']) ?></p>
    <dl class="comms-summary">
        <dt>Deadline</dt><dd><?= e(comms_fmt_deadline($t['due_date'], $t['due_time'])) ?><?php if ($t['original_due_date'] !== $t['due_date'] || (string)$t['original_due_time'] !== (string)$t['due_time']): ?> <small class="comms-muted">(originally <?= e(comms_fmt_deadline($t['original_due_date'], $t['original_due_time'])) ?>)</small><?php endif; ?></dd>
        <dt>Assigner</dt><dd><?= e($d['assigner']) ?></dd>
        <dt>Reviewer</dt><dd><?= e($d['reviewer']) ?></dd>
        <?php if ($t['review_due_date']): ?><dt>Review deadline</dt><dd><?= e(comms_fmt_deadline($t['review_due_date'], $t['review_due_time'])) ?></dd><?php endif; ?>
        <?php if ($t['reminder_minutes']): ?><dt>Advance reminder</dt><dd><?= e(COMMS_REMINDER_CHOICES[(int)$t['reminder_minutes']] ?? '') ?></dd><?php endif; ?>
        <dt>Evidence</dt><dd><?= $t['evidence_required'] ? 'Required for completion' : 'Optional' ?></dd>
        <?php if ($d['template']): ?><dt>Template</dt><dd><?= e((string)$d['template']) ?></dd><?php endif; ?>
        <?php if ($d['link']): ?><dt>Linked record</dt><dd><?php if ($d['link']['available']): ?><a href="<?= e(base_url($d['link']['href'])) ?>"><?= e($d['link']['label']) ?></a><?php else: ?><span class="comms-muted"><?= e($d['link']['label']) ?> — <?= e($d['link']['note']) ?></span><?php endif; ?></dd><?php endif; ?>
        <?php if ($d['conversation']): ?><dt>Related chat</dt><dd><a href="<?= e(base_url('modules/comms/index.php?tab=chat')) ?>">Open Chat</a></dd><?php endif; ?>
    </dl>
</section>

<?php if ($my !== null && $t['status'] !== 'cancelled'): ?>
<section class="card">
    <h3 class="card__title">Your part</h3>
    <p><span class="badge <?= e(comms_assignee_status_badge($my['status'])) ?>"><?= e(comms_assignee_status_label($my['status'])) ?></span> <strong><?= (int)$my['progress'] ?>%</strong> complete</p>
    <?php if ($my['status'] === 'pending_acceptance'): ?>
        <div class="comms-actions">
            <form method="post" action="<?= e($action) ?>"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="accept"><button class="btn btn--primary" type="submit"><span class="btn__label">Accept task</span></button></form>
            <details><summary class="btn btn--ghost">Decline…</summary>
                <form method="post" action="<?= e($action) ?>" class="comms-inline"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="decline">
                    <textarea class="input" name="reason" rows="2" maxlength="500" required placeholder="Why are you declining? (required)"></textarea>
                    <button class="btn btn--danger" type="submit"><span class="btn__label">Decline task</span></button></form></details>
        </div>
    <?php elseif ($my['status'] === 'accepted' && can('comms.task_progress')): ?>
        <form method="post" action="<?= e($action) ?>"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="start"><button class="btn btn--primary" type="submit"><span class="btn__label">Start work</span></button></form>
    <?php endif; ?>
    <?php if ($canWork && !$hasChecklist): ?>
        <form method="post" action="<?= e($action) ?>" class="comms-inline"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="progress">
            <label class="field__label" for="pct">Update your progress</label>
            <div class="comms-progressrow"><input id="pct" type="range" min="0" max="100" step="5" name="pct" value="<?= (int)$my['progress'] ?>" data-range><output data-range-out><?= (int)$my['progress'] ?>%</output></div>
            <textarea class="input" name="comment" rows="2" maxlength="1000" required placeholder="Comment (required) — what changed?"></textarea>
            <button class="btn btn--primary" type="submit"><span class="btn__label">Save progress</span></button>
            <p class="field__hint">You can update progress at any time, also after the deadline. 100% does not complete the task: submission and independent approval come next.</p>
        </form>
    <?php endif; ?>
    <?php if ($my['status'] === 'submitted'): ?>
        <div class="comms-banner">Your completion is waiting for review by <?= e($d['reviewer']) ?>. It cannot be edited or withdrawn.</div>
    <?php endif; ?>
    <?php
    $lastRej = null;
    foreach ($d['submissions'] as $sx) { if ((int)$sx['user_id'] === $me) { if ($sx['status'] === 'rejected') { $lastRej = $sx; } break; } }
    if ($lastRej && $my['status'] === 'in_progress'): ?>
        <div class="comms-banner"><strong>Correction requested.</strong> <?= e((string)$lastRej['decision_comment']) ?> <small class="comms-muted">— <?= e((string)$lastRej['decider']) ?>, <?= e((string)$lastRej['decided_when']) ?></small></div>
    <?php endif; ?>
    <?php if (in_array($my['status'], ['accepted', 'in_progress'], true) && can('comms.task_submit') && $t['status'] !== 'cancelled'):
        $needReq = $hasChecklist && $myState && !$myState['checklist']['can_submit']; ?>
        <details class="comms-submit" <?= $lastRej ? 'open' : '' ?>><summary class="btn btn--primary">Submit completion for review…</summary>
            <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="comms-inline"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="uuid" value="<?= e(comms_uuid4()) ?>">
                <?php if ($needReq): ?><div class="comms-banner">Complete every required checklist step first (<?= (int)$myState['checklist']['req_done'] ?> of <?= (int)$myState['checklist']['req_total'] ?> done).</div><?php endif; ?>
                <textarea class="input" name="comment" rows="3" maxlength="2000" required placeholder="Completion comment (required) — what was done?"></textarea>
                <label class="field__label">Evidence <?= $t['evidence_required'] ? '<span class="field__required">* required</span>' : '(optional)' ?></label>
                <input class="input" type="file" name="evidence[]" multiple <?= $t['evidence_required'] ? 'required' : '' ?> accept="<?= e('.' . implode(',.', array_intersect(comms_allowed_extensions(), array_keys(comms_file_types())))) ?>" data-evidence-input>
                <p class="field__hint">Up to <?= (int)COMMS_EVIDENCE_MAX_FILES ?> files, <?= (int)comms_setting('comms_max_file_mb') ?> MB each. After you submit you cannot change it; the reviewer approves it or asks for a correction.</p>
                <button class="btn btn--primary" type="submit" data-once <?= $needReq ? 'disabled' : '' ?>><span class="btn__label">Submit for review</span></button>
            </form></details>
    <?php endif; ?>
    <?php if (in_array($my['status'], ['accepted', 'in_progress'], true) && $t['status'] !== 'cancelled'): ?>
        <details class="comms-submit"><summary class="btn btn--ghost">Request a deadline extension…</summary>
            <form method="post" action="<?= e($action) ?>" class="comms-inline"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="ext_request">
                <div class="comms-linkrow"><input class="input" type="date" name="date" min="<?= e(comms_today()) ?>" required><input class="input" type="time" name="time"></div>
                <textarea class="input" name="reason" rows="2" maxlength="500" required placeholder="Why do you need more time? (required)"></textarea>
                <button class="btn btn--primary" type="submit"><span class="btn__label">Send request</span></button>
                <p class="field__hint">The deadline changes only if the assigner (or an authorised approver) approves it.</p>
            </form></details>
    <?php endif; ?>
    <?php if ($my['status'] === 'declined'): ?><p class="comms-muted">You declined this task. Reason: <?= e((string)$my['decline_reason']) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <h3 class="card__title">Employees</h3>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Employee</th><th>Status</th><th class="is-num">Progress</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($d['assignees'] as $a): ?>
            <tr<?= $a['removed'] ? ' style="opacity:.55"' : '' ?>>
                <td><strong><?= e($a['name']) ?></strong><?= $a['is_me'] ? ' <small class="comms-muted">(you)</small>' : '' ?><?= $a['self'] ? ' <small class="comms-muted">self-assigned</small>' : '' ?><?= $a['removed'] ? ' <small class="comms-muted">removed</small>' : '' ?></td>
                <td><span class="badge <?= e(comms_assignee_status_badge($a['status'])) ?>"><?= e(comms_assignee_status_label($a['status'])) ?></span><?= !empty($a['correction']) ? ' <span class="badge badge--blocked">Correction requested</span>' : '' ?><?= !empty($a['overdue']) ? ' <span class="badge badge--blocked">Overdue</span>' : '' ?></td>
                <td class="is-num"><?= (int)$a['progress'] ?>%<div class="comms-bar"><i style="width:<?= (int)$a['progress'] ?>%"></i></div></td>
                <td class="is-wrap"><?php if ($a['accepted_at']): ?><small>Accepted <?= e(comms_fmt_ts((int)strtotime((string)$a['accepted_at']), 'd M, h:i A')) ?></small><?php endif; ?>
                    <?php if ($a['declined_at']): ?><small>Declined <?= e(comms_fmt_ts((int)strtotime((string)$a['declined_at']), 'd M, h:i A')) ?>: <?= e((string)$a['decline_reason']) ?></small><?php endif; ?>
                    <?php if (!empty($a['checklist']['has'])): ?><small><?= (int)$a['checklist']['req_done'] ?>/<?= (int)$a['checklist']['req_total'] ?> required steps</small><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<?php if ($hasChecklist): $cl = $d['checklist']; ?>
<section class="card">
    <h3 class="card__title">Checklist <small class="comms-muted">version <?= (int)$cl['version'] ?></small></h3>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Step</th><?php foreach ($d['assignees'] as $a): if ($a['removed']) { continue; } ?><th class="is-num"><?= e($a['name']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($cl['items'] as $i => $it): ?>
            <tr><td class="is-wrap"><?= e($it['text']) ?> <small class="comms-muted"><?= $it['required'] ? 'required' : 'optional' ?></small></td>
            <?php foreach ($d['assignees'] as $a): if ($a['removed']) { continue; } $done = $a['checklist']['items'][$i]['done'] ?? false; ?>
                <td class="is-num">
                <?php if ($a['is_me'] && $canWork): ?>
                    <form method="post" action="<?= e($action) ?>" data-step-form><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="step"><input type="hidden" name="key" value="<?= e($it['key']) ?>"><input type="hidden" name="done" value="<?= $done ? '0' : '1' ?>">
                        <label><input type="checkbox" <?= $done ? 'checked' : '' ?> data-step-box aria-label="<?= e($it['text']) ?>"><noscript><button type="submit">Save</button></noscript></label></form>
                <?php else: ?><?= $done ? '✓' : '—' ?><?php endif; ?></td>
            <?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <details><summary class="comms-muted">Checklist history (<?= count($d['versions']) ?> version<?= count($d['versions']) === 1 ? '' : 's' ?>)</summary>
        <?php foreach ($d['versions'] as $v): ?>
            <p><strong>Version <?= (int)$v['version'] ?></strong> <small class="comms-muted"><?= e(comms_fmt_ts((int)$v['ts'], 'd M Y, h:i A')) ?><?= $v['change_note'] ? ' — ' . e((string)$v['change_note']) : '' ?></small></p>
            <ol class="comms-ol"><?php foreach ($v['items'] as $it): ?><li><?= e($it['text']) ?> <small class="comms-muted"><?= $it['required'] ? '(required)' : '(optional)' ?></small></li><?php endforeach; ?></ol>
        <?php endforeach; ?>
    </details>
</section>
<?php endif; ?>

<?php if (!$hasChecklist && $d['progress']): ?>
<section class="card">
    <h3 class="card__title">Progress updates</h3>
    <?php foreach ($d['progress'] as $p): ?>
        <div class="comms-entry">
            <div><strong><?= e($p['name']) ?></strong> <?= (int)$p['old_pct'] ?>% → <strong><?= (int)$p['new_pct'] ?>%</strong> <small class="comms-muted"><?= e($p['when']) ?><?= $p['edited_at'] ? ' · edited' : '' ?></small></div>
            <?php if ($p['comment']): ?><div class="comms-pre"><?= e((string)$p['comment']) ?></div><?php endif; ?>
            <?php if ($p['can_edit']): ?><details><summary class="comms-link">Correct comment</summary>
                <form method="post" action="<?= e($action) ?>" class="comms-inline"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="progress_edit"><input type="hidden" name="progress_id" value="<?= (int)$p['id'] ?>">
                    <textarea class="input" name="comment" rows="2" maxlength="1000" required><?= e((string)$p['comment']) ?></textarea><button class="btn btn--primary" type="submit"><span class="btn__label">Save</span></button></form></details><?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($d['submissions']): ?>
<section class="card" id="submissions">
    <h3 class="card__title">Completion submissions</h3>
    <?php foreach ($d['submissions'] as $sx): ?>
        <div class="comms-entry">
            <div><strong><?= e($sx['name']) ?></strong> — round <?= (int)$sx['round_no'] ?>
                <span class="badge <?= $sx['status'] === 'approved' ? 'badge--active' : ($sx['status'] === 'rejected' ? 'badge--blocked' : 'badge--pending') ?>"><?= e(comms_submission_status_label($sx['status'])) ?></span>
                <?php if ($sx['reopen_id']): ?><span class="badge badge--pending">Correction after reopening</span><?php endif; ?>
                <?php if ($sx['reopened_at']): ?><span class="badge badge--inactive">Reopened later</span><?php endif; ?>
                <?php if ($sx['review_overdue']): ?><span class="badge badge--blocked">Review overdue</span><?php endif; ?>
                <?php if ($sx['reviewer_unavailable']): ?><span class="badge badge--blocked">Reviewer unavailable</span><?php endif; ?>
                <small class="comms-muted"><?= e($sx['when']) ?></small></div>
            <div class="comms-pre"><?= e((string)$sx['comment']) ?></div>
            <?php if ($sx['evidence_count'] > 0): ?>
                <?php if ($sx['evidence_visible']): ?><div class="comms-evlist">
                    <?php foreach ($sx['evidence'] as $ev): ?>
                        <span class="comms-file"><strong><?= e($ev['name']) ?></strong> <small class="comms-muted"><?= e(number_format($ev['size'] / 1024, 0)) ?> KB</small>
                            <?php if ($ev['inline']): ?><a class="comms-link" target="_blank" rel="noopener" href="<?= e(comms_evidence_url($me, $ev['id'], false)) ?>">Preview</a><?php endif; ?>
                            <a class="comms-link" href="<?= e(comms_evidence_url($me, $ev['id'], true)) ?>">Download</a></span>
                    <?php endforeach; ?></div>
                <?php else: ?><small class="comms-muted"><?= (int)$sx['evidence_count'] ?> evidence file<?= $sx['evidence_count'] === 1 ? '' : 's' ?> attached (you do not have permission to view evidence).</small><?php endif; ?>
            <?php endif; ?>
            <?php if ($sx['decided_when']): ?><div class="comms-muted">Decision by <?= e((string)$sx['decider']) ?>, <?= e($sx['decided_when']) ?><?= $sx['decision_comment'] ? ': ' . e((string)$sx['decision_comment']) : '' ?></div><?php endif; ?>
            <?php if ($sx['can_decide']): ?>
                <form method="post" action="<?= e($action) ?>" class="comms-inline"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="review"><input type="hidden" name="submission_id" value="<?= (int)$sx['id'] ?>">
                    <textarea class="input" name="comment" rows="2" maxlength="2000" placeholder="Comment (required if you reject)"></textarea>
                    <div class="comms-actions"><button class="btn btn--primary" type="submit" name="decision" value="approve" data-confirm-btn="Approve this completion?"><span class="btn__label">Approve</span></button>
                        <button class="btn btn--danger" type="submit" name="decision" value="reject"><span class="btn__label">Reject — request correction</span></button></div>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($d['can_reassign']): ?>
<section class="card">
    <details <?= $d['reviewer_flagged'] ? 'open' : '' ?>><summary class="btn btn--ghost">Change the reviewer…</summary>
        <form method="post" action="<?= e($action) ?>" class="comms-inline" data-confirm="Change the reviewer? Waiting submissions move to the new reviewer."><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="reassign">
            <select class="select" name="new_reviewer" required><option value="">— Select the new reviewer —</option>
                <?php foreach ($d['reviewer_choices'] as $rc): ?><option value="<?= (int)$rc['id'] ?>"><?= e($rc['name'] . ' — ' . $rc['role']) ?></option><?php endforeach; ?></select>
            <textarea class="input" name="reason" rows="2" maxlength="500" required placeholder="Reason (required)"></textarea>
            <button class="btn btn--primary" type="submit"><span class="btn__label">Change reviewer</span></button></form></details>
</section>
<?php endif; ?>

<?php if ($d['can_reopen']): ?>
<section class="card">
    <details><summary class="btn btn--ghost">Reopen for correction…</summary>
        <form method="post" action="<?= e($action) ?>" class="comms-inline" data-confirm="Reopen this task for the selected employees? Their approval stays in the history."><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="reopen">
            <div class="comms-mentions"><span class="comms-muted">Employees:</span>
                <?php foreach ($d['reopenable'] as $ra): ?><label class="comms-check"><input type="checkbox" name="employees[]" value="<?= (int)$ra['user_id'] ?>" checked> <?= e($ra['name']) ?></label><?php endforeach; ?></div>
            <textarea class="input" name="reason" rows="2" maxlength="500" required placeholder="Reason for reopening (required)"></textarea>
            <textarea class="input" name="details" rows="3" maxlength="3000" required placeholder="What must be corrected? (required)"></textarea>
            <button class="btn btn--danger" type="submit"><span class="btn__label">Reopen task</span></button></form></details>
</section>
<?php endif; ?>

<?php if ($d['extensions']): ?>
<section class="card">
    <h3 class="card__title">Deadline extension requests</h3>
    <?php foreach ($d['extensions'] as $x): ?>
        <div class="comms-entry">
            <div><strong><?= e($x['requester']) ?></strong> asked for <strong><?= e(comms_fmt_deadline($x['proposed_date'], $x['proposed_time'])) ?></strong>
                <span class="badge <?= $x['status'] === 'approved' ? 'badge--active' : ($x['status'] === 'pending' ? 'badge--pending' : 'badge--inactive') ?>"><?= e(ucfirst($x['status'])) ?></span> <small class="comms-muted"><?= e($x['when']) ?></small></div>
            <div class="comms-pre"><?= e((string)$x['reason']) ?></div>
            <?php if ($x['decider']): ?><div class="comms-muted">Decided by <?= e((string)$x['decider']) ?><?= $x['decision_comment'] ? ': ' . e((string)$x['decision_comment']) : '' ?></div><?php endif; ?>
            <?php if ($x['status'] === 'pending' && $d['can_decide_ext'] && (int)$x['requested_by'] !== $me): ?>
                <form method="post" action="<?= e($action) ?>" class="comms-inline"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="ext_decide"><input type="hidden" name="ext_id" value="<?= (int)$x['id'] ?>">
                    <textarea class="input" name="comment" rows="2" maxlength="500" placeholder="Comment (required if you reject)"></textarea>
                    <div class="comms-actions"><button class="btn btn--primary" type="submit" name="decision" value="approve"><span class="btn__label">Approve new deadline</span></button><button class="btn btn--danger" type="submit" name="decision" value="reject"><span class="btn__label">Reject</span></button></div></form>
            <?php elseif ($x['status'] === 'pending' && (int)$x['requested_by'] === $me): ?>
                <form method="post" action="<?= e($action) ?>"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="ext_cancel"><input type="hidden" name="ext_id" value="<?= (int)$x['id'] ?>"><button class="btn btn--ghost" type="submit"><span class="btn__label">Withdraw request</span></button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card" id="comments">
    <h3 class="card__title">Discussion</h3>
    <?php
    $top = array_values(array_filter($d['comments'], static fn($c) => $c['parent_id'] === null));
    $replies = [];
    foreach ($d['comments'] as $c) { if ($c['parent_id'] !== null) { $replies[(int)$c['parent_id']][] = $c; } }
    $renderComment = static function (array $c, bool $isReply) use ($action, $id, $csrf): void { ?>
        <div class="comms-entry<?= $isReply ? ' comms-entry--reply' : '' ?><?= $c['important'] ? ' is-important' : '' ?>">
            <div><strong><?= e((string)($c['name'] ?? 'Former user')) ?></strong> <small class="comms-muted"><?= e($c['when']) ?><?= $c['edited_at'] ? ' · edited' : '' ?><?= $c['important'] ? ' · important' : '' ?></small></div>
            <div class="comms-pre"><?= e($c['body']) ?></div>
            <?php if ($c['mentions']): ?><small class="comms-muted">Mentioned: <?= e(implode(', ', $c['mentions'])) ?></small><?php endif; ?>
            <div>
                <?php if (!$isReply): ?><details class="comms-inlinedet"><summary class="comms-link">Reply</summary>
                    <form method="post" action="<?= e($action) ?>" class="comms-inline"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="comment"><input type="hidden" name="parent_id" value="<?= (int)$c['id'] ?>">
                        <textarea class="input" name="body" rows="2" maxlength="4000" required></textarea><button class="btn btn--primary" type="submit"><span class="btn__label">Reply</span></button></form></details><?php endif; ?>
                <?php if ($c['can_edit']): ?><details class="comms-inlinedet"><summary class="comms-link">Edit</summary>
                    <form method="post" action="<?= e($action) ?>" class="comms-inline"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="comment_edit"><input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                        <textarea class="input" name="body" rows="3" maxlength="4000" required><?= e($c['body']) ?></textarea><button class="btn btn--primary" type="submit"><span class="btn__label">Save</span></button></form></details><?php endif; ?>
            </div>
        </div>
    <?php };
    foreach ($top as $c) { $renderComment($c, false); foreach ($replies[(int)$c['id']] ?? [] as $r) { $renderComment($r, true); } }
    if (!$top): ?><p class="comms-muted">No comments yet.</p><?php endif; ?>
    <form method="post" action="<?= e($action) ?>" class="comms-inline"><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="comment">
        <label class="field__label" for="cbody">Add a comment</label>
        <textarea class="input" id="cbody" name="body" rows="3" maxlength="4000" required></textarea>
        <div class="comms-mentions"><span class="comms-muted">Mention:</span>
            <?php foreach ($d['participants'] as $p): if ($p['id'] === $me) { continue; } ?><label class="comms-check"><input type="checkbox" name="mentions[]" value="<?= (int)$p['id'] ?>"> <?= e($p['name']) ?></label><?php endforeach; ?>
        </div>
        <label class="comms-check"><input type="checkbox" name="important" value="1"> Important — notify everyone on this task</label>
        <button class="btn btn--primary" type="submit"><span class="btn__label">Post comment</span></button>
    </form>
</section>

<?php if ($d['can_cancel']): ?>
<section class="card">
    <details><summary class="btn btn--ghost">Cancel this task…</summary>
        <form method="post" action="<?= e($action) ?>" class="comms-inline" data-confirm="Cancel this task? Assigned employees will be notified. The history is kept."><?= $csrf() ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="action" value="cancel">
            <textarea class="input" name="reason" rows="2" maxlength="500" required placeholder="Reason for cancelling (required)"></textarea>
            <button class="btn btn--danger" type="submit"><span class="btn__label">Cancel task</span></button></form></details>
</section>
<?php endif; ?>

<section class="card">
    <details><summary class="card__title" style="cursor:pointer">History</summary>
        <?php if ($d['deadline_history']): ?><h4>Deadline changes</h4>
            <?php foreach ($d['deadline_history'] as $h): ?><div class="comms-entry"><?= $h['kind'] === 'review_due' ? 'Review deadline' : 'Deadline' ?>: <?= e(comms_fmt_deadline($h['old_date'], $h['old_time'])) ?> → <strong><?= e(comms_fmt_deadline($h['new_date'], $h['new_time'])) ?></strong> <small class="comms-muted">by <?= e((string)($h['name'] ?? 'System')) ?> · <?= e($h['when']) ?> · <?= $h['source'] === 'extension' ? 'approved extension' : 'edited by assigner' ?></small></div><?php endforeach; endif; ?>
        <?php if ($d['reviewer_changes']): ?><h4>Reviewer changes</h4>
            <?php foreach ($d['reviewer_changes'] as $c): ?><div class="comms-entry"><?= e((string)($c['old_name'] ?? '—')) ?> → <strong><?= e($c['new_name']) ?></strong> <small class="comms-muted">by <?= e((string)($c['by_name'] ?? '—')) ?> · <?= e($c['when']) ?></small><div class="comms-muted"><?= e((string)$c['reason']) ?></div></div><?php endforeach; endif; ?>
        <?php if ($d['reopens']): ?><h4>Reopenings</h4>
            <?php foreach ($d['reopens'] as $r): ?><div class="comms-entry">Reopened by <?= e((string)($r['by_name'] ?? '—')) ?> <small class="comms-muted"><?= e($r['when']) ?> · <?= e(implode(', ', $r['affected_names'])) ?></small><div class="comms-muted"><?= e((string)$r['reason']) ?></div><div class="comms-pre"><?= e((string)$r['details']) ?></div></div><?php endforeach; endif; ?>
        <h4>Activity</h4>
        <?php foreach ($d['events'] as $ev):
            $new = $ev['new_values'] ? json_decode($ev['new_values'], true) : null; $old = $ev['old_values'] ? json_decode($ev['old_values'], true) : null; ?>
            <div class="comms-entry"><strong><?= e((string)($ev['name'] ?? 'System')) ?></strong> — <?= e(str_replace('_', ' ', $ev['event'])) ?> <small class="comms-muted"><?= e($ev['when']) ?></small>
                <?php if ($ev['note']): ?><div class="comms-muted"><?= e((string)$ev['note']) ?></div><?php endif; ?>
                <?php if (is_array($new) && is_array($old) && $ev['event'] === 'edited'): foreach ($new as $k => $v): if (is_array($v)) { continue; } ?><div class="comms-muted"><?= e(str_replace('_', ' ', $k)) ?>: <?= e((string)($old[$k] ?? '—')) ?> → <?= e((string)$v) ?></div><?php endforeach; endif; ?>
            </div>
        <?php endforeach; ?>
    </details>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
