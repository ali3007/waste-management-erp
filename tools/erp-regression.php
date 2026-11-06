<?php
/**
 * ERP regression tests for the Users / shared-layout / permissions fixes (command line).
 *
 *   php tools/erp-regression.php --confirm-test-database
 *
 * Runs against a THROWAWAY database (its name must contain "test"; schema.sql loaded, nothing else).
 * Attendance has its own suite: tools/attendance-selftest.php.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require_once dirname(__DIR__) . '/config.php';
if (!str_contains(strtolower(DB_NAME), 'test') || !in_array('--confirm-test-database', $argv, true)) {
    fwrite(STDERR, "Refusing to run: the database name must contain \"test\" and --confirm-test-database must be given.\n");
    exit(2);
}
require_once MODULES_PATH . '/users/bootstrap.php';

$pass = $fail = 0;
function t(bool $c, string $n): void { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? '  ok   ' : '  FAIL ') . $n . "\n"; }
function section(string $s): void { echo "\n== {$s}\n"; }
$root = dirname(__DIR__);

section('Bug 2 — shared header must not overwrite page variables');
foreach (['includes/header.php', 'includes/sidebar.php', 'includes/footer.php'] as $f) {
    $src = (string)file_get_contents("$root/$f");
    t(!preg_match('/\$avatar\b\s*=/', $src) && !preg_match('/\$userAvatar\b\s*=/', $src), "$f does not assign \$avatar / \$userAvatar into page scope");
}
$hdr = (string)file_get_contents("$root/includes/header.php");
t(str_contains($hdr, '$headerAvatar') && str_contains($hdr, '$headerUser'), 'header uses header-local variable names');
foreach (['edit', 'view'] as $page) {
    $src = (string)file_get_contents("$root/modules/users/$page.php");
    t(str_contains($src, '$userAvatar') && !preg_match('/\$avatar\b/', $src), "users/$page.php uses its own \$userAvatar (not the header's name)");
}

section('Profile pictures — avatar_url()');
@mkdir(AVATAR_PATH, 0775, true);
$good = 'avatar-regression-' . bin2hex(random_bytes(4)) . '.png';
file_put_contents(AVATAR_PATH . '/' . $good, 'x');
t(str_contains(avatar_url($good), rawurlencode($good)), 'an existing file name resolves to its own URL');
t(avatar_url('does-not-exist-' . $good) === '', 'a missing file gives no picture (neutral default), never another user\'s');
t(avatar_url(null) === '' && avatar_url('') === '', 'empty value gives no picture');
t(avatar_url('../../config.php') === '' && avatar_url('..\\config.php') === '' && avatar_url('sub/' . $good) === '', 'path-like values are never followed');
@unlink(AVATAR_PATH . '/' . $good);

section('Delegation rule — hand out only what you hold');
t(users_slugs_covered(['users.view'], ['users.view', 'users.create']), 'a subset is allowed');
t(!users_slugs_covered(['users.view', 'roles.update'], ['users.view']), 'a permission the holder lacks is refused');
t(users_slugs_covered([], []), 'a role with no permissions is allowed to anyone');
t(users_slugs_covered(['anything.at_all'], ['*']), 'the wildcard holder may grant anything');
t(!users_slugs_covered(['system.settings'], ['users.view', 'users.create', 'users.update', 'roles.view', 'roles.update']), 'a user manager cannot grant system settings');

section('Roles screen — every installed module\'s permissions are registered');
$before = (int)Database::fetchValue('SELECT COUNT(*) FROM permissions');
users_load_module_permissions();
$after = (int)Database::fetchValue('SELECT COUNT(*) FROM permissions');
t($after > $before, "permissions registered from modules: {$before} → {$after}");
foreach (['hr' => 'employee.view', 'transport' => 'transport.config', 'complaint' => 'complaint.view', 'attendance' => 'attendance.access', 'structure' => null] as $mod => $slug) {
    t($slug === null ? (int)Database::fetchValue("SELECT COUNT(*) FROM permissions WHERE module = :m", ['m' => $mod]) > 0 : Database::exists('SELECT id FROM permissions WHERE slug = :s', ['s' => $slug]), "module '{$mod}' permissions are listed");
}
t((int)Database::fetchValue("SELECT COUNT(*) FROM permissions WHERE slug LIKE 'attendance.timing_manage_%'") >= 3, 'the per-category attendance timing permissions are listed');
users_load_module_permissions();
t((int)Database::fetchValue('SELECT COUNT(*) FROM permissions') === $after, 'loading twice registers nothing new (idempotent)');

echo "\n" . ($fail === 0 ? 'ALL PASSED' : "FAILED: {$fail}") . " — {$pass} checks passed\n";
exit($fail === 0 ? 0 : 1);
