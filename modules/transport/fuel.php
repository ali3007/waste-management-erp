<?php
/** Fuel records — list, filter, add, edit, soft-delete, receipt upload; total cost is calculated server-side. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.fuel_view');
$canManage = can('transport.fuel_manage');

$f = trn_read_vehicle_filters($_GET);
$f['date_from'] = trn_valid_date(query('date_from')) ?? '';
$f['date_to'] = trn_valid_date(query('date_to')) ?? '';
$ft = max(0, (int)query('ftype'));
$pv = (int)query('vehicle');

/* On this page the Driver filter means the driver who fuelled, so it is applied
   to the fuel record, not to the vehicle's current driver. */
$fv = $f;
$fv['driver'] = 0;
[$vw, $vp] = trn_vehicle_filter_sql($fv);
$where = 'r.deleted_at IS NULL AND r.vehicle_id IN (SELECT v.id ' . trn_vehicle_from() . " WHERE {$vw})";
$params = $vp;
if ($ft > 0) { $where .= ' AND r.fuel_type_id = :ft'; $params['ft'] = $ft; }
if ($pv > 0) { $where .= ' AND r.vehicle_id = :pv'; $params['pv'] = $pv; }
if ($f['date_from'] !== '') { $where .= ' AND r.fuel_date >= :df'; $params['df'] = $f['date_from']; }
if ($f['date_to'] !== '') { $where .= ' AND r.fuel_date <= :dt'; $params['dt'] = $f['date_to']; }
if ($f['driver'] > 0) { $where .= ' AND r.driver_id = :drv'; $params['drv'] = $f['driver']; }

$base = "FROM vehicle_fuel_records r JOIN vehicles v ON v.id = r.vehicle_id JOIN fuel_types t ON t.id = r.fuel_type_id LEFT JOIN employees e ON e.id = r.driver_id WHERE {$where}";
$agg = Database::fetch("SELECT COUNT(*) AS n, COALESCE(SUM(r.quantity),0) AS qty, COALESCE(SUM(r.total_cost),0) AS cost {$base}", $params);
$pg = trn_paginate((int)$agg['n'], 25);
$rows = Database::fetchAll("SELECT r.*, t.name AS type_name, v.registration_no, e.name AS driver_name {$base} ORDER BY r.fuel_date DESC, r.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);

$editId = $canManage ? (int)query('edit') : 0;
$edit = $editId > 0 ? Database::fetch('SELECT r.*, v.registration_no, e.name AS driver_name FROM vehicle_fuel_records r JOIN vehicles v ON v.id = r.vehicle_id LEFT JOIN employees e ON e.id = r.driver_id WHERE r.id = :id AND r.deleted_at IS NULL', ['id' => $editId]) : null;
if ($edit !== null && !trn_vehicle_in_scope((int)$edit['vehicle_id'])) { $edit = null; }
$pre = null;
if ($canManage && !$edit && $pv > 0 && ($pre = trn_find_vehicle($pv)) !== null && !trn_vehicle_in_scope($pv)) { $pre = null; }
$formOpen = $edit !== null || query('new') === '1' || old('quantity', '') !== '';
$val = static fn(string $k, string $d = ''): string => old($k, (string)($edit[$k] ?? $d));

$qs = static fn(array $x = []): string => trn_report_query($f, $x + ['ftype' => $ft, 'vehicle' => $pv]);
$here = 'modules/transport/fuel.php' . $qs(['page' => $pg['page'] > 1 ? $pg['page'] : null]);
$filterAction = base_url('modules/transport/fuel.php');
$filterDates = true;
$filterKeep = array_filter(['vehicle' => $pv]);
$filterHelp = 'The Driver filter here means the driver who fuelled. Region to Beat match where the vehicle is deployed now.';

$pageTitle = 'Fuel Management';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
if ($canManage && !Database::exists("SELECT id FROM fuel_types WHERE status='active' LIMIT 1")) { trn_setup_notice(['fuel_type' => 'fuel types']); }
trn_search_bar();

if ($canManage): ?>
<details class="card trn-more" <?= $formOpen ? 'open' : '' ?>>
    <summary><?= $edit ? 'Edit fuel record' : 'Add fuel record' ?></summary>
    <form method="post" action="<?= e(base_url('modules/transport/process/fuel-save.php')) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <div class="field-row">
            <?php if ($edit): ?>
                <div class="field"><span class="field__label">Vehicle</span><div class="control"><input class="input" value="<?= e((string)$edit['registration_no']) ?>" disabled></div></div>
            <?php else: trn_picker('vehicle', 'vehicle_id', 'Vehicle', $pre ? (int)$pre['id'] : ((int)old('vehicle_id', '') ?: null), $pre ? (string)$pre['registration_no'] : '', true); endif; ?>
            <?php trn_picker('driver', 'driver_id', 'Driver (optional)', $edit && $edit['driver_id'] ? (int)$edit['driver_id'] : null, (string)($edit['driver_name'] ?? ''), false, 'Left empty, the vehicle\'s driver on that date is used.'); ?>
        </div>
        <div class="field-row">
            <?php field_input('f_date', 'Fuel date', ['name' => 'fuel_date', 'type' => 'date', 'value' => $val('fuel_date', trn_today()), 'required' => true, 'attrs' => 'max="' . trn_today() . '"']); ?>
            <?php trn_field_select('f_type', 'Fuel type', trn_options('fuel_type', (int)($edit['fuel_type_id'] ?? 0)), $val('fuel_type_id'), 'Choose a fuel…', ['name' => 'fuel_type_id', 'required' => true]); ?>
        </div>
        <div class="field-row">
            <?php field_input('f_qty', 'Quantity (' . trn_volume_unit() . ')', ['name' => 'quantity', 'value' => $val('quantity'), 'required' => true, 'inputmode' => 'decimal', 'attrs' => 'data-qty']); ?>
            <?php field_input('f_rate', 'Rate per ' . trn_volume_unit() . (trn_currency() !== '' ? ' (' . trn_currency() . ')' : ''), ['name' => 'rate', 'value' => $val('rate'), 'required' => true, 'inputmode' => 'decimal', 'attrs' => 'data-rate']); ?>
        </div>
        <div class="field-row">
            <div class="field"><label class="field__label" for="f_total">Total cost (calculated)</label><div class="control"><input class="input" id="f_total" type="text" readonly tabindex="-1" data-total value="<?= $edit ? e(number_format((float)$edit['total_cost'], 2)) : '' ?>"></div>
                <p class="field__hint">Always recalculated by the server as quantity × rate.</p></div>
            <?php field_input('f_odo', 'Odometer (' . trn_distance_unit() . ')', ['name' => 'odometer', 'value' => $val('odometer'), 'inputmode' => 'decimal', 'hint' => 'Needed to measure fuel efficiency.']); ?>
        </div>
        <label class="checkbox"><input type="checkbox" name="is_full_tank" value="1" <?= (!$edit && old('quantity', '') === '') || ($edit ? (int)$edit['is_full_tank'] === 1 : old('is_full_tank', '') === '1') ? 'checked' : '' ?>><span class="checkbox__box"></span><span>Tank filled to full (used to measure efficiency honestly)</span></label>
        <div class="field-row">
            <?php field_input('f_station', 'Fuel station (optional)', ['name' => 'station', 'value' => $val('station'), 'maxlength' => 150]); ?>
            <div class="field"><label class="field__label" for="f_receipt">Receipt (optional)</label>
                <div class="control"><input class="input" type="file" id="f_receipt" name="receipt" accept="application/pdf,image/jpeg,image/png,image/gif,image/webp"></div>
                <?php if ($edit && $edit['receipt_file']): ?><label class="checkbox"><input type="checkbox" name="remove_receipt" value="1"><span class="checkbox__box"></span><span>Remove current: <?= e((string)$edit['receipt_original']) ?></span></label><?php endif; ?></div>
        </div>
        <?php field_input('f_remarks', 'Remarks (optional)', ['name' => 'remarks', 'value' => $val('remarks'), 'maxlength' => 500]); ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button type="submit" class="btn btn--primary"><span class="btn__label"><?= $edit ? 'Save changes' : 'Add record' ?></span></button>
            <?php if ($edit): ?><a class="btn btn--ghost" href="<?= e(base_url($here)) ?>"><span class="btn__label">Cancel</span></a><?php endif; ?></div>
    </form>
</details>
<?php clear_old(); endif; ?>

<?php require __DIR__ . '/partials/vehicle-filter.php'; ?>
<div class="toolbar"><form method="get" action="<?= e($filterAction) ?>" class="trn-inline-filter" style="margin:0">
    <?php foreach (array_merge(trn_active_vehicle_filters($f), array_filter(['date_from' => $f['date_from'], 'date_to' => $f['date_to'], 'vehicle' => $pv])) as $k => $v): ?><input type="hidden" name="<?= e((string)$k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
    <select class="select" name="ftype" data-autosubmit aria-label="Fuel type"><option value="">All fuel types</option>
        <?php foreach (Database::fetchAll('SELECT id, name FROM fuel_types ORDER BY name') as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $ft === (int)$t['id'] ? 'selected' : '' ?>><?= e((string)$t['name']) ?></option><?php endforeach; ?></select></form></div>

<?php if (!$rows): ?>
    <div class="card"><div class="empty-state"><?= icon('fuel') ?><h3 style="margin-bottom:6px">No fuel records</h3><p style="margin:0">Nothing matches — add a record above or relax the filters.</p></div></div>
<?php else: ?>
    <div class="table-meta"><span><?= number_format((int)$agg['n']) ?> record<?= (int)$agg['n'] === 1 ? '' : 's' ?> · <?= e(trn_num($agg['qty'], 2)) ?> <?= e(trn_volume_unit()) ?> · <strong class="trn-money"><?= e(trn_money($agg['cost'])) ?></strong></span><span>Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Date</th><th>Vehicle</th><th>Driver</th><th>Fuel</th><th><?= e(trn_volume_unit()) ?></th><th>Rate</th><th>Total</th><th>Odometer</th><th>Receipt</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr><td><?= e(trn_date((string)$r['fuel_date'])) ?></td>
                <td><a href="<?= e(base_url('modules/transport/vehicle-view.php?id=' . (int)$r['vehicle_id'] . '&tab=fuel')) ?>"><strong><?= e((string)$r['registration_no']) ?></strong></a></td>
                <td><?= e((string)($r['driver_name'] ?: '—')) ?></td><td><?= e((string)$r['type_name']) ?><?= (int)$r['is_full_tank'] ? '' : '<span class="cell-sub">Partial fill</span>' ?></td>
                <td><?= e(trn_num($r['quantity'], 2)) ?></td><td class="trn-money"><?= e(trn_num($r['rate'], 2)) ?></td><td class="trn-money"><?= e(trn_money($r['total_cost'])) ?></td>
                <td><?= $r['odometer'] !== null ? e(trn_num($r['odometer'], 1)) : '—' ?></td>
                <td><?php if ($r['receipt_file']): ?><a target="_blank" rel="noopener" href="<?= e(trn_file_url('receipt', (int)$r['id'])) ?>">View</a><?php else: ?>—<?php endif; ?></td>
                <td><?php if ($canManage): ?><div class="cell-actions">
                    <a class="icon-btn" href="<?= e(base_url('modules/transport/fuel.php?edit=' . (int)$r['id'])) ?>" title="Edit" aria-label="Edit"><?= icon('pencil') ?></a>
                    <form method="post" action="<?= e(base_url('modules/transport/process/fuel-delete.php')) ?>" data-confirm="Delete this fuel record?">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                        <button type="submit" class="icon-btn icon-btn--danger" title="Delete" aria-label="Delete"><?= icon('trash') ?></button></form></div><?php endif; ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php trn_pagination($pg, static fn(int $p): string => $filterAction . $qs(['page' => $p])); ?>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
