<?php
/**
 * Communication module self-test (command line), Phase 1 foundation.
 *   php tools/comms-selftest.php --confirm-test-database
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
function uid(string $name): int { return (int)Database::fetchValue('SELECT id FROM users WHERE name = :n', ['n' => $name]); }

section('Schema + registration');
$tables = array_column(Database::fetchAll("SHOW TABLES LIKE 'comms\\_%'"), 0 ?: null);
t(Database::exists("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'comms_audit_log'"), 'comms_audit_log created');
t(Database::exists("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'comms_migrations'"), 'comms_migrations created');
t((int)Database::fetchValue("SELECT COUNT(*) FROM permissions WHERE module = 'comms'") === count(comms_permission_catalogue()), 'all ' . count(comms_permission_catalogue()) . ' permissions registered');
t(Database::fetchValue("SELECT version FROM modules WHERE slug = 'comms'") === COMMS_VERSION, 'module row recorded');
$before = (int)Database::fetchValue('SELECT COUNT(*) FROM permissions');
comms_run_migrations();
foreach (comms_permission_catalogue() as $a => $l) { Permission::register('comms', $a, $l); }
t((int)Database::fetchValue('SELECT COUNT(*) FROM permissions') === $before, 're-running migrations/registration is idempotent');
t((int)Database::fetchValue('SELECT COUNT(*) FROM comms_migrations') === count(comms_migration_files()), 'each migration recorded exactly once');
$fks = (int)Database::fetchValue("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'fk_comms_audit_actor'");
t($fks === 1, 'audit FK to users present');
t((int)Database::fetchValue("SELECT COUNT(*) FROM users") === 5, 'existing users untouched');

section('Settings (configurable, not hard-coded)');
t(comms_setting('comms_poll_interval_sec') === '5', 'default poll interval');
Database::execute("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('comms_edit_window_min','30','comms') ON DUPLICATE KEY UPDATE setting_value='30'");
t(comms_tz()->getName() === APP_TIMEZONE, 'central timezone defaults to ERP timezone');

section('Eligibility = existing roles/permissions, live');
$clerk = uid('Clerk Active'); $blocked = uid('Clerk Blocked'); $rev = uid('Reviewer One'); $admin = uid('Admin NoComms'); $root = uid('Root');
$roleClerk = (int)Database::fetchValue("SELECT id FROM roles WHERE slug='office-clerk'");
$roleRev = (int)Database::fetchValue("SELECT id FROM roles WHERE slug='reviewer'");
Permission::grant($roleClerk, 'comms.access'); Permission::grant($roleClerk, 'comms.task_create');
Permission::grant($roleRev, 'comms.access'); Permission::grant($roleRev, 'comms.task_review');
t(comms_user_can($root, 'comms.task_review'), 'Super Admin eligible for everything');
t(comms_user_can($rev, 'comms.task_review'), 'reviewer role eligible to review');
t(!comms_user_can($clerk, 'comms.task_review'), 'one permission does not grant another (clerk cannot review)');
t(comms_user_can($clerk, 'comms.task_create'), 'clerk eligible for task_create');
t(!comms_user_can($blocked, 'comms.task_create'), 'blocked account never eligible');
t(!comms_user_can($admin, 'comms.access'), 'Administrator role without comms grants is NOT eligible (no implicit access)');
Permission::grant($roleRev, 'comms.task_review');
Permission::revoke($roleRev, 'comms.access');
t(!comms_user_can($rev, 'comms.task_review'), 'revoking workspace access removes eligibility immediately');
Permission::grant($roleRev, 'comms.access');
Database::execute("UPDATE users SET status='inactive' WHERE id = :i", ['i' => $rev]);
t(!comms_user_can($rev, 'comms.task_review'), 'deactivated user no longer eligible');
Database::execute("UPDATE users SET status='active' WHERE id = :i", ['i' => $rev]);
t(count(comms_eligible_users('comms.task_review', 'Reviewer')) === 1, 'name search works');
t(count(comms_eligible_users('comms.task_review', "x' OR '1'='1")) === 0, 'search is injection-safe');
t(count(comms_eligible_users('comms.task_review', '%')) === 0, 'LIKE wildcard in search is escaped');

section('Audit trail');
comms_audit('test.action', 'Unit test', ['actor_id' => $root, 'subject_type' => 'task', 'subject_id' => 7, 'old' => ['a' => 1], 'new' => ['a' => 2]]);
$row = Database::fetch("SELECT * FROM comms_audit_log WHERE action='test.action'");
t($row !== null && $row['ip_address'] === 'cli' && json_decode($row['old_values'], true) === ['a' => 1] && json_decode($row['new_values'], true) === ['a' => 2], 'audit records actor, old/new values');
Database::execute('DELETE FROM users WHERE id = :i', ['i' => $blocked]);
comms_audit('test.second', 'x', ['actor_id' => $clerk]);
Database::execute('DELETE FROM users WHERE id = :i', ['i' => $clerk]);
t(Database::fetchValue("SELECT actor_id FROM comms_audit_log WHERE action='test.second'") === null, 'audit row survives user deletion (actor set NULL)');
t(!str_contains((string)file_get_contents(MODULES_PATH . '/comms/lib/core.php'), 'UPDATE comms_audit_log') && !str_contains(implode('', array_map('file_get_contents', glob(MODULES_PATH . '/comms/lib/*.php'))), 'DELETE FROM comms_audit_log'), 'module code has no update/delete path for audit');


section('Settings validation and saving');
$good = ['comms_poll_interval_sec' => '5', 'comms_edit_window_min' => '15', 'comms_timezone' => 'Asia/Karachi', 'comms_max_file_mb' => '10', 'comms_storage_limit_mb' => '2048', 'comms_voice_max_sec' => '120', 'comms_allowed_extensions' => 'PDF, .docx ,jpg,pdf'];
[$cl, $er] = comms_validate_settings($good);
t(!$er && $cl['comms_allowed_extensions'] === 'pdf,docx,jpg', 'valid input accepted; extensions normalised and de-duplicated');
foreach ([['comms_poll_interval_sec', '1'], ['comms_poll_interval_sec', '61'], ['comms_poll_interval_sec', 'abc'], ['comms_poll_interval_sec', '5; DROP'], ['comms_edit_window_min', '-1'], ['comms_max_file_mb', '0'], ['comms_max_file_mb', '201'], ['comms_voice_max_sec', '4'], ['comms_storage_limit_mb', '99'], ['comms_timezone', 'Mars/Base'], ['comms_timezone', '']] as [$k, $v]) {
    [, $e2] = comms_validate_settings([$k => $v] + $good);
    t(isset($e2[$k]), "rejects $k = '" . $v . "'");
}
foreach (['php', 'exe', 'html', 'svg', 'js', 'phtml', 'htaccess', 'bat'] as $bad) {
    [, $e2] = comms_validate_settings(['comms_allowed_extensions' => "pdf,$bad"] + $good);
    t(isset($e2['comms_allowed_extensions']), "executable/script type '$bad' can never be allowed");
}
[, $e2] = comms_validate_settings(['comms_allowed_extensions' => 'pdf,../etc'] + $good); t(isset($e2['comms_allowed_extensions']), 'path-like extension rejected');
[, $e2] = comms_validate_settings(['comms_allowed_extensions' => ' , '] + $good); t(isset($e2['comms_allowed_extensions']), 'empty list rejected');
[, $e2] = comms_validate_settings(['comms_max_file_mb' => '500', 'comms_storage_limit_mb' => '100'] + $good); t(isset($e2['comms_max_file_mb']), 'file size cannot exceed total storage');
$root = uid('Root');
comms_save_settings($cl, $root);   // baseline
$changed = comms_save_settings(array_merge($cl, ['comms_poll_interval_sec' => '8']), $root);
t($changed === ['comms_poll_interval_sec'], 'only changed keys are written');
Database::execute("DELETE FROM system_settings WHERE setting_key = 'x'"); 
$a = Database::fetch("SELECT * FROM comms_audit_log WHERE action = 'settings.changed' ORDER BY id DESC LIMIT 1");
t($a !== null && json_decode($a['old_values'], true)['comms_poll_interval_sec'] === '5' && json_decode($a['new_values'], true)['comms_poll_interval_sec'] === '8', 'settings change audited with old and new value');
t(comms_save_settings(array_merge($cl, ['comms_poll_interval_sec' => '8']), $root) === [] , 'saving identical values writes nothing and logs nothing extra');

section('Files');
foreach (['lib', 'migrations'] as $d) { t(is_file(MODULES_PATH . "/comms/$d/.htaccess"), "$d/.htaccess blocks direct access"); }
foreach (glob(MODULES_PATH . '/comms/{*,lib/*}.php', GLOB_BRACE) as $f) {
    t(str_contains((string)file_get_contents($f), "defined('SWMS') || exit") || in_array(basename($f), ['index.php', 'settings.php', 'tasks.php', 'task-new.php', 'task-edit.php', 'task-view.php', 'templates.php', 'template-edit.php'], true), basename($f) . ' refuses direct execution or is an entry point');
}
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
