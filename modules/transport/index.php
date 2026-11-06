<?php
/**
 * Transport Dashboard — live fleet numbers and interactive charts, filtered by
 * region / district / tehsil / UC / vehicle type / status / driver / date range.
 * The first paint comes from the server (cards work without JavaScript); the
 * charts and the Refresh button use process/dashboard-data.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.dashboard');

$f = trn_read_vehicle_filters($_GET);
$f['q'] = '';
$range = trn_read_range($_GET);
$f['date_from'] = $range['custom'] ? $range['from'] : '';
$f['date_to'] = $range['custom'] ? $range['to'] : '';
$data = trn_dashboard_data($f, $range['from'], $range['to']);
$c = $data['cards'];

$vehiclesUrl = base_url('modules/transport/vehicles.php');
$link = static fn(array $x): string => $vehiclesUrl . trn_vehicle_filter_query($f, $x);
$cardList = [
    ['Total Vehicles', $c['total'], 'truck', '', 'Every vehicle that is not archived', $link([])],
    ['Active Vehicles', $c['active'], 'check-big', '', 'Status is in the "In service" group', $link(['category' => 'active'])],
    ['Assigned Vehicles', $c['assigned'], 'users', 'info', 'Have a current driver', $link(['has_driver' => 'yes'])],
    ['Available Vehicles', $c['available'], 'play', '', 'In service, no driver yet', $link(['category' => 'active', 'has_driver' => 'no'])],
    ['Under Maintenance', $c['maintenance'], 'wrench', 'warn', 'Status is in the maintenance group', $link(['category' => 'maintenance'])],
    ['Out of Service', $c['out_of_service'], 'alert', 'danger', 'Not usable for now', $link(['category' => 'out_of_service'])],
    ['Inactive Vehicles', $c['inactive'], 'ban', 'danger', 'Retired or idle', $link(['category' => 'inactive'])],
    ['Documents Expiring Soon', $c['docs_expiring'], 'calendar', 'warn', $c['docs_expired'] . ' already expired', base_url('modules/transport/documents.php') . trn_report_query($f, ['doc_status' => 'expiring'])],
];

$filterAction = base_url('modules/transport/index.php');
$filterDates = true;
$filterShowSearch = false;
$filterHelp = 'Region to UC match where each vehicle is deployed now. The date range affects maintenance and fuel charts only.';
$jsConfig = [
    'dataUrl' => base_url('modules/transport/process/dashboard-data.php'),
    'vehiclesUrl' => $vehiclesUrl, 'query' => trn_report_query($f), 'currency' => trn_currency(), 'volume' => trn_volume_unit(),
    'canVehicles' => can('transport.view'),
];

$pageTitle = 'Transport Dashboard';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js', 'vendor/chartjs/chart.umd.min.js', 'transport-charts.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
trn_setup_notice(trn_missing_setup());
trn_search_bar();
require __DIR__ . '/partials/vehicle-filter.php';
?>
<div class="toolbar">
    <span class="trn-muted" id="trn-range-note">Maintenance and fuel: <?= e(trn_date($range['from'])) ?> – <?= e(trn_date($range['to'])) ?></span>
    <div class="toolbar__spacer"></div>
    <button type="button" class="btn btn--ghost" id="trn-refresh"><?= icon('refresh') ?><span class="btn__label">Refresh</span></button>
</div>

<div class="grid grid--4" id="trn-cards">
    <?php foreach ($cardList as [$label, $value, $icon, $accent, $meta, $href]): ?>
        <div class="card card--flat stat-card <?= $accent ? 'stat-card--accent-' . e($accent) : '' ?>">
            <span class="stat-card__icon"><?= icon($icon) ?></span>
            <div class="stat__label"><?= e($label) ?></div>
            <div class="stat__value"><?= number_format((int)$value) ?></div>
            <div class="stat__meta"><?= e($meta) ?></div>
            <?php if (can('transport.view') || $icon === 'calendar'): ?><a class="stat-card__link" href="<?= e($href) ?>" aria-label="View <?= e($label) ?>"></a><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid grid--3" style="margin-top:var(--space-4)">
    <div class="card"><h3 class="card__title">Maintenance cost</h3><div class="stat__value trn-money" id="trn-tot-maint"><?= e(trn_money($data['totals']['maintenance_cost'])) ?></div><div class="stat__meta">In the selected period</div></div>
    <div class="card"><h3 class="card__title">Fuel cost</h3><div class="stat__value trn-money" id="trn-tot-fuel"><?= e(trn_money($data['totals']['fuel_cost'])) ?></div><div class="stat__meta">In the selected period</div></div>
    <div class="card"><h3 class="card__title">Fuel consumed</h3><div class="stat__value" id="trn-tot-litres"><?= e(trn_num($data['totals']['fuel_litres'], 1)) ?> <?= e(trn_volume_unit()) ?></div><div class="stat__meta">In the selected period</div></div>
</div>

<div class="grid grid--2" style="margin-top:var(--space-4)">
    <section class="card"><h3 class="card__title">Vehicles by type</h3><div class="trn-chart-box"><canvas id="ch-by_type" aria-label="Vehicles by type"></canvas></div></section>
    <section class="card"><h3 class="card__title">Vehicles by status</h3><div class="trn-chart-box"><canvas id="ch-by_status" aria-label="Vehicles by status"></canvas></div></section>
    <section class="card"><h3 class="card__title">Vehicles by region</h3><div class="trn-chart-box"><canvas id="ch-by_region" aria-label="Vehicles by region"></canvas></div></section>
    <section class="card"><h3 class="card__title">Vehicles by district</h3><div class="trn-chart-box"><canvas id="ch-by_district" aria-label="Vehicles by district"></canvas></div></section>
    <section class="card"><h3 class="card__title">Vehicles by tehsil</h3><div class="trn-chart-box"><canvas id="ch-by_tehsil" aria-label="Vehicles by tehsil"></canvas></div></section>
    <section class="card"><h3 class="card__title">Vehicles by UC</h3><div class="trn-chart-box"><canvas id="ch-by_uc" aria-label="Vehicles by UC"></canvas></div></section>
    <section class="card"><h3 class="card__title">Vehicle deployment</h3><div class="trn-chart-box"><canvas id="ch-deployment" aria-label="Deployed versus not deployed"></canvas></div></section>
    <section class="card"><h3 class="card__title">Driver coverage</h3><div class="trn-chart-box"><canvas id="ch-drivers" aria-label="Vehicles with and without a driver"></canvas></div></section>
    <section class="card"><h3 class="card__title">Maintenance cost by month</h3><div class="trn-chart-box"><canvas id="ch-maintenance_cost" aria-label="Maintenance cost by month"></canvas></div></section>
    <section class="card"><h3 class="card__title">Fuel cost by month</h3><div class="trn-chart-box"><canvas id="ch-fuel_cost" aria-label="Fuel cost by month"></canvas></div></section>
    <section class="card" style="grid-column:1/-1"><h3 class="card__title">Fuel consumption by month (<?= e(trn_volume_unit()) ?>)</h3><div class="trn-chart-box"><canvas id="ch-fuel_litres" aria-label="Fuel consumption by month"></canvas></div></section>
</div>

<section class="card" style="margin-top:var(--space-4)">
    <h3 class="card__title">Documents needing attention</h3>
    <div id="trn-attention">
    <?php if (!$data['attention']): ?><p class="trn-muted" style="margin:0">Nothing is expired or expiring soon.</p><?php else: ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Vehicle</th><th>Document</th><th>Expires</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($data['attention'] as $a): ?><tr>
            <td><?php if (can('transport.details')): ?><a href="<?= e(base_url('modules/transport/vehicle-view.php?id=' . (int)$a['vehicle_id'] . '&tab=documents')) ?>"><?= e((string)$a['registration_no']) ?></a><?php else: ?><?= e((string)$a['registration_no']) ?><?php endif; ?></td>
            <td><?= e((string)$a['type_name']) ?></td><td><?= e(trn_date((string)$a['expiry_date'])) ?></td>
            <td><span class="badge <?= e(trn_doc_status_badge((string)$a['st'])) ?>"><?= e(trn_doc_status_label((string)$a['st'])) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
    </div>
</section>

<script type="application/json" id="trn-data"><?= json_encode($data + ['config' => $jsConfig], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php require INCLUDES_PATH . '/footer.php'; ?>
