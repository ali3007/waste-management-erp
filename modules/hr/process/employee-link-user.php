<?php
/**
 * Link (or unlink) a login account to an employee — employees.user_id.
 *
 * This is what lets a signed-in user be recognised as that employee: Office
 * Staff then mark their own attendance, and a Supervisor's UC scope comes from
 * their assignment. One account can belong to only one employee (database
 * unique key). Needs the same permission and area scope as editing the employee.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

$id = (int)post('employee_id');
$back = 'modules/hr/employee-edit.php?id=' . $id;
if (!is_post()) {
    redirect($back);
}
Permission::require('employee.update');
Security::requireCsrf($back);

$employee = $id > 0 ? hr_find_employee($id) : null;
if ($employee === null) {
    flash('error', 'That employee could not be found.');
    redirect('modules/hr/employees.php');
}
hr_require_employee_scope($id);

$userId = (int)post('user_id');
$current = $employee['user_id'] !== null ? (int)$employee['user_id'] : 0;

if ($userId === 0) {                                   // unlink
    if ($current === 0) {
        flash('info', 'This employee has no login account linked.');
        redirect($back);
    }
    Database::update('employees', ['user_id' => null, 'updated_by' => Auth::id()], ['id' => $id]);
    hr_log('employee.user_unlinked', $id, 'Login account unlinked from ' . $employee['name']);
    flash('success', 'Login account unlinked. That user can no longer be recognised as this employee.');
    redirect($back);
}

if ($userId === $current) {
    flash('info', 'That account is already linked to this employee.');
    redirect($back);
}

$user = Database::fetch('SELECT id, name, email, status FROM users WHERE id = :id', ['id' => $userId]);
if ($user === null) {
    flash('error', 'That user account could not be found.');
    redirect($back);
}
if (!in_array($user['status'], ['active', 'pending'], true)) {
    flash('error', 'Only an Active or Pending account can be linked. This one is ' . $user['status'] . '.');
    redirect($back);
}
$other = Database::fetch('SELECT id, name FROM employees WHERE user_id = :u AND id <> :e', ['u' => $userId, 'e' => $id]);
if ($other !== null) {
    flash('error', 'That account is already linked to ' . $other['name'] . '. Unlink it there first.');
    redirect($back);
}

try {
    Database::update('employees', ['user_id' => $userId, 'updated_by' => Auth::id()], ['id' => $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {                    // lost a race with another admin
        flash('error', 'That account was just linked to another employee.');
        redirect($back);
    }
    throw $e;
}
hr_log('employee.user_linked', $id, 'Login account ' . $user['email'] . ' linked to ' . $employee['name']);
flash('success', 'Linked. ' . $user['name'] . ' is now recognised as ' . $employee['name'] . ' — they should sign out and back in.');
redirect($back);
