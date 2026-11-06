<?php
/** Deploy a vehicle to Region → District → Tehsil → UC (→ Beat), or change its deployment. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = trn_return_url('modules/transport/deployments.php');
trn_post_guard('transport.deployment_manage', $back);

$vehicle = trn_require_vehicle((int)post('vehicle_id'));
[$r, $d, $t, $u, $b] = trn_post_geography();
$geo = trn_resolve_geography($r, $d, $t, $u, $b);
if ($geo === null) {
    trn_back_with_old($back, 'Invalid administrative hierarchy — choose a valid Region, District, Tehsil and UC (Beat is optional).');
}
$mode = $vehicle['deployment_id'] !== null ? 'change' : 'assign';
$res = trn_deploy_vehicle($vehicle, $geo, post('start_date') ?: trn_today(), Security::cleanMultiline(post('remarks'), 500), $mode);
if (!$res['ok']) {
    trn_back_with_old($back, $res['message']);
}
clear_old();
trn_back($back, 'success', $res['message']);
