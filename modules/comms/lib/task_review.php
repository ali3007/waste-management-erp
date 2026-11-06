<?php
/**
 * Completion submission, independent review, reviewer unavailability / reassignment and reopening (Phase 4).
 *
 * Rules enforced here (never in the browser):
 *  - an employee submits with a comment, all REQUIRED checklist steps done, and valid evidence when the task requires it;
 *  - while a submission is pending the employee cannot edit or withdraw it (there is no such function) or update progress;
 *  - every submission is reviewed independently by the task's designated, eligible reviewer, who can never be the submitter;
 *  - rejection needs a reason and returns ONLY that employee to In Progress;
 *  - an unavailable reviewer is flagged, never replaced automatically;
 *  - reopening is a separate, authorised step that keeps the original approval and all history.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const COMMS_EVIDENCE_MAX_FILES = 5;

/* ------------------------------------------------------------------ *
 | Evidence storage                                                    |
 * ------------------------------------------------------------------ */

function comms_evidence_dir(string $stored): string
{
    return comms_storage_root() . '/task-evidence/' . substr($stored, 0, 2);
}

function comms_evidence_path(array $ev): string
{
    return comms_evidence_dir((string)$ev['stored_name']) . '/' . $ev['stored_name'];
}

/** Validate one evidence file the same way chat files are validated. @return array{0:?array,1:string} [info, error] */
function comms_evidence_check(string $tmp, string $origName, bool $isUploaded): array
{
    if ($isUploaded && !is_uploaded_file($tmp)) {
        return [null, 'The upload was not valid. Please try again.'];
    }
    $name = comms_safe_filename($origName);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $types = comms_file_types();
    if ($ext === '' || in_array($ext, COMMS_FORBIDDEN_EXT, true) || !isset($types[$ext]) || !in_array($ext, comms_allowed_extensions(), true)) {
        return [null, '“' . $name . '” is not an allowed file type.'];
    }
    $size = (int)@filesize($tmp);
    if ($size <= 0) {
        return [null, '“' . $name . '” is empty.'];
    }
    if ($size > (int)comms_setting('comms_max_file_mb') * 1048576) {
        return [null, '“' . $name . '” is larger than the ' . comms_setting('comms_max_file_mb') . ' MB limit.'];
    }
    if (!comms_content_matches($tmp, $ext, $types[$ext][1])) {
        return [null, '“' . $name . '” does not match its file type, so it was refused.'];
    }
    return [['name' => $name, 'mime' => $types[$ext][0], 'size' => $size, 'tmp' => $tmp], ''];
}

/* ------------------------------------------------------------------ *
 | Submission                                                          |
 * ------------------------------------------------------------------ */

/**
 * @param array<int,array{tmp:string,name:string}> $files
 */
function comms_task_submit(int $me, int $taskId, string $comment, array $files, string $uuid, bool $isUploaded = true): array
{
    if (!comms_actor_can($me, 'comms.task_submit')) {
        return comms_res(false, 'You do not have permission to submit completion.');
    }
    $a = comms_my_assignment($me, $taskId);
    if ($a === null) {
        return comms_res(false, 'This task is not assigned to you.');
    }
    if (!comms_is_uuid($uuid)) {
        return comms_res(false, 'Invalid request identifier.');
    }
    $dup = Database::fetch('SELECT id FROM comms_task_submissions WHERE user_id = :u AND client_uuid = :c', ['u' => $me, 'c' => strtolower($uuid)]);
    if ($dup !== null) {
        return comms_res(true, '', ['id' => (int)$dup['id'], 'duplicate' => true]);
    }
    if ($a['task_status'] === 'cancelled') {
        return comms_res(false, 'This task was cancelled.');
    }
    $why = match ($a['status']) {
        'pending_acceptance' => 'Accept the task before submitting.',
        'submitted' => 'Your completion is already waiting for review. It cannot be edited or withdrawn; the reviewer will approve it or ask for a correction.',
        'approved' => 'Your completion was already approved.',
        'declined', 'cancelled' => 'You cannot submit for this task.',
        default => '',
    };
    if ($why !== '') {
        return comms_res(false, $why);
    }
    $comment = comms_clean_body($comment);
    if ($comment === '' || mb_strlen($comment) > 2000) {
        return comms_res(false, 'Add a completion comment (up to 2,000 characters).');
    }
    $cl = comms_checklist_state($taskId, $me);
    if ($cl['has'] && !$cl['can_submit']) {
        return comms_res(false, 'Complete every required checklist step first (' . $cl['req_done'] . ' of ' . $cl['req_total'] . ' done).');
    }
    $task = Database::fetch('SELECT * FROM comms_tasks WHERE id = :i', ['i' => $taskId]);
    if ($task['reviewer_id'] === null) {
        return comms_res(false, 'This task has no reviewer. Ask the assigner to assign one.');
    }
    $files = array_values(array_filter($files, static fn($f): bool => is_array($f) && ($f['tmp'] ?? '') !== ''));
    if (count($files) > COMMS_EVIDENCE_MAX_FILES) {
        return comms_res(false, 'Attach at most ' . COMMS_EVIDENCE_MAX_FILES . ' evidence files.');
    }
    if ((int)$task['evidence_required'] === 1 && !$files) {
        return comms_res(false, 'This task requires evidence. Attach at least one valid file.');
    }
    $checked = [];
    $bytes = 0;
    foreach ($files as $f) {
        [$info, $err] = comms_evidence_check((string)$f['tmp'], (string)($f['name'] ?? ''), $isUploaded);
        if ($info === null) {
            return comms_res(false, $err);
        }
        $checked[] = $info;
        $bytes += $info['size'];
    }
    $st = comms_storage_state();
    if ($checked && $st['used'] + $bytes > $st['limit']) {
        return comms_res(false, 'Storage is full. Ask an administrator to free space or raise the storage limit.');
    }
    $stored = [];
    foreach ($checked as $info) {
        $key = bin2hex(random_bytes(16));
        $dir = comms_evidence_dir($key);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            foreach ($stored as $s) { @unlink(comms_evidence_dir($s['key']) . '/' . $s['key']); }
            log_event('comms', 'Cannot create evidence directory', ['dir' => $dir]);
            return comms_res(false, 'The evidence could not be stored. Please contact the administrator.');
        }
        $ok = $isUploaded ? @move_uploaded_file($info['tmp'], "$dir/$key") : @copy($info['tmp'], "$dir/$key");
        if (!$ok) {
            foreach ($stored as $s) { @unlink(comms_evidence_dir($s['key']) . '/' . $s['key']); }
            return comms_res(false, 'The evidence could not be stored. Please try again.');
        }
        @chmod("$dir/$key", 0640);
        $stored[] = $info + ['key' => $key, 'sha' => hash_file('sha256', "$dir/$key")];
    }
    $round = 1 + (int)Database::fetchValue('SELECT COALESCE(MAX(round_no), 0) FROM comms_task_submissions WHERE task_id = :t AND user_id = :u', ['t' => $taskId, 'u' => $me]);
    $reopen = Database::fetchValue(
        'SELECT r.id FROM comms_task_reopens r WHERE r.task_id = :t AND JSON_CONTAINS(r.affected, :u) AND r.id > COALESCE((SELECT MAX(s.reopen_id) FROM comms_task_submissions s WHERE s.task_id = :t2 AND s.user_id = :u2 AND s.reopen_id IS NOT NULL), 0) ORDER BY r.id DESC LIMIT 1',
        ['t' => $taskId, 'u' => (string)$me, 't2' => $taskId, 'u2' => $me]
    );
    Database::beginTransaction();
    try {
        $sid = Database::insert('comms_task_submissions', [
            'task_id' => $taskId, 'user_id' => $me, 'round_no' => $round, 'client_uuid' => strtolower($uuid), 'comment' => $comment,
            'checklist_version' => $cl['has'] ? $cl['version'] : null, 'progress_at_submit' => (int)$a['progress'], 'reviewer_id' => (int)$task['reviewer_id'], 'reopen_id' => $reopen ?: null,
        ]);
        foreach ($stored as $s) {
            Database::insert('comms_task_evidence', ['submission_id' => $sid, 'task_id' => $taskId, 'uploader_id' => $me, 'original_name' => $s['name'], 'stored_name' => $s['key'], 'mime' => $s['mime'], 'size_bytes' => $s['size'], 'sha256' => $s['sha']]);
        }
        Database::execute("UPDATE comms_task_assignees SET status = 'submitted' WHERE id = :i AND status IN ('accepted','in_progress')", ['i' => $a['id']]);
        comms_task_event($taskId, $me, $reopen ? 'correction_submitted' : 'submitted', ['status' => $a['status']], ['status' => 'submitted', 'round' => $round, 'evidence_files' => count($stored)]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        foreach ($stored as $s) { @unlink(comms_evidence_dir($s['key']) . '/' . $s['key']); }
        $dup = Database::fetch('SELECT id FROM comms_task_submissions WHERE user_id = :u AND client_uuid = :c', ['u' => $me, 'c' => strtolower($uuid)]);
        if ($dup !== null) {
            return comms_res(true, '', ['id' => (int)$dup['id'], 'duplicate' => true]);
        }
        log_event('comms', 'submission failed', ['error' => $e->getMessage()]);
        return comms_res(false, 'The submission could not be saved. Please try again.');
    }
    $no = comms_task_label($task);
    comms_notify([(int)$task['reviewer_id']], 'task.submitted', 'Completion of task ' . $no . ' needs your review', 'task', $taskId, 'high', $me);
    comms_notify([(int)$task['assigner_id']], 'task.submitted', 'A completion was submitted for task ' . $no, 'task', $taskId, 'normal', $me);
    comms_audit_safe('task.submitted', 'Task completion submitted', ['actor_id' => $me, 'subject_type' => 'task', 'subject_id' => $taskId, 'new' => ['submission' => $sid, 'round' => $round, 'evidence' => count($stored)]]);
    comms_flag_reviewer_if_unavailable($taskId);
    return comms_res(true, '', ['id' => $sid, 'duplicate' => false, 'round' => $round]);
}

/* ------------------------------------------------------------------ *
 | Review                                                              |
 * ------------------------------------------------------------------ */

function comms_reviewer_available(?int $reviewerId): bool
{
    return $reviewerId !== null && $reviewerId > 0 && comms_actor_can($reviewerId, 'comms.task_review');
}

/** May $me decide this pending submission? @return array{0:bool,1:string} */
function comms_review_can_decide(int $me, array $sub, array $task): array
{
    if ($sub['status'] !== 'pending_review') {
        return [false, 'This submission has already been decided.'];
    }
    if ($task['status'] === 'cancelled') {
        return [false, 'The task was cancelled.'];
    }
    if ((int)$sub['user_id'] === $me) {
        return [false, 'You cannot review your own submission.'];
    }
    if (Database::exists('SELECT 1 FROM comms_task_assignees WHERE task_id = :t AND user_id = :u AND removed_at IS NULL', ['t' => $task['id'], 'u' => $me])) {
        return [false, 'A person assigned to the task cannot review it.'];
    }
    if ((int)$task['reviewer_id'] !== $me || (int)$sub['reviewer_id'] !== $me) {
        return [false, 'Only the designated reviewer can decide this submission.'];
    }
    if (!comms_actor_can($me, 'comms.task_review')) {
        return [false, 'You no longer have permission to review tasks.'];
    }
    return [true, ''];
}

function comms_review_decide(int $me, int $subId, string $decision, string $comment): array
{
    $sub = Database::fetch('SELECT * FROM comms_task_submissions WHERE id = :i', ['i' => $subId]);
    $task = $sub ? comms_task_get($me, (int)$sub['task_id']) : null;
    if ($sub === null || $task === null) {
        return comms_res(false, 'Submission not found.');
    }
    [$ok, $why] = comms_review_can_decide($me, $sub, $task);
    if (!$ok) {
        return comms_res(false, $why);
    }
    if (!in_array($decision, ['approve', 'reject'], true)) {
        return comms_res(false, 'Choose approve or reject.');
    }
    $comment = trim(comms_clean_body($comment));
    if ($decision === 'reject' && (mb_strlen($comment) < 5 || mb_strlen($comment) > 2000)) {
        return comms_res(false, 'Give a reason of 5 to 2,000 characters when rejecting.');
    }
    if (mb_strlen($comment) > 2000) {
        return comms_res(false, 'The comment is too long.');
    }
    $approve = $decision === 'approve';
    Database::beginTransaction();
    try {
        $n = Database::execute("UPDATE comms_task_submissions SET status = :s, decided_by = :u, decided_at = NOW(), decision_comment = :c WHERE id = :i AND status = 'pending_review'",
            ['s' => $approve ? 'approved' : 'rejected', 'u' => $me, 'c' => $comment !== '' ? $comment : null, 'i' => $subId]);
        if ($n !== 1) {
            Database::rollBack();
            return comms_res(false, 'This submission has already been decided.');
        }
        Database::execute("UPDATE comms_task_assignees SET status = :s WHERE task_id = :t AND user_id = :u AND removed_at IS NULL AND status = 'submitted'", ['s' => $approve ? 'approved' : 'in_progress', 't' => $sub['task_id'], 'u' => $sub['user_id']]);
        comms_task_event((int)$sub['task_id'], $me, $approve ? 'approved' : 'rejected', ['status' => 'submitted'], ['status' => $approve ? 'approved' : 'in_progress', 'submission' => $subId, 'employee' => (int)$sub['user_id']], $comment !== '' ? $comment : null);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('comms', 'review failed', ['error' => $e->getMessage()]);
        return comms_res(false, 'The decision could not be saved.');
    }
    comms_task_recompute((int)$sub['task_id']);
    $no = comms_task_label($task);
    comms_notify([(int)$sub['user_id']], $approve ? 'task.approved' : 'task.rejected', $approve ? 'Your completion of task ' . $no . ' was approved' : 'Your completion of task ' . $no . ' needs correction', 'task', (int)$sub['task_id'], 'high', $me);
    comms_notify([(int)$task['assigner_id']], $approve ? 'task.approved' : 'task.rejected', ($approve ? 'A completion was approved on task ' : 'A completion was rejected on task ') . $no, 'task', (int)$sub['task_id'], 'normal', $me);
    comms_audit_safe($approve ? 'task.approved' : 'task.rejected', $approve ? 'Task completion approved' : 'Task completion rejected', ['actor_id' => $me, 'subject_type' => 'task', 'subject_id' => (int)$sub['task_id'], 'new' => ['submission' => $subId, 'employee' => (int)$sub['user_id']]]);
    return comms_res(true);
}

/* ------------------------------------------------------------------ *
 | Reviewer unavailability and reassignment                            |
 * ------------------------------------------------------------------ */

/** Flag (never auto-fix) an open task whose reviewer lost permission or was deactivated; clear the flag if they are available again. */
function comms_flag_reviewer_if_unavailable(int $taskId): void
{
    $t = Database::fetch('SELECT id, task_no, status, reviewer_id, assigner_id, reviewer_flag_at FROM comms_tasks WHERE id = :i', ['i' => $taskId]);
    if ($t === null || !in_array($t['status'], ['active', 'partially_completed'], true)) {
        return;
    }
    $available = comms_reviewer_available($t['reviewer_id'] !== null ? (int)$t['reviewer_id'] : null);
    if ($available) {
        if ($t['reviewer_flag_at'] !== null) {
            Database::execute('UPDATE comms_tasks SET reviewer_flag_at = NULL WHERE id = :i', ['i' => $taskId]);
        }
        return;
    }
    if ($t['reviewer_flag_at'] !== null || Database::execute('UPDATE comms_tasks SET reviewer_flag_at = NOW() WHERE id = :i AND reviewer_flag_at IS NULL', ['i' => $taskId]) !== 1) {
        return;
    }
    comms_task_event($taskId, null, 'reviewer_unavailable', ['reviewer' => $t['reviewer_id']], null, 'The reviewer is no longer available. A new reviewer must be selected.');
    $authorised = [(int)$t['assigner_id']];
    foreach (comms_task_participants($taskId) as $p) {
        if ($p['id'] !== (int)$t['reviewer_id'] && comms_actor_can($p['id'], 'comms.reviewer_reassign')) {
            $authorised[] = $p['id'];
        }
    }
    comms_notify($authorised, 'task.reviewer_unavailable', 'Task ' . $t['task_no'] . ': the reviewer is unavailable — select a new reviewer', 'task', $taskId, 'high');
}

function comms_job_reviewer_flags(): int
{
    $n = 0;
    foreach (Database::fetchAll("SELECT id FROM comms_tasks WHERE status IN ('active','partially_completed') LIMIT 1000") as $r) {
        $before = Database::fetchValue('SELECT reviewer_flag_at FROM comms_tasks WHERE id = :i', ['i' => $r['id']]);
        comms_flag_reviewer_if_unavailable((int)$r['id']);
        $n += ($before === null && Database::fetchValue('SELECT reviewer_flag_at FROM comms_tasks WHERE id = :i', ['i' => $r['id']]) !== null) ? 1 : 0;
    }
    return $n;
}

/** Manual selection of a new eligible reviewer. Nothing here ever runs automatically. */
function comms_reviewer_reassign(int $me, int $taskId, int $newReviewer, string $reason): array
{
    $t = comms_task_get($me, $taskId);
    if ($t === null || !comms_actor_can($me, 'comms.reviewer_reassign') || !array_intersect(['assigner', 'reviewer'], $t['_roles'])) {
        return comms_res(false, 'You cannot reassign the reviewer of this task.');
    }
    if (!in_array($t['status'], ['active', 'partially_completed'], true)) {
        return comms_res(false, 'The reviewer can only be changed on an open task.');
    }
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
        return comms_res(false, 'Give a reason of 5 to 500 characters.');
    }
    if ($newReviewer <= 0 || $newReviewer === (int)$t['reviewer_id']) {
        return comms_res(false, 'Choose a different reviewer.');
    }
    if (!comms_actor_can($newReviewer, 'comms.task_review')) {
        return comms_res(false, 'The selected person is not an eligible reviewer.');
    }
    if (Database::exists('SELECT 1 FROM comms_task_assignees WHERE task_id = :t AND user_id = :u AND removed_at IS NULL', ['t' => $taskId, 'u' => $newReviewer])) {
        return comms_res(false, 'The reviewer cannot be someone assigned to do the task.');
    }
    $old = $t['reviewer_id'] !== null ? (int)$t['reviewer_id'] : null;
    Database::beginTransaction();
    try {
        Database::execute('UPDATE comms_tasks SET reviewer_id = :n, reviewer_flag_at = NULL WHERE id = :i', ['n' => $newReviewer, 'i' => $taskId]);
        Database::execute("UPDATE comms_task_submissions SET reviewer_id = :n WHERE task_id = :t AND status = 'pending_review'", ['n' => $newReviewer, 't' => $taskId]);
        Database::insert('comms_task_reviewer_changes', ['task_id' => $taskId, 'old_reviewer' => $old, 'new_reviewer' => $newReviewer, 'reason' => $reason, 'changed_by' => $me]);
        comms_task_event($taskId, $me, 'reviewer_changed', ['reviewer' => $old], ['reviewer' => $newReviewer], $reason);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        return comms_res(false, 'The reviewer could not be changed.');
    }
    $no = comms_task_label($t);
    $pending = (int)Database::fetchValue("SELECT COUNT(*) FROM comms_task_submissions WHERE task_id = :t AND status = 'pending_review'", ['t' => $taskId]);
    comms_notify([$newReviewer], 'task.reviewer', 'You were selected as reviewer for task ' . $no . ($pending ? ' (' . $pending . ' submission' . ($pending === 1 ? '' : 's') . ' waiting)' : ''), 'task', $taskId, 'high', $me);
    comms_notify(array_filter([$old, (int)$t['assigner_id']]), 'task.reviewer_changed', 'The reviewer of task ' . $no . ' was changed', 'task', $taskId, 'normal', $me);
    comms_audit_safe('task.reviewer_changed', 'Task reviewer changed', ['actor_id' => $me, 'subject_type' => 'task', 'subject_id' => $taskId, 'old' => ['reviewer' => $old], 'new' => ['reviewer' => $newReviewer]]);
    return comms_res(true);
}

/* ------------------------------------------------------------------ *
 | Reopening                                                           |
 * ------------------------------------------------------------------ */

/**
 * Reopen approved work for correction. Only a separately authorised participant (assigner or reviewer holding
 * comms.task_reopen). Progress, checklist state, the original approval and all history are kept.
 * @param int[] $userIds approved employees to send back to In Progress
 */
function comms_task_reopen(int $me, int $taskId, array $userIds, string $reason, string $details): array
{
    $t = comms_task_get($me, $taskId);
    if ($t === null || !comms_actor_can($me, 'comms.task_reopen') || !array_intersect(['assigner', 'reviewer'], $t['_roles'])) {
        return comms_res(false, 'You cannot reopen this task.');
    }
    if (!in_array($t['status'], ['completed', 'partially_completed'], true)) {
        return comms_res(false, 'Only a completed (or partially completed) task can be reopened.');
    }
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
    $details = comms_clean_body($details);
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500 || mb_strlen($details) < 5 || mb_strlen($details) > 3000) {
        return comms_res(false, 'Give a reason (5–500 characters) and the correction details (5–3,000 characters).');
    }
    $approved = array_map('intval', array_column(Database::fetchAll("SELECT user_id FROM comms_task_assignees WHERE task_id = :t AND removed_at IS NULL AND status = 'approved'", ['t' => $taskId]), 'user_id'));
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) {
        $userIds = $approved;
    }
    if (!$userIds || array_diff($userIds, $approved)) {
        return comms_res(false, 'Choose employees whose completion was approved.');
    }
    Database::beginTransaction();
    try {
        $rid = Database::insert('comms_task_reopens', ['task_id' => $taskId, 'reopened_by' => $me, 'reason' => $reason, 'details' => $details, 'affected' => json_encode($userIds), 'prev_status' => $t['status']]);
        foreach ($userIds as $u) {
            Database::execute("UPDATE comms_task_assignees SET status = 'in_progress' WHERE task_id = :t AND user_id = :u AND status = 'approved'", ['t' => $taskId, 'u' => $u]);
            Database::execute("UPDATE comms_task_submissions SET reopened_at = NOW() WHERE task_id = :t AND user_id = :u AND status = 'approved' AND reopened_at IS NULL", ['t' => $taskId, 'u' => $u]);
        }
        comms_task_event($taskId, $me, 'reopened', ['status' => $t['status']], ['employees' => $userIds, 'reopen' => $rid], $reason);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('comms', 'reopen failed', ['error' => $e->getMessage()]);
        return comms_res(false, 'The task could not be reopened.');
    }
    comms_task_recompute($taskId);
    Database::execute('UPDATE comms_tasks SET overdue_notified_at = NULL WHERE id = :i', ['i' => $taskId]);
    comms_reminders_reschedule($taskId);
    $no = comms_task_label($t);
    comms_notify($userIds, 'task.reopened', 'Task ' . $no . ' was reopened for correction', 'task', $taskId, 'high', $me);
    comms_notify([(int)$t['assigner_id'], (int)$t['reviewer_id']], 'task.reopened', 'Task ' . $no . ' was reopened', 'task', $taskId, 'normal', $me);
    comms_audit_safe('task.reopened', 'Task reopened', ['actor_id' => $me, 'subject_type' => 'task', 'subject_id' => $taskId, 'old' => ['status' => $t['status']], 'new' => ['employees' => $userIds, 'reopen' => $rid]]);
    return comms_res(true, '', ['reopen_id' => $rid]);
}

/* ------------------------------------------------------------------ *
 | Evidence access                                                     |
 * ------------------------------------------------------------------ */

/**
 * Who may see a submission's evidence: the submitter, the task's reviewer, and other participants only if they
 * hold comms.evidence_view. Outsiders never.
 */
function comms_can_view_evidence(int $me, array $task, array $sub): bool
{
    if (!comms_actor_can($me, 'comms.access') || !comms_task_roles($me, $task)) {
        return false;
    }
    if ((int)$sub['user_id'] === $me || (int)$task['reviewer_id'] === $me || (int)$sub['reviewer_id'] === $me) {
        return true;
    }
    return comms_actor_can($me, 'comms.evidence_view');
}

function comms_evidence_url(int $userId, int $evidenceId, bool $download): string
{
    return base_url('modules/comms/process/task-file.php') . '?id=' . $evidenceId . '&t=' . comms_link_token($userId, 'e' . $evidenceId) . ($download ? '&dl=1' : '');
}

/** @return array{ok:bool,status:int,ev?:array} */
function comms_authorize_evidence(int $me, int $evId, string $token): array
{
    if (!comms_link_valid($me, 'e' . $evId, $token)) {
        return ['ok' => false, 'status' => 403];
    }
    $ev = Database::fetch('SELECT * FROM comms_task_evidence WHERE id = :i', ['i' => $evId]);
    if ($ev === null) {
        return ['ok' => false, 'status' => 404];
    }
    $sub = Database::fetch('SELECT * FROM comms_task_submissions WHERE id = :i', ['i' => $ev['submission_id']]);
    $task = Database::fetch('SELECT * FROM comms_tasks WHERE id = :i', ['i' => $ev['task_id']]);
    if ($sub === null || $task === null || !comms_can_view_evidence($me, $task, $sub)) {
        return ['ok' => false, 'status' => 403];
    }
    return ['ok' => true, 'status' => 200, 'ev' => $ev];
}

/* ------------------------------------------------------------------ *
 | What the task page needs                                            |
 * ------------------------------------------------------------------ */

function comms_task_review_view(int $me, array $t): array
{
    $subs = Database::fetchAll('SELECT s.*, u.name, UNIX_TIMESTAMP(s.submitted_at) AS ts, UNIX_TIMESTAMP(s.decided_at) AS dts, du.name AS decider FROM comms_task_submissions s JOIN users u ON u.id = s.user_id LEFT JOIN users du ON du.id = s.decided_by WHERE s.task_id = :t ORDER BY s.id DESC', ['t' => $t['id']]);
    $reviewerOk = comms_reviewer_available($t['reviewer_id'] !== null ? (int)$t['reviewer_id'] : null);
    $reviewDue = $t['review_due_date'] ? comms_deadline_ts((string)$t['review_due_date'], $t['review_due_time']) : null;
    foreach ($subs as &$s) {
        $s['when'] = comms_fmt_ts((int)$s['ts'], 'd M Y, h:i A');
        $s['decided_when'] = $s['dts'] ? comms_fmt_ts((int)$s['dts'], 'd M Y, h:i A') : null;
        $canSee = comms_can_view_evidence($me, $t, $s);
        $s['evidence_visible'] = $canSee;
        $s['evidence'] = [];
        $n = 0;
        foreach (Database::fetchAll('SELECT * FROM comms_task_evidence WHERE submission_id = :s ORDER BY id', ['s' => $s['id']]) as $e) {
            $n++;
            if ($canSee) {
                $s['evidence'][] = ['id' => (int)$e['id'], 'name' => $e['original_name'], 'size' => (int)$e['size_bytes'], 'mime' => $e['mime'], 'inline' => comms_inline_ok((string)$e['mime'])];
            }
        }
        $s['evidence_count'] = $n;
        [$can] = comms_review_can_decide($me, $s, $t);
        $s['can_decide'] = $can;
        $s['review_overdue'] = $s['status'] === 'pending_review' && $reviewDue !== null && $reviewDue < time();
        $s['reviewer_unavailable'] = $s['status'] === 'pending_review' && !$reviewerOk;
    }
    $changes = Database::fetchAll('SELECT c.*, o.name AS old_name, n.name AS new_name, b.name AS by_name, UNIX_TIMESTAMP(c.created_at) AS ts FROM comms_task_reviewer_changes c LEFT JOIN users o ON o.id = c.old_reviewer JOIN users n ON n.id = c.new_reviewer LEFT JOIN users b ON b.id = c.changed_by WHERE c.task_id = :t ORDER BY c.id DESC', ['t' => $t['id']]);
    foreach ($changes as &$c) { $c['when'] = comms_fmt_ts((int)$c['ts'], 'd M Y, h:i A'); }
    $reopens = Database::fetchAll('SELECT r.*, u.name AS by_name, UNIX_TIMESTAMP(r.created_at) AS ts FROM comms_task_reopens r LEFT JOIN users u ON u.id = r.reopened_by WHERE r.task_id = :t ORDER BY r.id DESC', ['t' => $t['id']]);
    foreach ($reopens as &$r) { $r['when'] = comms_fmt_ts((int)$r['ts'], 'd M Y, h:i A'); $r['affected_names'] = array_values(comms_user_names(json_decode((string)$r['affected'], true) ?: [])); }
    $isParticipantRole = array_intersect(['assigner', 'reviewer'], $t['_roles']);
    $approved = array_values(array_filter(array_map(static fn($a) => $a, Database::fetchAll("SELECT a.user_id, u.name FROM comms_task_assignees a JOIN users u ON u.id = a.user_id WHERE a.task_id = :t AND a.removed_at IS NULL AND a.status = 'approved'", ['t' => $t['id']]))));
    $open = in_array($t['status'], ['active', 'partially_completed'], true);
    return [
        'submissions' => $subs, 'reviewer_changes' => $changes, 'reopens' => $reopens, 'reviewer_available' => $reviewerOk,
        'reviewer_flagged' => $open && !$reviewerOk,
        'can_reassign' => $open && $isParticipantRole && comms_actor_can($me, 'comms.reviewer_reassign'),
        'can_reopen' => in_array($t['status'], ['completed', 'partially_completed'], true) && $isParticipantRole && comms_actor_can($me, 'comms.task_reopen') && $approved,
        'reopenable' => $approved, 'review_overdue' => (bool)array_filter($subs, static fn($s) => $s['review_overdue']),
        'reviewer_choices' => ($open && $isParticipantRole && comms_actor_can($me, 'comms.reviewer_reassign')) ? array_values(array_filter(comms_eligible_users('comms.task_review', '', 200), static fn($u) => (int)$u['id'] !== (int)$t['reviewer_id'] && !Database::exists('SELECT 1 FROM comms_task_assignees WHERE task_id = :t AND user_id = :u AND removed_at IS NULL', ['t' => $t['id'], 'u' => $u['id']]))) : [],
    ];
}
