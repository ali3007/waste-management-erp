<?php
/**
 * Driver Assignment — assign, change or remove a vehicle's driver.
 * Drivers are existing HR employees from the pool/type chosen in
 * Transport Configuration. History is never overwritten (see Assignment History).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.driver_view');
$canAssign = can('transport.driver_assign');

$f = trn_read_vehicle_filters($_GET);
[$where, $params] = trn_vehicle_filter_sql($f);
$total = (int)Database::fetchValue('SELECT COUNT(*) ' . trn_vehicle_from() . ' WHERE ' . $where, $params);
$pg = trn_paginate($total, 25);
$rows = Database::fetchAll(trn_vehicle_select() . ' WHERE ' . $where . ' ORDER BY ' . trn_vehicle_order_sql($f) . ' LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $params);

$pre = null;
if ($canAssign && (int)query('vehicle') > 0) {
    $pre = trn_find_vehicle((int)query('vehicle'));
    if ($pre !== null && !trn_vehicle_in_scope((int)$pre['id'])) {
        $pre = null;
    }
}
$here = 'modules/transport/drivers.php' . trn_vehicle_filter_query($f, ['page' => $pg['page'] > 1 ? $pg['page'] : null]);
$filterAction = base_url('modules/transport/drivers.php');
$filterShowDeployment = true;

$pageTitle = 'Driver Assignment';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
if ($canAssign && !trn_driver_rules_configured()): ?>
    <div class="alert alert--warning" role="status"><?= icon('alert') ?><span>No driver rule is set yet, so nobody can be assigned.
        <?php if (can('transport.config')): ?><a href="<?= e(base_url('modules/transport/config.php?tab=settings')) ?>">Choose which HR staff count as drivers</a>.<?php else: ?>Ask an administrator to set it in Transport Configuration.<?php endif; ?></span></div>
<?php endif;
trn_search_bar();

if ($canAssign): ?>
<section class="card" id="assign-form">
    <h3 class="card__title"><?= $pre && $pre['driver_id'] ? 'Change driver' : 'Assign driver' ?></h3>
    <p class="card__subtitle">If the vehicle already has a driver, that assignment is closed on the date below and kept in history.</p>
    <form method="post" action="<?= e(base_url('modules/transport/process/driver-assign.php')) ?>" novalidate>
        <?= csrf_field() ?><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <div class="field-row">
            <?php trn_picker('vehicle', 'vehicle_id', 'Vehicle', $pre ? (int)$pre['id'] : ((int)old('vehicle_id', '') ?: null), $pre ? (string)$pre['registration_no'] : '', true); ?>
            <?php trn_picker('driver', 'employee_id', 'Driver', ((int)old('employee_id', '') ?: null), '', true, 'Active HR employees who match the driver rule.'); ?>
        </div>
        <div class="field-row">
            <?php field_input('da_date', 'Assignment date', ['name' => 'assigned_date', 'type' => 'date', 'value' => old('assigned_date', trn_today()), 'required' => true, 'attrs' => 'max="' . trn_today() . '"']); ?>
            <?php field_input('da_remarks', 'Remarks (optional)', ['name' => 'remarks', 'value' => old('remarks', ''), 'maxlength' => 500]); ?>
        </div>
        <?php if ($pre && $pre['driver_id']): ?><p class="trn-note">Current driver: <strong><?= e((string)$pre['driver_name']) ?></strong> since <?= e(trn_date((string)$pre['driver_since'])) ?>.</p><?php endif; ?>
        <button type="submit" class="btn btn--primary"><span class="btn__label"><?= $pre && $pre['driver_id'] ? 'Change driver' : 'Assign driver' ?></span></button>
    </form>
</section>
<?php clear_old(); endif; ?>

<?php require __DIR__ . '/partials/vehicle-filter.php'; ?>

<?php if (!$rows): ?>
    <div class="card"><div class="empty-state"><?= icon('users') ?><h3 style="margin-bottom:6px">No vehicles to show</h3><p style="margin:0">Add vehicles first, or relax the filters.</p></div></div>
<?php else: ?>
    <div class="table-meta"><span><?= number_format($total) ?> vehicle<?= $total === 1 ? '' : 's' ?></span><span>Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Vehicle</th><th>Status</th><th>Current driver</th><th>Since</th><th>Deployment</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e((string)$r['registration_no']) ?></strong><span class="cell-sub"><?= e((string)$r['type_name']) ?></span></td>
                <td><span class="badge <?= e(trn_status_badge_class((string)$r['status_category'])) ?>"><?= e((string)$r['status_name']) ?></span></td>
                <td class="is-wrap"><?php if ($r['driver_name']): ?><strong><?= e((string)$r['driver_name']) ?></strong><span class="cell-sub"><?= e((string)$r['driver_code']) ?> · <?= e(Security::formatCnic((string)$r['driver_cnic'])) ?></span><?php else: ?><span class="badge badge--pending">No driver</span><?php endif; ?></td>
                <td><?= $r['driver_since'] ? e(trn_date((string)$r['driver_since'])) : '—' ?></td>
                <td class="is-wrap"><?= $r['uc_name'] ? e((string)$r['uc_name']) : '<span class="trn-muted">Not deployed</span>' ?></td>
                <td class="is-wrap"><?php if ($canAssign && $r['deleted_at'] === null): ?>
                    <div class="cell-actions"><a class="btn btn--ghost" href="<?= e(base_url('modules/transport/drivers.php?vehicle=' . (int)$r['id'] . '#assign-form')) ?>"><span class="btn__label"><?= $r['driver_id'] ? 'Change' : 'Assign' ?></span></a></div>
                    <?php if ($r['driver_id']): ?>
                    <details class="trn-more"><summary>Remove driver</summary>
                        <form method="post" action="<?= e(base_url('modules/transport/process/driver-end.php')) ?>" data-confirm="Remove <?= e((string)$r['driver_name']) ?> from <?= e((string)$r['registration_no']) ?>?">
                            <?= csrf_field() ?><input type="hidden" name="vehicle_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <?php field_input('de_date_' . (int)$r['id'], 'End date', ['name' => 'end_date', 'type' => 'date', 'value' => trn_today(), 'attrs' => 'max="' . trn_today() . '"']); ?>
                            <?php field_input('de_reason_' . (int)$r['id'], 'Reason (optional)', ['name' => 'reason', 'maxlength' => 255]); ?>
                            <button type="submit" class="btn btn--primary"><span class="btn__label">Remove driver</span></button>
                        </form></details>
                    <?php endif; ?>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php trn_pagination($pg, static fn(int $p): string => $filterAction . trn_vehicle_filter_query($f, ['page' => $p])); ?>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
