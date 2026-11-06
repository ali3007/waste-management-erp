<?php
/**
 * Tasks (Phase 3): validation, creation, editing, cancellation, checklists with versions, templates, lists.
 *
 * Access rule: ONLY the assigner, the currently assigned employees and the designated reviewer can
 * open a task. Nothing is granted by department or by holding a broad permission.
 * Every function takes the acting user id and re-checks permission live.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const COMMS_PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];
const COMMS_REMINDER_CHOICES = [60 => '1 hour before', 180 => '3 hours before', 1440 => '1 day before', 2880 => '2 days before', 10080 => '1 week before'];
const COMMS_MAX_ASSIGNEES = 50;
const COMMS_MAX_CHECKLIST = 100;

function comms_today(): string
{
    return comms_now()->format('Y-m-d');
}

function comms_user_names(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (Database::fetchAll('SELECT id, name FROM users WHERE id IN (' . implode(',', $ids) . ')') as $r) {
        $out[(int)$r['id']] = (string)$r['name'];
    }
    return $out;
}

function comms_task_label(array $t): string
{
    return (string)($t['task_no'] ?? ('T-' . $t['id']));
}

/* ------------------------------------------------------------------ *
 | Access                                                              |
 * ------------------------------------------------------------------ */

/** The task if (and only if) $me is its assigner, an active assignee or its reviewer; else null. */
function comms_task_get(int $me, int $taskId): ?array
{
    $t = Database::fetch('SELECT * FROM comms_tasks WHERE id = :i', ['i' => $taskId]);
    if ($t === null) {
        return null;
    }
    $roles = comms_task_roles($me, $t);
    if (!$roles) {
        return null;
    }
    $t['_roles'] = $roles;
    return $t;
}

/** @return string[] subset of assigner|assignee|reviewer */
function comms_task_roles(int $me, array $t): array
{
    $r = [];
    if ((int)$t['assigner_id'] === $me) {
        $r[] = 'assigner';
    }
    if ((int)$t['reviewer_id'] === $me) {
        $r[] = 'reviewer';
    }
    if (Database::exists('SELECT 1 FROM comms_task_assignees WHERE task_id = :t AND user_id = :u AND removed_at IS NULL', ['t' => $t['id'], 'u' => $me])) {
        $r[] = 'assignee';
    }
    return $r;
}

/* ------------------------------------------------------------------ *
 | Events                                                              |
 * ------------------------------------------------------------------ */

function comms_task_event(int $taskId, ?int $userId, string $event, ?array $old = null, ?array $new = null, ?string $note = null): void
{
    $j = static fn($v): ?string => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    Database::insert('comms_task_events', ['task_id' => $taskId, 'user_id' => $userId, 'event' => $event, 'old_values' => $j($old), 'new_values' => $j($new), 'note' => $note !== null ? mb_substr($note, 0, 500) : null]);
}

/* ------------------------------------------------------------------ *
 | Validation                                                          |
 * ------------------------------------------------------------------ */

function comms_valid_date(string $d): bool
{
    $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    return $x !== false && $x->format('Y-m-d') === $d;
}

function comms_valid_time(string $t): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

/** Is date(+time) strictly in the past in the central timezone? A date without time is "past" only once the day is over. */
function comms_deadline_is_past(string $date, ?string $time): bool
{
    $now = comms_now();
    if ($time === null || $time === '') {
        return $date < $now->format('Y-m-d');
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . substr($time, 0, 5), comms_tz());
    return $dt === false || $dt <= $now;
}

/** Normalise a checklist from form input: [{key?, text, required}] -> clean list with stable keys. */
function comms_clean_checklist($raw): array
{
    $out = [];
    $seen = [];
    foreach (is_array($raw) ? $raw : [] as $it) {
        if (!is_array($it)) {
            continue;
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string)($it['text'] ?? '')) ?? '');
        if ($text === '') {
            continue;
        }
        $key = (string)($it['key'] ?? '');
        if (!preg_match('/^[0-9a-f]{12}$/', $key) || isset($seen[$key])) {
            $key = bin2hex(random_bytes(6));
        }
        $seen[$key] = true;
        $out[] = ['key' => $key, 'text' => mb_substr($text, 0, 255), 'required' => !empty($it['required']) && $it['required'] !== '0'];
    }
    return $out;
}

/**
 * Validate task details for creation (or the changed parts for editing, via $existing).
 * @return array{0:array,1:array<string,string>} cleaned values, errors keyed by field
 */
function comms_task_validate(int $me, array $in, ?array $existing = null): array
{
    $e = [];
    $c = [];
    $c['title'] = trim(preg_replace('/\s+/u', ' ', (string)($in['title'] ?? '')) ?? '');
    if (mb_strlen($c['title']) < 3 || mb_strlen($c['title']) > 200) {
        $e['title'] = 'Enter a title of 3 to 200 characters.';
    }
    $c['description'] = comms_clean_body((string)($in['description'] ?? ''));
    if (mb_strlen($c['description']) < 5 || mb_strlen($c['description']) > 5000) {
        $e['description'] = 'Enter a description of 5 to 5,000 characters.';
    }
    $c['priority'] = (string)($in['priority'] ?? '');
    if (!isset(COMMS_PRIORITIES[$c['priority']])) {
        $e['priority'] = 'Choose a priority.';
    }

    $c['due_date'] = trim((string)($in['due_date'] ?? ''));
    $c['due_time'] = trim((string)($in['due_time'] ?? ''));
    if (!comms_valid_date($c['due_date'])) {
        $e['due_date'] = 'Choose a valid due date.';
    } elseif ($c['due_time'] !== '' && !comms_valid_time($c['due_time'])) {
        $e['due_time'] = 'Enter the time as HH:MM.';
    } else {
        $unchanged = $existing !== null && $existing['due_date'] === $c['due_date'] && substr((string)$existing['due_time'], 0, 5) === $c['due_time'];
        if (!$unchanged && comms_deadline_is_past($c['due_date'], $c['due_time'] !== '' ? $c['due_time'] : null)) {
            $e['due_date'] = 'The deadline cannot be in the past.';
        }
    }
    $c['due_time'] = $c['due_time'] !== '' ? $c['due_time'] : null;

    $c['review_due_date'] = trim((string)($in['review_due_date'] ?? ''));
    $c['review_due_time'] = trim((string)($in['review_due_time'] ?? ''));
    if ($c['review_due_date'] === '') {
        $c['review_due_date'] = null;
        $c['review_due_time'] = null;
    } elseif (!comms_valid_date($c['review_due_date']) || ($c['review_due_time'] !== '' && !comms_valid_time($c['review_due_time']))) {
        $e['review_due_date'] = 'Enter a valid review deadline.';
    } else {
        $c['review_due_time'] = $c['review_due_time'] !== '' ? $c['review_due_time'] : null;
        $same = $existing !== null && $existing['review_due_date'] === $c['review_due_date'] && substr((string)$existing['review_due_time'], 0, 5) === (string)$c['review_due_time'];
        if (!$same && comms_deadline_is_past($c['review_due_date'], $c['review_due_time'])) {
            $e['review_due_date'] = 'The review deadline cannot be in the past.';
        } elseif (!isset($e['due_date']) && $c['review_due_date'] < $c['due_date']) {
            $e['review_due_date'] = 'The review deadline cannot be before the task deadline.';
        }
    }

    $rm = trim((string)($in['reminder_minutes'] ?? ''));
    $c['reminder_minutes'] = $rm === '' ? null : (int)$rm;
    if ($c['reminder_minutes'] !== null && !isset(COMMS_REMINDER_CHOICES[$c['reminder_minutes']])) {
        $e['reminder_minutes'] = 'Choose one of the listed reminder times.';
    }
    $prevReminder = $existing !== null && $existing['reminder_minutes'] !== null ? (int)$existing['reminder_minutes'] : null;
    if (!isset($e['reminder_minutes']) && $c['reminder_minutes'] !== $prevReminder && !comms_actor_can($me, 'comms.deadline_extend')) {
        $e['reminder_minutes'] = 'You do not have permission to set or change advance reminders.';
    }
    $c['evidence_required'] = !empty($in['evidence_required']) && $in['evidence_required'] !== '0' ? 1 : 0;

    // Assignees
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['assignees'] ?? [])), static fn(int $i): bool => $i > 0)));
    $c['assignees'] = $ids;
    if (!$ids) {
        $e['assignees'] = 'Select at least one employee.';
    } elseif (count($ids) > COMMS_MAX_ASSIGNEES) {
        $e['assignees'] = 'A task can have at most ' . COMMS_MAX_ASSIGNEES . ' assignees.';
    } else {
        foreach ($ids as $u) {
            if ($u === $me) {
                if (!comms_actor_can($me, 'comms.task_self_assign')) {
                    $e['assignees'] = 'You do not have permission to assign tasks to yourself.';
                    break;
                }
            } elseif (!comms_actor_can($me, 'comms.task_assign')) {
                $e['assignees'] = 'You do not have permission to assign tasks to other employees.';
                break;
            }
            if (!comms_actor_can($u, 'comms.task_progress')) {
                $e['assignees'] = 'One of the selected people cannot be assigned tasks.';
                break;
            }
        }
    }

    // Reviewer
    $c['reviewer_id'] = (int)($in['reviewer_id'] ?? 0);
    if ($existing !== null) {
        $c['reviewer_id'] = (int)$existing['reviewer_id'];   // changed only through reviewer reassignment
    } elseif ($c['reviewer_id'] <= 0) {
        $e['reviewer_id'] = 'Select the reviewer.';
    } elseif (!comms_actor_can($c['reviewer_id'], 'comms.task_review')) {
        $e['reviewer_id'] = 'The selected person is not an eligible reviewer.';
    }
    if (!isset($e['reviewer_id']) && $c['reviewer_id'] > 0 && in_array($c['reviewer_id'], $ids, true)) {
        $e['reviewer_id'] = 'The reviewer cannot also be assigned to do the task.';
    }

    $c['checklist'] = comms_clean_checklist($in['checklist'] ?? []);
    if (count($c['checklist']) > COMMS_MAX_CHECKLIST) {
        $e['checklist'] = 'A checklist can have at most ' . COMMS_MAX_CHECKLIST . ' steps.';
    }
    $c['template_id'] = (int)($in['template_id'] ?? 0) ?: null;
    if ($c['template_id'] !== null && !Database::exists('SELECT 1 FROM comms_task_templates WHERE id = :i AND deleted_at IS NULL', ['i' => $c['template_id']])) {
        $c['template_id'] = $existing['template_id'] ?? null;
    }

    // Optional ERP record link
    $c['link_type'] = trim((string)($in['link_type'] ?? '')) ?: null;
    $c['link_id'] = (int)($in['link_id'] ?? 0) ?: null;
    if ($c['link_type'] === null || $c['link_id'] === null) {
        $c['link_type'] = $c['link_id'] = null;
    } else {
        $same = $existing !== null && $existing['link_type'] === $c['link_type'] && (int)$existing['link_id'] === $c['link_id'];
        if (!isset(comms_link_types()[$c['link_type']])) {
            $e['link'] = 'Unsupported record type.';
        } elseif (!$same && !comms_link_can_view($me, $c['link_type'], $c['link_id'])) {
            $e['link'] = 'You cannot link that record (not found or no access).';
        }
    }
    // Optional link to a chat the creator belongs to
    $c['conversation_id'] = (int)($in['conversation_id'] ?? 0) ?: null;
    if ($c['conversation_id'] !== null) {
        $same = $existing !== null && (int)$existing['conversation_id'] === $c['conversation_id'];
        if (!$same && comms_membership($c['conversation_id'], $me) === null) {
            $e['conversation_id'] = 'You can only link a chat you belong to.';
        }
    }
    return [$c, $e];
}

/* ------------------------------------------------------------------ *
 | Checklists (versioned)                                              |
 * ------------------------------------------------------------------ */

function comms_checklist_current(int $taskId): ?array
{
    $cl = Database::fetch('SELECT * FROM comms_task_checklists WHERE task_id = :t ORDER BY version DESC LIMIT 1', ['t' => $taskId]);
    if ($cl === null) {
        return null;
    }
    $items = Database::fetchAll('SELECT item_key AS `key`, text, required FROM comms_task_checklist_items WHERE checklist_id = :c ORDER BY position', ['c' => $cl['id']]);
    foreach ($items as &$i) {
        $i['required'] = (bool)$i['required'];
    }
    return ['id' => (int)$cl['id'], 'version' => (int)$cl['version'], 'items' => $items, 'created_by' => $cl['created_by'], 'created_at' => $cl['created_at'], 'note' => $cl['change_note']];
}

function comms_checklist_versions(int $taskId): array
{
    $out = [];
    foreach (Database::fetchAll('SELECT id, version, change_note, created_by, UNIX_TIMESTAMP(created_at) AS ts FROM comms_task_checklists WHERE task_id = :t ORDER BY version DESC', ['t' => $taskId]) as $v) {
        $v['items'] = Database::fetchAll('SELECT item_key AS `key`, text, required FROM comms_task_checklist_items WHERE checklist_id = :c ORDER BY position', ['c' => $v['id']]);
        $out[] = $v;
    }
    return $out;
}

/** Describe the difference between two item lists (for the history and notifications). */
function comms_checklist_diff(array $old, array $new): array
{
    $o = [];
    foreach ($old as $i) { $o[$i['key']] = $i; }
    $n = [];
    foreach ($new as $i) { $n[$i['key']] = $i; }
    $d = ['added' => [], 'removed' => [], 'renamed' => [], 'required_changed' => [], 'reordered' => false];
    foreach ($n as $k => $i) {
        if (!isset($o[$k])) { $d['added'][] = $i['text']; }
        else {
            if ($o[$k]['text'] !== $i['text']) { $d['renamed'][] = $o[$k]['text'] . ' → ' . $i['text']; }
            if ((bool)$o[$k]['required'] !== (bool)$i['required']) { $d['required_changed'][] = $i['text'] . ($i['required'] ? ' (now required)' : ' (now optional)'); }
        }
    }
    foreach ($o as $k => $i) {
        if (!isset($n[$k])) { $d['removed'][] = $i['text']; }
    }
    $d['reordered'] = array_keys(array_intersect_key($o, $n)) !== array_keys(array_intersect_key($n, $o));
    return $d;
}

function comms_checklist_changed(array $d): bool
{
    return $d['added'] || $d['removed'] || $d['renamed'] || $d['required_changed'] || $d['reordered'];
}

/** Write a new checklist version (never edits an old one). Returns the new version number or null if unchanged. */
function comms_checklist_save(int $taskId, array $items, int $by, string $note = ''): ?int
{
    $cur = comms_checklist_current($taskId);
    $curItems = $cur['items'] ?? [];
    if (!$items && !$cur) {
        return null;
    }
    if (!comms_checklist_changed(comms_checklist_diff($curItems, $items)) && $cur !== null && count($curItems) === count($items)) {
        return null;
    }
    $ver = ($cur['version'] ?? 0) + 1;
    $id = Database::insert('comms_task_checklists', ['task_id' => $taskId, 'version' => $ver, 'change_note' => $note !== '' ? mb_substr($note, 0, 500) : null, 'created_by' => $by]);
    foreach ($items as $pos => $it) {
        Database::insert('comms_task_checklist_items', ['checklist_id' => $id, 'item_key' => $it['key'], 'position' => $pos + 1, 'text' => $it['text'], 'required' => $it['required'] ? 1 : 0]);
    }
    return $ver;
}

/* ------------------------------------------------------------------ *
 | Create                                                              |
 * ------------------------------------------------------------------ */

function comms_task_create(int $me, array $in, string $uuid): array
{
    if (!comms_actor_can($me, 'comms.task_create')) {
        return comms_res(false, 'You do not have permission to create tasks.');
    }
    if (!comms_is_uuid($uuid)) {
        return comms_res(false, 'Invalid request identifier.');
    }
    $dup = Database::fetch('SELECT id FROM comms_tasks WHERE create_uuid = :u AND assigner_id = :a', ['u' => strtolower($uuid), 'a' => $me]);
    if ($dup !== null) {
        return comms_res(true, '', ['id' => (int)$dup['id'], 'duplicate' => true]);
    }
    [$c, $errors] = comms_task_validate($me, $in);
    if ($errors) {
        return comms_res(false, implode(' ', $errors), ['errors' => $errors]);
    }
    Database::beginTransaction();
    try {
        $id = Database::insert('comms_tasks', [
            'create_uuid' => strtolower($uuid), 'title' => $c['title'], 'description' => $c['description'], 'priority' => $c['priority'],
            'due_date' => $c['due_date'], 'due_time' => $c['due_time'], 'original_due_date' => $c['due_date'], 'original_due_time' => $c['due_time'],
            'review_due_date' => $c['review_due_date'], 'review_due_time' => $c['review_due_time'], 'reminder_minutes' => $c['reminder_minutes'],
            'evidence_required' => $c['evidence_required'], 'assigner_id' => $me, 'reviewer_id' => $c['reviewer_id'], 'template_id' => $c['template_id'],
            'link_type' => $c['link_type'], 'link_id' => $c['link_id'], 'conversation_id' => $c['conversation_id'],
        ]);
        $no = 'T-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
        Database::execute('UPDATE comms_tasks SET task_no = :n WHERE id = :i', ['n' => $no, 'i' => $id]);
        foreach ($c['assignees'] as $u) {
            Database::insert('comms_task_assignees', ['task_id' => $id, 'user_id' => $u, 'self_assigned' => $u === $me ? 1 : 0]);
        }
        if ($c['checklist']) {
            comms_checklist_save($id, $c['checklist'], $me, 'Initial checklist');
        }
        comms_task_event($id, $me, 'created', null, ['title' => $c['title'], 'priority' => $c['priority'], 'due' => $c['due_date'] . ($c['due_time'] ? ' ' . $c['due_time'] : ''), 'assignees' => $c['assignees'], 'reviewer' => $c['reviewer_id'], 'checklist_steps' => count($c['checklist'])]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        $dup = Database::fetch('SELECT id FROM comms_tasks WHERE create_uuid = :u AND assigner_id = :a', ['u' => strtolower($uuid), 'a' => $me]);
        if ($dup !== null) {
            return comms_res(true, '', ['id' => (int)$dup['id'], 'duplicate' => true]);
        }
        log_event('comms', 'task create failed', ['error' => $e->getMessage()]);
        return comms_res(false, 'The task could not be saved. Please try again.');
    }
    // Notify only AFTER the task is saved (after the creator's final confirmation).
    $task = ['id' => $id, 'task_no' => $no];
    comms_reminders_reschedule((int)$id);
    comms_notify($c['assignees'], 'task.assigned', 'Task ' . $no . ' assigned to you', 'task', $id, 'high', $me);
    comms_notify([$c['reviewer_id']], 'task.reviewer', 'You were selected as reviewer for task ' . $no, 'task', $id, 'high', $me);
    comms_audit_safe('task.created', 'Task created', ['actor_id' => $me, 'subject_type' => 'task', 'subject_id' => $id, 'new' => ['task_no' => $no, 'assignees' => $c['assignees'], 'reviewer' => $c['reviewer_id'], 'template' => $c['template_id']]]);
    return comms_res(true, '', ['id' => $id, 'task_no' => $no, 'duplicate' => false]);
}

/* ------------------------------------------------------------------ *
 | Edit / cancel                                                       |
 * ------------------------------------------------------------------ */

function comms_task_can_edit(int $me, array $t): bool
{
    return (int)$t['assigner_id'] === $me && !in_array($t['status'], ['cancelled', 'completed'], true) && $t['archived_at'] === null && comms_actor_can($me, 'comms.task_edit');
}

function comms_task_edit(int $me, int $taskId, array $in): array
{
    $t = comms_task_get($me, $taskId);
    if ($t === null || !comms_task_can_edit($me, $t)) {
        return comms_res(false, 'You cannot edit this task.');
    }
    [$c, $errors] = comms_task_validate($me, $in, $t);
    if ($errors) {
        return comms_res(false, implode(' ', $errors), ['errors' => $errors]);
    }
    $cur = Database::fetchAll('SELECT user_id, status FROM comms_task_assignees WHERE task_id = :t AND removed_at IS NULL', ['t' => $taskId]);
    $curIds = array_map(static fn($r) => (int)$r['user_id'], $cur);
    $add = array_values(array_diff($c['assignees'], $curIds));
    $remove = array_values(array_diff($curIds, $c['assignees']));
    foreach ($cur as $r) {
        if (in_array((int)$r['user_id'], $remove, true) && in_array($r['status'], ['submitted', 'approved'], true)) {
            return comms_res(false, 'An employee who has submitted or been approved cannot be removed.');
        }
    }
    if (!$c['assignees'] || count(array_diff($c['assignees'], $remove)) === 0) {
        return comms_res(false, 'A task needs at least one assignee.');
    }

    $old = [];
    $new = [];
    foreach (['title', 'description', 'priority', 'due_date', 'due_time', 'review_due_date', 'review_due_time', 'reminder_minutes', 'evidence_required', 'link_type', 'link_id', 'conversation_id'] as $f) {
        $ov = $t[$f];
        if (in_array($f, ['due_time', 'review_due_time'], true) && $ov !== null) { $ov = substr((string)$ov, 0, 5); }
        $nv = $c[$f];
        if ((string)$ov !== (string)$nv) {
            $old[$f] = $ov;
            $new[$f] = $nv;
        }
    }
    $curCl = comms_checklist_current($taskId);
    $diff = comms_checklist_diff($curCl['items'] ?? [], $c['checklist']);
    $clChanged = comms_checklist_changed($diff) || count($curCl['items'] ?? []) !== count($c['checklist']);

    if (!$old && !$add && !$remove && !$clChanged) {
        return comms_res(true, '', ['changed' => false]);
    }
    Database::beginTransaction();
    try {
        if ($new) {
            $set = [];
            $p = ['i' => $taskId];
            foreach ($new as $f => $v) { $set[] = "`$f` = :$f"; $p[$f] = $v; }
            if (array_key_exists('due_date', $new) || array_key_exists('due_time', $new)) {
                $set[] = 'overdue_notified_at = NULL';
            }
            Database::execute('UPDATE comms_tasks SET ' . implode(', ', $set) . ' WHERE id = :i', $p);
            if (array_key_exists('due_date', $new) || array_key_exists('due_time', $new)) {
                comms_deadline_history_add($taskId, 'due', $t['due_date'], $t['due_time'], $c['due_date'], $c['due_time'], $me, 'edit');
            }
            if (array_key_exists('review_due_date', $new) || array_key_exists('review_due_time', $new)) {
                comms_deadline_history_add($taskId, 'review_due', $t['review_due_date'], $t['review_due_time'], $c['review_due_date'], $c['review_due_time'], $me, 'edit');
            }
        }
        foreach ($add as $u) {
            $ex = Database::fetch('SELECT id FROM comms_task_assignees WHERE task_id = :t AND user_id = :u', ['t' => $taskId, 'u' => $u]);
            if ($ex) {
                Database::execute("UPDATE comms_task_assignees SET removed_at = NULL, status = 'pending_acceptance', progress = 0, assigned_at = NOW(), accepted_at = NULL, declined_at = NULL, decline_reason = NULL, started_at = NULL, self_assigned = :s WHERE id = :i", ['s' => $u === $me ? 1 : 0, 'i' => $ex['id']]);
            } else {
                Database::insert('comms_task_assignees', ['task_id' => $taskId, 'user_id' => $u, 'self_assigned' => $u === $me ? 1 : 0]);
            }
        }
        foreach ($remove as $u) {
            Database::execute("UPDATE comms_task_assignees SET removed_at = NOW(), status = 'cancelled' WHERE task_id = :t AND user_id = :u", ['t' => $taskId, 'u' => $u]);
        }
        $ver = $clChanged ? comms_checklist_save($taskId, $c['checklist'], $me, comms_checklist_summary($diff)) : null;
        $ev = $new;
        if ($add) { $ev['assignees_added'] = $add; }
        if ($remove) { $ev['assignees_removed'] = $remove; }
        comms_task_event($taskId, $me, 'edited', $old ?: null, $ev ?: null);
        if ($ver !== null) {
            comms_task_event($taskId, $me, 'checklist_changed', null, ['version' => $ver, 'diff' => $diff]);
        }
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('comms', 'task edit failed', ['error' => $e->getMessage()]);
        return comms_res(false, 'The changes could not be saved.');
    }
    if ($clChanged) {
        comms_task_recalc_progress($taskId, $me);
    }
    comms_task_recompute($taskId);
    if (array_key_exists('due_date', $new) || array_key_exists('due_time', $new) || array_key_exists('reminder_minutes', $new)) {
        comms_reminders_reschedule($taskId);   // cancel the old reminder, schedule from the revised deadline
    }
    $no = comms_task_label($t);
    $stay = array_values(array_diff($curIds, $remove));
    $relevant = array_intersect(array_keys($new), ['title', 'description', 'priority', 'due_date', 'due_time', 'evidence_required', 'link_type', 'link_id']);
    if ($relevant) {
        comms_notify($stay, 'task.edited', 'Task ' . $no . ' was updated', 'task', $taskId, in_array('due_date', $relevant, true) || in_array('priority', $relevant, true) ? 'high' : 'normal', $me);
        comms_notify([(int)$t['reviewer_id']], 'task.edited', 'Task ' . $no . ' was updated', 'task', $taskId, 'normal', $me);
    }
    if ($clChanged) {
        comms_notify($stay, 'task.checklist', 'The checklist of task ' . $no . ' was updated', 'task', $taskId, 'normal', $me);
    }
    comms_notify($add, 'task.assigned', 'Task ' . $no . ' assigned to you', 'task', $taskId, 'high', $me);
    comms_notify($remove, 'task.removed', 'You were removed from task ' . $no, 'task', $taskId, 'normal', $me);
    comms_audit_safe('task.edited', 'Task edited', ['actor_id' => $me, 'subject_type' => 'task', 'subject_id' => $taskId, 'old' => $old ?: null, 'new' => $ev ?? null]);
    return comms_res(true, '', ['changed' => true]);
}

function comms_checklist_summary(array $d): string
{
    $p = [];
    foreach (['added' => 'Added', 'removed' => 'Removed', 'renamed' => 'Renamed', 'required_changed' => 'Required changed'] as $k => $l) {
        if ($d[$k]) { $p[] = $l . ' ' . count($d[$k]); }
    }
    if ($d['reordered']) { $p[] = 'Reordered'; }
    return implode('; ', $p);
}

function comms_task_cancel(int $me, int $taskId, string $reason): array
{
    $t = comms_task_get($me, $taskId);
    if ($t === null || (int)$t['assigner_id'] !== $me || !comms_actor_can($me, 'comms.task_cancel')) {
        return comms_res(false, 'You cannot cancel this task.');
    }
    if ($t['status'] === 'cancelled') {
        return comms_res(false, 'This task is already cancelled.');
    }
    if ($t['status'] === 'completed') {
        return comms_res(false, 'A completed task cannot be cancelled.');
    }
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
        return comms_res(false, 'Enter a reason of 5 to 500 characters.');
    }
    $assignees = array_map(static fn($r) => (int)$r['user_id'], Database::fetchAll('SELECT user_id FROM comms_task_assignees WHERE task_id = :t AND removed_at IS NULL', ['t' => $taskId]));
    Database::beginTransaction();
    try {
        Database::execute("UPDATE comms_tasks SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = :u, cancel_reason = :r WHERE id = :i", ['u' => $me, 'r' => $reason, 'i' => $taskId]);
        Database::execute("UPDATE comms_task_assignees SET status = 'cancelled' WHERE task_id = :t AND removed_at IS NULL AND status <> 'approved'", ['t' => $taskId]);
        comms_task_event($taskId, $me, 'cancelled', ['status' => $t['status']], ['status' => 'cancelled'], $reason);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        return comms_res(false, 'Could not cancel the task.');
    }
    comms_notify(array_merge($assignees, [(int)$t['reviewer_id']]), 'task.cancelled', 'Task ' . comms_task_label($t) . ' was cancelled', 'task', $taskId, 'high', $me);
    comms_audit_safe('task.cancelled', 'Task cancelled', ['actor_id' => $me, 'subject_type' => 'task', 'subject_id' => $taskId, 'old' => ['status' => $t['status']], 'new' => ['status' => 'cancelled']]);
    return comms_res(true);
}

/** Overall status from the individual workflows (Phase 4 relies on this after approvals). */
function comms_task_recompute(int $taskId): void
{
    $t = Database::fetch('SELECT status FROM comms_tasks WHERE id = :i', ['i' => $taskId]);
    if ($t === null || $t['status'] === 'cancelled') {
        return;
    }
    $rows = Database::fetchAll("SELECT status FROM comms_task_assignees WHERE task_id = :t AND removed_at IS NULL AND status NOT IN ('declined','cancelled')", ['t' => $taskId]);
    $total = count($rows);
    $approved = count(array_filter($rows, static fn($r) => $r['status'] === 'approved'));
    $new = $total > 0 && $approved === $total ? 'completed' : ($approved > 0 ? 'partially_completed' : 'active');
    if ($new !== $t['status']) {
        Database::execute('UPDATE comms_tasks SET status = :s WHERE id = :i', ['s' => $new, 'i' => $taskId]);
    }
}

/* ------------------------------------------------------------------ *
 | Templates                                                           |
 * ------------------------------------------------------------------ */

function comms_template_list(bool $includeDeleted = false): array
{
    $rows = Database::fetchAll('SELECT * FROM comms_task_templates ' . ($includeDeleted ? '' : 'WHERE deleted_at IS NULL ') . 'ORDER BY name');
    foreach ($rows as &$r) {
        $r['checklist'] = json_decode((string)$r['checklist_json'], true) ?: [];
    }
    return $rows;
}

function comms_template_save(int $me, int $id, array $in): array
{
    if (!comms_actor_can($me, 'comms.template_manage')) {
        return comms_res(false, 'You do not have permission to manage templates.');
    }
    $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')) ?? '');
    $title = trim(preg_replace('/\s+/u', ' ', (string)($in['title'] ?? '')) ?? '');
    $desc = comms_clean_body((string)($in['description'] ?? ''));
    $prio = (string)($in['priority'] ?? 'medium');
    if ($name === '' || mb_strlen($name) > 120) {
        return comms_res(false, 'Enter a template name of up to 120 characters.');
    }
    if (mb_strlen($title) > 200 || mb_strlen($desc) > 5000 || !isset(COMMS_PRIORITIES[$prio])) {
        return comms_res(false, 'Check the title, description and priority.');
    }
    $cl = comms_clean_checklist($in['checklist'] ?? []);
    if (count($cl) > COMMS_MAX_CHECKLIST) {
        return comms_res(false, 'A checklist can have at most ' . COMMS_MAX_CHECKLIST . ' steps.');
    }
    $data = ['name' => $name, 'title' => $title !== '' ? $title : null, 'description' => $desc !== '' ? $desc : null, 'priority' => $prio,
        'evidence_required' => !empty($in['evidence_required']) && $in['evidence_required'] !== '0' ? 1 : 0, 'checklist_json' => json_encode($cl, JSON_UNESCAPED_UNICODE), 'updated_by' => $me];
    if ($id > 0) {
        if (!Database::exists('SELECT 1 FROM comms_task_templates WHERE id = :i AND deleted_at IS NULL', ['i' => $id])) {
            return comms_res(false, 'Template not found.');
        }
        Database::update('comms_task_templates', $data, ['id' => $id]);
        comms_audit_safe('template.updated', 'Task template updated', ['actor_id' => $me, 'subject_type' => 'template', 'subject_id' => $id]);
        return comms_res(true, '', ['id' => $id]);
    }
    $data['created_by'] = $me;
    $id = Database::insert('comms_task_templates', $data);
    comms_audit_safe('template.created', 'Task template created', ['actor_id' => $me, 'subject_type' => 'template', 'subject_id' => $id]);
    return comms_res(true, '', ['id' => $id]);
}

/** Soft delete: tasks created from the template keep working and keep their template_id. */
function comms_template_delete(int $me, int $id): array
{
    if (!comms_actor_can($me, 'comms.template_manage')) {
        return comms_res(false, 'You do not have permission to manage templates.');
    }
    $n = Database::execute('UPDATE comms_task_templates SET deleted_at = NOW(), updated_by = :u WHERE id = :i AND deleted_at IS NULL', ['u' => $me, 'i' => $id]);
    if ($n === 0) {
        return comms_res(false, 'Template not found.');
    }
    comms_audit_safe('template.deleted', 'Task template deleted', ['actor_id' => $me, 'subject_type' => 'template', 'subject_id' => $id]);
    return comms_res(true);
}

/* ------------------------------------------------------------------ *
 | Lists                                                               |
 * ------------------------------------------------------------------ */

/** Tasks the actor may see, with filters and pagination. */
function comms_task_list(int $me, array $f = [], int $page = 1, int $perPage = 20): array
{
    $page = max(1, $page);
    $perPage = max(5, min(100, $perPage));
    $w = [];
    $p = ['me' => $me, 'me2' => $me, 'me3' => $me];
    $scope = (string)($f['scope'] ?? 'all');
    $mine = 'EXISTS (SELECT 1 FROM comms_task_assignees a WHERE a.task_id = t.id AND a.user_id = :me AND a.removed_at IS NULL)';
    $w[] = match ($scope) {
        'mine' => $mine,
        'assigned_by_me' => 't.assigner_id = :me2',
        'review' => 't.reviewer_id = :me3',
        default => "(t.assigner_id = :me2 OR t.reviewer_id = :me3 OR $mine)",
    };
    if ($scope === 'mine') { $p = ['me' => $me]; }
    elseif ($scope === 'assigned_by_me') { $p = ['me2' => $me]; }
    elseif ($scope === 'review') { $p = ['me3' => $me]; }
    if (!empty($f['status']) && in_array($f['status'], ['active', 'partially_completed', 'completed', 'cancelled'], true)) {
        $w[] = 't.status = :st';
        $p['st'] = $f['status'];
    }
    if (!empty($f['priority']) && isset(COMMS_PRIORITIES[$f['priority']])) {
        $w[] = 't.priority = :pr';
        $p['pr'] = $f['priority'];
    }
    if (!empty($f['my_status']) && in_array($f['my_status'], ['pending_acceptance', 'accepted', 'declined', 'in_progress', 'submitted', 'rejected', 'approved', 'cancelled'], true)) {
        $w[] = 'EXISTS (SELECT 1 FROM comms_task_assignees x WHERE x.task_id = t.id AND x.user_id = :ms AND x.status = :mss AND x.removed_at IS NULL)';
        $p['ms'] = $me;
        $p['mss'] = $f['my_status'];
    }
    if (!empty($f['overdue'])) {
        $w[] = "t.status IN ('active','partially_completed') AND (t.due_date < :td1 OR (t.due_date = :td2 AND t.due_time IS NOT NULL AND t.due_time < :tm))";
        $p['td1'] = comms_today();
        $p['td2'] = comms_today();
        $p['tm'] = comms_now()->format('H:i:s');
    }
    if (!empty($f['awaiting_review'])) {
        $w[] = "EXISTS (SELECT 1 FROM comms_task_submissions sr WHERE sr.task_id = t.id AND sr.status = 'pending_review' AND sr.reviewer_id = :ar)";
        $p['ar'] = $me;
    }
    if (!empty($f['review_overdue'])) {
        $w[] = "EXISTS (SELECT 1 FROM comms_task_submissions so WHERE so.task_id = t.id AND so.status = 'pending_review') AND t.status <> 'cancelled' AND t.review_due_date IS NOT NULL AND (t.review_due_date < :rd1 OR (t.review_due_date = :rd2 AND t.review_due_time IS NOT NULL AND t.review_due_time < :rt))";
        $p['rd1'] = comms_today();
        $p['rd2'] = comms_today();
        $p['rt'] = comms_now()->format('H:i:s');
    }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (!empty($f[$k]) && comms_valid_date((string)$f[$k])) {
            $w[] = "t.due_date $op :d$k";
            $p["d$k"] = $f[$k];
        }
    }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $w[] = '(t.title LIKE :q1 OR t.task_no LIKE :q2 OR t.description LIKE :q3)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $p['q1'] = $p['q2'] = $p['q3'] = $like;
    }
    $where = implode(' AND ', $w);
    $total = (int)Database::fetchValue("SELECT COUNT(*) FROM comms_tasks t WHERE $where", $p);
    $rows = Database::fetchAll(
        "SELECT t.id, t.task_no, t.title, t.priority, t.status, t.due_date, t.due_time, t.assigner_id, t.reviewer_id, u.name AS assigner,
                (SELECT COUNT(*) FROM comms_task_assignees a WHERE a.task_id = t.id AND a.removed_at IS NULL) AS n_assignees,
                (SELECT status FROM comms_task_assignees a WHERE a.task_id = t.id AND a.user_id = " . (int)$me . " AND a.removed_at IS NULL) AS my_status,
                (SELECT progress FROM comms_task_assignees a WHERE a.task_id = t.id AND a.user_id = " . (int)$me . " AND a.removed_at IS NULL) AS my_progress,
                (SELECT COUNT(*) FROM comms_task_submissions sp WHERE sp.task_id = t.id AND sp.status = 'pending_review') AS pending_reviews,
                (SELECT ROUND(AVG(progress)) FROM comms_task_assignees a WHERE a.task_id = t.id AND a.removed_at IS NULL AND a.status NOT IN ('declined','cancelled')) AS avg_progress
           FROM comms_tasks t LEFT JOIN users u ON u.id = t.assigner_id
          WHERE $where ORDER BY (t.status IN ('completed','cancelled')), t.due_date ASC, t.id DESC LIMIT " . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
        $p
    );
    $today = comms_today();
    foreach ($rows as &$r) {
        $r['overdue'] = in_array($r['status'], ['active', 'partially_completed'], true) && ($r['due_date'] < $today || ($r['due_date'] === $today && $r['due_time'] !== null && $r['due_time'] < comms_now()->format('H:i:s')));
    }
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage))];
}
