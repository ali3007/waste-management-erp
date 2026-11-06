<?php
/**
 * Transport navigation — one definition used by the sidebar and by the
 * module's landing redirect. Only items the signed-in user may open appear.
 * Plain functions, no database access, safe to include from the sidebar.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** @return array<int,array{label:string,href:string,script:string,permission:string}> */
function trn_nav_items(): array
{
    $defs = [
        ['Transport Dashboard', 'index.php',        'transport.dashboard'],
        ['Vehicles',            'vehicles.php',     'transport.view'],
        ['Vehicle Types',       'vehicle-types.php', 'transport.types_view'],
        ['Vehicle Documents',   'documents.php',    'transport.documents_view'],
        ['Driver Assignment',   'drivers.php',      'transport.driver_view'],
        ['Vehicle Deployment',  'deployments.php',  'transport.deployment_view'],
        ['Maintenance',         'maintenance.php',  'transport.maintenance_view'],
        ['Fuel Management',     'fuel.php',         'transport.fuel_view'],
        ['Assignment History',  'history.php',      'transport.history'],
        ['Transport Reports',   'reports.php',      'transport.reports'],
        ['Audit Trail',         'audit.php',        'transport.audit'],
        ['Configuration',       'config.php',       'transport.config'],
    ];
    $items = [];
    foreach ($defs as [$label, $file, $perm]) {
        if (can($perm)) {
            $items[] = ['label' => $label, 'href' => base_url('modules/transport/' . $file), 'script' => 'transport/' . $file, 'permission' => $perm];
        }
    }
    return $items;
}
