<?php
/** Remove a (not yet past) holiday. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = att_return_url('modules/attendance/holidays.php');
att_post_guard(['attendance.holidays_manage'], $back);

$res = att_holiday_delete((int)post('id'), att_ctx());
att_back($back, $res['ok'] ? 'success' : 'error', $res['message']);
