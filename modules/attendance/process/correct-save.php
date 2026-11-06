<?php
/** Apply an Attendance Edit correction. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = 'modules/attendance/corrections.php';
att_post_guard(['attendance.edit'], $back);

$employeeId = (int)post('employee_id');
$date = post('date');
$res = att_correct($employeeId, $date, ['status' => post('status'), 'checkin' => post('checkin'), 'checkout' => post('checkout'), 'reason' => post('reason')], att_ctx());
att_back($back . att_qs(['employee' => $employeeId, 'date' => $date]), $res['ok'] ? 'success' : 'error', $res['message']);
