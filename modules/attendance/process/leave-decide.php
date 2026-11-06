<?php
/** Approve or reject a pending leave request. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = att_return_url('modules/attendance/leave.php');
att_post_guard(['attendance.leave_approve'], $back);

$res = att_leave_decide((int)post('id'), post('decision'), post('note'), att_ctx());
att_back($back, $res['ok'] ? 'success' : 'error', $res['message']);
