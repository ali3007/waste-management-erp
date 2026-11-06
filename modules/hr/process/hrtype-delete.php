<?php
/**
 * Deletes an HR Staff Type that no employee currently holds.
 * Staff Pools are never deleted from here — only deactivated — since
 * every Type belongs to one and a dangling Type would be worse than an
 * inactive Pool.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('hrtype.delete');

if (!is_post()) {
    redirect('modules/hr/types.php');
}

Security::requireCsrf('modules/hr/types.php');

$id = (int)post('id');
$type = Database::fetch('SELECT * FROM hr_staff_types WHERE id = :id', ['id' => $id]);

if ($type === null) {
    flash('error', 'That HR Type could not be found.');
    redirect('modules/hr/types.php');
}

$employeeCount = (int)Database::fetchValue('SELECT COUNT(*) FROM employees WHERE staff_type_id = :id', ['id' => $id]);
if ($employeeCount > 0) {
    flash('error', 'This type is still assigned to ' . $employeeCount . ' employee(s). Reassign them first.');
    redirect('modules/hr/types.php');
}

try {
    Database::execute('DELETE FROM hr_staff_types WHERE id = :id', ['id' => $id]);

    Database::insert('activity_log', [
        'user_id' => Auth::id(), 'module' => 'hr', 'action' => 'type.deleted',
        'description' => 'Deleted HR Type ' . $type['name'],
        'ip_address' => client_ip(),
    ]);
} catch (Throwable $e) {
    flash('error', Database::friendlyError($e));
    redirect('modules/hr/types.php');
}

flash('success', 'HR Type deleted.');
redirect('modules/hr/types.php');
