<?php
/**
 * Download the Employment Letter (spec section 18).
 * Only for active employees; always generated from the latest database
 * values, so a letter made after an edit reflects the edit.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/pdf.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('employee.letter');
Permission::require('employee.view');

$id = (int)query('id');
$employee = $id > 0 ? hr_find_employee($id) : null;
if ($employee === null) {
    flash('error', 'Record not found.');
    redirect('modules/hr/employees.php');
}
hr_require_employee_scope($id);

if ($employee['status'] !== 'active') {
    flash('error', 'An employment letter can only be generated for an active employee.');
    redirect('modules/hr/employee-view.php?id=' . $id);
}

try {
    $user = Auth::user();
    hr_log('employee.letter_generated', $id, 'Employment letter generated');
    hr_generate_employment_letter($id, (string)($user['name'] ?? ''));
} catch (Throwable $e) {
    log_event('hr', 'Employment letter failed', ['employee_id' => $id, 'error' => $e->getMessage()]);
    flash('error', 'The letter could not be generated. ' . (APP_ENV === 'development' ? $e->getMessage() : 'Check the log for details.'));
    redirect('modules/hr/employee-view.php?id=' . $id);
}
