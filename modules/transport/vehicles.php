<?php
/** Vehicles — searchable, filterable, sortable, paginated list. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.view');

$f = trn_read_vehicle_filters($_GET);
[$where, $params] = trn_vehicle_filter_sql($f);
$total = (int)Database::fetchValue('SELECT COUNT(*) ' . trn_vehicle_from() . ' WHERE ' . $where, $params);
$pg = trn_paginate($total, 25);
$rows = Database::fetchAll(
    trn_vehicle_select() . ' WHERE ' . $where . ' ORDER BY ' . trn_vehicle_order_sql($f) . ' LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'],
    $params
);

$filterAction = base_url('modules/transport/vehicles.php');
$filterShowOwnership = true;
$filterShowDeployment = true;
$filterShowArchive = true;
$filterShowBeat = true;
$canDetails = can('transport.details');

$sortLink = static function (string $key, string $label) use ($f, $filterAction): string {
    $dir = ($f['sort'] === $key && $f['dir'] === 'asc') ? 'desc' : 'asc';
    $arrow = $f['sort'] === $key ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="' . e($filterAction . trn_vehicle_filter_query($f, ['sort' => $key, 'dir' => $dir, 'page' => null])) . '">' . e($label) . $arrow . '</a>';
};

$pageTitle = 'Vehicles';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
trn_setup_notice(trn_missing_setup());
trn_search_bar();
?>
<div class="toolbar">
    <div class="toolbar__spacer" style="margin-left:0"></div>
    <?php if (can('transport.reports_export')): ?>
        <a class="btn btn--ghost" href="<?= e(base_url('modules/transport/report-export.php') . trn_vehicle_filter_query($f, ['report' => 'vehicles_all', 'format' => 'xlsx'])) ?>"><?= icon('download') ?><span class="btn__label">Export Excel</span></a>
        <a class="btn btn--ghost" href="<?= e(base_url('modules/transport/report-export.php') . trn_vehicle_filter_query($f, ['report' => 'vehicles_all', 'format' => 'csv'])) ?>"><?= icon('download') ?><span class="btn__label">Export CSV</span></a>
    <?php endif; ?>
    <?php if (can('transport.create')): ?>
        <a class="btn btn--primary" href="<?= e(base_url('modules/transport/vehicle-form.php')) ?>"><?= icon('plus') ?><span class="btn__label">Add vehicle</span></a>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/vehicle-filter.php'; ?>

<?php if (!$rows): ?>
    <div class="card"><div class="empty-state"><?= icon('truck') ?>
        <h3 style="margin-bottom:6px"><?= trn_active_vehicle_filters($f) ? 'No vehicles match these filters' : 'No vehicles yet' ?></h3>
        <p style="margin:0"><?= trn_active_vehicle_filters($f) ? 'Try clearing a filter.' : (can('transport.create') ? 'Add the first vehicle using the button above.' : 'Vehicles will appear here once they are added.') ?></p>
    </div></div>
<?php else: ?>
    <div class="table-meta"><span><?= number_format($total) ?> vehicle<?= $total === 1 ? '' : 's' ?> found</span><span>Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr class="trn-sort">
                <th><?= $sortLink('reg', 'Vehicle') ?></th><th><?= $sortLink('type', 'Type') ?></th><th><?= $sortLink('status', 'Status') ?></th>
                <th><?= $sortLink('ownership', 'Ownership') ?></th><th><?= $sortLink('driver', 'Current driver') ?></th><th><?= $sortLink('area', 'Deployment') ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="is-wrap"><strong><?= e((string)$r['registration_no']) ?></strong>
                        <span class="cell-sub"><?= e(trim((string)$r['make'] . ' ' . (string)$r['model'])) ?><?= $r['tracker_no'] ? ' · Tracker ' . e((string)$r['tracker_no']) : '' ?></span></td>
                    <td><?= e((string)$r['type_name']) ?></td>
                    <td><span class="badge <?= e(trn_status_badge_class((string)$r['status_category'])) ?>"><?= e((string)$r['status_name']) ?></span>
                        <?php if ($r['deleted_at'] !== null): ?><span class="badge badge--blocked">Archived</span><?php endif; ?></td>
                    <td><?= e((string)$r['ownership_name']) ?></td>
                    <td class="is-wrap"><?php if ($r['driver_name']): ?><?= e((string)$r['driver_name']) ?><span class="cell-sub"><?= e((string)$r['driver_code']) ?></span><?php else: ?><span class="cell-sub">No driver</span><?php endif; ?></td>
                    <td class="is-wrap"><?php if ($r['uc_name']): ?><?= e((string)$r['uc_name']) ?><?= $r['beat_name'] ? ' / ' . e((string)$r['beat_name']) : '' ?><span class="cell-sub"><?= e((string)$r['tehsil_name']) ?>, <?= e((string)$r['district_name']) ?></span><?php else: ?><span class="badge badge--pending">Not deployed</span><?php endif; ?></td>
                    <td><div class="cell-actions">
                        <?php if ($canDetails): ?><a class="icon-btn" href="<?= e(base_url('modules/transport/vehicle-view.php?id=' . (int)$r['id'])) ?>" title="View details" aria-label="View details"><?= icon('search') ?></a><?php endif; ?>
                        <?php if (can('transport.update') && $r['deleted_at'] === null): ?><a class="icon-btn" href="<?= e(base_url('modules/transport/vehicle-form.php?id=' . (int)$r['id'])) ?>" title="Edit" aria-label="Edit"><?= icon('pencil') ?></a><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php trn_pagination($pg, static fn(int $p): string => $filterAction . trn_vehicle_filter_query($f, ['page' => $p])); ?>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
