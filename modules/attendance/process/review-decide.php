<?php
/** Record a review decision for one pending case. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = 'modules/attendance/review.php';
att_post_guard(['attendance.review'], $back);

$res = att_review_decide((int)post('id'), post('decision'), post('reason'), att_ctx());
att_back($back, $res['ok'] ? 'success' : 'error', $res['message']);
