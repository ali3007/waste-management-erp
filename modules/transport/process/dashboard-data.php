<?php
/** AJAX: live dashboard numbers for the current filters (JSON). */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    json_response(['ok' => false, 'message' => 'Not installed.'], 503);
}
Permission::require('transport.dashboard');

$f = trn_read_vehicle_filters($_GET);
$range = trn_read_range($_GET);
json_response(['ok' => true] + trn_dashboard_data($f, $range['from'], $range['to']));
