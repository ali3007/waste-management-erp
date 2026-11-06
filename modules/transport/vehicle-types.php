<?php
/** Vehicle Type Management — fully database-driven, soft-deactivate only. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.types_view');

$kind = 'vehicle_type';
$pageUrl = 'modules/transport/vehicle-types.php';
$canManage = can('transport.types_manage');

$pageTitle = 'Vehicle Types';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
require __DIR__ . '/partials/lookup-manager.php';
require INCLUDES_PATH . '/footer.php';
