<?php
/** Submit a leave request (own, or on behalf of an assigned employee). */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = att_return_url('modules/attendance/leave.php');
if (!IS_INSTALLED || !is_post()) {
    redirect($back);
}
att_require_any(['attendance.leave_submit_self', 'attendance.leave_submit_proxy']);
Security::requireCsrf($back);

$res = att_leave_submit((int)post('employee_id'), post('start_date'), post('end_date'), post('reason'), att_ctx());
att_back($back, $res['ok'] ? 'success' : 'error', $res['message']);
