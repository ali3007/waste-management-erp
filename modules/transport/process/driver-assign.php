<?php
/** Assign a driver to a vehicle, or change its driver (rules in lib/assignments.php). */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = trn_return_url('modules/transport/drivers.php');
trn_post_guard('transport.driver_assign', $back);

$vehicle = trn_require_vehicle((int)post('vehicle_id'));
/* The vehicle's own state decides: a vehicle with a driver is being changed. */
$mode = $vehicle['driver_id'] !== null ? 'change' : 'assign';
$res = trn_assign_driver($vehicle, (int)post('employee_id'), post('assigned_date') ?: trn_today(),
    Security::cleanMultiline(post('remarks'), 500), $mode);

if (!$res['ok']) {
    trn_back_with_old($back, $res['message']);
}
clear_old();
trn_back($back, 'success', $res['message']);
