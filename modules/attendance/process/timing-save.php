<?php
/**
 * Save a new timing version for ONE scope: the company default, a staff category, or one
 * category inside one UC. Authorisation is checked here, server-side, for exactly that scope.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = 'modules/attendance/timing.php';
if (!IS_INSTALLED) {
    redirect('install/index.php');
}
if (!is_post()) {
    redirect($back);
}
Permission::require('attendance.access');
Security::requireCsrf($back);

$ucId = (int)post('uc_id') ?: null;
$poolId = (int)post('pool_id') ?: null;
$inherit = post('inherit') === '1';
$returnQs = ($poolId ? '?pool=' . $poolId . ($ucId ? '&uc=' . $ucId : '') : '');

/* Company default and legacy UC-wide scopes need the global permission; a category (or one UC of it)
   needs the global permission or that category's own. */
if ($poolId === null ? !can('attendance.timing_manage') : !att_can_manage_timing($poolId)) {
    att_audit_safe('timing_blocked', 'Timing change refused: no permission for this scope', [
        'subject_type' => 'timing', 'employee_id' => null, 'uc_id' => $ucId, 'outcome' => 'blocked', 'reason' => 'not_authorized',
        'new' => ['pool_id' => $poolId, 'uc_id' => $ucId],
    ]);
    att_deny('You do not have permission to manage this timing.');
}

[$ok, $message] = att_save_timing(
    $ucId,
    ['checkin_start' => post('checkin_start'), 'checkin_end' => post('checkin_end'), 'checkout_start' => post('checkout_start'), 'checkout_end' => post('checkout_end')],
    post('effective_from') !== '' ? post('effective_from') : null,
    $inherit,
    $poolId
);
att_back($back . $returnQs, $ok ? 'success' : 'error', $message);
