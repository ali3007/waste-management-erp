<?php
/** Withdraw a vehicle from its current deployment. The deployment stays in history. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = trn_return_url('modules/transport/deployments.php');
trn_post_guard('transport.deployment_manage', $back);

$vehicle = trn_require_vehicle((int)post('vehicle_id'));
$res = trn_end_deployment($vehicle, post('end_date') ?: trn_today(), Security::cleanText(post('reason'), 255));
trn_back($back, $res['ok'] ? 'success' : 'error', $res['message']);
