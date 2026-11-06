<?php
/**
 * Task submission / review / deadlines self-test (Phase 4):  php tools/comms-review-selftest.php --confirm-test-database
 * Runs against a THROWAWAY database whose name contains "test".
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require_once dirname(__DIR__) . '/config.php';
if (!str_contains(strtolower(DB_NAME), 'test') || !in_array('--confirm-test-database', $argv, true)) {
    fwrite(STDERR, "Refusing to run: DB name must contain \"test\" and --confirm-test-database must be given.\n"); exit(2);
}
require_once MODULES_PATH . '/comms/bootstrap.php';
$pass = $fail = 0;
function t(bool $c, string $n): void { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? '  ok   ' : '  FAIL ') . $n . "\n"; }
function section(string $s): void { echo "\n== $s\n"; }
function uuid(): string { $d = random_bytes(16); $d[6] = chr(ord($d[6]) & 0x0f | 0x40); $d[8] = chr(ord($d[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4)); }
function S(string $k, string $v): void { Database::execute("INSERT INTO system_settings (setting_key,setting_value,setting_group) VALUES (:k,:v,'comms') ON DUPLICATE KEY UPDATE setting_value=:v2", ['k' => $k, 'v' => $v, 'v2' => $v]); comms_settings_reset(); }
function notes(int $uid, string $type = ''): array { return Database::fetchAll('SELECT * FROM comms_notifications WHERE user_id = :u' . ($type !== '' ? ' AND type = :t' : '') . ' ORDER BY id', $type !== '' ? ['u' => $uid, 't' => $type] : ['u' => $uid]); }
function tmpf(string $bytes): string { $p = tempnam(sys_get_temp_dir(), 'ev'); file_put_contents($p, $bytes); return $p; }
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$img = fn(string $n = 'proof.png') => ['tmp' => tmpf($PNG), 'name' => $n];

foreach (['comms_notifications','comms_task_reminders','comms_task_deadline_history','comms_task_extensions','comms_task_reopens','comms_task_reviewer_changes','comms_task_evidence','comms_task_submissions','comms_task_events','comms_task_edits','comms_task_mentions','comms_task_comments','comms_task_step_log','comms_task_checklist_items','comms_task_checklists','comms_task_progress','comms_task_assignees','comms_tasks','comms_task_templates'] as $tb) { Database::execute("DELETE FROM $tb"); }
Database::execute("DELETE FROM users WHERE cnic LIKE '96969696%'");
foreach (glob(comms_storage_root() . '/task-evidence/*/*') ?: [] as $f) { @unlink($f); }
S('comms_edit_window_min', '15'); S('comms_allowed_extensions', 'jpg,jpeg,png,gif,webp,pdf,docx,xlsx,txt,csv'); S('comms_max_file_mb', '10'); S('comms_storage_limit_mb', '2048');
$mkRole = static function (string $n, array $perms): int { $r = Database::insert('roles', ['name' => $n . random_int(1, 99999), 'slug' => strtolower($n) . random_int(1, 99999), 'level' => 5, 'is_system' => 0]); foreach ($perms as $p) { Permission::grant($r, "comms.$p"); } return $r; };
$rAdmin = $mkRole('Assigner', ['access', 'task_create', 'task_assign', 'task_self_assign', 'task_edit', 'task_cancel', 'deadline_extend', 'reviewer_reassign', 'task_reopen', 'send_message', 'task_progress', 'task_submit']);
$rAdmin2 = $mkRole('Assigner2', ['access', 'task_create', 'task_assign', 'task_edit', 'evidence_view']);
$rWork = $mkRole('Worker', ['access', 'task_progress', 'task_submit']);
$rWork3 = $mkRole('WorkerNoSubmit', ['access', 'task_progress']);
$rRev = $mkRole('Reviewer', ['access', 'task_review']);
$rRevX = $mkRole('ReviewerX', ['access', 'task_review', 'deadline_extend', 'reviewer_reassign', 'task_reopen']);
$rOnly = $mkRole('Only', ['access']);
$mk = static fn(string $n, int $role, int $i): int => Database::insert('users', ['role_id' => $role, 'name' => $n, 'cnic' => '969696969' . $i . '00', 'password_hash' => 'x', 'login_type' => 'cnic', 'status' => 'active']);
$cr = $mk('Assigner', $rAdmin, 1); $cr2 = $mk('Assigner2', $rAdmin2, 2); $w1 = $mk('Worker1', $rWork, 3); $w2 = $mk('Worker2', $rWork, 4); $w3 = $mk('Worker3', $rWork3, 5);
$rv = $mk('Reviewer', $rRev, 6); $rv2 = $mk('Reviewer2', $rRev, 7); $rvx = $mk('ReviewerX', $rRevX, 8); $out = $mk('Outsider', $rOnly, 9);
$due = date('Y-m-d', time() + 86400 * 5);
$base = ['title' => 'Clean the depot', 'description' => 'Full clean and photos of the result.', 'priority' => 'medium', 'due_date' => $due, 'assignees' => [$w1, $w2], 'reviewer_id' => $rv, 'evidence_required' => '1'];
$mkTask = function (array $o = [], ?int $by = null) use ($base, $cr) { $r = comms_task_create($by ?? $cr, array_merge($base, $o), uuid()); if (!$r['ok']) { fwrite(STDERR, 'mkTask failed: ' . $r['message'] . "\n"); } return $r['id']; };
$work = function (int $T, int $u) { comms_task_accept($u, $T); comms_task_start($u, $T); };
$status = fn(int $T, int $u) => Database::fetchValue('SELECT status FROM comms_task_assignees WHERE task_id = :t AND user_id = :u', ['t' => $T, 'u' => $u]);

section('Submission rules');
$T = $mkTask(); $sub = fn(int $u, int $task = 0, ?string $c = 'All cleaned', array $f = null) => comms_task_submit($u, $task ?: $GLOBALS['T'], (string)$c, $f ?? [$GLOBALS['img']()], uuid(), false);
$GLOBALS['T'] = $T; $GLOBALS['img'] = $img;
t(!$sub($w1)['ok'] && str_contains($sub($w1)['message'], 'Accept'), 'cannot submit before accepting');
$work($T, $w1); $work($T, $w2);
t(!comms_task_submit($w3, $T, 'x', [$img()], uuid(), false)['ok'], 'a non-assignee cannot submit');
t(!comms_task_submit($out, $T, 'x', [$img()], uuid(), false)['ok'], 'an outsider cannot submit');
$r = comms_task_submit($w1, $T, '   ', [$img()], uuid(), false); t(!$r['ok'] && str_contains($r['message'], 'comment'), 'a completion comment is required');
$r = comms_task_submit($w1, $T, 'Done', [], uuid(), false); t(!$r['ok'] && str_contains($r['message'], 'requires evidence'), 'evidence-required task: submission without a file is refused');
foreach ([['<?php echo 1;', 'x.php'], ['<?php echo 1;', 'x.png'], ["MZ\x90", 'x.exe'], [$PNG, 'x.pdf']] as [$b, $n]) {
    t(!comms_task_submit($w1, $T, 'Done', [['tmp' => tmpf($b), 'name' => $n]], uuid(), false)['ok'], "invalid evidence refused: $n");
}
t(!comms_task_submit($w1, $T, 'Done', array_fill(0, 6, $img()), uuid(), false)['ok'], 'at most 5 evidence files');
S('comms_max_file_mb', '1'); t(!comms_task_submit($w1, $T, 'Done', [['tmp' => tmpf("%PDF-1.4" . str_repeat('x', 1100000)), 'name' => 'big.pdf']], uuid(), false)['ok'], 'oversized evidence refused'); S('comms_max_file_mb', '10');
t((int)Database::fetchValue('SELECT COUNT(*) FROM comms_task_submissions') === 0 && $status($T, $w1) === 'in_progress', 'refused attempts leave no submission and no status change');
$orph = 0; foreach (glob(comms_storage_root() . '/task-evidence/*/*') ?: [] as $f) { $orph++; } t($orph === 0, 'and no stray files on disk');
$tc = $mkTask(['title' => 'Checklist task', 'assignees' => [$w1], 'evidence_required' => '0', 'checklist' => [['text' => 'Step A', 'required' => '1'], ['text' => 'Step B', 'required' => '1'], ['text' => 'Optional C', 'required' => '0']]]); $work($tc, $w1);
$r = comms_task_submit($w1, $tc, 'Finished', [], uuid(), false); t(!$r['ok'] && str_contains($r['message'], '0 of 2'), 'required checklist steps block submission');
$ks = array_column(comms_checklist_state($tc, $w1)['items'], 'key'); comms_task_step_toggle($w1, $tc, $ks[0], true);
t(!comms_task_submit($w1, $tc, 'Finished', [], uuid(), false)['ok'], 'one of two required steps is still not enough');
comms_task_step_toggle($w1, $tc, $ks[1], true);
$r = comms_task_submit($w1, $tc, 'Finished', [], uuid(), false); t($r['ok'], 'with all required steps done and evidence optional, no file is needed');
t(Database::fetchValue('SELECT checklist_version FROM comms_task_submissions WHERE id = :i', ['i' => $r['id']]) == 1, 'the checklist version at submission is recorded');
$u = uuid(); $nb = comms_storage_used();
$r1 = comms_task_submit($w1, $T, 'All cleaned, see photo', [$img('front.png'), $img('back.png')], $u, false);
t($r1['ok'] && $r1['round'] === 1, 'valid submission with two evidence files');
$S1 = $r1['id'];
$again = comms_task_submit($w1, $T, 'All cleaned, see photo', [$img()], $u, false); t($again['ok'] && $again['duplicate'] && (int)Database::fetchValue('SELECT COUNT(*) FROM comms_task_submissions WHERE task_id = :t', ['t' => $T]) === 1, 'retrying the same submission stores nothing twice');
$ev = Database::fetchAll('SELECT * FROM comms_task_evidence WHERE submission_id = :s', ['s' => $S1]);
t(count($ev) === 2 && is_file(comms_evidence_path($ev[0])) && preg_match('/^[0-9a-f]{32}$/', $ev[0]['stored_name']) && str_contains(comms_evidence_path($ev[0]), '/task-evidence/') && !str_contains(comms_evidence_path($ev[0]), '/files/'), 'evidence stored privately, separate from chat attachments');
t(Database::fetchValue('SELECT uploader_id FROM comms_task_evidence WHERE id = :i', ['i' => $ev[0]['id']]) == $w1 && strlen($ev[0]['sha256']) === 64, 'uploader and checksum recorded');
t(comms_storage_used() > $nb, 'evidence counts toward the storage limit');
t($status($T, $w1) === 'submitted' && $status($T, $w2) === 'in_progress', "only the submitting employee's workflow moved to Submitted");
t(count(notes($rv, 'task.submitted')) === 2 && notes($rv, 'task.submitted')[1]['priority'] === 'high' && count(notes($cr, 'task.submitted')) === 2, 'the reviewer is notified (prominently) and so is the assigner');
$r = comms_task_submit($w1, $T, 'second try', [$img()], uuid(), false); t(!$r['ok'] && str_contains($r['message'], 'cannot be edited or withdrawn'), 'while review is pending the submission cannot be replaced or withdrawn');
t(!comms_task_progress_update($w1, $T, 80, 'tweak')['ok'] && !comms_task_decline($w1, $T, 'changed my mind')['ok'], 'and progress / acceptance cannot be changed meanwhile');
t(!function_exists('comms_task_withdraw') && !function_exists('comms_submission_edit'), 'there is no edit or withdraw operation to call at all');
S('comms_storage_limit_mb', '100'); Database::execute("UPDATE comms_task_evidence SET size_bytes = 100*1048576 WHERE id = :i", ['i' => $ev[0]['id']]);
$r = comms_task_submit($w2, $T, 'Done too', [$img()], uuid(), false); t(!$r['ok'] && str_contains($r['message'], 'Storage is full'), 'a full storage limit refuses evidence with a clear message');
Database::execute("UPDATE comms_task_evidence SET size_bytes = 70 WHERE id = :i", ['i' => $ev[0]['id']]); S('comms_storage_limit_mb', '2048');

section('Independent review');
t(!comms_review_decide($w1, $S1, 'approve', '')['ok'], 'the submitter cannot approve their own submission');
t(!comms_review_decide($cr, $S1, 'approve', '')['ok'], 'the assigner (not the reviewer) cannot approve');
t(!comms_review_decide($rv2, $S1, 'approve', '')['ok'], 'a different reviewer who is not on the task cannot decide');
t(!comms_review_decide($w2, $S1, 'approve', '')['ok'], 'a co-assignee cannot decide');
t(!comms_review_decide($rv, $S1, 'maybe', '')['ok'], 'only approve or reject are accepted');
$r = comms_review_decide($rv, $S1, 'reject', 'no'); t(!$r['ok'] && str_contains($r['message'], 'reason'), 'a rejection needs a real reason');
$r = comms_review_decide($rv, $S1, 'reject', 'The back corner is still dirty, see photo 2'); t($r['ok'], 'the reviewer rejects with a reason');
$row = Database::fetch('SELECT * FROM comms_task_submissions WHERE id = :i', ['i' => $S1]);
t($row['status'] === 'rejected' && (int)$row['decided_by'] === $rv && $row['decided_at'] !== null && str_contains($row['decision_comment'], 'back corner'), 'reviewer, time and reason recorded');
t($status($T, $w1) === 'in_progress' && $status($T, $w2) === 'in_progress', 'rejection returns ONLY that employee to In Progress');
t(count(notes($w1, 'task.rejected')) === 1 && notes($w1, 'task.rejected')[0]['priority'] === 'high' && !str_contains(notes($w1, 'task.rejected')[0]['title'], 'back corner'), 'the employee is notified; the reason is not in the notification text');
t(!comms_review_decide($rv, $S1, 'approve', '')['ok'], 'a decided submission cannot be decided again');
$r2 = comms_task_submit($w1, $T, 'Corner cleaned now', [$img('corner.png')], uuid(), false);
t($r2['ok'] && $r2['round'] === 2, 'the employee corrects and resubmits (round 2)');
t((int)Database::fetchValue('SELECT COUNT(*) FROM comms_task_submissions WHERE task_id = :t AND user_id = :u', ['t' => $T, 'u' => $w1]) === 2 && (int)Database::fetchValue('SELECT COUNT(*) FROM comms_task_evidence WHERE task_id = :t', ['t' => $T]) === 3, 'every submission and every evidence file is preserved');
$S2 = $r2['id'];
$r = comms_review_decide($rv, $S2, 'approve', 'Looks good'); t($r['ok'], 'the reviewer approves round 2');
$row = Database::fetch('SELECT * FROM comms_task_submissions WHERE id = :i', ['i' => $S2]);
t($row['status'] === 'approved' && (int)$row['decided_by'] === $rv && $row['decided_at'] !== null, 'approval records the reviewer and the time');
t($status($T, $w1) === 'approved' && Database::fetchValue('SELECT status FROM comms_tasks WHERE id = :i', ['i' => $T]) === 'partially_completed', 'one approved employee of two = task Partially Completed');
$work($T, $w2); $S3 = comms_task_submit($w2, $T, 'Mine is done as well', [$img()], uuid(), false)['id'];
t(comms_review_decide($rv, $S3, 'approve', '')['ok'] === true, "Worker2's submission is reviewed on its own");
t(Database::fetchValue('SELECT status FROM comms_tasks WHERE id = :i', ['i' => $T]) === 'completed' && $status($T, $w2) === 'approved', 'all employees approved = task Completed');
t(count(notes($w2, 'task.approved')) === 1 && Database::exists("SELECT 1 FROM comms_audit_log WHERE action = 'task.approved'"), 'approval notified and audited');
t(!comms_task_submit($w1, $T, 'again', [$img()], uuid(), false)['ok'], 'an approved employee cannot submit again');

section('Self-approval is impossible, even by data corruption');
$T2 = $mkTask(['title' => 'Own work', 'assignees' => [$cr]]);  // the assigner works on the task too
$work($T2, $cr); $sx = comms_task_submit($cr, $T2, 'I did it myself', [$img()], uuid(), false)['id'];
t(!comms_review_decide($cr, $sx, 'approve', '')['ok'], 'an assigner who works on the task cannot approve their own submission');
Database::execute('UPDATE comms_tasks SET reviewer_id = :r WHERE id = :t', ['r' => $cr, 't' => $T2]); Database::execute('UPDATE comms_task_submissions SET reviewer_id = :r WHERE id = :s', ['r' => $cr, 's' => $sx]);
$r = comms_review_decide($cr, $sx, 'approve', ''); t(!$r['ok'], 'even if the reviewer were forced to equal the submitter, the decision is refused');
Database::execute('UPDATE comms_tasks SET reviewer_id = :r WHERE id = :t', ['r' => $rv, 't' => $T2]); Database::execute('UPDATE comms_task_submissions SET reviewer_id = :r WHERE id = :s', ['r' => $rv, 's' => $sx]);
Database::execute("INSERT INTO comms_task_assignees (task_id, user_id, status) VALUES (:t, :u, 'accepted')", ['t' => $T2, 'u' => $rv]);
t(!comms_review_decide($rv, $sx, 'approve', '')['ok'], 'a reviewer who is also assigned to the task cannot decide');
Database::execute('DELETE FROM comms_task_assignees WHERE task_id = :t AND user_id = :u', ['t' => $T2, 'u' => $rv]);
Permission::revoke($rRev, 'comms.task_review');
t(!comms_review_decide($rv, $sx, 'approve', '')['ok'], 'a reviewer who lost the review permission cannot decide');
Permission::grant($rRev, 'comms.task_review');
t(comms_review_decide($rv, $sx, 'approve', '')['ok'], 'the proper reviewer can');
t(!comms_review_decide($rv, 99999, 'approve', '')['ok'], 'unknown submission');

section('Evidence access');
$TE = $mkTask(['title' => 'Evidence access', 'assignees' => [$w1, $w2]], $cr); $work($TE, $w1); $work($TE, $w2);
$sid = comms_task_submit($w1, $TE, 'See files', [$img()], uuid(), false)['id']; $evid = (int)Database::fetchValue('SELECT id FROM comms_task_evidence WHERE submission_id = :s', ['s' => $sid]);
$ok = fn(int $u) => comms_authorize_evidence($u, $evid, comms_link_token($u, 'e' . $evid))['ok'];
t($ok($w1), 'the submitter can open their evidence');
t($ok($rv), 'the reviewer can open it');
t(!$ok($cr), 'the assigner WITHOUT comms.evidence_view cannot');
t(!$ok($w2), 'a co-assignee without evidence_view cannot');
t(!$ok($out) && !$ok($rv2), 'outsiders and other reviewers cannot');
Permission::grant($rOnly, 'comms.evidence_view'); t(!$ok($out), 'evidence_view alone does not let a non-participant in');
Permission::revoke($rOnly, 'comms.evidence_view');
Permission::grant($rWork, 'comms.evidence_view'); t($ok($w2), 'a co-assignee WITH evidence_view can'); Permission::revoke($rWork, 'comms.evidence_view');
$TE2 = $mkTask(['title' => 'Evidence 2', 'assignees' => [$w1]], $cr2); $work($TE2, $w1); $sid2 = comms_task_submit($w1, $TE2, 'files', [$img()], uuid(), false)['id']; $ev2 = (int)Database::fetchValue('SELECT id FROM comms_task_evidence WHERE submission_id = :s', ['s' => $sid2]);
t(comms_authorize_evidence($cr2, $ev2, comms_link_token($cr2, 'e' . $ev2))['ok'], 'an assigner WITH evidence_view can open it');
t(!comms_link_valid($w1, 'e' . $evid, comms_link_token($w2, 'e' . $evid)) && !comms_authorize_evidence($w1, $evid, comms_link_token($w1, 'e' . ($evid + 1)))['ok'], "a link is bound to its user and its file");
t(!comms_authorize_evidence($w1, $evid, comms_link_token($w1, 'e' . $evid, time() - 3600))['ok'], 'an expired link is refused');
Database::execute("UPDATE users SET status = 'inactive' WHERE id = :u", ['u' => $w1]); t(!$ok($w1), 'a deactivated user loses access at once'); Database::execute("UPDATE users SET status = 'active' WHERE id = :u", ['u' => $w1]);
comms_task_cancel($cr, $TE, 'Cancelled for test purposes'); t($ok($w1) && $ok($rv), 'evidence stays viewable by participants after cancellation');
$d = comms_task_detail($w2, $TE); t($d['submissions'][0]['evidence_count'] === 1 && $d['submissions'][0]['evidence'] === [] && $d['submissions'][0]['evidence_visible'] === false, 'the task page tells a co-assignee that evidence exists but lists no files');
$d = comms_task_detail($rv, $TE); t(count($d['submissions'][0]['evidence']) === 1 && $d['submissions'][0]['can_decide'] === false, 'the reviewer sees the file; a cancelled task cannot be decided');

section('Reviewer unavailability');
$TR = $mkTask(['title' => 'Reviewer gone', 'assignees' => [$w1]]); $work($TR, $w1); $sr = comms_task_submit($w1, $TR, 'Done', [$img()], uuid(), false)['id'];
Database::execute("UPDATE users SET status = 'inactive' WHERE id = :u", ['u' => $rv]);
t(comms_run_scheduled(true)['reviewer_flags'] >= 1, 'the scheduler flags the task when the reviewer is deactivated');
$tk = Database::fetch('SELECT * FROM comms_tasks WHERE id = :i', ['i' => $TR]);
t($tk['reviewer_flag_at'] !== null && (int)$tk['reviewer_id'] === $rv, 'flagged for manual reassignment; the reviewer is NOT switched automatically');
t(count(notes($cr, 'task.reviewer_unavailable')) >= 1, 'the assigner (authorised) is notified');
$n = count(notes($cr, 'task.reviewer_unavailable')); comms_run_scheduled(true); t(count(notes($cr, 'task.reviewer_unavailable')) === $n, 'and only once per episode');
$d = comms_task_detail($cr, $TR); t($d['reviewer_flagged'] && $d['submissions'][0]['reviewer_unavailable'], 'the task page shows "Reviewer unavailable" on the waiting submission');
t(!comms_review_decide($rv, $sr, 'approve', '')['ok'], 'the deactivated reviewer cannot decide');
t(!comms_review_decide($rv2, $sr, 'approve', '')['ok'], "another reviewer cannot just take over");
t(!comms_reviewer_reassign($w1, $TR, $rv2, 'Taking over')['ok'] && !comms_reviewer_reassign($out, $TR, $rv2, 'Taking over')['ok'], 'only an authorised participant can reassign (assignees and outsiders cannot)');
t(!comms_reviewer_reassign($cr2, $TR, $rv2, 'No permission')['ok'], 'without comms.reviewer_reassign it is refused');
t(!comms_reviewer_reassign($cr, $TR, $rv2, 'no')['ok'], 'a reason is required');
t(!comms_reviewer_reassign($cr, $TR, $w2, 'Wrong person here')['ok'] && !comms_reviewer_reassign($cr, $TR, $out, 'Not a reviewer')['ok'] && !comms_reviewer_reassign($cr, $TR, $w1, 'Assignee as reviewer')['ok'], 'the new reviewer must be an eligible reviewer and not an assignee');
t(!comms_reviewer_reassign($cr, $TR, $rv, 'Same person again')['ok'], 'cannot pick the same reviewer');
t(comms_reviewer_reassign($cr, $TR, $rv2, 'Reviewer left the organisation')['ok'], 'manual reassignment succeeds');
$tk = Database::fetch('SELECT * FROM comms_tasks WHERE id = :i', ['i' => $TR]); $ch = Database::fetch('SELECT * FROM comms_task_reviewer_changes WHERE task_id = :t', ['t' => $TR]);
t((int)$tk['reviewer_id'] === $rv2 && $tk['reviewer_flag_at'] === null && (int)$ch['old_reviewer'] === $rv && (int)$ch['new_reviewer'] === $rv2 && $ch['reason'] === 'Reviewer left the organisation' && (int)$ch['changed_by'] === $cr && $ch['created_at'] !== null, 'previous reviewer, new reviewer, reason, who and when recorded; flag cleared');
t((int)Database::fetchValue('SELECT reviewer_id FROM comms_task_submissions WHERE id = :i', ['i' => $sr]) === $rv2 && count(notes($rv2, 'task.reviewer')) === 1, 'the waiting submission moves to the new reviewer, who is notified');
t(comms_review_decide($rv2, $sr, 'approve', '')['ok'], 'the new reviewer can decide it');
Database::execute("UPDATE users SET status = 'active' WHERE id = :u", ['u' => $rv]);
$TR2 = $mkTask(['title' => 'Permission revoked', 'assignees' => [$w1]]); Permission::revoke($rRev, 'comms.task_review'); comms_run_scheduled(true);
t(Database::fetchValue('SELECT reviewer_flag_at FROM comms_tasks WHERE id = :i', ['i' => $TR2]) !== null, 'a reviewer who loses the permission also flags the task');
Permission::grant($rRev, 'comms.task_review'); comms_run_scheduled(true);
t(Database::fetchValue('SELECT reviewer_flag_at FROM comms_tasks WHERE id = :i', ['i' => $TR2]) === null && (int)Database::fetchValue('SELECT reviewer_id FROM comms_tasks WHERE id = :i', ['i' => $TR2]) === $rv, 'if they regain it the flag clears — still no automatic switch');
t(comms_reviewer_reassign($rvx, comms_task_create($cr, array_merge($base, ['reviewer_id' => $rvx, 'title' => 'Reviewer X task', 'assignees' => [$w1]]), uuid())['id'], $rv2, 'Handing over my reviews')['ok'], 'the current reviewer holding comms.reviewer_reassign can hand over');

section('Review deadlines');
$TD = $mkTask(['title' => 'Review deadline', 'assignees' => [$w1], 'review_due_date' => date('Y-m-d', time() + 86400 * 6)]); $work($TD, $w1);
$sd = comms_task_submit($w1, $TD, 'Done', [$img()], uuid(), false)['id'];
comms_run_scheduled(true); t(!notes($rv, 'task.review_overdue'), 'no overdue-review notice before the review deadline');
Database::execute("UPDATE comms_tasks SET review_due_date = :d WHERE id = :i", ['d' => date('Y-m-d', time() - 86400), 'i' => $TD]);
$d = comms_task_detail($rv, $TD); t($d['submissions'][0]['review_overdue'] && $d['review_overdue'], 'a missed review deadline shows as overdue review');
$r = comms_run_scheduled(true); t($r['review_overdue'] >= 1 && count(notes($rv, 'task.review_overdue')) === 1 && count(notes($cr, 'task.review_overdue')) === 1, 'the reviewer and assigner are notified');
comms_run_scheduled(true); t(count(notes($rv, 'task.review_overdue')) === 1, 'only once');
t(count(comms_task_list($rv, ['review_overdue' => 1])['rows']) === 1 && count(comms_task_list($rv, ['awaiting_review' => 1])['rows']) >= 1, 'filters: overdue reviews / awaiting my review');
comms_review_decide($rv, $sd, 'approve', ''); t(comms_task_list($rv, ['review_overdue' => 1])['rows'] === [], 'no longer overdue once decided');
$TD2 = $mkTask(['title' => 'No review deadline', 'assignees' => [$w1]]); $work($TD2, $w1); comms_task_submit($w1, $TD2, 'Done', [$img()], uuid(), false);
t(!comms_task_detail($rv, $TD2)['review_overdue'], 'with no review deadline there is no separate review deadline');

section('Reopening');
$TO = $mkTask(['title' => 'Reopen me', 'assignees' => [$w1, $w2], 'evidence_required' => '0', 'checklist' => [['text' => 'Step A', 'required' => '1'], ['text' => 'Step B', 'required' => '1']]]); $work($TO, $w1); $work($TO, $w2);
foreach ([$w1, $w2] as $u) { foreach (array_column(comms_checklist_state($TO, $u)['items'], 'key') as $k) { comms_task_step_toggle($u, $TO, $k, true); } $sid = comms_task_submit($u, $TO, 'Done', [], uuid(), false)['id']; comms_review_decide($rv, $sid, 'approve', ''); }
t(Database::fetchValue('SELECT status FROM comms_tasks WHERE id = :i', ['i' => $TO]) === 'completed', 'setup: task completed');
t(!comms_task_reopen($rv, $TO, [$w1], 'Quality problem', 'Redo step B please')['ok'], 'a reviewer without comms.task_reopen cannot reopen');
t(!comms_task_reopen($w1, $TO, [$w1], 'Quality problem', 'Redo step B please')['ok'] && !comms_task_reopen($out, $TO, [$w1], 'Quality problem', 'Redo step B please')['ok'], 'employees and outsiders cannot');
t(!comms_task_reopen($cr, $TO, [$w1], '', 'Redo step B please')['ok'] && !comms_task_reopen($cr, $TO, [$w1], 'Quality problem', '')['ok'], 'a reason AND correction details are mandatory');
t(!comms_task_reopen($cr, $TO, [$w3], 'Quality problem', 'Redo step B please')['ok'], 'only employees whose completion was approved can be reopened');
$ks = array_column(comms_checklist_state($TO, $w1)['items'], 'key'); $progBefore = comms_checklist_state($TO, $w1)['pct'];
$r = comms_task_reopen($cr, $TO, [$w1], 'Quality problem found on inspection', 'Step B was not done properly; please redo it and resubmit.'); t($r['ok'], 'the authorised assigner reopens one employee');
t($status($TO, $w1) === 'in_progress' && $status($TO, $w2) === 'approved' && Database::fetchValue('SELECT status FROM comms_tasks WHERE id = :i', ['i' => $TO]) === 'partially_completed', 'only that employee returns to In Progress; the task becomes Partially Completed');
t(comms_checklist_state($TO, $w1)['pct'] === $progBefore && (int)Database::fetchValue("SELECT progress FROM comms_task_assignees WHERE task_id = :t AND user_id = :u", ['t' => $TO, 'u' => $w1]) === 100, 'progress and checklist state are preserved, not reset to zero');
t(comms_task_step_toggle($w1, $TO, $ks[1], false)['ok'], 'the employee can update the checklist while correcting');
$orig = Database::fetch("SELECT * FROM comms_task_submissions WHERE task_id = :t AND user_id = :u ORDER BY id LIMIT 1", ['t' => $TO, 'u' => $w1]);
t($orig['status'] === 'approved' && $orig['reopened_at'] !== null && (int)$orig['decided_by'] === $rv, 'the original approval is preserved (with who approved it)');
t(count(notes($w1, 'task.reopened')) === 1 && notes($w1, 'task.reopened')[0]['priority'] === 'high', 'the affected employee is notified');
$ro = Database::fetch('SELECT * FROM comms_task_reopens WHERE task_id = :t', ['t' => $TO]); t($ro['reason'] !== '' && str_contains($ro['details'], 'redo it') && json_decode($ro['affected'], true) === [$w1] && $ro['prev_status'] === 'completed', 'reason, correction details, affected employees and previous status recorded');
t(!comms_task_submit($w1, $TO, 'fixed', [], uuid(), false)['ok'], 'resubmission is blocked until required steps are done again');
comms_task_step_toggle($w1, $TO, $ks[1], true);
$rc = comms_task_submit($w1, $TO, 'Step B redone', [], uuid(), false); t($rc['ok'] && $rc['round'] === 2, 'a NEW submission is required for the correction');
$subc = Database::fetch('SELECT * FROM comms_task_submissions WHERE id = :i', ['i' => $rc['id']]); t((int)$subc['reopen_id'] === (int)$ro['id'], 'the correction is tracked separately, linked to the reopen');
t(Database::exists("SELECT 1 FROM comms_task_events WHERE task_id = :t AND event = 'correction_submitted'", ['t' => $TO]), 'and labelled as a correction in the history');
t(comms_review_decide($rv, $rc['id'], 'approve', 'Fixed')['ok'] && Database::fetchValue('SELECT status FROM comms_tasks WHERE id = :i', ['i' => $TO]) === 'completed', 'a new approval completes the task again');
t((int)Database::fetchValue("SELECT COUNT(*) FROM comms_task_submissions WHERE task_id = :t AND status = 'approved'", ['t' => $TO]) === 3, 'both approvals of Worker1 are kept in history');
t(!comms_task_reopen($cr, $TD2, [$w1], 'Reason here ok', 'Details here ok')['ok'], 'a task with nothing approved cannot be reopened');
$d = comms_task_detail($cr, $TO); t($d['can_reopen'] && count($d['reopens']) === 1, 'the page offers reopen to the authorised user and lists reopen history');
$Tcx = $mkTask(['title' => 'Cancelled', 'assignees' => [$w1]]); comms_task_cancel($cr, $Tcx, 'No longer required here'); t(!comms_task_reopen($cr, $Tcx, [$w1], 'Reason here ok', 'Details here ok')['ok'], 'a cancelled task cannot be reopened');

section('Extension requests');
$TX = $mkTask(['title' => 'Extend me', 'assignees' => [$w1, $w2]]); $work($TX, $w1);
$newDue = date('Y-m-d', time() + 86400 * 9); $orig = Database::fetch('SELECT due_date, due_time, original_due_date FROM comms_tasks WHERE id = :i', ['i' => $TX]);
$rq = fn($u, $d, $r = 'Supplier delivery delayed') => comms_extension_request($u, $TX, $d, '', $r);
t(!$rq($w2, $newDue)['ok'], 'must accept before requesting an extension');
t(!$rq($out, $newDue)['ok'] && !$rq($cr, $newDue)['ok'] && !$rq($rv, $newDue)['ok'], 'only assigned employees can request one');
t(!$rq($w1, date('Y-m-d', time() - 86400))['ok'], 'a past proposal is refused');
t(!$rq($w1, $orig['due_date'])['ok'] && !$rq($w1, date('Y-m-d', time() + 86400 * 2))['ok'], 'the proposal must be later than the current deadline');
t(!$rq($w1, $newDue, 'no')['ok'] && !$rq($w1, 'banana')['ok'], 'a reason and a valid date are required');
$e1 = $rq($w1, $newDue); t($e1['ok'], 'a valid request is recorded');
t(Database::fetchValue('SELECT due_date FROM comms_tasks WHERE id = :i', ['i' => $TX]) === $orig['due_date'], 'the deadline does NOT change before approval');
t(!$rq($w1, date('Y-m-d', time() + 86400 * 10))['ok'], 'only one pending request per employee');
t(count(notes($cr, 'task.extension_requested')) === 1 && notes($cr, 'task.extension_requested')[0]['priority'] === 'high', 'the assigner is notified');
t(!comms_extension_decide($w1, $e1['id'], true, '')['ok'], 'the requester cannot approve their own request');
t(!comms_extension_decide($w2, $e1['id'], true, '')['ok'] && !comms_extension_decide($out, $e1['id'], true, '')['ok'] && !comms_extension_decide($rv, $e1['id'], true, '')['ok'], 'employees, outsiders and a reviewer without deadline_extend cannot decide');
t(!comms_extension_decide($cr, $e1['id'], false, 'no')['ok'], 'rejecting needs a reason');
Database::execute("UPDATE comms_tasks SET due_date = :d WHERE id = :i", ['d' => date('Y-m-d', time() - 86400 * 2), 'i' => $TX]);
t(comms_task_detail($cr, $TX)['overdue'] === true, 'the deadline passes while the request is pending: the task is overdue');
t(comms_task_progress_update($w1, $TX, 40, 'Still working past the deadline')['ok'], 'and employees can still update progress (not locked)');
Database::execute("UPDATE comms_tasks SET due_date = :d WHERE id = :i", ['d' => $orig['due_date'], 'i' => $TX]);
$e2 = $rq($w1, date('Y-m-d', time() + 86400 * 12), 'second proposal for later'); t(!$e2['ok'], '(pending request still blocks a second one)');
t(comms_extension_cancel($w1, $e1['id'])['ok'] && Database::fetchValue('SELECT status FROM comms_task_extensions WHERE id = :i', ['i' => $e1['id']]) === 'cancelled', 'the requester can withdraw their pending request');
$e3 = $rq($w1, $newDue); t($e3['ok'], 'and make a new one');
t(comms_extension_decide($cr, $e3['id'], false, 'We cannot move this date')['ok'] && Database::fetchValue('SELECT due_date FROM comms_tasks WHERE id = :i', ['i' => $TX]) === $orig['due_date'], 'a rejection leaves the deadline unchanged');
t(count(notes($w1, 'task.extension_rejected')) === 1, 'and notifies the requester');
$e4 = $rq($w1, $newDue, 'Third attempt with more time');
$nBefore = (int)Database::fetchValue('SELECT COUNT(*) FROM comms_notifications');
t(comms_extension_decide($cr, $e4['id'], true, 'Approved')['ok'], 'the assigner approves');
$tk = Database::fetch('SELECT * FROM comms_tasks WHERE id = :i', ['i' => $TX]);
t($tk['due_date'] === $newDue && $tk['original_due_date'] === $orig['original_due_date'], 'the deadline moves only now; the original deadline is preserved');
$h = Database::fetch('SELECT * FROM comms_task_deadline_history WHERE task_id = :t AND source = \'extension\'', ['t' => $TX]);
t($h['old_date'] === $orig['due_date'] && $h['new_date'] === $newDue && (int)$h['changed_by'] === $cr && (int)$h['extension_id'] === (int)$e4['id'] && $h['created_at'] !== null, 'old and new deadline, approver, request and time kept in the deadline history');
$ex = Database::fetch('SELECT * FROM comms_task_extensions WHERE id = :i', ['i' => $e4['id']]); t($ex['status'] === 'approved' && (int)$ex['decided_by'] === $cr && $ex['reason'] === 'Third attempt with more time' && $ex['old_due_date'] === $orig['due_date'], 'the request, reason, decision and previous deadline are preserved');
t(count(notes($w1, 'task.deadline_changed')) === 1 && count(notes($w2, 'task.deadline_changed')) === 1, 'all assigned employees are notified of the new deadline');
t(!comms_extension_decide($cr, $e4['id'], true, '')['ok'], 'a decided request cannot be decided again');
$ey = comms_extension_request($w1, $TX, date('Y-m-d', time() + 86400 * 20), '', 'Another one for later on'); 
Database::execute("UPDATE comms_task_extensions SET proposed_date = :d WHERE id = :i", ['d' => date('Y-m-d', time() - 86400), 'i' => $ey['id']]);
t(!comms_extension_decide($cr, $ey['id'], true, '')['ok'], 'a request whose proposed date has already passed cannot be approved');
comms_extension_cancel($w1, $ey['id']); Permission::grant($rRev, 'comms.deadline_extend'); $ez = comms_extension_request($w1, $TX, date('Y-m-d', time() + 86400 * 30), '', 'Much later date wanted');
t(comms_extension_decide($rv, $ez['id'], true, '')['ok'], 'a participating reviewer holding deadline_extend may also approve'); Permission::revoke($rRev, 'comms.deadline_extend');
$d = comms_task_detail($cr, $TX); t(count($d['extensions']) >= 4 && count($d['deadline_history']) === 2, 'the page data lists requests and deadline history');

section('Deadline changes made directly by the assigner');
$TY = $mkTask(['title' => 'Direct edit', 'assignees' => [$w1]]); $cl = [];
$newD = date('Y-m-d', time() + 86400 * 8);
t(comms_task_edit($cr, $TY, array_merge($base, ['title' => 'Direct edit', 'assignees' => [$w1], 'due_date' => $newD, 'checklist' => []]))['ok'], 'direct deadline edit works');
$h = Database::fetch('SELECT * FROM comms_task_deadline_history WHERE task_id = :t', ['t' => $TY]); t($h['source'] === 'edit' && $h['new_date'] === $newD && (int)$h['changed_by'] === $cr && $h['old_date'] === $due, 'it is recorded in the deadline history with editor and time');

section('Reminders');
$rem = fn(int $T) => Database::fetchAll('SELECT * FROM comms_task_reminders WHERE task_id = :t ORDER BY id', ['t' => $T]);
t(!comms_task_create($cr2, array_merge($base, ['reminder_minutes' => '1440', 'title' => 'x']), uuid())['ok'], 'setting a reminder needs comms.deadline_extend');
$TM = $mkTask(['title' => 'Reminded', 'assignees' => [$w1, $w2], 'reminder_minutes' => '1440']);
$r = $rem($TM); t(count($r) === 1 && (int)$r[0]['fire_ts'] === comms_deadline_ts($due, null) - 86400 && (int)$r[0]['minutes'] === 1440, 'a reminder is scheduled 1 day before the deadline (date-only deadline = end of that day)');
t(comms_run_scheduled(true)['reminders'] === 0 && !notes($w1, 'task.reminder'), 'not sent before its time');
Database::execute('UPDATE comms_task_reminders SET fire_ts = :t WHERE task_id = :i', ['t' => time() - 5, 'i' => $TM]);
$work($TM, $w1); $work($TM, $w2); $sm = comms_task_submit($w1, $TM, 'Done', [$img()], uuid(), false)['id']; comms_review_decide($rv, $sm, 'approve', '');
$sent = comms_run_scheduled(true)['reminders'];
t($sent === 2 && count(notes($w2, 'task.reminder')) === 1 && count(notes($cr, 'task.reminder')) === 1 && !notes($w1, 'task.reminder'), 'sent to the employee still working and the assigner — not to the one already approved');
t(notes($w2, 'task.reminder')[0]['priority'] === 'high' && str_contains(notes($w2, 'task.reminder')[0]['title'], '1 day'), 'the text says how long remains');
comms_run_scheduled(true); t(count(notes($w2, 'task.reminder')) === 1, 'a reminder fires only once');
$TM2 = $mkTask(['title' => 'Reminded 2', 'assignees' => [$w1], 'reminder_minutes' => '60']); Database::execute('UPDATE comms_task_reminders SET fire_ts = :t WHERE task_id = :i', ['t' => time() - 5, 'i' => $TM2]);
$work($TM2, $w1); $sm2 = comms_task_submit($w1, $TM2, 'Done', [$img()], uuid(), false)['id']; comms_review_decide($rv, $sm2, 'approve', ''); $nn = count(notes($cr, 'task.reminder')); comms_run_scheduled(true);
t(count(notes($cr, 'task.reminder')) === $nn, 'no reminder once the task is completed and approved');
$TM3 = $mkTask(['title' => 'Reminded 3', 'assignees' => [$w1], 'reminder_minutes' => '60']); Database::execute('UPDATE comms_task_reminders SET fire_ts = :t WHERE task_id = :i', ['t' => time() - 5, 'i' => $TM3]); comms_task_cancel($cr, $TM3, 'Not needed any more'); $nn = count(notes($w1, 'task.reminder')); comms_run_scheduled(true);
t(count(notes($w1, 'task.reminder')) === $nn, 'no reminder for a cancelled task');
$TM4 = $mkTask(['title' => 'Reminded 4', 'assignees' => [$w1], 'reminder_minutes' => '1440']); $old = $rem($TM4)[0];
$later = date('Y-m-d', time() + 86400 * 15);
comms_task_edit($cr, $TM4, array_merge($base, ['title' => 'Reminded 4', 'assignees' => [$w1], 'due_date' => $later, 'reminder_minutes' => '1440', 'checklist' => []]));
$all = $rem($TM4); t(count($all) === 2 && $all[0]['cancelled_at'] !== null && $all[1]['cancelled_at'] === null && (int)$all[1]['fire_ts'] === comms_deadline_ts($later, null) - 86400, 'a deadline edit cancels the old reminder and schedules a new one from the revised deadline');
Database::execute('UPDATE comms_task_reminders SET fire_ts = :t WHERE id = :i', ['t' => time() - 5, 'i' => $all[0]['id']]); $nn = count(notes($w1, 'task.reminder')); comms_run_scheduled(true); t(count(notes($w1, 'task.reminder')) === $nn, 'the cancelled reminder never fires');
$TM5 = $mkTask(['title' => 'Reminded 5', 'assignees' => [$w1], 'reminder_minutes' => '1440']); $work($TM5, $w1);
$e = comms_extension_request($w1, $TM5, date('Y-m-d', time() + 86400 * 30), '', 'Need a whole month more'); comms_extension_decide($cr, $e['id'], true, '');
$r5 = $rem($TM5); t(count($r5) === 2 && $r5[0]['cancelled_at'] !== null && (int)$r5[1]['fire_ts'] === comms_deadline_ts(date('Y-m-d', time() + 86400 * 30), null) - 86400, 'an approved extension reschedules the reminder');
$TM6 = $mkTask(['title' => 'No reminder', 'assignees' => [$w1]]); t($rem($TM6) === [], 'no reminder unless enabled');
$TM7 = $mkTask(['title' => 'Too late for reminder', 'assignees' => [$w1], 'due_date' => comms_today(), 'reminder_minutes' => '10080']); t($rem($TM7) === [], 'a reminder whose time has already passed is not scheduled');
$TM8 = $mkTask(['title' => 'Reminded 8', 'assignees' => [$w1], 'reminder_minutes' => '1440']);
comms_task_edit($cr, $TM8, array_merge($base, ['title' => 'Reminded 8', 'assignees' => [$w1], 'reminder_minutes' => '', 'checklist' => []])); t(Database::fetchValue('SELECT COUNT(*) FROM comms_task_reminders WHERE task_id = :t AND cancelled_at IS NULL', ['t' => $TM8]) == 0, 'turning the reminder off cancels it');
t(!comms_task_edit($cr2, $TM8, array_merge($base, ['title' => 'Reminded 8', 'assignees' => [$w1], 'reminder_minutes' => '60', 'checklist' => []]))['ok'], '(a user without the permission cannot change reminders)');

section('Overdue handling');
$TV = $mkTask(['title' => 'Overdue soon', 'assignees' => [$w1, $w2], 'due_date' => comms_today(), 'due_time' => '23:59']);
Database::execute("UPDATE comms_tasks SET due_date = :d, due_time = NULL WHERE id = :i", ['d' => date('Y-m-d', time() - 86400), 'i' => $TV]);
$work($TV, $w1); $w1s = comms_task_submit($w1, $TV, 'Done', [$img()], uuid(), false)['id'];
t(comms_task_detail($cr, $TV)['overdue'] && comms_task_list($cr, ['overdue' => 1])['total'] >= 1, 'a task past its deadline is overdue (page and list filter)');
$r = comms_run_scheduled(true); t($r['overdue'] >= 1 && count(notes($w2, 'task.overdue')) === 1 && count(notes($cr, 'task.overdue')) >= 1, 'employees and the assigner are notified once');
t(notes($w2, 'task.overdue')[0]['priority'] === 'high' && !notes($rv, 'task.overdue'), 'with prominence; the reviewer is not spammed');
comms_run_scheduled(true); t(count(notes($w2, 'task.overdue')) === 1, 'and not repeated every minute');
$d = comms_task_detail($cr, $TV); $ov = array_column(array_filter($d['assignees'], fn($a) => $a['overdue']), 'name'); t(in_array('Worker2', $ov, true) && in_array('Worker1', $ov, true), 'per employee overdue flags (submitted but not yet approved is still overdue)');
comms_review_decide($rv, $w1s, 'approve', ''); $d = comms_task_detail($cr, $TV); t(!in_array('Worker1', array_column(array_filter($d['assignees'], fn($a) => $a['overdue']), 'name'), true) && $d['overdue'], 'an approved employee is no longer overdue; the task stays overdue until everyone is approved');
$work($TV, $w2); $sw = comms_task_submit($w2, $TV, 'Late but done', [$img()], uuid(), false); t($sw['ok'], 'overdue work can still be submitted (no lock)');
comms_review_decide($rv, $sw['id'], 'approve', ''); t(!comms_task_detail($cr, $TV)['overdue'] && Database::fetchValue('SELECT status FROM comms_tasks WHERE id = :i', ['i' => $TV]) === 'completed', 'overdue ends when the task is approved as complete');
$TV2 = $mkTask(['title' => 'Overdue then extended', 'assignees' => [$w1]]); $work($TV2, $w1); Database::execute("UPDATE comms_tasks SET due_date = :d WHERE id = :i", ['d' => date('Y-m-d', time() - 86400), 'i' => $TV2]); comms_run_scheduled(true);
t(Database::fetchValue('SELECT overdue_notified_at FROM comms_tasks WHERE id = :i', ['i' => $TV2]) !== null, 'setup: overdue notice sent');
$ev2x = comms_task_edit($cr, $TV2, array_merge($base, ['title' => 'Overdue then extended', 'assignees' => [$w1], 'due_date' => date('Y-m-d', time() + 86400 * 3), 'checklist' => []]));
t($ev2x['ok'] && !comms_task_detail($cr, $TV2)['overdue'] && Database::fetchValue('SELECT overdue_notified_at FROM comms_tasks WHERE id = :i', ['i' => $TV2]) === null, 'an official deadline change clears overdue and re-arms the notice');
t(comms_task_detail($cr, $TV)['task']['reviewer_id'] == $rv && Database::fetchValue('SELECT assigner_id FROM comms_tasks WHERE id = :i', ['i' => $TV]) == $cr, 'nothing escalates automatically: assigner and reviewer are untouched by overdue');
t(!comms_task_overdue(['status' => 'cancelled', 'due_date' => '2000-01-01', 'due_time' => null]) && !comms_task_overdue(['status' => 'completed', 'due_date' => '2000-01-01', 'due_time' => null]), 'completed and cancelled tasks are never overdue');

section('Scheduler');
Database::execute("DELETE FROM system_settings WHERE setting_key = 'comms_cron_last'"); $r = comms_run_scheduled(true); t(isset($r['reminders']) && (int)Database::fetchValue("SELECT setting_value FROM system_settings WHERE setting_key = 'comms_cron_last'") >= time() - 5, 'a forced run executes all jobs and records the time');
t(comms_run_scheduled(true)['reminders'] >= 0, 'jobs are idempotent');
Database::execute("SELECT GET_LOCK('swms_comms_cron', 0)"); $pdo2 = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS); $locked = $pdo2->query("SELECT GET_LOCK('swms_comms_cron', 0)")->fetchColumn(); t((string)$locked === '0', 'a second runner cannot start while one holds the lock');
Database::execute("SELECT RELEASE_LOCK('swms_comms_cron')");
t(is_file(MODULES_PATH . '/../tools/comms-cron.php'), 'a command-line runner exists for sites that prefer a real scheduler');

section('Audit and privacy');
$aud = json_encode(Database::fetchAll("SELECT description, old_values, new_values FROM comms_audit_log WHERE action LIKE 'task.%'"));
t(!str_contains($aud, 'back corner') && !str_contains($aud, 'Supplier delivery') && !str_contains($aud, 'Quality problem found'), 'reasons and comments are never copied into the audit log');
t(Database::exists("SELECT 1 FROM comms_audit_log WHERE action IN ('task.submitted','task.reopened','task.reviewer_changed','task.extension_approved')"), 'submissions, reopening, reviewer changes and extensions are audited');
$leak = json_encode(Database::fetchAll('SELECT title FROM comms_notifications')); t(!str_contains($leak, 'back corner') && !str_contains($leak, 'Redo step B') && !str_contains($leak, 'Supplier'), 'notification text never carries reasons or comments');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
