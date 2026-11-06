<?php
/** Maintenance records — list, filter, add, edit, soft-delete, invoice upload. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.maintenance_view');
$canManage = can('transport.maintenance_manage');

$f = trn_read_vehicle_filters($_GET);
$f['date_from'] = trn_valid_date(query('date_from')) ?? '';
$f['date_to'] = trn_valid_date(query('date_to')) ?? '';
$mt = max(0, (int)query('mtype'));
$pv = (int)query('vehicle');

[$vw, $vp] = trn_vehicle_filter_sql($f);
$where = 'm.deleted_at IS NULL AND m.vehicle_id IN (SELECT v.id ' . trn_vehicle_from() . " WHERE {$vw})";
$params = $vp;
if ($mt > 0) { $where .= ' AND m.maintenance_type_id = :mt'; $params['mt'] = $mt; }
if ($pv > 0) { $where .= ' AND m.vehicle_id = :pv'; $params['pv'] = $pv; }
if ($f['date_from'] !== '') { $where .= ' AND m.maintenance_date >= :df'; $params['df'] = $f['date_from']; }
if ($f['date_to'] !== '') { $where .= ' AND m.maintenance_date <= :dt'; $params['dt'] = $f['date_to']; }

$base = "FROM vehicle_maintenance m JOIN vehicles v ON v.id = m.vehicle_id JOIN maintenance_types t ON t.id = m.maintenance_type_id WHERE {$where}";
$agg = Database::fetch("SELECT COUNT(*) AS n, COALESCE(SUM(m.cost),0) AS cost {$base}", $params);
$pg = trn_paginate((int)$agg['n'], 25);
$rows = Database::fetchAll("SELECT m.*, t.name AS type_name, v.registration_no {$base} ORDER BY m.maintenance_date DESC, m.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);

$editId = $canManage ? (int)query('edit') : 0;
$edit = $editId > 0 ? Database::fetch('SELECT m.*, v.registration_no FROM vehicle_maintenance m JOIN vehicles v ON v.id = m.vehicle_id WHERE m.id = :id AND m.deleted_at IS NULL', ['id' => $editId]) : null;
if ($edit !== null && !trn_vehicle_in_scope((int)$edit['vehicle_id'])) { $edit = null; }
$pre = null;
if ($canManage && !$edit && $pv > 0 && ($pre = trn_find_vehicle($pv)) !== null && !trn_vehicle_in_scope($pv)) { $pre = null; }
$formOpen = $edit !== null || query('new') === '1' || old('description', '') !== '' || old('cost', '') !== '';
$val = static fn(string $k, string $d = ''): string => old($k, (string)($edit[$k] ?? $d));

$qs = static fn(array $x = []): string => trn_report_query($f, $x + ['mtype' => $mt, 'vehicle' => $pv]);
$here = 'modules/transport/maintenance.php' . $qs(['page' => $pg['page'] > 1 ? $pg['page'] : null]);
$filterAction = base_url('modules/transport/maintenance.php');
$filterDates = true;
$filterKeep = array_filter(['vehicle' => $pv]);

$pageTitle = 'Maintenance';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
if ($canManage): trn_setup_notice(array_intersect_key(trn_missing_setup(), [])); if (!Database::exists("SELECT id FROM maintenance_types WHERE status='active' LIMIT 1")) { trn_setup_notice(['maintenance_type' => 'maintenance types']); } endif;
trn_search_bar();

if ($canManage): ?>
<details class="card trn-more" <?= $formOpen ? 'open' : '' ?>>
    <summary><?= $edit ? 'Edit maintenance record' : 'Add maintenance record' ?></summary>
    <form method="post" action="<?= e(base_url('modules/transport/process/maintenance-save.php')) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <div class="field-row">
            <?php if ($edit): ?>
                <div class="field"><span class="field__label">Vehicle</span><div class="control"><input class="input" value="<?= e((string)$edit['registration_no']) ?>" disabled></div></div>
            <?php else: trn_picker('vehicle', 'vehicle_id', 'Vehicle', $pre ? (int)$pre['id'] : ((int)old('vehicle_id', '') ?: null), $pre ? (string)$pre['registration_no'] : '', true); endif; ?>
            <?php trn_field_select('m_type', 'Maintenance type', trn_options('maintenance_type', (int)($edit['maintenance_type_id'] ?? 0)), $val('maintenance_type_id'), 'Choose a type…', ['name' => 'maintenance_type_id', 'required' => true]); ?>
        </div>
        <div class="field-row">
            <?php field_input('m_date', 'Maintenance date', ['name' => 'maintenance_date', 'type' => 'date', 'value' => $val('maintenance_date', trn_today()), 'required' => true, 'attrs' => 'max="' . trn_today() . '"']); ?>
            <?php field_input('m_cost', 'Cost' . (trn_currency() !== '' ? ' (' . trn_currency() . ')' : ''), ['name' => 'cost', 'value' => $val('cost'), 'required' => true, 'inputmode' => 'decimal']); ?>
        </div>
        <div class="field"><label class="field__label" for="m_desc">Work performed <span class="field__required">*</span></label>
            <textarea class="textarea" id="m_desc" name="description" rows="2" maxlength="1000"><?= e($val('description')) ?></textarea></div>
        <div class="field-row">
            <?php field_input('m_workshop', 'Workshop / vendor', ['name' => 'workshop', 'value' => $val('workshop'), 'maxlength' => 150]); ?>
            <?php field_input('m_odo', 'Odometer (' . trn_distance_unit() . ')', ['name' => 'odometer', 'value' => $val('odometer'), 'inputmode' => 'decimal']); ?>
        </div>
        <div class="field-row">
            <?php field_input('m_next', 'Next maintenance date (optional)', ['name' => 'next_maintenance_date', 'type' => 'date', 'value' => $val('next_maintenance_date')]); ?>
            <div class="field"><label class="field__label" for="m_inv">Invoice / receipt (optional)</label>
                <div class="control"><input class="input" type="file" id="m_inv" name="invoice" accept="application/pdf,image/jpeg,image/png,image/gif,image/webp"></div>
                <?php if ($edit && $edit['invoice_file']): ?><label class="checkbox"><input type="checkbox" name="remove_invoice" value="1"><span class="checkbox__box"></span><span>Remove current: <?= e((string)$edit['invoice_original']) ?></span></label><?php endif; ?></div>
        </div>
        <?php field_input('m_remarks', 'Remarks (optional)', ['name' => 'remarks', 'value' => $val('remarks'), 'maxlength' => 500]); ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button type="submit" class="btn btn--primary"><span class="btn__label"><?= $edit ? 'Save changes' : 'Add record' ?></span></button>
            <?php if ($edit): ?><a class="btn btn--ghost" href="<?= e(base_url($here)) ?>"><span class="btn__label">Cancel</span></a><?php endif; ?></div>
    </form>
</details>
<?php clear_old(); endif; ?>

<?php require __DIR__ . '/partials/vehicle-filter.php'; ?>
<div class="toolbar"><form method="get" action="<?= e($filterAction) ?>" class="trn-inline-filter" style="margin:0">
    <?php foreach (array_merge(trn_active_vehicle_filters($f), array_filter(['date_from' => $f['date_from'], 'date_to' => $f['date_to'], 'vehicle' => $pv])) as $k => $v): ?><input type="hidden" name="<?= e((string)$k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
    <select class="select" name="mtype" data-autosubmit aria-label="Maintenance type"><option value="">All maintenance types</option>
        <?php foreach (Database::fetchAll('SELECT id, name FROM maintenance_types ORDER BY name') as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $mt === (int)$t['id'] ? 'selected' : '' ?>><?= e((string)$t['name']) ?></option><?php endforeach; ?></select></form></div>

<?php if (!$rows): ?>
    <div class="card"><div class="empty-state"><?= icon('wrench') ?><h3 style="margin-bottom:6px">No maintenance records</h3><p style="margin:0">Nothing matches — add a record above or relax the filters.</p></div></div>
<?php else: ?>
    <div class="table-meta"><span><?= number_format((int)$agg['n']) ?> record<?= (int)$agg['n'] === 1 ? '' : 's' ?> · total <strong class="trn-money"><?= e(trn_money($agg['cost'])) ?></strong></span><span>Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Date</th><th>Vehicle</th><th>Type</th><th>Work</th><th>Cost</th><th>Next due</th><th>Invoice</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr><td><?= e(trn_date((string)$r['maintenance_date'])) ?></td>
                <td><a href="<?= e(base_url('modules/transport/vehicle-view.php?id=' . (int)$r['vehicle_id'] . '&tab=maintenance')) ?>"><strong><?= e((string)$r['registration_no']) ?></strong></a></td>
                <td><?= e((string)$r['type_name']) ?></td>
                <td class="is-wrap"><?= e((string)$r['description']) ?><span class="cell-sub"><?= e((string)$r['workshop']) ?><?= $r['odometer'] !== null ? ' · ' . e(trn_num($r['odometer'], 1)) . ' ' . e(trn_distance_unit()) : '' ?></span></td>
                <td class="trn-money"><?= e(trn_money($r['cost'])) ?></td><td><?= e(trn_date($r['next_maintenance_date'])) ?></td>
                <td><?php if ($r['invoice_file']): ?><a target="_blank" rel="noopener" href="<?= e(trn_file_url('invoice', (int)$r['id'])) ?>">View</a><?php else: ?>—<?php endif; ?></td>
                <td><?php if ($canManage): ?><div class="cell-actions">
                    <a class="icon-btn" href="<?= e(base_url('modules/transport/maintenance.php?edit=' . (int)$r['id'])) ?>" title="Edit" aria-label="Edit"><?= icon('pencil') ?></a>
                    <form method="post" action="<?= e(base_url('modules/transport/process/maintenance-delete.php')) ?>" data-confirm="Delete this maintenance record?">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                        <button type="submit" class="icon-btn icon-btn--danger" title="Delete" aria-label="Delete"><?= icon('trash') ?></button></form></div><?php endif; ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php trn_pagination($pg, static fn(int $p): string => $filterAction . $qs(['page' => $p])); ?>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
