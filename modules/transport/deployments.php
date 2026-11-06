<?php
/**
 * Vehicle Deployment — deploy a vehicle to Region → District → Tehsil → UC
 * (→ Beat), change it, or withdraw it. Every deployment is kept as history.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.deployment_view');
$canManage = can('transport.deployment_manage');

$f = trn_read_vehicle_filters($_GET);
[$where, $params] = trn_vehicle_filter_sql($f);
$total = (int)Database::fetchValue('SELECT COUNT(*) ' . trn_vehicle_from() . ' WHERE ' . $where, $params);
$pg = trn_paginate($total, 25);
$rows = Database::fetchAll(trn_vehicle_select() . ' WHERE ' . $where . ' ORDER BY ' . trn_vehicle_order_sql($f) . ' LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $params);

$pre = null;
if ($canManage && (int)query('vehicle') > 0) {
    $pre = trn_find_vehicle((int)query('vehicle'));
    if ($pre !== null && !trn_vehicle_in_scope((int)$pre['id'])) {
        $pre = null;
    }
}
$here = 'modules/transport/deployments.php' . trn_vehicle_filter_query($f, ['page' => $pg['page'] > 1 ? $pg['page'] : null]);
$filterAction = base_url('modules/transport/deployments.php');
$filterShowDeployment = true;
$filterShowBeat = true;
$geoSel = ['region_id' => old('region_id', ''), 'district_id' => old('district_id', ''), 'tehsil_id' => old('tehsil_id', ''), 'uc_id' => old('uc_id', ''), 'beat_id' => old('beat_id', '')];

$pageTitle = 'Vehicle Deployment';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
trn_search_bar();

if ($canManage): ?>
<section class="card" id="deploy-form">
    <h3 class="card__title"><?= $pre && $pre['deployment_id'] ? 'Change deployment' : 'Deploy vehicle' ?></h3>
    <p class="card__subtitle">If the vehicle is already deployed, that deployment is closed on the start date below and kept in history. <?= trn_is_scoped() ? 'You can deploy only to UCs inside your own area.' : '' ?></p>
    <form method="post" action="<?= e(base_url('modules/transport/process/deployment-save.php')) ?>" novalidate>
        <?= csrf_field() ?><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <?php trn_picker('vehicle', 'vehicle_id', 'Vehicle', $pre ? (int)$pre['id'] : ((int)old('vehicle_id', '') ?: null), $pre ? (string)$pre['registration_no'] : '', true); ?>
        <?php trn_geo_fields($geoSel, true); ?>
        <div class="field-row">
            <?php field_input('dp_date', 'Start date', ['name' => 'start_date', 'type' => 'date', 'value' => old('start_date', trn_today()), 'required' => true, 'attrs' => 'max="' . trn_today() . '"']); ?>
            <?php field_input('dp_remarks', 'Remarks (optional)', ['name' => 'remarks', 'value' => old('remarks', ''), 'maxlength' => 500]); ?>
        </div>
        <?php if ($pre && $pre['deployment_id']): ?><p class="trn-note">Currently deployed to <strong><?= e(trn_area_text($pre)) ?></strong> since <?= e(trn_date((string)$pre['deployed_since'])) ?>.</p><?php endif; ?>
        <button type="submit" class="btn btn--primary"><span class="btn__label"><?= $pre && $pre['deployment_id'] ? 'Change deployment' : 'Deploy vehicle' ?></span></button>
    </form>
</section>
<?php clear_old(); endif; ?>

<?php require __DIR__ . '/partials/vehicle-filter.php'; ?>

<?php if (!$rows): ?>
    <div class="card"><div class="empty-state"><?= icon('calendar') ?><h3 style="margin-bottom:6px">No vehicles to show</h3><p style="margin:0">Add vehicles first, or relax the filters.</p></div></div>
<?php else: ?>
    <div class="table-meta"><span><?= number_format($total) ?> vehicle<?= $total === 1 ? '' : 's' ?></span><span>Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Vehicle</th><th>Status</th><th>Current deployment</th><th>Since</th><th>Driver</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e((string)$r['registration_no']) ?></strong><span class="cell-sub"><?= e((string)$r['type_name']) ?></span></td>
                <td><span class="badge <?= e(trn_status_badge_class((string)$r['status_category'])) ?>"><?= e((string)$r['status_name']) ?></span></td>
                <td class="is-wrap"><?php if ($r['uc_name']): ?><strong><?= e((string)$r['uc_name']) ?><?= $r['beat_name'] ? ' / ' . e((string)$r['beat_name']) : '' ?></strong><span class="cell-sub"><?= e((string)$r['tehsil_name']) ?>, <?= e((string)$r['district_name']) ?>, <?= e((string)$r['region_name']) ?></span><?php else: ?><span class="badge badge--pending">Not deployed</span><?php endif; ?></td>
                <td><?= $r['deployed_since'] ? e(trn_date((string)$r['deployed_since'])) : '—' ?></td>
                <td><?= e((string)($r['driver_name'] ?: '—')) ?></td>
                <td class="is-wrap"><?php if ($canManage && $r['deleted_at'] === null): ?>
                    <div class="cell-actions"><a class="btn btn--ghost" href="<?= e(base_url('modules/transport/deployments.php?vehicle=' . (int)$r['id'] . '#deploy-form')) ?>"><span class="btn__label"><?= $r['deployment_id'] ? 'Change' : 'Deploy' ?></span></a></div>
                    <?php if ($r['deployment_id']): ?>
                    <details class="trn-more"><summary>Withdraw</summary>
                        <form method="post" action="<?= e(base_url('modules/transport/process/deployment-end.php')) ?>" data-confirm="Withdraw <?= e((string)$r['registration_no']) ?> from <?= e((string)$r['uc_name']) ?>?">
                            <?= csrf_field() ?><input type="hidden" name="vehicle_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <?php field_input('we_date_' . (int)$r['id'], 'End date', ['name' => 'end_date', 'type' => 'date', 'value' => trn_today(), 'attrs' => 'max="' . trn_today() . '"']); ?>
                            <?php field_input('we_reason_' . (int)$r['id'], 'Reason (optional)', ['name' => 'reason', 'maxlength' => 255]); ?>
                            <button type="submit" class="btn btn--primary"><span class="btn__label">Withdraw vehicle</span></button>
                        </form></details>
                    <?php endif; ?>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php trn_pagination($pg, static fn(int $p): string => $filterAction . trn_vehicle_filter_query($f, ['page' => $p])); ?>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
