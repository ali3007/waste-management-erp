<?php
/**
 * Closes one specific assignment row. Never deletes it — assignment
 * history stays queryable for reporting even after removal.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('assignment.update');

if (!is_post()) {
    redirect('modules/hr/assigned.php');
}

$assignmentId = (int)post('assignment_id');
$employeeId = (int)post('employee_id'); // present when called from employee-assign.php; absent from assigned.php's list
$backTo = $employeeId > 0 ? 'modules/hr/employee-assign.php?id=' . $employeeId : 'modules/hr/assigned.php';

Security::requireCsrf($backTo);

$assignment = Database::fetch(
    'SELECT a.*, e.name AS employee_name, u.name AS uc_name
       FROM employee_assignments a JOIN employees e ON e.id = a.employee_id JOIN ucs u ON u.id = a.uc_id
      WHERE a.id = :id AND a.unassigned_at IS NULL',
    ['id' => $assignmentId]
);

if ($assignment === null) {
    flash('error', 'That assignment could not be found, or was already removed.');
    redirect($backTo);
}

hr_require_employee_scope((int)$assignment['employee_id']);

try {
    Database::update('employee_assignments', [
        'unassigned_at' => date('Y-m-d H:i:s'), 'unassigned_by' => Auth::id(),
    ], ['id' => $assignmentId]);

    Database::insert('activity_log', [
        'user_id' => Auth::id(), 'module' => 'hr', 'action' => 'employee.unassigned',
        'subject_type' => 'employee', 'subject_id' => (int)$assignment['employee_id'],
        'description' => $assignment['employee_name'] . ' removed from ' . $assignment['uc_name'],
        'ip_address' => client_ip(),
    ]);
} catch (Throwable $e) {
    flash('error', Database::friendlyError($e));
    redirect($backTo);
}

flash('success', $assignment['employee_name'] . ' removed from ' . $assignment['uc_name'] . '.' . (hr_is_assigned((int)$assignment['employee_id']) ? '' : ' Employee moved to Unassigned list.'));
redirect($backTo);
