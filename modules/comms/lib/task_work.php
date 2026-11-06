<?php
/**
 * Task work (Phase 3): per-employee acceptance, progress, checklist ticks, comments and mentions.
 * Every employee has an independent workflow: one person's decision, progress or checklist never
 * touches another person's. Progress is allowed at any time, including after the deadline.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const COMMS_MILESTONES = [25, 50, 75, 100];

/** The actor's own active assignee row, with the task, or null. */
function comms_my_assignment(int $me, int $taskId): ?array
{
    return Database::fetch(
        "SELECT a.*, t.status AS task_status, t.assigner_id, t.reviewer_id, t.task_no, t.id AS tid, t.priority
           FROM comms_task_assignees a JOIN comms_tasks t ON t.id = a.task_id
          WHERE a.task_id = :t AND a.user_id = :u AND a.removed_at IS NULL",
        ['t' => $taskId, 'u' => $me]
    );
}

/* ---------------- acceptance ---------------- */

function comms_task_accept(int $me, int $taskId): array
{
    $a = comms_my_assignment($me, $taskId);
    if ($a === null || !comms_actor_can($me, 'comms.access')) {
        return comms_res(false, 'This task is not assigned to you.');
    }
    if ($a['task_status'] === 'cancelled') {
        return comms_res(false, 'This task was cancelled.');
    }
    if ($a['status'] !== 'pending_acceptance') {
        return comms_res(false, 'You have already responded to this task.');
    }
    Database::execute("UPDATE comms_task_assignees SET status = 'accepted', accepted_at = NOW() WHERE id = :i AND status = 'pending_acceptance'", ['i' => $a['id']]);
    comms_task_event($taskId, $me, 'accepted', ['status' => 'pending_acceptance'], ['status' => 'accepted']);
    comms_notify([(int)$a['assigner_id']], 'task.accepted', 'Task ' . $a['task_no'] . ' was accepted', 'task', $taskId, 'normal', $me);
    return comms_res(true);
}

function comms_task_decline(int $me, int $taskId, string $reason): array
{
    $a = comms_my_assignment($me, $taskId);
    if ($a === null || !comms_actor_can($me, 'comms.access')) {
        return comms_res(false, 'This task is not assigned to you.');
    }
    if ($a['task_status'] === 'cancelled') {
        return comms_res(false, 'This task was cancelled.');
    }
    if ($a['status'] !== 'pending_acceptance') {
        return comms_res(false, 'You have already responded to this task.');
    }
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
        return comms_res(false, 'Give a reason of 5 to 500 characters.');
    }
    Database::execute("UPDATE comms_task_assignees SET status = 'declined', declined_at = NOW(), decline_reason = :r WHERE id = :i AND status = 'pending_acceptance'", ['r' => $reason, 'i' => $a['id']]);
    comms_task_event($taskId, $me, 'declined', ['status' => 'pending_acceptance'], ['status' => 'declined'], $reason);
    comms_task_recompute($taskId);
    comms_notify([(int)$a['assigner_id']], 'task.declined', 'Task ' . $a['task_no'] . ' was declined', 'task', $taskId, 'high', $me);
    return comms_res(true);
}

function comms_task_start(int $me, int $taskId): array
{
    $a = comms_my_assignment($me, $taskId);
    if ($a === null || !comms_actor_can($me, 'comms.task_progress')) {
        return comms_res(false, 'You cannot start this task.');
    }
    if ($a['task_status'] === 'cancelled' || $a['status'] !== 'accepted') {
        return comms_res(false, $a['status'] === 'pending_acceptance' ? 'Accept the task first.' : 'This task cannot be started now.');
    }
    Database::execute("UPDATE comms_task_assignees SET status = 'in_progress', started_at = NOW() WHERE id = :i AND status = 'accepted'", ['i' => $a['id']]);
    comms_task_event($taskId, $me, 'started', ['status' => 'accepted'], ['status' => 'in_progress']);
    return comms_res(true);
}

/** May this assignee update progress now? Allowed at any time (also after the deadline) while the work is theirs to do. */
function comms_can_work(array $a): bool
{
    return $a['task_status'] !== 'cancelled' && in_array($a['status'], ['accepted', 'in_progress', 'rejected'], true);
}

/* ---------------- progress ---------------- */

function comms_checklist_state(int $taskId, int $userId, ?array $cl = null): array
{
    $cl ??= comms_checklist_current($taskId);
    if ($cl === null) {
        return ['has' => false, 'items' => [], 'done' => 0, 'total' => 0, 'req_done' => 0, 'req_total' => 0, 'pct' => 0, 'version' => 0, 'can_submit' => true];
    }
    $latest = [];
    foreach (Database::fetchAll('SELECT s.item_key, s.done FROM comms_task_step_log s JOIN (SELECT item_key, MAX(id) AS mid FROM comms_task_step_log WHERE task_id = :t AND user_id = :u GROUP BY item_key) m ON m.mid = s.id', ['t' => $taskId, 'u' => $userId]) as $r) {
        $latest[$r['item_key']] = (bool)$r['done'];
    }
    $items = [];
    $done = $reqDone = $reqTotal = 0;
    foreach ($cl['items'] as $it) {
        $d = $latest[$it['key']] ?? false;
        $items[] = $it + ['done' => $d];
        $done += $d ? 1 : 0;
        if ($it['required']) { $reqTotal++; $reqDone += $d ? 1 : 0; }
    }
    $total = count($items);
    // Percent of REQUIRED steps done (all steps if none is required), against the latest version.
    $pct = $reqTotal > 0 ? (int)round(100 * $reqDone / $reqTotal) : ($total > 0 ? (int)round(100 * $done / $total) : 0);
    return ['has' => true, 'items' => $items, 'done' => $done, 'total' => $total, 'req_done' => $reqDone, 'req_total' => $reqTotal, 'pct' => $pct, 'version' => $cl['version'], 'can_submit' => $reqDone === $reqTotal];
}

function comms_progress_log(int $taskId, int $userId, int $old, int $new, string $kind, ?string $comment, ?int $by): int
{
    return Database::insert('comms_task_progress', ['task_id' => $taskId, 'user_id' => $userId, 'old_pct' => $old, 'new_pct' => $new, 'kind' => $kind, 'comment' => $comment !== null ? mb_substr($comment, 0, 1000) : null, 'created_by' => $by]);
}

function comms_milestone_notify(array $a, int $old, int $new, int $me): void
{
    $hit = array_filter(COMMS_MILESTONES, static fn(int $m): bool => $old < $m && $new >= $m);
    if (!$hit) {
        return;
    }
    $m = max($hit);   // one notification even if several thresholds were crossed at once
    comms_notify([(int)$a['assigner_id'], (int)$a['reviewer_id']], 'task.milestone', 'Task ' . $a['task_no'] . ': progress reached ' . $m . '%', 'task', (int)$a['task_id'], 'normal', $me);
}

function comms_task_progress_update(int $me, int $taskId, int $pct, string $comment): array
{
    $a = comms_my_assignment($me, $taskId);
    if ($a === null || !comms_actor_can($me, 'comms.task_progress')) {
        return comms_res(false, 'You cannot update progress on this task.');
    }
    if (!comms_can_work($a)) {
        return comms_res(false, $a['status'] === 'pending_acceptance' ? 'Accept the task before updating progress.' : 'Progress cannot be updated in the current state.');
    }
    if (comms_checklist_current($taskId) !== null) {
        return comms_res(false, 'This task has a checklist: progress follows the completed steps.');
    }
    $comment = trim(comms_clean_body($comment));
    if ($comment === '' || mb_strlen($comment) > 1000) {
        return comms_res(false, 'Add a comment (up to 1,000 characters) with the progress update.');
    }
    if ($pct < 0 || $pct > 100) {
        return comms_res(false, 'Progress must be between 0 and 100.');
    }
    $old = (int)$a['progress'];
    Database::beginTransaction();
    try {
        comms_progress_log($taskId, $me, $old, $pct, 'manual', $comment, $me);
        Database::execute('UPDATE comms_task_assignees SET progress = :p WHERE id = :i', ['p' => $pct, 'i' => $a['id']]);
        if ($a['status'] === 'accepted') {
            Database::execute("UPDATE comms_task_assignees SET status = 'in_progress', started_at = COALESCE(started_at, NOW()) WHERE id = :i", ['i' => $a['id']]);
        }
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        return comms_res(false, 'The update could not be saved.');
    }
    comms_milestone_notify($a + ['task_id' => $taskId], $old, $pct, $me);
    return comms_res(true, '', ['progress' => $pct]);
}

function comms_task_step_toggle(int $me, int $taskId, string $key, bool $done): array
{
    $a = comms_my_assignment($me, $taskId);
    if ($a === null || !comms_actor_can($me, 'comms.task_progress')) {
        return comms_res(false, 'You cannot update this checklist.');
    }
    if (!comms_can_work($a)) {
        return comms_res(false, $a['status'] === 'pending_acceptance' ? 'Accept the task before ticking steps.' : 'The checklist cannot be updated in the current state.');
    }
    $cl = comms_checklist_current($taskId);
    if ($cl === null || !in_array($key, array_column($cl['items'], 'key'), true)) {
        return comms_res(false, 'That step is not part of the current checklist.');
    }
    $before = comms_checklist_state($taskId, $me, $cl);
    $was = null;
    foreach ($before['items'] as $it) { if ($it['key'] === $key) { $was = $it['done']; } }
    if ($was === $done) {
        return comms_res(true, '', ['progress' => $before['pct']]);
    }
    Database::beginTransaction();
    try {
        Database::insert('comms_task_step_log', ['task_id' => $taskId, 'user_id' => $me, 'item_key' => $key, 'done' => $done ? 1 : 0, 'version' => $cl['version']]);
        $after = comms_checklist_state($taskId, $me, $cl);
        $old = (int)$a['progress'];
        if ($after['pct'] !== $old) {
            comms_progress_log($taskId, $me, $old, $after['pct'], 'checklist', null, $me);
        }
        Database::execute('UPDATE comms_task_assignees SET progress = :p WHERE id = :i', ['p' => $after['pct'], 'i' => $a['id']]);
        if ($a['status'] === 'accepted') {
            Database::execute("UPDATE comms_task_assignees SET status = 'in_progress', started_at = COALESCE(started_at, NOW()) WHERE id = :i", ['i' => $a['id']]);
        }
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        return comms_res(false, 'The step could not be saved.');
    }
    comms_milestone_notify($a + ['task_id' => $taskId], $old, $after['pct'], $me);
    return comms_res(true, '', ['progress' => $after['pct']]);
}

/** After a checklist version change: re-evaluate every assignee against the latest version. May lower percentages (expected). */
function comms_task_recalc_progress(int $taskId, int $by): void
{
    $cl = comms_checklist_current($taskId);
    foreach (Database::fetchAll('SELECT id, user_id, progress FROM comms_task_assignees WHERE task_id = :t AND removed_at IS NULL', ['t' => $taskId]) as $a) {
        $new = $cl === null ? (int)$a['progress'] : comms_checklist_state($taskId, (int)$a['user_id'], $cl)['pct'];
        if ($new !== (int)$a['progress']) {
            comms_progress_log($taskId, (int)$a['user_id'], (int)$a['progress'], $new, 'system', 'Recalculated after the checklist changed (version ' . ($cl['version'] ?? 0) . ')', $by);
            Database::execute('UPDATE comms_task_assignees SET progress = :p WHERE id = :i', ['p' => $new, 'i' => $a['id']]);
        }
    }
}

/* ---------------- editing own progress comment / comments ---------------- */

function comms_within_author_window(int $createdTs): bool
{
    return comms_in_edit_window($createdTs);
}

function comms_progress_comment_edit(int $me, int $progressId, string $comment): array
{
    $p = Database::fetch('SELECT *, UNIX_TIMESTAMP(created_at) AS ts FROM comms_task_progress WHERE id = :i', ['i' => $progressId]);
    $t = $p ? comms_task_get($me, (int)$p['task_id']) : null;
    if ($p === null || $t === null || $p['kind'] !== 'manual') {
        return comms_res(false, 'Progress update not found.');
    }
    $own = (int)$p['created_by'] === $me && comms_within_author_window((int)$p['ts']);
    $authorised = (int)$t['assigner_id'] === $me && comms_actor_can($me, 'comms.task_edit');
    if (!$own && !$authorised) {
        return comms_res(false, (int)$p['created_by'] === $me ? 'The time limit for editing this update has passed. Ask the assigner to correct it.' : 'You cannot edit this update.');
    }
    $comment = trim(comms_clean_body($comment));
    if ($comment === '' || mb_strlen($comment) > 1000) {
        return comms_res(false, 'Enter a comment up to 1,000 characters.');
    }
    if ($comment === (string)$p['comment']) {
        return comms_res(true);
    }
    Database::insert('comms_task_edits', ['entity_type' => 'progress', 'entity_id' => $progressId, 'old_text' => (string)$p['comment'], 'new_text' => $comment, 'edited_by' => $me]);
    Database::execute('UPDATE comms_task_progress SET comment = :c, edited_at = NOW() WHERE id = :i', ['c' => $comment, 'i' => $progressId]);
    comms_task_event((int)$p['task_id'], $me, 'progress_comment_edited', null, ['progress_id' => $progressId]);
    return comms_res(true);
}

/* ---------------- comments and mentions ---------------- */

/** Participants who can be @mentioned / who may read comments: assigner, active assignees, reviewer. */
function comms_task_participants(int $taskId): array
{
    $t = Database::fetch('SELECT assigner_id, reviewer_id FROM comms_tasks WHERE id = :i', ['i' => $taskId]);
    if ($t === null) {
        return [];
    }
    $ids = array_column(Database::fetchAll('SELECT user_id FROM comms_task_assignees WHERE task_id = :t AND removed_at IS NULL', ['t' => $taskId]), 'user_id');
    $ids[] = $t['assigner_id'];
    $ids[] = $t['reviewer_id'];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $names = comms_user_names($ids);
    $out = [];
    foreach ($ids as $i) {
        $out[] = ['id' => $i, 'name' => $names[$i] ?? 'User'];
    }
    usort($out, static fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

function comms_comment_add(int $me, int $taskId, string $body, int $parentId = 0, bool $important = false, array $mentionIds = []): array
{
    $t = comms_task_get($me, $taskId);
    if ($t === null || !comms_actor_can($me, 'comms.access')) {
        return comms_res(false, 'You cannot comment on this task.');
    }
    $body = comms_clean_body($body);
    if ($body === '' || mb_strlen($body) > 4000) {
        return comms_res(false, 'Write a comment of up to 4,000 characters.');
    }
    $parent = null;
    if ($parentId > 0) {
        $parent = Database::fetch('SELECT id, user_id, parent_id FROM comms_task_comments WHERE id = :i AND task_id = :t', ['i' => $parentId, 't' => $taskId]);
        if ($parent === null) {
            return comms_res(false, 'The comment you are replying to was not found.');
        }
        if ($parent['parent_id'] !== null) {
            $parentId = (int)$parent['parent_id'];   // replies stay one level deep
        }
    }
    $part = array_column(comms_task_participants($taskId), 'id');
    $mentions = array_values(array_unique(array_filter(array_map('intval', $mentionIds), static fn(int $i): bool => $i !== $me && in_array($i, $part, true))));
    foreach ($mentions as $m) {
        if (!comms_actor_can($m, 'comms.access')) {
            $mentions = array_values(array_diff($mentions, [$m]));
        }
    }
    $id = Database::insert('comms_task_comments', ['task_id' => $taskId, 'user_id' => $me, 'parent_id' => $parentId ?: null, 'body' => $body, 'important' => $important ? 1 : 0]);
    foreach ($mentions as $m) {
        Database::insert('comms_task_mentions', ['comment_id' => $id, 'user_id' => $m]);
    }
    $no = comms_task_label($t);
    comms_notify($mentions, 'task.mention', 'You were mentioned on task ' . $no, 'task', $taskId, 'high', $me);
    $others = [];
    if ($important) {
        $others = $part;                       // an "important" comment is sent to every participant
    } else {
        $others[] = (int)$t['assigner_id'];    // otherwise only the assigner...
        if ($parent !== null) {
            $others[] = (int)$parent['user_id']; // ...and the person replied to
        }
    }
    $others = array_values(array_diff(array_map('intval', $others), $mentions));
    comms_notify($others, $parent !== null ? 'task.reply' : 'task.comment', ($parent !== null ? 'New reply on task ' : 'New comment on task ') . $no, 'task', $taskId, $important ? 'high' : 'normal', $me);
    return comms_res(true, '', ['id' => $id]);
}

function comms_comment_edit(int $me, int $commentId, string $body): array
{
    $c = Database::fetch('SELECT *, UNIX_TIMESTAMP(created_at) AS ts FROM comms_task_comments WHERE id = :i', ['i' => $commentId]);
    $t = $c ? comms_task_get($me, (int)$c['task_id']) : null;
    if ($c === null || $t === null) {
        return comms_res(false, 'Comment not found.');
    }
    $own = (int)$c['user_id'] === $me && comms_within_author_window((int)$c['ts']);
    $authorised = (int)$t['assigner_id'] === $me && comms_actor_can($me, 'comms.task_edit');
    if (!$own && !$authorised) {
        return comms_res(false, (int)$c['user_id'] === $me ? 'The time limit for editing this comment has passed. Ask the assigner to correct it.' : 'You cannot edit this comment.');
    }
    $body = comms_clean_body($body);
    if ($body === '' || mb_strlen($body) > 4000) {
        return comms_res(false, 'Write a comment of up to 4,000 characters.');
    }
    if ($body === (string)$c['body']) {
        return comms_res(true);
    }
    Database::insert('comms_task_edits', ['entity_type' => 'comment', 'entity_id' => $commentId, 'old_text' => (string)$c['body'], 'new_text' => $body, 'edited_by' => $me]);
    Database::execute('UPDATE comms_task_comments SET body = :b, edited_at = NOW() WHERE id = :i', ['b' => $body, 'i' => $commentId]);
    comms_task_event((int)$c['task_id'], $me, 'comment_edited', null, ['comment_id' => $commentId]);
    return comms_res(true);
}

/* ---------------- everything the task page needs ---------------- */

function comms_task_detail(int $me, int $taskId): ?array
{
    $t = comms_task_get($me, $taskId);
    if ($t === null) {
        return null;
    }
    $isAssigner = in_array('assigner', $t['_roles'], true);
    $cl = comms_checklist_current($taskId);
    $rows = Database::fetchAll('SELECT a.*, u.name FROM comms_task_assignees a JOIN users u ON u.id = a.user_id WHERE a.task_id = :t ORDER BY a.removed_at IS NOT NULL, u.name', ['t' => $taskId]);
    $assignees = [];
    foreach ($rows as $a) {
        $removed = $a['removed_at'] !== null;
        $st = $removed ? ['has' => false] : comms_checklist_state($taskId, (int)$a['user_id'], $cl);
        $assignees[] = [
            'user_id' => (int)$a['user_id'], 'name' => $a['name'], 'status' => $a['status'], 'progress' => (int)$a['progress'], 'removed' => $removed,
            'self' => (bool)$a['self_assigned'], 'accepted_at' => $a['accepted_at'], 'declined_at' => $a['declined_at'], 'decline_reason' => $a['decline_reason'],
            'checklist' => $st, 'is_me' => (int)$a['user_id'] === $me,
        ];
    }
    $names = comms_user_names(array_merge([(int)$t['assigner_id'], (int)$t['reviewer_id']], array_column($rows, 'user_id')));
    $comments = Database::fetchAll('SELECT c.*, UNIX_TIMESTAMP(c.created_at) AS ts, u.name FROM comms_task_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.task_id = :t ORDER BY c.id', ['t' => $taskId]);
    $mentionRows = Database::fetchAll('SELECT m.comment_id, u.name FROM comms_task_mentions m JOIN comms_task_comments c ON c.id = m.comment_id JOIN users u ON u.id = m.user_id WHERE c.task_id = :t', ['t' => $taskId]);
    $mentioned = [];
    foreach ($mentionRows as $m) { $mentioned[(int)$m['comment_id']][] = $m['name']; }
    foreach ($comments as &$c) {
        $c['mentions'] = $mentioned[(int)$c['id']] ?? [];
        $c['can_edit'] = ((int)$c['user_id'] === $me && comms_within_author_window((int)$c['ts'])) || ($isAssigner && comms_actor_can($me, 'comms.task_edit'));
        $c['when'] = comms_fmt_ts((int)$c['ts'], 'd M Y, h:i A');
    }
    $progress = Database::fetchAll('SELECT p.*, UNIX_TIMESTAMP(p.created_at) AS ts, u.name FROM comms_task_progress p JOIN users u ON u.id = p.user_id WHERE p.task_id = :t ORDER BY p.id DESC LIMIT 100', ['t' => $taskId]);
    foreach ($progress as &$p) {
        $p['when'] = comms_fmt_ts((int)$p['ts'], 'd M Y, h:i A');
        $p['can_edit'] = $p['kind'] === 'manual' && (((int)$p['created_by'] === $me && comms_within_author_window((int)$p['ts'])) || ($isAssigner && comms_actor_can($me, 'comms.task_edit')));
    }
    $events = Database::fetchAll('SELECT e.*, UNIX_TIMESTAMP(e.created_at) AS ts, u.name FROM comms_task_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.task_id = :t ORDER BY e.id DESC LIMIT 200', ['t' => $taskId]);
    foreach ($events as &$ev) { $ev['when'] = comms_fmt_ts((int)$ev['ts'], 'd M Y, h:i A'); }
    $conv = null;
    if ($t['conversation_id'] !== null && comms_membership((int)$t['conversation_id'], $me) !== null) {
        $conv = (int)$t['conversation_id'];   // shown only to people who are in that chat
    }
    $tpl = $t['template_id'] ? Database::fetchValue('SELECT name FROM comms_task_templates WHERE id = :i', ['i' => $t['template_id']]) : null;
    $lastSub = [];
    foreach (Database::fetchAll('SELECT s.user_id, s.status FROM comms_task_submissions s JOIN (SELECT user_id, MAX(id) AS m FROM comms_task_submissions WHERE task_id = :t GROUP BY user_id) x ON x.m = s.id', ['t' => $taskId]) as $r) { $lastSub[(int)$r['user_id']] = $r['status']; }
    $deadlineTs = comms_deadline_ts((string)$t['due_date'], $t['due_time']);
    foreach ($assignees as &$aa) {
        $aa['correction'] = !$aa['removed'] && $aa['status'] === 'in_progress' && ($lastSub[$aa['user_id']] ?? '') === 'rejected';
        $aa['overdue'] = !$aa['removed'] && in_array($aa['status'], ['pending_acceptance', 'accepted', 'in_progress', 'submitted'], true) && in_array($t['status'], ['active', 'partially_completed'], true) && $deadlineTs < time();
    }
    unset($aa);
    $base = [
        'task' => $t, 'assigner' => $names[(int)$t['assigner_id']] ?? 'Former user', 'reviewer' => $names[(int)$t['reviewer_id']] ?? 'Not set',
        'assignees' => $assignees, 'checklist' => $cl, 'versions' => comms_checklist_versions($taskId), 'comments' => $comments, 'progress' => $progress, 'events' => $events,
        'link' => comms_link_resolve($me, $t['link_type'], $t['link_id'] !== null ? (int)$t['link_id'] : null), 'conversation' => $conv, 'template' => $tpl,
        'participants' => comms_task_participants($taskId),
        'can_edit' => comms_task_can_edit($me, $t), 'can_cancel' => $isAssigner && !in_array($t['status'], ['cancelled', 'completed'], true) && comms_actor_can($me, 'comms.task_cancel'),
        'my' => comms_my_assignment($me, $taskId), 'overdue' => comms_task_overdue($t),
    ];
    return $base + comms_task_review_view($me, $t) + comms_task_deadline_view($me, $t);
}
