<?php
/**
 * Activates or deactivates a Staff Pool or an HR Staff Type.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('hrtype.update');

if (!is_post()) {
    redirect('modules/hr/types.php');
}

Security::requireCsrf('modules/hr/types.php');

$kind = post('kind');
if (!in_array($kind, ['pool', 'type'], true)) {
    flash('error', 'Unknown record type.');
    redirect('modules/hr/types.php');
}

$table = $kind === 'pool' ? 'hr_staff_pools' : 'hr_staff_types';
$id = (int)post('id');
$newStatus = post('status');

if (!in_array($newStatus, ['active', 'inactive'], true)) {
    flash('error', 'That is not a valid status.');
    redirect('modules/hr/types.php');
}

$row = Database::fetch("SELECT * FROM `{$table}` WHERE id = :id", ['id' => $id]);
if ($row === null) {
    flash('error', 'That record could not be found.');
    redirect('modules/hr/types.php');
}

try {
    Database::update($table, ['status' => $newStatus, 'updated_by' => Auth::id()], ['id' => $id]);

    Database::insert('activity_log', [
        'user_id' => Auth::id(), 'module' => 'hr', 'action' => $kind . '.status_changed',
        'subject_type' => $kind, 'subject_id' => $id,
        'description' => $row['name'] . ' set to ' . $newStatus,
        'ip_address' => client_ip(),
    ]);
} catch (Throwable $e) {
    flash('error', Database::friendlyError($e));
    redirect('modules/hr/types.php');
}

flash('success', '"' . $row['name'] . '" is now ' . ($newStatus === 'active' ? 'active.' : 'inactive.'));
redirect('modules/hr/types.php');
