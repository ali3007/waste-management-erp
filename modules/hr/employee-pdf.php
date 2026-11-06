<?php
/**
 * Download the Employee Profile PDF (spec section 17).
 * Needs employee.pdf AND employee.view, and the employee must be inside
 * the user's area scope. Document INFORMATION is included only for users
 * who may view documents.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/pdf.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('employee.pdf');
Permission::require('employee.view');

$id = (int)query('id');
if ($id <= 0 || hr_find_employee($id) === null) {
    flash('error', 'Record not found.');
    redirect('modules/hr/employees.php');
}
hr_require_employee_scope($id);

try {
    hr_log('employee.pdf_generated', $id, 'Employee profile PDF generated');
    hr_generate_profile_pdf($id, can('employee.doc_view'));
} catch (Throwable $e) {
    log_event('hr', 'Profile PDF failed', ['employee_id' => $id, 'error' => $e->getMessage()]);
    flash('error', 'The PDF could not be generated. ' . (APP_ENV === 'development' ? $e->getMessage() : 'Check the log for details.'));
    redirect('modules/hr/employee-view.php?id=' . $id);
}
