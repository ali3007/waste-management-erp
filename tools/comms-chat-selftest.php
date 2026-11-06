<?php
/**
 * Chat engine self-test (command line):  php tools/comms-chat-selftest.php --confirm-test-database
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

foreach (['comms_messages','comms_members','comms_conversations','comms_presence'] as $tb) { Database::execute("DELETE FROM $tb"); }
Database::execute("DELETE FROM users WHERE cnic LIKE '99999999999%'");
$roleChat = Database::insert('roles', ['name' => 'Chat Role ' . random_int(1, 99999), 'slug' => 'chat-' . random_int(1, 99999), 'level' => 5, 'is_system' => 0]);
$roleMgr  = Database::insert('roles', ['name' => 'Chat Mgr ' . random_int(1, 99999), 'slug' => 'chatm-' . random_int(1, 99999), 'level' => 6, 'is_system' => 0]);
$roleNone = Database::insert('roles', ['name' => 'No Chat ' . random_int(1, 99999), 'slug' => 'nochat-' . random_int(1, 99999), 'level' => 1, 'is_system' => 0]);
foreach (['access', 'send_message', 'chat_private'] as $a) { Permission::grant($roleChat, "comms.$a"); Permission::grant($roleMgr, "comms.$a"); }
foreach (['group_create', 'group_manage'] as $a) { Permission::grant($roleMgr, "comms.$a"); }
Permission::grant($roleNone, 'comms.access');
$mk = static fn(string $n, int $role, int $i): int => Database::insert('users', ['role_id' => $role, 'name' => $n, 'cnic' => '9999999999' . $i . '0', 'password_hash' => 'x', 'login_type' => 'cnic', 'status' => 'active']);
$a = $mk('Alice', $roleMgr, 1); $b = $mk('Bob', $roleChat, 2); $c = $mk('Carol', $roleChat, 3); $d = $mk('Dave', $roleNone, 4); $e = $mk('Eve', $roleChat, 5);

section('Private chat');
t(comms_private_open($b, $a)['ok'] === true, 'Bob can open a chat with Alice');
$p = comms_private_open($b, $a); $conv = $p['conversation_id'];
t(comms_private_open($a, $b)['conversation_id'] === $conv, 'same pair always resolves to ONE conversation (either direction)');
t((int)Database::fetchValue("SELECT COUNT(*) FROM comms_conversations WHERE type='private'") === 1, 'no duplicate private conversation');
t(!comms_private_open($b, $b)['ok'], 'cannot chat with yourself');
t(!comms_private_open($d, $a)['ok'], 'user without chat_private permission cannot start a chat');
t(!comms_private_open($a, $d)['ok'], 'cannot start a chat with someone lacking send_message');
t(count(comms_directory($b)) >= 3 && !in_array($b, array_column(comms_directory($b), 'id')), 'directory excludes self');
t(!in_array($d, array_map('intval', array_column(comms_directory($b), 'id'))), 'directory excludes ineligible people');
t(comms_directory($d) === [], 'directory empty without chat_private');

section('Sending, idempotency, validation');
$u1 = uuid();
$r1 = comms_send($b, $conv, "Salaam Alice\nsecond line", $u1);
t($r1['ok'] && !$r1['duplicate'], 'message sent');
$r2 = comms_send($b, $conv, "Salaam Alice\nsecond line", $u1);
t($r2['ok'] && $r2['duplicate'] && $r2['id'] === $r1['id'], 'retry with same uuid returns the original (no duplicate)');
t((int)Database::fetchValue('SELECT COUNT(*) FROM comms_messages') === 1, 'exactly one row stored');
t(!comms_send($b, $conv, '   ', uuid())['ok'], 'empty message refused');
t(!comms_send($b, $conv, str_repeat('x', COMMS_MAX_BODY + 1), uuid())['ok'], 'over-long message refused');
t(!comms_send($b, $conv, 'hi', 'not-a-uuid')['ok'], 'bad uuid refused');
t(!comms_send($c, $conv, 'intruder', uuid())['ok'], 'non-member cannot send');
t(!comms_send($d, $conv, 'x', uuid())['ok'], 'user without send_message cannot send');
t(comms_send($b, $conv, 'اسلام علیکم 😊', uuid())['ok'], 'Urdu text and emoji accepted');
$body = Database::fetchValue("SELECT body FROM comms_messages ORDER BY id DESC LIMIT 1");
t($body === 'اسلام علیکم 😊', 'Urdu/emoji stored intact');
comms_send($b, $conv, "<script>alert(1)</script>", uuid());
t(str_contains((string)Database::fetchValue('SELECT body FROM comms_messages ORDER BY id DESC LIMIT 1'), '<script>'), 'markup stored as text (escaped at display, never executed)');

section('Reading, privacy and unread');
t(!comms_fetch($c, $conv)['ok'], 'non-member cannot read a private chat');
$f = comms_fetch($a, $conv);
t($f['ok'] && count($f['messages']) === 3, 'Alice sees the 3 messages');
$list = comms_conversations($a);
t($list[0]['unread'] === 3 && $list[0]['title'] === 'Bob', 'Alice has 3 unread; title is the other person');
t(comms_conversations($b)[0]['unread'] === 0, "sender's own messages are not unread");
$lastId = end($f['messages'])['id'];
comms_mark_read($a, $conv, $lastId + 1000);
t(comms_conversations($a)[0]['unread'] === 0, 'mark read clears unread');
t((int)Database::fetchValue('SELECT last_read_msg_id FROM comms_members WHERE conversation_id = :c AND user_id = :u', ['c' => $conv, 'u' => $a]) === $lastId, 'forged future id is clamped to the real last message');
$olderCount = count(comms_fetch($a, $conv, 0, $lastId, 1)['messages']);
t($olderCount === 1, 'scroll-back loads older messages in pages');

section('Receipts');
$m1 = $r1['id'];
$rc = comms_receipts($b, $conv, [$m1, $lastId]);
t($rc[$m1]['state'] === 'read', 'read once Alice has read it');
$new = comms_send($b, $conv, 'later', uuid())['id'];
t(comms_receipts($b, $conv, [$new])[$new]['state'] === 'sent', 'new message is Sent');
comms_mark_delivered($a);
t(comms_receipts($b, $conv, [$new])[$new]['state'] === 'delivered', 'Delivered after Alice\'s client polls');
comms_mark_read($a, $conv, $new);
t(comms_receipts($b, $conv, [$new])[$new]['state'] === 'read', 'Read after Alice opens it');

section('Replies');
$rep = comms_send($a, $conv, 'reply', uuid(), $m1);
$ff = comms_fetch($b, $conv, 0, 0, 1);
t($ff['messages'][0]['reply']['body'] !== '' && $ff['messages'][0]['reply']['name'] === 'Bob', 'reply shows the quoted message');

section('Groups: creation and permissions');
t(!comms_group_create($b, 'Nope', [$c])['ok'], 'user without group_create cannot create a group');
t(!comms_group_create($a, '', [$b])['ok'], 'group name required');
t(!comms_group_create($a, 'G', [$d])['ok'], 'cannot add someone lacking send_message');
$g = comms_group_create($a, 'Operations', [$b, $c]); $gid = $g['conversation_id'];
t($g['ok'], 'Alice (group_create) creates a group');
t(comms_membership($gid, $a)['role'] === 'manager' && comms_membership($gid, $b)['role'] === 'member', 'creator is manager, others are members');
$gm1 = comms_send($b, $gid, 'before Eve joins', uuid())['id'];
$gm2 = comms_send($c, $gid, 'also before Eve', uuid())['id'];

section('Groups: membership window (new members and removed members)');
t(!comms_group_add($b, $gid, $e)['ok'], 'plain member cannot add people');
t(comms_group_add($a, $gid, $e)['ok'], 'manager adds Eve');
t(!comms_group_add($a, $gid, $e)['ok'], 'cannot add an existing member twice');
t(comms_fetch($e, $gid)['messages'] === [], 'Eve sees NO pre-join history');
t(!array_filter(comms_conversations($e), static fn($x) => $x['id'] === $gid && $x['unread'] > 0), 'Eve has no unread from before she joined');
$er = comms_send($e, $gid, 'reply attempt', uuid(), $gm1);
t($er['ok'] && Database::fetchValue('SELECT reply_to_id FROM comms_messages WHERE id = :i', ['i' => $er['id']]) === null, 'replying to a pre-join message is dropped (cannot be used to reach old history)');
$gm3 = comms_send($b, $gid, 'after Eve joins', uuid())['id'];
$ev = comms_fetch($e, $gid);
t(count($ev['messages']) >= 1 && $ev['messages'][count($ev['messages']) - 1]['id'] === $gm3, 'Eve sees messages sent after she joined');
t(!in_array($gm1, array_column($ev['messages'], 'id'), true), 'old message ids never returned to Eve');
t(!comms_fetch($e, $gid, 0, $gm3)['messages'] || !in_array($gm1, array_column(comms_fetch($e, $gid, 0, $gm3)['messages'], 'id'), true), 'scroll-back cannot reach before the join point');
t(comms_group_remove($a, $gid, $e)['ok'], 'manager removes Eve');
t(!comms_fetch($e, $gid)['ok'], 'removed member cannot read anything (old links/ids included)');
t(!comms_send($e, $gid, 'x', uuid())['ok'], 'removed member cannot send');
t($gid !== 0 && !array_filter(comms_conversations($e), static fn($x) => $x['id'] === $gid), 'removed member no longer lists the group');
comms_send($b, $gid, 'after Eve removed', uuid());
comms_group_add($a, $gid, $e);
$re = comms_fetch($e, $gid);
t(count($re['messages']) === 0, 're-added member starts fresh: neither old nor gap-period messages');
t(!comms_group_remove($a, $gid, $a)['ok'], 'manager cannot remove themselves');
t(!comms_group_remove($b, $gid, $c)['ok'], 'plain member cannot remove others');

section('Group receipts and live permission changes');
$gm = comms_send($a, $gid, 'to everyone', uuid())['id'];
comms_mark_read($b, $gid, $gm);
$rg = comms_receipts($a, $gid, [$gm])[$gm];
t($rg['read'] === 1 && $rg['total'] === 3 && $rg['state'] === 'sent', 'group read count 1 of 3 (not "read" until all have read)');
Permission::revoke($roleChat, 'comms.send_message');
t(!comms_send($b, $gid, 'x', uuid())['ok'], 'revoking send_message stops sending immediately');
t(!comms_private_open($a, $b)['ok'], 'opening a chat rechecks the target\'s permission at that moment');
t(!comms_send($a, $conv, 'x', uuid())['ok'], 'private chat: cannot message someone who lost chat permission');
Permission::grant($roleChat, 'comms.send_message');
Database::execute("UPDATE users SET status='inactive' WHERE id = :u", ['u' => $b]);
t(!comms_send($b, $gid, 'x', uuid())['ok'], 'deactivated user cannot send');
t(comms_send($a, $gid, 'still works for others', uuid())['ok'], 'other members unaffected');
Database::execute("UPDATE users SET status='active' WHERE id = :u", ['u' => $b]);

section('Presence and typing');
comms_touch_presence($b);
$pl = comms_conversations($a);
$priv = array_values(array_filter($pl, static fn($x) => $x['type'] === 'private'))[0];
t($priv['online'] === true, 'online shown after a recent poll');
Database::execute("UPDATE comms_presence SET last_seen_at = NOW() - INTERVAL 5 MINUTE WHERE user_id = :u", ['u' => $b]);
$priv = array_values(array_filter(comms_conversations($a), static fn($x) => $x['type'] === 'private'))[0];
t($priv['online'] === false && $priv['last_seen'] !== '', 'offline with a last-seen time after inactivity');
comms_set_typing($b, $conv);
t(comms_typing_names($a, $conv) === ['Bob'], 'typing indicator visible to the other member');
t(comms_typing_names($c, $conv) === [], 'typing indicator not visible to a non-member');
Database::execute("UPDATE comms_presence SET typing_at = NOW() - INTERVAL 30 SECOND WHERE user_id = :u", ['u' => $b]);
t(comms_typing_names($a, $conv) === [], 'typing indicator expires');

section('Audit');
t((int)Database::fetchValue("SELECT COUNT(*) FROM comms_audit_log WHERE action IN ('group.created','group.member_added','group.member_removed','chat.private_created')") >= 5, 'chat/group events audited');
t(!str_contains(json_encode(Database::fetchAll('SELECT old_values,new_values,description FROM comms_audit_log')), 'Salaam'), 'message text is never written to the audit log');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
