<?php
/**
 * Deadlines (Phase 4): deadline history, extension requests, advance reminders, overdue notices and the
 * scheduler that fires them. All times are computed server-side in the central timezone.
 *
 * A deadline given as a date only means "end of that day" (23:59:59). The task is OVERDUE once its
 * deadline passes while it is not fully approved; it is never locked, and nothing escalates automatically.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** Unix time of a deadline (date + optional HH:MM[:SS]) in the central timezone. */
function comms_deadline_ts(string $date, ?string $time): int
{
    $t = $time !== null && $time !== '' ? substr($time, 0, 5) . ':00' : '23:59:59';
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $t, comms_tz());
    return $dt !== false ? $dt->getTimestamp() : PHP_INT_MAX;
}

function comms_task_overdue(array $t): bool
{
    return in_array($t['status'], ['active', 'partially_completed'], true) && comms_deadline_ts((string)$t['due_date'], $t['due_time']) < time();
}

/** Employees still expected to do work (not declined, cancelled, removed, approved). */
function comms_pending_employee_ids(int $taskId, bool $includeSubmitted = true): array
{
    $st = $includeSubmitted ? "('pending_acceptance','accepted','in_progress','rejected','submitted')" : "('pending_acceptance','accepted','in_progress','rejected')";
    return array_map('intval', array_column(Database::fetchAll("SELECT user_id FROM comms_task_assignees WHERE task_id = :t AND removed_at IS NULL AND status IN $st", ['t' => $taskId]), 'user_id'));
}

function comms_deadline_history_add(int $taskId, string $kind, ?string $oldD, ?string $oldT, ?string $newD, ?string $newT, ?int $by, string $source, ?int $extId = null): void
{
    Database::insert('comms_task_deadline_history', [
        'task_id' => $taskId, 'kind' => $kind, 'old_date' => $oldD, 'old_time' => $oldT !== null && $oldT !== '' ? $oldT : null,
        'new_date' => $newD, 'new_time' => $newT !== null && $newT !== '' ? $newT : null, 'changed_by' => $by, 'source' => $source, 'extension_id' => $extId,
    ]);
}

/* ------------------------------------------------------------------ *
 | Reminders                                                           |
 * ------------------------------------------------------------------ */

/** Cancel any unsent advance reminder and schedule a fresh one from the CURRENT deadline, if enabled and still in the future. */
function comms_reminders_reschedule(int $taskId): void
{
    $t = Database::fetch('SELECT id, status, due_date, due_time, reminder_minutes FROM comms_tasks WHERE id = :i', ['i' => $taskId]);
    if ($t === null) {
        return;
    }
    Database::execute('UPDATE comms_task_reminders SET cancelled_at = NOW() WHERE task_id = :t AND sent_at IS NULL AND cancelled_at IS NULL', ['t' => $taskId]);
    if (!in_array($t['status'], ['active', 'partially_completed'], true) || empty($t['reminder_minutes'])) {
        return;
    }
    $deadline = comms_deadline_ts((string)$t['due_date'], $t['due_time']);
    $fire = $deadline - (int)$t['reminder_minutes'] * 60;
    if ($fire > time()) {
        Database::insert('comms_task_reminders', ['task_id' => $taskId, 'fire_ts' => $fire, 'minutes' => (int)$t['reminder_minutes'], 'deadline_ts' => $deadline]);
    }
}

function comms_reminder_label(int $minutes): string
{
    return isset(COMMS_REMINDER_CHOICES[$minutes]) ? str_replace(' before', '', COMMS_REMINDER_CHOICES[$minutes]) : ($minutes . ' minutes');
}

/** Send due reminders once. Skipped when the task is finished, cancelled, or its deadline has since changed. */
function comms_job_reminders(): int
{
    $sent = 0;
    $rows = Database::fetchAll(
        "SELECT r.id, r.task_id, r.minutes, r.deadline_ts, t.task_no, t.status, t.due_date, t.due_time, t.assigner_id
           FROM comms_task_reminders r JOIN comms_tasks t ON t.id = r.task_id
          WHERE r.sent_at IS NULL AND r.cancelled_at IS NULL AND r.fire_ts <= :now LIMIT 200",
        ['now' => time()]
    );
    foreach ($rows as $r) {
        // Claim it first so two overlapping runs can never both send it.
        if (Database::execute('UPDATE comms_task_reminders SET sent_at = NOW() WHERE id = :i AND sent_at IS NULL AND cancelled_at IS NULL', ['i' => $r['id']]) !== 1) {
            continue;
        }
        if (!in_array($r['status'], ['active', 'partially_completed'], true) || (int)$r['deadline_ts'] !== comms_deadline_ts((string)$r['due_date'], $r['due_time'])) {
            continue;
        }
        $emps = comms_pending_employee_ids((int)$r['task_id']);
        if (!$emps) {
            continue;   // everyone is already approved/declined: nothing to remind about
        }
        $sent += comms_notify(array_merge($emps, [(int)$r['assigner_id']]), 'task.reminder', 'Reminder: task ' . $r['task_no'] . ' is due in ' . comms_reminder_label((int)$r['minutes']), 'task', (int)$r['task_id'], 'high');
    }
    return $sent;
}

/** One notice per deadline when a task becomes overdue (not repeated; reset when the deadline changes). */
function comms_job_overdue(): int
{
    $n = 0;
    $rows = Database::fetchAll("SELECT id, task_no, due_date, due_time, assigner_id FROM comms_tasks WHERE status IN ('active','partially_completed') AND overdue_notified_at IS NULL AND due_date <= :d LIMIT 500", ['d' => comms_today()]);
    foreach ($rows as $r) {
        if (comms_deadline_ts((string)$r['due_date'], $r['due_time']) >= time()) {
            continue;
        }
        if (Database::execute('UPDATE comms_tasks SET overdue_notified_at = NOW() WHERE id = :i AND overdue_notified_at IS NULL', ['i' => $r['id']]) !== 1) {
            continue;
        }
        $emps = comms_pending_employee_ids((int)$r['id'], false);
        $n += comms_notify(array_merge($emps, [(int)$r['assigner_id']]), 'task.overdue', 'Task ' . $r['task_no'] . ' is overdue', 'task', (int)$r['id'], 'high');
    }
    return $n;
}

/** Review deadline missed while a submission is still waiting: tell the reviewer and the assigner once. */
function comms_job_review_overdue(): int
{
    $n = 0;
    $rows = Database::fetchAll(
        "SELECT s.id, s.reviewer_id, t.id AS task_id, t.task_no, t.review_due_date, t.review_due_time, t.assigner_id
           FROM comms_task_submissions s JOIN comms_tasks t ON t.id = s.task_id
          WHERE s.status = 'pending_review' AND s.review_overdue_notified_at IS NULL AND t.review_due_date IS NOT NULL AND t.review_due_date <= :d AND t.status <> 'cancelled' LIMIT 500",
        ['d' => comms_today()]
    );
    foreach ($rows as $r) {
        if (comms_deadline_ts((string)$r['review_due_date'], $r['review_due_time']) >= time()) {
            continue;
        }
        if (Database::execute('UPDATE comms_task_submissions SET review_overdue_notified_at = NOW() WHERE id = :i AND review_overdue_notified_at IS NULL', ['i' => $r['id']]) !== 1) {
            continue;
        }
        $n += comms_notify([(int)$r['reviewer_id'], (int)$r['assigner_id']], 'task.review_overdue', 'Review for task ' . $r['task_no'] . ' is overdue', 'task', (int)$r['task_id'], 'high');
    }
    return $n;
}

/**
 * Run all due jobs. Called opportunistically from normal page activity (at most once a minute across the
 * whole system, guarded by a database lock) and by tools/comms-cron.php for sites that prefer a real scheduler.
 */
function comms_run_scheduled(bool $force = false): array
{
    static $ran = false;
    if ($ran && !$force) {
        return ['skipped' => true];
    }
    $ran = true;
    if (!$force) {
        $last = (int)Database::fetchValue("SELECT setting_value FROM system_settings WHERE setting_key = 'comms_cron_last'");
        if (time() - $last < 60) {
            return ['skipped' => true];
        }
    }
    if ((string)Database::fetchValue("SELECT GET_LOCK('swms_comms_cron', 0)") !== '1') {
        return ['skipped' => true];
    }
    try {
        Database::execute("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('comms_cron_last', :v, 'comms') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", ['v' => (string)time()]);
        $out = ['reminders' => comms_job_reminders(), 'overdue' => comms_job_overdue(), 'review_overdue' => comms_job_review_overdue(), 'reviewer_flags' => comms_job_reviewer_flags()];
    } catch (Throwable $e) {
        log_event('comms', 'scheduled jobs failed', ['error' => $e->getMessage()]);
        $out = ['error' => true];
    } finally {
        Database::query("SELECT RELEASE_LOCK('swms_comms_cron')");
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 | Extension requests                                                  |
 * ------------------------------------------------------------------ */

/** The assigner, or a participant who holds comms.deadline_extend. */
function comms_can_decide_extension(int $me, array $t): bool
{
    if ((int)$t['assigner_id'] === $me) {
        return true;
    }
    return !empty($t['_roles']) && comms_actor_can($me, 'comms.deadline_extend');
}

function comms_extension_request(int $me, int $taskId, string $date, string $time, string $reason): array
{
    $t = comms_task_get($me, $taskId);
    $a = $t ? comms_my_assignment($me, $taskId) : null;
    if ($t === null || $a === null || !comms_actor_can($me, 'comms.access')) {
        return comms_res(false, 'Only an employee assigned to this task can request an extension.');
    }
    if (!in_array($t['status'], ['active', 'partially_completed'], true) || !in_array($a['status'], ['accepted', 'in_progress'], true)) {
        return comms_res(false, $a['status'] === 'pending_acceptance' ? 'Accept the task before requesting an extension.' : 'An extension cannot be requested in the current state.');
    }
    if (!comms_valid_date($date) || ($time !== '' && !comms_valid_time($time))) {
        return comms_res(false, 'Enter a valid proposed date and time.');
    }
    $time = $time !== '' ? $time : null;
    $proposed = comms_deadline_ts($date, $time);
    if ($proposed <= time()) {
        return comms_res(false, 'The proposed deadline cannot be in the past.');
    }
    if ($proposed <= comms_deadline_ts((string)$t['due_date'], $t['due_time'])) {
        return comms_res(false, 'The proposed deadline must be later than the current deadline.');
    }
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
        return comms_res(false, 'Give a reason of 5 to 500 characters.');
    }
    if (Database::exists("SELECT 1 FROM comms_task_extensions WHERE task_id = :t AND requested_by = :u AND status = 'pending'", ['t' => $taskId, 'u' => $me])) {
        return comms_res(false, 'You already have a pending extension request on this task.');
    }
    $id = Database::insert('comms_task_extensions', ['task_id' => $taskId, 'requested_by' => $me, 'proposed_date' => $date, 'proposed_time' => $time, 'reason' => $reason]);
    comms_task_event($taskId, $me, 'extension_requested', null, ['extension_id' => $id, 'proposed' => $date . ($time ? ' ' . $time : '')], $reason);
    $approvers = [(int)$t['assigner_id']];
    foreach (comms_task_participants($taskId) as $p) {
        if ($p['id'] !== $me && comms_actor_can($p['id'], 'comms.deadline_extend')) {
            $approvers[] = $p['id'];
        }
    }
    comms_notify($approvers, 'task.extension_requested', 'Deadline extension requested for task ' . comms_task_label($t), 'task', $taskId, 'high', $me);
    return comms_res(true, '', ['id' => $id]);
}

function comms_extension_cancel(int $me, int $extId): array
{
    $e = Database::fetch('SELECT * FROM comms_task_extensions WHERE id = :i', ['i' => $extId]);
    if ($e === null || (int)$e['requested_by'] !== $me || $e['status'] !== 'pending' || comms_task_get($me, (int)$e['task_id']) === null) {
        return comms_res(false, 'This request cannot be withdrawn.');
    }
    Database::execute("UPDATE comms_task_extensions SET status = 'cancelled', decided_at = NOW() WHERE id = :i AND status = 'pending'", ['i' => $extId]);
    comms_task_event((int)$e['task_id'], $me, 'extension_withdrawn', null, ['extension_id' => $extId]);
    return comms_res(true);
}

function comms_extension_decide(int $me, int $extId, bool $approve, string $comment): array
{
    $e = Database::fetch('SELECT * FROM comms_task_extensions WHERE id = :i', ['i' => $extId]);
    $t = $e ? comms_task_get($me, (int)$e['task_id']) : null;
    if ($e === null || $t === null || !comms_can_decide_extension($me, $t) || !comms_actor_can($me, 'comms.access')) {
        return comms_res(false, 'You cannot decide this request.');
    }
    if ((int)$e['requested_by'] === $me) {
        return comms_res(false, 'You cannot decide your own request.');
    }
    if ($e['status'] !== 'pending') {
        return comms_res(false, 'This request has already been decided.');
    }
    if (!in_array($t['status'], ['active', 'partially_completed'], true)) {
        return comms_res(false, 'The task is no longer open.');
    }
    $comment = trim(preg_replace('/\s+/u', ' ', $comment) ?? '');
    if (!$approve && (mb_strlen($comment) < 5 || mb_strlen($comment) > 500)) {
        return comms_res(false, 'Give a reason of 5 to 500 characters when rejecting.');
    }
    if ($approve && mb_strlen($comment) > 500) {
        return comms_res(false, 'The comment is too long.');
    }
    $label = comms_task_label($t);
    if (!$approve) {
        Database::execute("UPDATE comms_task_extensions SET status = 'rejected', decided_by = :u, decided_at = NOW(), decision_comment = :c WHERE id = :i AND status = 'pending'", ['u' => $me, 'c' => $comment, 'i' => $extId]);
        comms_task_event((int)$t['id'], $me, 'extension_rejected', null, ['extension_id' => $extId], $comment);
        comms_notify([(int)$e['requested_by']], 'task.extension_rejected', 'Your extension request for task ' . $label . ' was rejected', 'task', (int)$t['id'], 'high', $me);
        return comms_res(true);
    }
    $newTs = comms_deadline_ts((string)$e['proposed_date'], $e['proposed_time']);
    if ($newTs <= time()) {
        return comms_res(false, 'The proposed deadline has already passed. Ask the employee to propose a later one, or reject this request.');
    }
    if ($newTs <= comms_deadline_ts((string)$t['due_date'], $t['due_time'])) {
        return comms_res(false, 'The task deadline is already later than the proposed one.');
    }
    Database::beginTransaction();
    try {
        $ok = Database::execute("UPDATE comms_task_extensions SET status = 'approved', decided_by = :u, decided_at = NOW(), decision_comment = :c, old_due_date = :od, old_due_time = :ot WHERE id = :i AND status = 'pending'",
            ['u' => $me, 'c' => $comment !== '' ? $comment : null, 'od' => $t['due_date'], 'ot' => $t['due_time'], 'i' => $extId]);
        if ($ok !== 1) {
            Database::rollBack();
            return comms_res(false, 'This request has already been decided.');
        }
        Database::execute('UPDATE comms_tasks SET due_date = :d, due_time = :tm, overdue_notified_at = NULL WHERE id = :i', ['d' => $e['proposed_date'], 'tm' => $e['proposed_time'], 'i' => $t['id']]);
        comms_deadline_history_add((int)$t['id'], 'due', $t['due_date'], $t['due_time'], $e['proposed_date'], $e['proposed_time'], $me, 'extension', $extId);
        comms_task_event((int)$t['id'], $me, 'extension_approved', ['due' => $t['due_date'] . ($t['due_time'] ? ' ' . substr($t['due_time'], 0, 5) : '')], ['due' => $e['proposed_date'] . ($e['proposed_time'] ? ' ' . substr($e['proposed_time'], 0, 5) : ''), 'extension_id' => $extId], $comment);
        Database::commit();
    } catch (Throwable $ex) {
        Database::rollBack();
        log_event('comms', 'extension approve failed', ['error' => $ex->getMessage()]);
        return comms_res(false, 'The decision could not be saved.');
    }
    comms_reminders_reschedule((int)$t['id']);
    $notify = array_merge(comms_pending_employee_ids((int)$t['id']), [(int)$e['requested_by'], (int)$t['assigner_id'], (int)$t['reviewer_id']]);
    comms_notify($notify, 'task.deadline_changed', 'The deadline of task ' . $label . ' was extended', 'task', (int)$t['id'], 'high', $me);
    comms_audit_safe('task.extension_approved', 'Deadline extension approved', ['actor_id' => $me, 'subject_type' => 'task', 'subject_id' => (int)$t['id'], 'old' => ['due' => $t['due_date']], 'new' => ['due' => $e['proposed_date']]]);
    return comms_res(true);
}

/** Everything the task page shows about deadlines. */
function comms_task_deadline_view(int $me, array $t): array
{
    $ext = Database::fetchAll('SELECT x.*, u.name AS requester, d.name AS decider, UNIX_TIMESTAMP(x.created_at) AS ts FROM comms_task_extensions x JOIN users u ON u.id = x.requested_by LEFT JOIN users d ON d.id = x.decided_by WHERE x.task_id = :t ORDER BY x.id DESC', ['t' => $t['id']]);
    foreach ($ext as &$x) { $x['when'] = comms_fmt_ts((int)$x['ts'], 'd M Y, h:i A'); }
    $hist = Database::fetchAll('SELECT h.*, u.name, UNIX_TIMESTAMP(h.created_at) AS ts FROM comms_task_deadline_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.task_id = :t ORDER BY h.id DESC', ['t' => $t['id']]);
    foreach ($hist as &$h) { $h['when'] = comms_fmt_ts((int)$h['ts'], 'd M Y, h:i A'); }
    $t['_roles'] = $t['_roles'] ?? [];
    return ['extensions' => $ext, 'deadline_history' => $hist, 'can_decide_ext' => comms_can_decide_extension($me, $t) && in_array($t['status'], ['active', 'partially_completed'], true)];
}
