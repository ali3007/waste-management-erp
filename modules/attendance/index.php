<?php
/** Attendance landing: send each user to the first screen they are allowed to open. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/nav.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Auth::requireLogin();
$items = att_nav_items();
if (!$items) {
    Permission::require('attendance.access');
}
redirect('modules/' . $items[0]['script']);
