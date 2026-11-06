<?php
/** Global Transport search — vehicle, tracker, RFID, chassis, engine, driver name or CNIC (current or past). */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.view');

$q = Security::cleanText(query('q'), 120);
$rows = [];
if ($q !== '') {
    $f = trn_read_vehicle_filters(['q' => $q, 'archived' => 'include']);
    [$where, $params] = trn_vehicle_filter_sql($f);

    /* Also match PAST drivers: any vehicle this person ever held. */
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $digits = Security::normalizeCnic($q);
    $past = 'v.id IN (SELECT a.vehicle_id FROM vehicle_driver_assignments a JOIN employees pe ON pe.id = a.employee_id WHERE pe.name LIKE :pq' . ($digits !== '' ? ' OR pe.cnic LIKE :pc' : '') . ')';
    $params['pq'] = $like;
    if ($digits !== '') { $params['pc'] = '%' . $digits . '%'; }
    $scope = trn_scope_sql('v');
    $pastWhere = '(v.deleted_at IS NULL OR 1=1) AND ' . $past . ($scope !== '' ? ' AND ' . $scope : '');

    $rows = Database::fetchAll(
        trn_vehicle_select() . " WHERE ({$where}) OR ({$pastWhere}) ORDER BY v.deleted_at IS NOT NULL ASC, v.registration_no ASC LIMIT 100",
        $params
    );
}

$pageTitle = 'Search Transport';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
trn_search_bar($q);
if ($q === ''): ?>
    <div class="card"><div class="empty-state"><?= icon('search') ?><h3 style="margin-bottom:6px">Search the fleet</h3><p style="margin:0">Enter a vehicle number, tracker, RFID, chassis, engine number, or a driver's name or CNIC.</p></div></div>
<?php elseif (!$rows): ?>
    <div class="card"><div class="empty-state"><?= icon('search') ?><h3 style="margin-bottom:6px">No results for “<?= e($q) ?>”</h3><p style="margin:0">Check the spelling, or try part of the number.</p></div></div>
<?php else: ?>
    <div class="table-meta"><span><?= count($rows) ?> vehicle<?= count($rows) === 1 ? '' : 's' ?> found<?= count($rows) === 100 ? ' (showing the first 100)' : '' ?></span></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Vehicle</th><th>Type</th><th>Status</th><th>Current driver</th><th>Deployment</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
        <tr><td><strong><?= e((string)$r['registration_no']) ?></strong><span class="cell-sub"><?= $r['tracker_no'] ? 'Tracker ' . e((string)$r['tracker_no']) : '' ?><?= $r['rfid_no'] ? ' · RFID ' . e((string)$r['rfid_no']) : '' ?></span></td>
            <td><?= e((string)$r['type_name']) ?></td>
            <td><span class="badge <?= e(trn_status_badge_class((string)$r['status_category'])) ?>"><?= e((string)$r['status_name']) ?></span><?= $r['deleted_at'] ? ' <span class="badge badge--blocked">Archived</span>' : '' ?></td>
            <td><?= e((string)($r['driver_name'] ?: '—')) ?></td><td class="is-wrap"><?= $r['uc_name'] ? e((string)$r['uc_name']) : '—' ?></td>
            <td><?php if (can('transport.details')): ?><a class="icon-btn" href="<?= e(base_url('modules/transport/vehicle-view.php?id=' . (int)$r['id'])) ?>" title="View details" aria-label="View details"><?= icon('search') ?></a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
