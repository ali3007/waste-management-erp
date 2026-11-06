<?php
/**
 * Activates or deactivates an employee. Never a physical delete — an
 * employee's assignment history and any generated documents must stay
 * available for reporting even after they leave.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('employee.delete');

if (!is_post()) {
    redirect('modules/hr/employees.php');
}

$id = (int)post('id');
Security::requireCsrf('modules/hr/employee-view.php?id=' . $id);

$employee = Database::fetch('SELECT * FROM employees WHERE id = :id', ['id' => $id]);
if ($employee === null) {
    flash('error', 'That employee could not be found.');
    redirect('modules/hr/employees.php');
}

hr_require_employee_scope($id);

$newStatus = post('status');
if (!in_array($newStatus, ['active', 'inactive'], true)) {
    flash('error', 'That is not a valid status.');
    redirect('modules/hr/employees.php');
}

try {
    Database::update('employees', ['status' => $newStatus, 'updated_by' => Auth::id()], ['id' => $id]);

    Database::insert('activity_log', [
        'user_id' => Auth::id(), 'module' => 'hr', 'action' => 'employee.status_changed',
        'subject_type' => 'employee', 'subject_id' => $id,
        'description' => $employee['name'] . ' set to ' . $newStatus,
        'ip_address' => client_ip(),
    ]);
} catch (Throwable $e) {
    flash('error', Database::friendlyError($e));
    redirect('modules/hr/employees.php');
}

flash('success', $employee['name'] . ' is now ' . ($newStatus === 'active' ? 'active.' : 'inactive.'));
redirect('modules/hr/employees.php');
