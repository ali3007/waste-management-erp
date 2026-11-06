<?php
/**
 * Tasks self-test (Phase 3):  php tools/comms-tasks-selftest.php --confirm-test-database
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
function nnotes(): int { return (int)Database::fetchValue('SELECT COUNT(*) FROM comms_notifications'); }

foreach (['comms_notifications','comms_task_events','comms_task_edits','comms_task_mentions','comms_task_comments','comms_task_step_log','comms_task_checklist_items','comms_task_checklists','comms_task_progress','comms_task_assignees','comms_tasks','comms_task_templates','comms_members','comms_messages','comms_conversations'] as $tb) { Database::execute("DELETE FROM $tb"); }
Database::execute("DELETE FROM users WHERE cnic LIKE '97979797%'");
S('comms_edit_window_min', '15');
$mkRole = static function (string $n, array $perms): int { $r = Database::insert('roles', ['name' => $n . random_int(1, 99999), 'slug' => strtolower($n) . random_int(1, 99999), 'level' => 5, 'is_system' => 0]); foreach ($perms as $p) { Permission::grant($r, "comms.$p"); } return $r; };
$rCreator = $mkRole('Creator', ['access', 'task_create', 'task_assign', 'task_self_assign', 'task_edit', 'task_cancel', 'send_message', 'task_progress', 'deadline_extend']);
$rWorker  = $mkRole('Worker', ['access', 'task_progress']);
$rReview  = $mkRole('Reviewer', ['access', 'task_review']);
$rOnly    = $mkRole('Only', ['access']);
$rNoAssign = $mkRole('NoAssign', ['access', 'task_create']);
$rSelf    = $mkRole('Selfer', ['access', 'task_create', 'task_self_assign', 'task_progress']);
$rTpl     = $mkRole('Tpl', ['access', 'template_manage']);
$mk = static fn(string $n, int $role, int $i): int => Database::insert('users', ['role_id' => $role, 'name' => $n, 'cnic' => '979797970' . $i . '00', 'password_hash' => 'x', 'login_type' => 'cnic', 'status' => 'active']);
$cr = $mk('Creator', $rCreator, 1); $w1 = $mk('Worker1', $rWorker, 2); $w2 = $mk('Worker2', $rWorker, 3); $w3 = $mk('Worker3', $rWorker, 4);
$rv = $mk('Reviewer', $rReview, 5); $rv2 = $mk('Reviewer2', $rReview, 6); $out = $mk('Outsider', $rOnly, 7); $noa = $mk('NoAssign', $rNoAssign, 8);
$slf = $mk('Selfer', $rSelf, 9); $tpl = $mk('TplMgr', $rTpl, 10); $np = $mk('NoProg', $rOnly, 11);
$tomorrow = date('Y-m-d', time() + 86400 * 2);
$base = ['title' => 'Inspect the depot', 'description' => 'Walk the whole depot and report issues.', 'priority' => 'high', 'due_date' => $tomorrow, 'due_time' => '', 'assignees' => [$w1, $w2], 'reviewer_id' => $rv, 'evidence_required' => '1'];
$new = fn(array $o = [], ?int $by = null) => comms_task_create($by ?? $cr, array_merge($base, $o), uuid());

section('Validation');
$v = fn(array $o) => comms_task_validate($cr, array_merge($base, $o))[1];
t(!$v([]), 'a complete valid form passes');
t(isset($v(['title' => 'ab'])['title']) && isset($v(['title' => str_repeat('x', 201)])['title']), 'title length enforced');
t(isset($v(['description' => 'hey'])['description']), 'description required');
t(isset($v(['priority' => 'critical'])['priority']), 'priority whitelist');
t(isset($v(['due_date' => ''])['due_date']) && isset($v(['due_date' => '2026-02-30'])['due_date']) && isset($v(['due_date' => 'tomorrow'])['due_date']), 'invalid/impossible dates refused');
t(isset($v(['due_date' => date('Y-m-d', time() - 86400 * 2)])['due_date']), 'a past deadline is refused on creation');
t(!isset($v(['due_date' => comms_today(), 'due_time' => ''])['due_date']), 'today without a time is allowed (urgent tasks)');
t(isset($v(['due_date' => comms_today(), 'due_time' => '00:01'])['due_date']) || comms_now()->format('H:i') < '00:01', 'today with a time that has passed is refused');
t(!isset($v(['due_date' => comms_today(), 'due_time' => '23:59'])['due_date']), 'today with a later time is allowed');
t(isset($v(['due_time' => '25:00'])['due_time']), 'bad time refused');
t(isset($v(['review_due_date' => date('Y-m-d', time() + 86400)])['review_due_date']), 'review deadline before the task deadline refused');
t(!isset($v(['review_due_date' => date('Y-m-d', time() + 86400 * 5)])['review_due_date']), 'valid review deadline accepted');
t(isset($v(['reminder_minutes' => '7'])['reminder_minutes']) && !isset($v(['reminder_minutes' => '1440'])['reminder_minutes']), 'reminder must be one of the listed times');
t(isset(comms_task_validate($noa, array_merge($base, ['assignees' => [$w1], 'reminder_minutes' => '1440']))[1]['reminder_minutes']) && !isset(comms_task_validate($noa, array_merge($base, ['assignees' => [$w1]]))[1]['reminder_minutes']), 'setting a reminder needs comms.deadline_extend; leaving it unset needs nothing');
t(isset($v(['assignees' => []])['assignees']), 'at least one assignee');
t(isset($v(['assignees' => [$w1, $out]])['assignees']), 'assignee without task_progress is not eligible');
t(isset($v(['assignees' => [$w1, $np]])['assignees']), 'assignee with only access is not eligible');
t(isset(comms_task_validate($noa, array_merge($base, ['assignees' => [$w1]]))[1]['assignees']), 'creator without task_assign cannot assign others');
t(isset(comms_task_validate($cr, array_merge($base, ['assignees' => [$cr]]))[1]['assignees']) === false, 'creator with task_self_assign may assign self');
t(isset(comms_task_validate($noa, array_merge($base, ['assignees' => [$noa]]))[1]['assignees']), 'self-assignment without task_self_assign is refused');
t(!isset(comms_task_validate($slf, array_merge($base, ['assignees' => [$slf]]))[1]['assignees']), 'self-assign permission alone allows self-assignment only');
t(isset($v(['reviewer_id' => 0])['reviewer_id']) && isset($v(['reviewer_id' => $w1])['reviewer_id']), 'reviewer required and must hold task_review');
t(isset($v(['reviewer_id' => $w1, 'assignees' => [$w1]])['reviewer_id']), 'reviewer cannot also be an assignee');
t(isset($v(['checklist' => array_fill(0, 101, ['text' => 'x'])])['checklist']), 'checklist size capped');
t(isset($v(['link_type' => 'bogus', 'link_id' => 5])['link']), 'unsupported link type refused');
t(isset($v(['link_type' => 'uc', 'link_id' => 999999])['link']), 'link to a record that does not exist refused');
t(isset($v(['conversation_id' => 999])['conversation_id']), 'cannot link a chat you are not in');
$inj = comms_task_validate($cr, array_merge($base, ['title' => "x'; DROP TABLE users;--", 'description' => '<script>alert(1)</script> text']))[0];
t($inj['title'] === "x'; DROP TABLE users;--" && str_contains($inj['description'], '<script>'), 'markup/SQL kept as inert text (escaped on display, parameterised in SQL)');

section('Create: only after confirmation, saved atomically');
$tasksBefore = (int)Database::fetchValue('SELECT COUNT(*) FROM comms_tasks'); $nb = nnotes();
$bad = comms_task_create($cr, array_merge($base, ['title' => '']), uuid());
t(!$bad['ok'] && (int)Database::fetchValue('SELECT COUNT(*) FROM comms_tasks') === $tasksBefore && nnotes() === $nb, 'invalid form creates nothing and notifies nobody');
t(!comms_task_create($out, $base, uuid())['ok'], 'user without task_create refused');
t(!comms_task_create($cr, $base, 'nope')['ok'], 'bad request id refused');
$u = uuid(); $r1 = comms_task_create($cr, array_merge($base, ['checklist' => [['text' => 'Gate', 'required' => '1'], ['text' => 'Yard', 'required' => '1'], ['text' => 'Photos', 'required' => '0']], 'reminder_minutes' => '1440']), $u);
t($r1['ok'] && preg_match('/^T-\d{5}$/', $r1['task_no']) === 1, 'task created with a number like T-00001');
$T = $r1['id'];
$row = Database::fetch('SELECT * FROM comms_tasks WHERE id = :i', ['i' => $T]);
t($row['status'] === 'active' && (int)$row['assigner_id'] === $cr && (int)$row['reviewer_id'] === $rv && (int)$row['evidence_required'] === 1 && $row['original_due_date'] === $tomorrow, 'stored with assigner, reviewer, evidence flag and the original deadline');
t((int)Database::fetchValue("SELECT COUNT(*) FROM comms_task_assignees WHERE task_id = :t AND status = 'pending_acceptance'", ['t' => $T]) === 2, 'each assignee starts as Pending Acceptance');
t(comms_checklist_current($T)['version'] === 1 && count(comms_checklist_current($T)['items']) === 3, 'checklist stored as version 1');
$dup = comms_task_create($cr, $base, $u);
t($dup['ok'] && $dup['duplicate'] && $dup['id'] === $T && (int)Database::fetchValue('SELECT COUNT(*) FROM comms_tasks') === $tasksBefore + 1, 'double-clicking Confirm (same request id) never creates a second task');
$nw1 = notes($w1, 'task.assigned'); $nrv = notes($rv, 'task.reviewer');
t(count($nw1) === 1 && $nw1[0]['priority'] === 'high' && count(notes($w2, 'task.assigned')) === 1 && count($nrv) === 1, 'assignees and reviewer are notified (assignment = high priority)');
t(!notes($cr), 'the creator is not notified of their own action');
t(!str_contains(json_encode(Database::fetchAll('SELECT title FROM comms_notifications')), 'depot') && !str_contains($nw1[0]['title'], 'Walk'), 'notification text never contains the task title or description');
t(Database::exists("SELECT 1 FROM comms_task_events WHERE task_id = :t AND event = 'created'", ['t' => $T]) && Database::exists("SELECT 1 FROM comms_audit_log WHERE action = 'task.created' AND subject_id = :t", ['t' => $T]), 'creation recorded in the task history and the audit log');

section('Who can open a task');
t(comms_task_get($cr, $T) !== null && comms_task_get($w1, $T) !== null && comms_task_get($rv, $T) !== null, 'assigner, assignee and reviewer can open it');
t(comms_task_get($out, $T) === null && comms_task_get($rv2, $T) === null && comms_task_get($w3, $T) === null, 'no one else can — not even other reviewers or workers');
$root = (int)Database::fetchValue("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'super-admin' LIMIT 1");
t($root === 0 || comms_task_get($root, $T) === null, 'a Super Admin who is not a participant cannot open it either (no implicit access)');
t(comms_task_detail($out, $T) === null, 'detail view refuses non-participants');

section('Acceptance, decline, start — independent per employee');
$p = comms_task_progress_update($w1, $T, 10, 'started'); t(!$p['ok'] && str_contains($p['message'], 'Accept'), 'progress is refused before the task is accepted');
t(!comms_task_decline($w2, $T, 'no')['ok'], 'decline needs a real reason');
t(comms_task_accept($w1, $T)['ok'], 'Worker1 accepts');
t(comms_task_decline($w2, $T, 'I am on leave that week')['ok'], 'Worker2 declines with a reason');
$a1 = Database::fetch('SELECT * FROM comms_task_assignees WHERE task_id = :t AND user_id = :u', ['t' => $T, 'u' => $w1]); $a2 = Database::fetch('SELECT * FROM comms_task_assignees WHERE task_id = :t AND user_id = :u', ['t' => $T, 'u' => $w2]);
t($a1['status'] === 'accepted' && $a1['accepted_at'] !== null && $a2['status'] === 'declined' && $a2['decline_reason'] === 'I am on leave that week' && $a2['declined_at'] !== null, "one person's decision does not change the other's; reasons and times kept");
t(!comms_task_accept($w1, $T)['ok'] && !comms_task_accept($w2, $T)['ok'], 'a decision cannot be repeated or reversed');
t(count(notes($cr, 'task.declined')) === 1 && notes($cr, 'task.declined')[0]['priority'] === 'high' && count(notes($cr, 'task.accepted')) === 1, 'assigner notified of the acceptance and (prominently) of the decline');
t(!comms_task_accept($out, $T)['ok'] && !comms_task_decline($w3, $T, 'not mine at all')['ok'], 'a non-assignee cannot accept/decline');
t(comms_task_start($w1, $T)['ok'] && Database::fetchValue("SELECT status FROM comms_task_assignees WHERE task_id = :t AND user_id = :u", ['t' => $T, 'u' => $w1]) === 'in_progress', 'accepted employee can move to In Progress');
t(!comms_task_start($w2, $T)['ok'], 'a declined employee cannot start');
t(comms_task_get($w2, $T) !== null, 'a declined employee can still see the task');

section('Checklist progress (per employee, required steps, versions)');
$st = comms_checklist_state($T, $w1); $k = array_column($st['items'], 'key');
t($st['pct'] === 0 && $st['req_total'] === 2 && !$st['can_submit'], 'starts at 0%; 2 required steps; cannot be submitted yet');
t(comms_task_step_toggle($w1, $T, $k[0], true)['progress'] === 50, 'one of two required steps = 50%');
t(comms_task_step_toggle($w1, $T, $k[2], true)['progress'] === 50, 'ticking an optional step does not change the percentage');
t(comms_task_step_toggle($w1, $T, $k[1], true)['progress'] === 100, 'both required done = 100%');
t(comms_checklist_state($T, $w1)['can_submit'] === true, 'submission becomes possible once required steps are done');
t(Database::fetchValue("SELECT status FROM comms_tasks WHERE id = :i", ['i' => $T]) === 'active' && Database::fetchValue("SELECT status FROM comms_task_assignees WHERE task_id = :t AND user_id = :u", ['t' => $T, 'u' => $w1]) === 'in_progress', '100% does NOT complete the task (submission and approval are still required)');
t(comms_checklist_state($T, $w2)['pct'] === 0, "Worker2's checklist is separate");
t(!comms_task_step_toggle($w1, $T, 'ffffffffffff', true)['ok'], 'unknown step refused');
t(!comms_task_step_toggle($out, $T, $k[0], true)['ok'] && !comms_task_step_toggle($w2, $T, $k[0], true)['ok'], 'non-assignee / declined employee cannot tick steps');
t(!comms_task_progress_update($w1, $T, 50, 'manual')['ok'], 'manual percentage refused when a checklist drives progress');
$ms = array_column(notes($cr, 'task.milestone'), 'title');
t(count($ms) === 2 && str_contains($ms[0], '50%') && str_contains($ms[1], '100%'), 'milestones notify once each (50%, 100%) — not on every tick');
t(count(notes($rv, 'task.milestone')) === 2, 'the reviewer is told too');
$log = Database::fetchAll("SELECT old_pct, new_pct FROM comms_task_progress WHERE task_id = :t AND user_id = :u ORDER BY id", ['t' => $T, 'u' => $w1]);
t(count($log) === 2 && (int)$log[0]['old_pct'] === 0 && (int)$log[0]['new_pct'] === 50 && (int)$log[1]['new_pct'] === 100, 'previous and new percentage recorded for each change');
t(comms_task_step_toggle($w1, $T, $k[1], false)['progress'] === 50, 'un-ticking lowers progress');
comms_task_step_toggle($w1, $T, $k[1], true);

section('Editing: old/new values, notifications, checklist versions');
t(!comms_task_edit($w1, $T, $base)['ok'] && !comms_task_edit($rv, $T, $base)['ok'], 'only the assigner can edit');
$e0 = nnotes();
$cur = comms_checklist_current($T)['items'];
$in = array_merge($base, ['assignees' => [$w1, $w2], 'checklist' => $cur, 'priority' => 'urgent', 'title' => 'Inspect the depot (urgent)']);
$r = comms_task_edit($cr, $T, $in);
t($r['ok'] && $r['changed'], 'assigner edits title and priority');
$ev = Database::fetch("SELECT * FROM comms_task_events WHERE task_id = :t AND event = 'edited' ORDER BY id DESC LIMIT 1", ['t' => $T]);
t(json_decode($ev['old_values'], true)['priority'] === 'high' && json_decode($ev['new_values'], true)['priority'] === 'urgent' && (int)$ev['user_id'] === $cr, 'old and new values, editor and time recorded');
t(count(notes($w1, 'task.edited')) === 1 && notes($w1, 'task.edited')[0]['priority'] === 'high', 'affected employee notified (priority change is prominent)');
t(comms_checklist_current($T)['version'] === 1, 'an edit that does not touch the checklist creates no new version');
t(comms_task_edit($cr, $T, $in)['changed'] === false, 'saving with no change does nothing');
$items2 = $cur; $items2[] = ['key' => '', 'text' => 'Fire exits', 'required' => '1'];
$r = comms_task_edit($cr, $T, array_merge($in, ['checklist' => $items2]));
t($r['ok'] && comms_checklist_current($T)['version'] === 2 && count(comms_checklist_versions($T)) === 2, 'adding a step creates version 2 and keeps version 1');
t(count(comms_checklist_versions($T)[1]['items']) === 3, 'the previous version is preserved intact');
t(comms_checklist_state($T, $w1)['pct'] === 67 && (int)Database::fetchValue("SELECT progress FROM comms_task_assignees WHERE task_id = :t AND user_id = :u", ['t' => $T, 'u' => $w1]) === 67, 'a new required step lowers progress (100% → 67%) against the latest version');
t(!comms_checklist_state($T, $w1)['can_submit'], 'and blocks submission until the new step is done');
t((int)Database::fetchValue("SELECT COUNT(*) FROM comms_task_step_log WHERE task_id = :t AND user_id = :u", ['t' => $T, 'u' => $w1]) >= 5, 'completed-step history is never erased');
t(Database::exists("SELECT 1 FROM comms_task_progress WHERE task_id = :t AND user_id = :u AND kind = 'system' AND new_pct = 67", ['t' => $T, 'u' => $w1]), 'the automatic recalculation is logged as a system entry');
t(count(notes($w1, 'task.checklist')) === 1, 'employees are notified about checklist updates');
$ev = Database::fetch("SELECT * FROM comms_task_events WHERE task_id = :t AND event = 'checklist_changed' ORDER BY id DESC LIMIT 1", ['t' => $T]);
t(in_array('Fire exits', json_decode($ev['new_values'], true)['diff']['added'], true), 'the checklist history says what changed');
$items3 = comms_checklist_current($T)['items']; $items3[3]['required'] = '0';
comms_task_edit($cr, $T, array_merge($in, ['checklist' => $items3]));
t(comms_checklist_current($T)['version'] === 3 && comms_checklist_state($T, $w1)['pct'] === 100, 'making a step optional is a new version and recalculates (back to 100%)');
t(!comms_task_step_toggle($w1, $T, 'aaaaaaaaaaaa', true)['ok'], 'steps from nowhere are refused');
$gone = comms_checklist_current($T)['items']; $removedKey = $gone[2]['key']; unset($gone[2]); $gone = array_values($gone);
comms_task_edit($cr, $T, array_merge($in, ['checklist' => $gone]));
t(!comms_task_step_toggle($w1, $T, $removedKey, false)['ok'], 'a step removed in a later version can no longer be ticked');
t(count(comms_checklist_versions($T)) === 4, 'every change kept as its own version');

section('Editing: deadlines, assignees, reviewer');
Database::execute("UPDATE comms_tasks SET due_date = :d WHERE id = :i", ['d' => date('Y-m-d', time() - 86400 * 3), 'i' => $T]);
$cl = comms_checklist_current($T)['items'];
$r = comms_task_edit($cr, $T, array_merge($in, ['checklist' => $cl, 'due_date' => date('Y-m-d', time() - 86400 * 3), 'title' => 'Inspect depot v2']));
t($r['ok'], 'an already-passed deadline may stay as it is when other fields are edited');
$r = comms_task_edit($cr, $T, array_merge($in, ['checklist' => $cl, 'due_date' => date('Y-m-d', time() - 86400 * 2)]));
t(!$r['ok'], 'but changing the deadline to a past date is refused');
$newDue = date('Y-m-d', time() + 86400 * 6);
t(comms_task_edit($cr, $T, array_merge($in, ['checklist' => $cl, 'due_date' => $newDue]))['ok'], 'extending the deadline to a future date works');
$tk = Database::fetch('SELECT * FROM comms_tasks WHERE id = :i', ['i' => $T]);
t($tk['original_due_date'] === $tomorrow && $tk['due_date'] === $newDue, 'the original deadline is preserved');
$r = comms_task_edit($cr, $T, array_merge($in, ['checklist' => $cl, 'due_date' => $newDue, 'reviewer_id' => $rv2]));
t((int)Database::fetchValue('SELECT reviewer_id FROM comms_tasks WHERE id = :i', ['i' => $T]) === $rv, 'the reviewer cannot be swapped through a normal edit (reviewer reassignment is separate)');
t(!comms_task_edit($cr, $T, array_merge($in, ['checklist' => $cl, 'due_date' => $newDue, 'assignees' => [$w1, $rv]]))['ok'], 'cannot add the reviewer as an assignee');
t(!comms_task_edit($cr, $T, array_merge($in, ['checklist' => $cl, 'due_date' => $newDue, 'assignees' => []]))['ok'], 'cannot remove every assignee');
t(comms_task_edit($cr, $T, array_merge($in, ['checklist' => $cl, 'due_date' => $newDue, 'assignees' => [$w1, $w3]]))['ok'], 'swap Worker2 for Worker3');
t(count(notes($w3, 'task.assigned')) === 1 && Database::fetchValue("SELECT status FROM comms_task_assignees WHERE task_id = :t AND user_id = :u", ['t' => $T, 'u' => $w3]) === 'pending_acceptance', 'the added employee is notified and starts as Pending Acceptance');
t(comms_task_get($w2, $T) === null && count(notes($w2, 'task.removed')) === 1, 'the removed employee loses access and is told');
t(Database::exists("SELECT 1 FROM comms_task_assignees WHERE task_id = :t AND user_id = :u AND removed_at IS NOT NULL AND decline_reason IS NOT NULL", ['t' => $T, 'u' => $w2]), "the removed employee's history (decline reason) is preserved");
comms_task_edit($cr, $T, array_merge($in, ['checklist' => $cl, 'due_date' => $newDue, 'assignees' => [$w1, $w3, $w2]]));
t(Database::fetchValue("SELECT status FROM comms_task_assignees WHERE task_id = :t AND user_id = :u", ['t' => $T, 'u' => $w2]) === 'pending_acceptance' && comms_task_get($w2, $T) !== null, 're-adding a removed employee starts a fresh Pending Acceptance');
Database::execute("UPDATE comms_task_assignees SET status = 'submitted' WHERE task_id = :t AND user_id = :u", ['t' => $T, 'u' => $w3]);
t(!comms_task_edit($cr, $T, array_merge($in, ['checklist' => $cl, 'due_date' => $newDue, 'assignees' => [$w1, $w2]]))['ok'], 'an employee who has submitted cannot be removed');
Database::execute("UPDATE comms_task_assignees SET status = 'accepted' WHERE task_id = :t AND user_id = :u", ['t' => $T, 'u' => $w3]);

section('Progress without a checklist; after the deadline');
$R = $new(['title' => 'Call the supplier', 'assignees' => [$w1]]); $T2 = $R['id'];
comms_task_accept($w1, $T2);
t(!comms_task_progress_update($w1, $T2, 30, '')['ok'], 'a manual update needs a comment');
t(!comms_task_progress_update($w1, $T2, 101, 'too far')['ok'] && !comms_task_progress_update($w1, $T2, -1, 'too low')['ok'], '0–100 enforced');
$m0 = count(notes($cr, 'task.milestone'));
t(comms_task_progress_update($w1, $T2, 30, 'Supplier contacted')['progress'] === 30, 'manual progress with a comment accepted (and moves Accepted → In Progress)');
t(Database::fetchValue("SELECT status FROM comms_task_assignees WHERE task_id = :t AND user_id = :u", ['t' => $T2, 'u' => $w1]) === 'in_progress', 'status moved to In Progress by the first update');
$ms2 = notes($cr, 'task.milestone');
t(count($ms2) === $m0 + 1 && str_contains(end($ms2)['title'], '25%'), 'crossing 25% notified the assigner');
comms_task_progress_update($w1, $T2, 40, 'Quote received');
t(count(notes($cr, 'task.milestone')) === $m0 + 1, 'moving 30% → 40% (no milestone crossed) sent nothing');
$n1 = count(notes($cr, 'task.milestone')); comms_task_progress_update($w1, $T2, 45, 'minor'); comms_task_progress_update($w1, $T2, 49, 'minor');
t(count(notes($cr, 'task.milestone')) === $n1, 'minor changes between milestones send nothing');
comms_task_progress_update($w1, $T2, 100, 'Contract signed');
t(Database::fetchValue('SELECT status FROM comms_tasks WHERE id = :i', ['i' => $T2]) === 'active', '100% does not complete the task');
Database::execute("UPDATE comms_tasks SET due_date = :d WHERE id = :i", ['d' => date('Y-m-d', time() - 86400 * 5), 'i' => $T2]);
t(comms_task_progress_update($w1, $T2, 80, 'Correcting after the deadline')['ok'], 'progress can still be updated after the deadline (the task is not locked)');
$h = Database::fetchAll("SELECT old_pct, new_pct, comment FROM comms_task_progress WHERE task_id = :t ORDER BY id", ['t' => $T2]);
t((int)$h[1]['old_pct'] === 30 && (int)$h[1]['new_pct'] === 40 && $h[1]['comment'] === 'Quote received', 'each update stores previous %, new %, comment');
$pid = (int)Database::fetchValue("SELECT id FROM comms_task_progress WHERE task_id = :t AND comment = 'Supplier contacted'", ['t' => $T2]);
t(comms_progress_comment_edit($w1, $pid, 'Supplier contacted by phone')['ok'] && Database::exists("SELECT 1 FROM comms_task_edits WHERE entity_type = 'progress' AND entity_id = :i AND old_text = 'Supplier contacted'", ['i' => $pid]), 'the author can edit a progress comment within the time limit; the old text is kept');
Database::execute("UPDATE comms_task_progress SET created_at = NOW() - INTERVAL 2 HOUR WHERE id = :i", ['i' => $pid]);
t(!comms_progress_comment_edit($w1, $pid, 'late edit')['ok'], 'after the limit the author cannot');
t(comms_progress_comment_edit($cr, $pid, 'Corrected by the assigner')['ok'], 'an authorised user (assigner with task_edit) can correct it');
t(!comms_progress_comment_edit($out, $pid, 'x')['ok'], 'an outsider cannot');

section('Cancellation');
t(!comms_task_cancel($w1, $T2, 'I want it gone')['ok'] && !comms_task_cancel($rv, $T2, 'I want it gone')['ok'], 'only the assigner may cancel');
t(!comms_task_cancel($cr, $T2, 'no')['ok'], 'a reason is mandatory');
$nc = nnotes(); t(comms_task_cancel($cr, $T2, 'Supplier changed, no longer needed')['ok'], 'assigner cancels with a reason');
$tk = Database::fetch('SELECT * FROM comms_tasks WHERE id = :i', ['i' => $T2]);
t($tk['status'] === 'cancelled' && $tk['cancel_reason'] === 'Supplier changed, no longer needed' && (int)$tk['cancelled_by'] === $cr && $tk['cancelled_at'] !== null, 'cancellation recorded with who, when and why');
t(Database::fetchValue("SELECT status FROM comms_task_assignees WHERE task_id = :t AND user_id = :u", ['t' => $T2, 'u' => $w1]) === 'cancelled' && comms_task_get($w1, $T2) !== null, "the employee's status is Cancelled but the task and history stay visible");
t(count(notes($w1, 'task.cancelled')) === 1 && notes($w1, 'task.cancelled')[0]['priority'] === 'high', 'assigned employees are notified');
t(!comms_task_cancel($cr, $T2, 'twice over')['ok'], 'cannot cancel twice');
t(!comms_task_progress_update($w1, $T2, 50, 'late')['ok'] && !comms_task_accept($w1, $T2)['ok'] && !comms_task_edit($cr, $T2, $base)['ok'], 'no further work or edits on a cancelled task');
t((int)Database::fetchValue('SELECT COUNT(*) FROM comms_task_progress WHERE task_id = :t', ['t' => $T2]) > 0, 'progress history kept after cancellation');
t(!str_contains(json_encode(Database::fetchAll("SELECT title FROM comms_notifications WHERE type = 'task.cancelled'")), 'Supplier changed'), 'the cancellation reason is not in notification text');

section('Overall status from individual workflows');
$R = $new(['title' => 'Two person job', 'assignees' => [$w1, $w2]]); $T3 = $R['id'];
Database::execute("UPDATE comms_task_assignees SET status = 'approved' WHERE task_id = :t AND user_id = :u", ['t' => $T3, 'u' => $w1]); comms_task_recompute($T3);
t(Database::fetchValue('SELECT status FROM comms_tasks WHERE id = :i', ['i' => $T3]) === 'partially_completed', 'one approved + one pending = Partially Completed');
Database::execute("UPDATE comms_task_assignees SET status = 'approved' WHERE task_id = :t AND user_id = :u", ['t' => $T3, 'u' => $w2]); comms_task_recompute($T3);
t(Database::fetchValue('SELECT status FROM comms_tasks WHERE id = :i', ['i' => $T3]) === 'completed', 'all approved = Completed');
$R = $new(['title' => 'Declined one', 'assignees' => [$w1, $w2]]); $T4 = $R['id'];
Database::execute("UPDATE comms_task_assignees SET status = 'approved' WHERE task_id = :t AND user_id = :u", ['t' => $T4, 'u' => $w1]);
comms_task_accept($w1, $T4); comms_task_decline($w2, $T4, 'Cannot take this one');
t(Database::fetchValue('SELECT status FROM comms_tasks WHERE id = :i', ['i' => $T4]) === 'completed', 'a declined employee does not block completion of the others');
t(!comms_task_edit($cr, $T3, $base)['ok'] && !comms_task_cancel($cr, $T3, 'trying to cancel done')['ok'], 'a completed task cannot be edited or cancelled');

section('Comments, replies, mentions');
$R = $new(['title' => 'Discussion task', 'assignees' => [$w1, $w3]]); $T5 = $R['id'];
$nb = nnotes();
t(!comms_comment_add($out, $T5, 'let me in')['ok'] && !comms_comment_add($rv2, $T5, 'also me')['ok'], 'outsiders cannot comment');
t(!comms_comment_add($w1, $T5, '   ')['ok'], 'empty comment refused');
$c1 = comms_comment_add($w1, $T5, 'Starting tomorrow'); t($c1['ok'], 'an assignee comments');
t(count(notes($cr, 'task.comment')) === 1 && !notes($w3, 'task.comment') && !notes($rv, 'task.comment'), 'a normal comment notifies only the assigner — not everyone');
$c2 = comms_comment_add($cr, $T5, 'Thanks, noted', $c1['id']); t($c2['ok'] && count(notes($w1, 'task.reply')) === 1, 'a reply notifies the person replied to');
$c3 = comms_comment_add($w3, $T5, 'nested reply', $c2['id']);
t((int)Database::fetchValue('SELECT parent_id FROM comms_task_comments WHERE id = :i', ['i' => $c3['id']]) === $c1['id'], 'replies stay one level deep');
t(!comms_comment_add($w1, $T5, 'x', 999999)['ok'], 'reply to a comment of another task / nothing refused');
$c4 = comms_comment_add($cr, $T5, 'Please check this @Worker3 and @Outsider', 0, false, [$w3, $out]);
t(count(notes($w3, 'task.mention')) === 1 && notes($w3, 'task.mention')[0]['priority'] === 'high' && !notes($out), 'mentions notify participants only; an outsider is silently ignored');
t((int)Database::fetchValue('SELECT COUNT(*) FROM comms_task_mentions WHERE comment_id = :i', ['i' => $c4['id']]) === 1, 'mentions are preserved in history');
t(!notes($w3, 'task.comment'), 'a mentioned person gets one notification, not two');
$before = count(notes($rv, 'task.comment')); comms_comment_add($cr, $T5, 'Important: access closes Friday', 0, true);
t(count(notes($rv, 'task.comment')) === $before + 1 && count(notes($w3, 'task.comment')) === 1 && count(notes($w1, 'task.comment')) === 1, 'an "important" comment reaches every participant');
t(count(notes($cr, 'task.comment')) === 1, 'the author of an important comment does not notify themselves (only the earlier comment by Worker1 reached them)');
t(comms_comment_edit($w1, $c1['id'], 'Starting tomorrow morning')['ok'] && Database::exists("SELECT 1 FROM comms_task_edits WHERE entity_type = 'comment' AND entity_id = :i AND old_text = 'Starting tomorrow' AND new_text = 'Starting tomorrow morning'", ['i' => $c1['id']]), 'author edits within the limit; old and new text preserved');
t(!comms_comment_edit($w3, $c1['id'], 'hijack')['ok'], "nobody edits someone else's comment");
Database::execute('UPDATE comms_task_comments SET created_at = NOW() - INTERVAL 3 HOUR WHERE id = :i', ['i' => $c1['id']]);
t(!comms_comment_edit($w1, $c1['id'], 'too late')['ok'] && comms_comment_edit($cr, $c1['id'], 'Starting tomorrow (corrected)')['ok'], 'after the limit only the authorised assigner corrects it');
$d = comms_task_detail($w1, $T5); t(count($d['comments']) === 5 && $d['comments'][0]['mentions'] === [] && in_array('Worker3', $d['comments'][3]['mentions'], true), 'the task page data carries comments with their mentions');
t(comms_task_detail($out, $T5) === null, 'and nothing for outsiders');

section('Templates');
t(!comms_template_save($cr, 0, ['name' => 'X'])['ok'] && !comms_template_delete($cr, 1)['ok'], 'only template managers can create/edit/delete templates');
$tp = comms_template_save($tpl, 0, ['name' => 'Monthly inspection', 'title' => 'Monthly inspection', 'description' => 'Inspect and report', 'priority' => 'medium', 'evidence_required' => '1', 'checklist' => [['text' => 'Gate', 'required' => '1'], ['text' => 'Yard', 'required' => '0']]]);
t($tp['ok'], 'template created'); $tid = $tp['id'];
t(!comms_template_save($tpl, 0, ['name' => '', 'priority' => 'medium'])['ok'] && !comms_template_save($tpl, 0, ['name' => 'x', 'priority' => 'nope'])['ok'], 'template validation');
t(comms_template_save($tpl, $tid, ['name' => 'Monthly inspection v2', 'priority' => 'high', 'checklist' => [['text' => 'Gate', 'required' => '1']]])['ok'] && count(comms_template_list()[0]['checklist']) === 1, 'template edited');
$R = $new(['title' => 'From template', 'template_id' => $tid, 'assignees' => [$w1]]); $T6 = $R['id'];
t((int)Database::fetchValue('SELECT template_id FROM comms_tasks WHERE id = :i', ['i' => $T6]) === $tid, 'a task remembers its template');
t(comms_template_delete($tpl, $tid)['ok'] && comms_template_list() === [], 'template deleted (hidden from the list)');
t(Database::exists('SELECT 1 FROM comms_tasks WHERE id = :i', ['i' => $T6]) && comms_task_detail($cr, $T6)['template'] === 'Monthly inspection v2', 'deleting a template leaves its tasks (and their template name) intact');
t(Database::exists("SELECT 1 FROM comms_audit_log WHERE action = 'template.deleted'"), 'template deletion audited');
$R = $new(['title' => 'Bogus tpl', 'template_id' => $tid, 'assignees' => [$w1]]);
t($R['ok'] && Database::fetchValue('SELECT template_id FROM comms_tasks WHERE id = :i', ['i' => $R['id']]) === null, 'a deleted template id is ignored when creating a task');

section('Links to ERP records (never grant access)');
$hasEmp = true;
try { Permission::register('employee', 'view', 'View employees'); Database::execute("SET SESSION sql_mode = ''"); Database::execute('SET FOREIGN_KEY_CHECKS = 0'); $empId = Database::insert('employees', ['employee_code' => 'E-9001', 'name' => 'Link Target', 'father_name' => 'Test Father', 'cnic' => '9797979797979', 'status' => 'active']); Database::execute('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $e) { $hasEmp = false; $empId = 0; }
if ($hasEmp) {
    $rView = $mkRole('EmpView', ['access', 'task_create', 'task_assign']); Permission::grant($rView, 'employee.view');
    $viewer = $mk('EmpViewer', $rView, 12);
    t(comms_link_can_view($viewer, 'employee', $empId) && !comms_link_can_view($cr, 'employee', $empId), 'only holders of employee.view can link an employee');
    t(count(comms_link_search($viewer, 'employee', 'Link Tar')) === 1 && comms_link_search($cr, 'employee', 'Link Tar') === [], 'search shows only records the searcher may see');
    t(comms_link_search($viewer, 'employee', "%") === [] && comms_link_search($viewer, 'employee', "' OR 1=1 --") === [], 'link search is injection/wildcard safe');
    $rr = comms_task_create($viewer, array_merge($base, ['title' => 'Linked task', 'link_type' => 'employee', 'link_id' => $empId]), uuid());
    t($rr['ok'], 'a permitted user links the record'); $TL = $rr['id'];
    t(comms_task_create($cr, array_merge($base, ['title' => 'Linked task 2', 'link_type' => 'employee', 'link_id' => $empId]), uuid())['ok'] === false, 'a user without access cannot create the link');
    $dl = comms_task_detail($w1, $TL)['link'];
    t($dl['available'] === false && $dl['href'] === null && !str_contains(json_encode($dl), 'Link Target'), 'an assignee without access to employees sees no name or address — the link grants nothing');
    $dl = comms_link_resolve($viewer, 'employee', $empId); t($dl['available'] && str_contains($dl['label'], 'Link Target') && str_contains($dl['href'], 'employee-view.php?id=' . $empId), 'a viewer with access sees the label and the module page address');
    Database::execute('DELETE FROM employees WHERE id = :i', ['i' => $empId]);
    $dl = comms_link_resolve($viewer, 'employee', $empId); t($dl['available'] === false && str_contains($dl['note'], 'no longer available'), 'a deleted record degrades gracefully');
    t(Database::exists('SELECT 1 FROM comms_tasks WHERE id = :i', ['i' => $TL]), 'the task and its history survive');
} else { echo "  (skipped: no employees table fixture)\n"; }
$cv = comms_link_resolve($cr, 'complaint', 1); t($cv['available'] === false, 'complaint link without complaint.view stays closed');

section('Lists, filters, pagination, access');
$mine = comms_task_list($w1, ['scope' => 'mine']); $ids = array_column($mine['rows'], 'id');
t(in_array($T, $ids) && !in_array($T3 + 99, $ids) && $mine['total'] >= 3, 'an employee lists their own tasks');
t(array_diff(array_column(comms_task_list($out)['rows'], 'id'), []) === [], 'an outsider lists nothing');
t(count(comms_task_list($rv, ['scope' => 'review'])['rows']) >= 3 && comms_task_list($rv, ['scope' => 'mine'])['total'] === 0, 'the reviewer sees tasks to review, not as assignee');
t(comms_task_list($cr, ['scope' => 'assigned_by_me'])['total'] >= 5, 'the assigner lists what they assigned');
t(array_unique(array_column(comms_task_list($cr, ['status' => 'cancelled'])['rows'], 'status')) === ['cancelled'], 'status filter');
t(count(comms_task_list($cr, ['q' => 'Two person'])['rows']) === 1 && comms_task_list($cr, ['q' => '%'])['total'] === 0, 'keyword search is exact-ish and wildcard-safe');
t(comms_task_list($cr, ['priority' => 'urgent'])['total'] >= 1 && comms_task_list($cr, ['priority' => 'low'])['total'] === 0, 'priority filter');
$od = comms_task_list($cr, ['overdue' => 1]); t(in_array($T2, array_column($od['rows'], 'id')) === false, 'cancelled tasks are never overdue');
Database::execute("UPDATE comms_tasks SET due_date = :d WHERE id = :i", ['d' => date('Y-m-d', time() - 86400), 'i' => $T5]);
t(in_array($T5, array_column(comms_task_list($cr, ['overdue' => 1])['rows'], 'id')), 'an open task past its deadline shows as overdue');
t(comms_task_list($cr, ['from' => date('Y-m-d', time() + 86400 * 400)])['total'] === 0, 'date filter');
for ($i = 0; $i < 22; $i++) { $new(['title' => 'Bulk ' . $i, 'assignees' => [$w3]]); }
$pg = comms_task_list($w3, ['scope' => 'mine'], 1, 20); $pg2 = comms_task_list($w3, ['scope' => 'mine'], 2, 20);
t(count($pg['rows']) === 20 && $pg['pages'] >= 2 && count($pg2['rows']) >= 2 && !array_intersect(array_column($pg['rows'], 'id'), array_column($pg2['rows'], 'id')), 'pagination: 20 per page, no overlap');
t(Database::exists("SELECT 1 FROM comms_audit_log WHERE action = 'task.cancelled'") && !str_contains(json_encode(Database::fetchAll('SELECT description, old_values, new_values FROM comms_audit_log WHERE action LIKE \'task.%\'')), 'Walk the whole depot'), 'task actions audited; descriptions never copied into the audit log');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
