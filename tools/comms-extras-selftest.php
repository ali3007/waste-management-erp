<?php
/**
 * Chat extras self-test (files, voice, reactions, pins, edit/delete, forward, search).
 *   php tools/comms-extras-selftest.php --confirm-test-database      (throwaway database whose name contains "test")
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
function tmpf(string $bytes): string { $p = tempnam(sys_get_temp_dir(), 'cx'); file_put_contents($p, $bytes); return $p; }
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$WEBM = "\x1A\x45\xDF\xA3" . random_bytes(2000);

foreach (['comms_attachments','comms_reactions','comms_pins','comms_message_hidden','comms_message_edits','comms_messages','comms_members','comms_conversations','comms_presence'] as $tb) { Database::execute("DELETE FROM $tb"); }
Database::execute("DELETE FROM users WHERE cnic LIKE '98989898%'");
Database::execute("DELETE FROM system_settings WHERE setting_key IN ('comms_allowed_extensions','comms_max_file_mb','comms_storage_limit_mb','comms_edit_window_min','comms_voice_max_sec')"); comms_settings_reset();
$all = ['access','send_message','chat_private','send_file','send_voice','group_create','group_manage'];
$rA = Database::insert('roles', ['name' => 'XMgr ' . random_int(1, 99999), 'slug' => 'xm' . random_int(1, 99999), 'level' => 6, 'is_system' => 0]);
$rB = Database::insert('roles', ['name' => 'XMem ' . random_int(1, 99999), 'slug' => 'xb' . random_int(1, 99999), 'level' => 5, 'is_system' => 0]);
$rN = Database::insert('roles', ['name' => 'XNoFile ' . random_int(1, 99999), 'slug' => 'xn' . random_int(1, 99999), 'level' => 5, 'is_system' => 0]);
$rM = Database::insert('roles', ['name' => 'XModer ' . random_int(1, 99999), 'slug' => 'xo' . random_int(1, 99999), 'level' => 5, 'is_system' => 0]);
foreach ($all as $a) { Permission::grant($rA, "comms.$a"); }
foreach (['access','send_message','chat_private','send_file','send_voice'] as $a) { Permission::grant($rB, "comms.$a"); Permission::grant($rM, "comms.$a"); }
foreach (['access','send_message','chat_private'] as $a) { Permission::grant($rN, "comms.$a"); }
Permission::grant($rM, 'comms.message_moderate');
$mk = static fn(string $n, int $role, int $i): int => Database::insert('users', ['role_id' => $role, 'name' => $n, 'cnic' => '989898989' . $i . '00', 'password_hash' => 'x', 'login_type' => 'cnic', 'status' => 'active']);
$mgr = $mk('Mgr', $rA, 1); $bob = $mk('Bob', $rB, 2); $carl = $mk('Carl', $rB, 3); $nof = $mk('NoFile', $rN, 4); $mod = $mk('Moder', $rM, 5); $out = $mk('Outsider', $rB, 6);
$pc = comms_private_open($bob, $mgr)['conversation_id'];
$g = comms_group_create($mgr, 'Team', [$bob, $carl, $nof, $mod]); $gid = $g['conversation_id'];
S('comms_allowed_extensions', 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv,zip');

section('Files: accepted types');
$r = comms_send_file($bob, $gid, tmpf($PNG), 'photo.png', 0, uuid(), 'look', false);
t($r['ok'], 'real PNG accepted'); $pngMsg = $r['id'];
$att = Database::fetch('SELECT * FROM comms_attachments WHERE message_id = :m', ['m' => $pngMsg]);
t($att['mime'] === 'image/png' && $att['kind'] === 'file' && strlen($att['sha256']) === 64 && is_file(comms_attachment_path($att)), 'stored with mime, sha256 and a file on disk');
t(!str_contains(comms_attachment_path($att), 'photo') && preg_match('/^[0-9a-f]{32}$/', $att['stored_name']) === 1, 'stored under a random name (original name never touches the disk path)');
t(comms_send_file($bob, $gid, tmpf("%PDF-1.4\n" . str_repeat('x', 100)), 'Report.PDF', 0, uuid(), '', false)['ok'], 'PDF accepted (extension case-insensitive)');
t(comms_send_file($bob, $gid, tmpf("PK\x03\x04" . random_bytes(80)), 'sheet.xlsx', 0, uuid(), '', false)['ok'], 'xlsx (zip container) accepted');
t(comms_send_file($bob, $gid, tmpf("اسلام علیکم\nhello"), 'نوٹ.txt', 0, uuid(), '', false)['ok'], 'Urdu text file with an Urdu name accepted');
t(Database::fetchValue("SELECT original_name FROM comms_attachments WHERE original_name LIKE '%.txt' ORDER BY id DESC LIMIT 1") === 'نوٹ.txt', 'Urdu file name preserved');
$r = comms_send_file($bob, $gid, tmpf($PNG), '../../etc/passwd.png', 0, uuid(), '', false);
t($r['ok'] && Database::fetchValue('SELECT original_name FROM comms_attachments WHERE message_id = :m', ['m' => $r['id']]) === 'passwd.png', 'path traversal in the name is stripped');

section('Files: refused');
$cnt = fn() => (int)Database::fetchValue('SELECT COUNT(*) FROM comms_messages');
$n0 = $cnt();
foreach ([['<?php system($_GET[0]); ?>', 'shell.php'], ['<?php x ?>', 'shell.jpg'], ["MZ\x90\x00", 'tool.exe'], ['<html><script>1</script>', 'p.html'], ['<svg onload=1>', 'p.svg'], [$PNG, 'photo.php'], ['x', 'noext'], ['x', '.htaccess'], [$PNG, 'a.php.png.exe']] as [$bytes, $name]) {
    t(!comms_send_file($bob, $gid, tmpf($bytes), $name, 0, uuid(), '', false)['ok'], "refused: $name");
}
t(!comms_send_file($bob, $gid, tmpf("%PDF-1.4"), 'fake.png', 0, uuid(), '', false)['ok'], 'PDF renamed .png refused (content check)');
t(!comms_send_file($bob, $gid, tmpf($PNG), 'fake.pdf', 0, uuid(), '', false)['ok'], 'PNG renamed .pdf refused');
t(!comms_send_file($bob, $gid, tmpf($PNG . "<?php evil(); ?>"), 'poly.png', 0, uuid(), '', false)['ok'], 'image carrying PHP code (polyglot) refused');
t(!comms_send_file($bob, $gid, tmpf(random_bytes(40)), 'x.txt', 0, uuid(), '', false)['ok'], 'binary data named .txt refused');
t(!comms_send_file($bob, $gid, tmpf(''), 'empty.png', 0, uuid(), '', false)['ok'], 'empty file refused');
t(!comms_send_file($nof, $gid, tmpf($PNG), 'p.png', 0, uuid(), '', false)['ok'], 'user without send_file refused');
t(!comms_send_file($out, $gid, tmpf($PNG), 'p.png', 0, uuid(), '', false)['ok'], 'non-member refused');
S('comms_allowed_extensions', 'pdf');
t(!comms_send_file($bob, $gid, tmpf($PNG), 'p.png', 0, uuid(), '', false)['ok'], 'type not in the administrator\'s allowed list refused');
S('comms_allowed_extensions', 'php,png,exe');
t(!comms_send_file($bob, $gid, tmpf('<?php ?>'), 'x.php', 0, uuid(), '', false)['ok'], 'even if an admin lists php, it can never be sent');
S('comms_allowed_extensions', 'jpg,jpeg,png,gif,webp,pdf,docx,xlsx,txt,csv,zip');
S('comms_max_file_mb', '1');
t(!comms_send_file($bob, $gid, tmpf("%PDF-1.4" . str_repeat('x', 1100000)), 'big.pdf', 0, uuid(), '', false)['ok'], 'file over the size limit refused');
S('comms_max_file_mb', '10');
$after = $cnt();
t($after - $n0 === 0, 'no refused upload left a message behind');
$orph = 0; foreach (glob(comms_storage_root() . '/files/*/*') ?: [] as $f) { if (!Database::exists('SELECT 1 FROM comms_attachments WHERE stored_name = :s', ['s' => basename($f)])) { $orph++; } }
t($orph === 0, 'no orphan files left on disk after refusals');

section('Files: retries, storage limit');
$u = uuid(); $a1 = comms_send_file($bob, $gid, tmpf($PNG), 'again.png', 0, $u, '', false); $a2 = comms_send_file($bob, $gid, tmpf($PNG), 'again.png', 0, $u, '', false);
t($a1['ok'] && $a2['ok'] && $a2['duplicate'] && (int)Database::fetchValue("SELECT COUNT(*) FROM comms_attachments WHERE original_name = 'again.png'") === 1, 'retrying the same upload stores it once');
$filesBefore = count(glob(comms_storage_root() . '/files/*/*') ?: []);
S('comms_storage_limit_mb', '100');
Database::execute("UPDATE comms_attachments SET size_bytes = 100*1048576 WHERE id = (SELECT x.i FROM (SELECT MIN(id) AS i FROM comms_attachments) x)");
$st = comms_storage_state(); t($st['full'] && $st['warn'], 'storage reported full');
$rf = comms_send_file($bob, $gid, tmpf($PNG), 'late.png', 0, uuid(), '', false);
t(!$rf['ok'] && str_contains($rf['message'], 'Storage is full'), 'upload refused with a clear "storage is full" message');
t(count(glob(comms_storage_root() . '/files/*/*') ?: []) === $filesBefore, 'nothing stored when full');
t(comms_send($bob, $gid, 'text still works when storage is full', uuid())['ok'], 'text chat still works when storage is full');
Database::execute("UPDATE comms_attachments SET size_bytes = 70 WHERE size_bytes = 100*1048576");
Database::execute("UPDATE comms_attachments SET size_bytes = 95*1048576 WHERE id = (SELECT x.i FROM (SELECT MIN(id) AS i FROM comms_attachments) x)");
$st = comms_storage_state(); t(!$st['full'] && $st['warn'], 'warning state at 90%+');
Database::execute("UPDATE comms_attachments SET size_bytes = 70 WHERE size_bytes = 95*1048576");
S('comms_storage_limit_mb', '2048');

section('Voice messages');
$v = comms_send_voice($bob, $gid, tmpf($WEBM), 12, uuid(), false);
t($v['ok'], 'webm recording accepted'); $va = Database::fetch('SELECT * FROM comms_attachments WHERE message_id = :m', ['m' => $v['id']]);
t($va['kind'] === 'voice' && $va['mime'] === 'audio/webm' && (int)$va['duration_sec'] === 12, 'stored as voice with detected type and duration');
t(comms_send_voice($bob, $gid, tmpf("OggS" . random_bytes(500)), 5, uuid(), false)['ok'] && comms_send_voice($bob, $gid, tmpf("\0\0\0\x18ftypM4A " . random_bytes(500)), 5, uuid(), false)['ok'] && comms_send_voice($bob, $gid, tmpf("RIFF\0\0\0\0WAVEfmt " . random_bytes(500)), 5, uuid(), false)['ok'], 'ogg, mp4 and wav recordings accepted');
t(!comms_send_voice($bob, $gid, tmpf("<?php echo 1; ?>" . str_repeat('x', 300)), 5, uuid(), false)['ok'], 'script disguised as audio refused');
t(!comms_send_voice($bob, $gid, tmpf(random_bytes(500)), 5, uuid(), false)['ok'], 'unrecognised bytes refused');
t(!comms_send_voice($bob, $gid, tmpf($WEBM), 0, uuid(), false)['ok'] && !comms_send_voice($bob, $gid, tmpf($WEBM), 500, uuid(), false)['ok'], 'zero-length and over-limit durations refused');
t(!comms_send_voice($bob, $gid, tmpf("\x1A\x45\xDF\xA3" . random_bytes(3000000)), 10, uuid(), false)['ok'], 'implausibly large recording for its duration refused');
t(!comms_send_voice($nof, $gid, tmpf($WEBM), 5, uuid(), false)['ok'], 'user without send_voice refused');
$u = uuid(); comms_send_voice($bob, $gid, tmpf($WEBM), 5, $u, false); $dup = comms_send_voice($bob, $gid, tmpf($WEBM), 5, $u, false);
t($dup['duplicate'] === true, 'voice retry is idempotent');

section('Expiring links and download authorization');
$aid = (int)$att['id'];
$tok = comms_link_token($bob, 'a' . $aid);
t(comms_link_valid($bob, 'a' . $aid, $tok), 'fresh link valid for its user');
t(!comms_link_valid($carl, 'a' . $aid, $tok), 'link is useless to another user (cannot be shared)');
t(!comms_link_valid($bob, 'a' . ($aid + 1), $tok), 'link is bound to one file');
t(!comms_link_valid($bob, 'a' . $aid, $tok, time() + COMMS_LINK_TTL + 5), 'link expires');
t(!comms_link_valid($bob, 'a' . $aid, substr($tok, 0, -1) . (substr($tok, -1) === '0' ? '1' : '0')) && !comms_link_valid($bob, 'a' . $aid, '9999999999.' . str_repeat('a', 64)) && !comms_link_valid($bob, 'a' . $aid, ''), 'tampered, forged and empty tokens rejected');
t(comms_authorize_attachment($carl, $aid, comms_link_token($carl, 'a' . $aid))['ok'], 'another group member may open it');
t(!comms_authorize_attachment($out, $aid, comms_link_token($out, 'a' . $aid))['ok'], 'non-member refused even with a correctly signed link');
t(comms_authorize_attachment($out, $aid, comms_link_token($out, 'a' . $aid))['status'] === 403, '...with 403');
t(comms_authorize_attachment($carl, 999999, comms_link_token($carl, 'a999999'))['status'] === 404, 'unknown attachment is 404');
$eve = $mk('Eve', $rB, 7); comms_group_add($mgr, $gid, $eve);
t(!comms_authorize_attachment($eve, $aid, comms_link_token($eve, 'a' . $aid))['ok'], 'member added later cannot open a file sent before they joined');
$late = comms_send_file($bob, $gid, tmpf($PNG), 'after.png', 0, uuid(), '', false)['id']; $lateAtt = (int)Database::fetchValue('SELECT id FROM comms_attachments WHERE message_id = :m', ['m' => $late]);
t(comms_authorize_attachment($eve, $lateAtt, comms_link_token($eve, 'a' . $lateAtt))['ok'], 'but can open files sent after joining');
comms_group_remove($mgr, $gid, $eve);
t(!comms_authorize_attachment($eve, $lateAtt, comms_link_token($eve, 'a' . $lateAtt))['ok'], 'removed member loses file access at once');
Database::execute("UPDATE users SET status='inactive' WHERE id = :u", ['u' => $carl]);
t(!comms_authorize_attachment($carl, $aid, comms_link_token($carl, 'a' . $aid))['ok'], 'a deactivated user is refused even with a valid link');
Database::execute("UPDATE users SET status='active' WHERE id = :u", ['u' => $carl]);
comms_hide($carl, $pngMsg);
t(comms_authorize_attachment($carl, $aid, comms_link_token($carl, 'a' . $aid))['status'] === 404 && comms_authorize_attachment($bob, $aid, comms_link_token($bob, 'a' . $aid))['ok'], 'hidden-for-me blocks only that user');
Database::execute('DELETE FROM comms_message_hidden');

section('Edit and delete rules');
$m1 = comms_send($bob, $gid, 'original text', uuid())['id'];
t(comms_edit($bob, $m1, 'edited text')['ok'] && Database::fetchValue('SELECT body FROM comms_messages WHERE id = :i', ['i' => $m1]) === 'edited text', 'author edits own message inside the window');
t((int)Database::fetchValue('SELECT COUNT(*) FROM comms_message_edits WHERE message_id = :i AND old_body = :b', ['i' => $m1, 'b' => 'original text']) === 1, 'the old text is kept in edit history');
t(!comms_edit($carl, $m1, 'hijack')['ok'], 'another member cannot edit it');
t(!comms_edit($bob, $m1, '   ')['ok'], 'cannot edit to empty');
$mm = array_values(array_filter(comms_fetch($carl, $gid, 0, 0, 100)['messages'], fn($x) => $x['id'] === $m1))[0];
t($mm['edited'] && $mm['body'] === 'edited text' && $mm['can_edit'] === false && $mm['can_delete'] === false, 'others see "edited" and get no edit/delete rights');
Database::execute('UPDATE comms_messages SET created_at = NOW() - INTERVAL 2 HOUR WHERE id = :i', ['i' => $m1]);
$e = comms_edit($bob, $m1, 'too late'); t(!$e['ok'] && str_contains($e['message'], 'time limit'), 'edit refused after the time limit');
t(!comms_delete($bob, $m1)['ok'], 'delete-for-everyone refused after the time limit');
t(comms_edit($mod, $m1, 'moderated text')['ok'], 'a user with message_moderate can edit after the limit');
t(Database::exists("SELECT 1 FROM comms_audit_log WHERE action = 'chat.message_moderated_edit'"), 'moderation is audited separately');
t(!comms_edit($mgr, $m1, 'x')['ok'], 'manager role alone is not a moderator');
t(comms_hide($bob, $m1)['ok'] && !in_array($m1, array_column(comms_fetch($bob, $gid, 0, 0, 100)['messages'], 'id'), true) && in_array($m1, array_column(comms_fetch($carl, $gid, 0, 0, 100)['messages'], 'id'), true), 'delete-for-me hides it from me only');
Database::execute('DELETE FROM comms_message_hidden');
$m2 = comms_send($bob, $gid, 'to be deleted', uuid())['id']; comms_pin($mgr, $m2, true); comms_react($carl, $m2, '👍');
t(comms_delete($bob, $m2)['ok'], 'author deletes own message inside the window');
$dm = array_values(array_filter(comms_fetch($carl, $gid, 0, 0, 100)['messages'], fn($x) => $x['id'] === $m2))[0];
t($dm['deleted'] === true && $dm['body'] === '' && $dm['reactions'] === [] && $dm['file'] === null, 'deleted message shows no text, reactions or file to anyone');
t(Database::fetchValue('SELECT body FROM comms_messages WHERE id = :i', ['i' => $m2]) === 'to be deleted', 'the text is retained in the database for history/audit');
t(comms_pins_list($mgr, $gid) === [] || !in_array($m2, array_column(comms_pins_list($mgr, $gid), 'id'), true), 'deleting unpins');
t(!comms_edit($bob, $m2, 'x')['ok'] && !comms_react($carl, $m2, '👍')['ok'], 'a deleted message cannot be edited or reacted to');
$fm = comms_send_file($bob, $gid, tmpf($PNG), 'gone.png', 0, uuid(), '', false)['id']; $fa = (int)Database::fetchValue('SELECT id FROM comms_attachments WHERE message_id = :m', ['m' => $fm]);
comms_delete($bob, $fm);
t(comms_authorize_attachment($carl, $fa, comms_link_token($carl, 'a' . $fa))['status'] === 404, 'deleting a message also blocks its file');
t(is_file(comms_attachment_path(Database::fetch('SELECT * FROM comms_attachments WHERE id = :i', ['i' => $fa]))), 'the file itself is never auto-deleted');
t(!str_contains(json_encode(Database::fetchAll('SELECT description,old_values,new_values FROM comms_audit_log')), 'original text'), 'audit never contains message text');

section('Reactions');
$rm = comms_send($bob, $gid, 'react to me', uuid())['id'];
t(comms_react($carl, $rm, '👍')['ok'] && comms_react($mgr, $rm, '👍')['ok'] && comms_react($nof, $rm, '❤️')['ok'], 'members react');
$sh = fn($u) => array_values(array_filter(comms_fetch($u, $gid, 0, 0, 100)['messages'], fn($x) => $x['id'] === $rm))[0]['reactions'];
$rx = array_values(array_filter($sh($carl), fn($x) => $x['e'] === '👍'))[0]; t($rx['n'] === 2 && $rx['mine'] === true, 'counts grouped; "mine" flag is per viewer');
t(array_values(array_filter($sh($bob), fn($x) => $x['e'] === '👍'))[0]['mine'] === false, 'author did not react');
comms_react($carl, $rm, '❤️'); t(array_sum(array_column($sh($carl), 'n')) === 3, 'changing a reaction replaces (one per user)');
comms_react($carl, $rm, '❤️'); t(array_sum(array_column($sh($carl), 'n')) === 2, 'tapping the same reaction removes it');
t(!comms_react($carl, $rm, '💩')['ok'] && !comms_react($carl, $rm, '<b>')['ok'], 'only the allowed emoji accepted');
t(!comms_react($out, $rm, '👍')['ok'], 'non-member cannot react');

section('Pins and announcements');
$pm = comms_send($mgr, $gid, 'Pin me', uuid())['id'];
t(!comms_pin($bob, $pm, true)['ok'], 'plain member cannot pin in a group');
t(comms_pin($mgr, $pm, true)['ok'] && comms_pins_list($bob, $gid)[0]['id'] === $pm, 'manager pins; everyone sees the pin');
t(comms_pin($mgr, $pm, false)['ok'] && comms_pins_list($bob, $gid) === [], 'unpin works');
$pp = comms_send($bob, $pc, 'private pin', uuid())['id'];
t(comms_pin($bob, $pp, true)['ok'] && comms_pin($mgr, $pp, false)['ok'], 'either person can pin in a private chat');
t(!comms_pin($out, $pp, true)['ok'], 'outsider cannot pin');
t(!comms_announce($bob, $pm, true)['ok'] && !comms_announce($mgr, $pp, true)['ok'], 'only group managers announce, and only in groups');
t(comms_announce($mgr, $pm, true)['ok'] && array_values(array_filter(comms_fetch($bob, $gid, 0, 0, 100)['messages'], fn($x) => $x['id'] === $pm))[0]['announcement'] === true, 'announcement flag visible to members');

section('Reader list');
comms_mark_read($carl, $gid, $pm);
$rd = comms_readers($mgr, $pm); t($rd['ok'] && in_array('Carl', $rd['read'], true) && in_array('Bob', $rd['unread'], true), 'sender sees who has/has not read');
t(!comms_readers($carl, $pm)['ok'], 'a plain member cannot see readers of someone else\'s message');
t(!comms_readers($bob, $pp)['ok'], 'no reader list for private chats');
$rd2 = comms_readers($mgr, $pm); t(!in_array('Eve', array_merge($rd2['read'], $rd2['unread']), true), 'removed members are not listed');

section('Forwarding');
$fw = comms_forward($carl, $rm, [$pc]);
t(!$fw['ok'], 'cannot forward into a conversation you are not in');
$pc2 = comms_private_open($carl, $mgr)['conversation_id'];
$fw = comms_forward($carl, $rm, [$pc2]); t($fw['ok'] && Database::fetchValue('SELECT forwarded FROM comms_messages WHERE id = :i', ['i' => $fw['sent'][0] ? Database::fetchValue('SELECT MAX(id) FROM comms_messages WHERE conversation_id = :c', ['c' => $pc2]) : 0]) == 1, 'text forwarded and flagged');
$before = comms_storage_used();
$fw = comms_forward($carl, $late, [$pc2]); $fwMsg = (int)Database::fetchValue('SELECT MAX(id) FROM comms_messages WHERE conversation_id = :c', ['c' => $pc2]);
t($fw['ok'] && Database::fetchValue('SELECT stored_name FROM comms_attachments WHERE message_id = :m', ['m' => $fwMsg]) === Database::fetchValue('SELECT stored_name FROM comms_attachments WHERE message_id = :m', ['m' => $late]), 'file forwarded without copying bytes');
t(comms_storage_used() === $before, 'shared file counted once in the storage total');
$fa2 = (int)Database::fetchValue('SELECT id FROM comms_attachments WHERE message_id = :m', ['m' => $fwMsg]);
t(comms_authorize_attachment($mgr, $fa2, comms_link_token($mgr, 'a' . $fa2))['ok'], 'recipient can open the forwarded file');
t(!comms_forward($nof, $late, [$gid])['ok'], 'user without send_file cannot forward a file');
t(!comms_forward($out, $rm, [$gid])['ok'], 'cannot forward a message you cannot see');
t(!comms_forward($carl, $rm, [])['ok'] && !comms_forward($carl, $rm, [1, 2, 3, 4, 5, 6])['ok'], 'must choose 1–5 targets');
t(!comms_forward($carl, $m2, [$pc2])['ok'], 'a deleted message cannot be forwarded');

section('Search');
comms_send($bob, $gid, 'Budget meeting at 5 Salaam', uuid()); comms_send($bob, $gid, '100% done_ok', uuid()); comms_send($bob, $pc, 'private budget talk', uuid());
$sr = comms_search($carl, 'budget'); $convs = array_unique(array_column($sr['results'], 'conversation_id'));
t($sr['ok'] && $convs === [$gid], 'finds the group message, never someone else\'s private chat');
t(count(comms_search($bob, 'budget')['results']) === 2, 'a participant finds both of their own conversations');
t(comms_search($carl, 'a')['ok'] === false, 'one-character search refused');
t(count(comms_search($bob, '100%')['results']) === 1 && count(comms_search($bob, '%%')['results']) === 0, 'LIKE wildcards are escaped');
t(count(comms_search($bob, "' OR '1'='1")['results']) === 0, 'search is injection-safe');
t(count(comms_search($bob, 'photo.png')['results']) === 1, 'file names are searchable');
t(comms_search($bob, 'budget', ['conversation_id' => $pc])['results'][0]['conversation_id'] === $pc && count(comms_search($bob, 'budget', ['conversation_id' => $pc])['results']) === 1, 'restrict to one conversation');
t(count(comms_search($bob, 'budget', ['from' => date('Y-m-d', time() + 86400 * 2)])['results']) === 0 && count(comms_search($bob, 'budget', ['from' => date('Y-m-d', time() - 86400)])['results']) === 2, 'date filter works');
t(count(comms_search($bob, 'budget', ['sender_id' => $mgr])['results']) === 0, 'sender filter works');
t(count(comms_search($bob, 'to be deleted')['results']) === 0, 'deleted messages are not searchable');
t(count(comms_search($eve, 'budget')['results']) === 0, 'a removed member finds nothing');
$late2 = $mk('Late', $rB, 8); comms_group_add($mgr, $gid, $late2);
t(count(comms_search($late2, 'budget')['results']) === 0, 'a later-added member cannot find pre-join messages');
comms_hide($bob, (int)Database::fetchValue("SELECT id FROM comms_messages WHERE body LIKE 'Budget meeting%'"));
t(count(comms_search($bob, 'Budget meeting')['results']) === 0, 'hidden-for-me messages are not searchable for that user');

section('Group name, photo');
t(!comms_group_rename($bob, $gid, 'x')['ok'] && comms_group_rename($mgr, $gid, '  Ops   Team ')['ok'] && Database::fetchValue('SELECT name FROM comms_conversations WHERE id = :i', ['i' => $gid]) === 'Ops Team', 'only managers rename; spaces normalised');
t(!comms_group_photo($bob, $gid, tmpf($PNG), 'p.png', false)['ok'], 'member cannot change the photo');
t(!comms_group_photo($mgr, $gid, tmpf('<?php ?>'), 'p.png', false)['ok'] && !comms_group_photo($mgr, $gid, tmpf($PNG), 'p.gif', false)['ok'], 'non-images / wrong types refused');
t(comms_group_photo($mgr, $gid, tmpf($PNG), 'p.png', false)['ok'], 'manager sets the photo');
$ph = Database::fetchValue('SELECT photo FROM comms_conversations WHERE id = :i', ['i' => $gid]);
t(is_file(comms_storage_root() . "/photos/$ph"), 'photo stored privately');
comms_group_photo($mgr, $gid, tmpf($PNG), 'q.png', false);
t(!is_file(comms_storage_root() . "/photos/$ph"), 'replacing the photo removes the old file');

section('Change sync (edits/reactions reach open chats)');
$sync = comms_send($bob, $gid, 'sync me', uuid())['id'];
$since = comms_fetch($carl, $gid, 0, 0, 1)['now_ms']; usleep(20000);
comms_react($mgr, $sync, '🙏');
$up = comms_fetch($carl, $gid, 0, 0, 100, false, $since - 50)['messages'];
t(count($up) >= 1 && in_array($sync, array_column($up, 'id'), true), 'a reaction shows up in the "changed since" feed');
$none = comms_fetch($carl, $gid, 0, 0, 100, false, comms_now_ms() + 5000)['messages']; t($none === [], 'nothing reported when nothing changed');
comms_edit($bob, $sync, 'sync me v2'); t(in_array($sync, array_column(comms_fetch($carl, $gid, 0, 0, 100, false, $since - 50)['messages'], 'id'), true), 'edits are in the feed too');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
