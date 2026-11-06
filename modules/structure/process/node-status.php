<?php
/**
 * Activates or deactivates a record at any Structure level.
 * Deactivation never deletes anything — the row and every relationship
 * to it stay exactly as they were, for historical/reporting purposes.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('structure.status');

if (!is_post()) {
    redirect('modules/structure/index.php');
}

$level = post('level');
$config = structure_level_config($level);
$returnLevel = post('return_level', $level);
$returnParent = post('return_parent') !== '' ? (int)post('return_parent') : null;
$backTo = base_url('modules/structure/index.php') . '?' . http_build_query(array_filter([
    'level' => $returnLevel, 'parent' => $returnParent,
], static fn($v) => $v !== null));

if ($config === null) {
    flash('error', 'Unknown record type.');
    redirect('modules/structure/index.php');
}

Security::requireCsrf($backTo);

$id = (int)post('id');
$newStatus = post('status');

if (!in_array($newStatus, ['active', 'inactive'], true)) {
    flash('error', 'That is not a valid status.');
    redirect($backTo);
}

$row = structure_find($level, $id);
if ($row === null) {
    flash('error', 'That record could not be found.');
    redirect($backTo);
}

try {
    Database::update($config['table'], ['status' => $newStatus, 'updated_by' => Auth::id()], ['id' => $id]);

    Database::insert('activity_log', [
        'user_id' => Auth::id(), 'module' => 'structure', 'action' => "{$level}.status_changed",
        'subject_type' => $level, 'subject_id' => $id,
        'description' => ucfirst($level) . ' ' . $row['name'] . ' set to ' . $newStatus,
        'ip_address' => client_ip(),
    ]);
} catch (Throwable $e) {
    flash('error', Database::friendlyError($e));
    redirect($backTo);
}

flash('success', '"' . $row['name'] . '" is now ' . ($newStatus === 'active' ? 'active.' : 'inactive.'));
redirect($backTo);
