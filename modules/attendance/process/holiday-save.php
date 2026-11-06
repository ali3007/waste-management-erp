<?php
/** Add or edit a holiday. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = att_return_url('modules/attendance/holidays.php');
att_post_guard(['attendance.holidays_manage'], $back);

$id = (int)post('id');
$res = att_holiday_save($id > 0 ? $id : null, post('holiday_date'), post('title'), post('description'), att_ctx());
att_back($back, $res['ok'] ? 'success' : 'error', $res['message']);
