<?php
/**
 * Assigns or reassigns an employee to a UC (and optionally a Beat).
 *
 * Behaviour depends entirely on the employee's Staff Type
 * assignment_mode — never trust the page's UI alone for this, since a
 * tampered request could submit for a 'none' type or add a duplicate
 * UC to a 'single' type:
 *   - none:   always rejected here, regardless of what the form sent.
 *   - single: any existing open assignment(s) are closed first, then
 *             the new one opens — reassignment, not addition.
 *   - multi:  the new row is added alongside existing ones; assigning
 *             the same UC twice is rejected as a duplicate, not silently
 *             ignored or duplicated.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/structure/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

if (!is_post()) {
    redirect('modules/hr/employees.php');
}

$employeeId = (int)post('employee_id');
$backTo = 'modules/hr/employee-assign.php?id=' . $employeeId;

$employee = $employeeId > 0 ? hr_find_employee($employeeId) : null;
if ($employee === null) {
    flash('error', 'That employee could not be found.');
    redirect('modules/hr/employees.php');
}

hr_require_employee_scope($employeeId);

$alreadyAssigned = hr_is_assigned($employeeId);

/* This is a reassignment (closes an existing row) only for single mode
   with something already open. Multi mode never closes anything on
   this endpoint — every successful save here adds a row alongside
   whatever already exists — so it's always a "create" action and
   always logged as an addition, never as a reassignment, regardless of
   how many other UCs this employee already holds. */
$isReassignment = (string)$employee['assignment_mode'] === 'single' && $alreadyAssigned;
Permission::require($isReassignment ? 'assignment.update' : 'assignment.create');

Security::requireCsrf($backTo);

if ((string)$employee['status'] !== 'active') {
    flash('error', 'Inactive employees cannot be assigned. Activate ' . $employee['name'] . ' first.');
    redirect('modules/hr/employee-view.php?id=' . $employeeId);
}

if ((string)$employee['assignment_mode'] === 'none') {
    flash('error', 'This employee\'s staff type doesn\'t use UC/Beat assignment.');
    redirect('modules/hr/employee-view.php?id=' . $employeeId);
}

$ucId = (int)post('uc_id');
$beatId = post('beat_id') !== '' ? (int)post('beat_id') : null;

$uc = Database::fetch("SELECT * FROM ucs WHERE id = :id AND status = 'active'", ['id' => $ucId]);
if ($uc === null) {
    flash('error', 'Invalid administrative hierarchy — choose a valid, active UC.');
    redirect($backTo);
}
if (!hr_can_access_uc($ucId)) {
    flash('error', 'Unauthorized action — that UC is outside your area.');
    redirect($backTo);
}

if ($beatId !== null) {
    if ((string)$employee['assignment_mode'] !== 'single') {
        $beatId = null; // multi-mode never takes a Beat, regardless of what was posted
    } elseif (!Database::exists("SELECT id FROM beats WHERE id = :id AND uc_id = :uc AND status = 'active'", ['id' => $beatId, 'uc' => $ucId])) {
        flash('error', 'That Beat doesn\'t belong to the chosen UC.');
        redirect($backTo);
    }
}

try {
    Database::beginTransaction();

    if ((string)$employee['assignment_mode'] === 'multi') {
        if (Database::exists(
            'SELECT id FROM employee_assignments WHERE employee_id = :e AND uc_id = :u AND unassigned_at IS NULL',
            ['e' => $employeeId, 'u' => $ucId]
        )) {
            Database::rollBack();
            flash('error', $employee['name'] . ' is already assigned to ' . $uc['name'] . '.');
            redirect($backTo);
        }
    } else {
        /* single mode: close every currently-open row (normally at most
           one) before opening the new one — this is what makes the
           second submission a reassignment rather than an addition. */
        Database::execute(
            'UPDATE employee_assignments SET unassigned_at = NOW(), unassigned_by = :by
              WHERE employee_id = :e AND unassigned_at IS NULL',
            ['by' => Auth::id(), 'e' => $employeeId]
        );
    }

    Database::insert('employee_assignments', [
        'employee_id' => $employeeId, 'uc_id' => $ucId, 'beat_id' => $beatId,
        'assigned_by' => Auth::id(),
    ]);

    Database::insert('activity_log', [
        'user_id' => Auth::id(), 'module' => 'hr',
        'action' => $isReassignment ? 'employee.reassigned' : 'employee.assigned',
        'subject_type' => 'employee', 'subject_id' => $employeeId,
        'description' => $employee['name'] . ' assigned to ' . $uc['name'] . ($beatId ? ' (Beat included)' : ''),
        'ip_address' => client_ip(),
    ]);

    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    flash('error', Database::friendlyError($e));
    redirect($backTo);
}

flash('success', $isReassignment ? 'Employee reassigned successfully to ' . $uc['name'] . '.' : 'Employee assigned successfully to ' . $uc['name'] . '.');
redirect('modules/hr/employee-assign.php?id=' . $employeeId);
