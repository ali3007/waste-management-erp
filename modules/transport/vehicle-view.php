<?php
/**
 * Vehicle profile — details, driver, deployment, documents, maintenance,
 * fuel, tracker/RFID history and activity, one tab each. Tabs and actions
 * appear only when the user holds the matching permission; the vehicle itself
 * is checked against the user's area scope.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.details');

$id = (int)query('id');
$v = trn_require_vehicle($id);
$archived = $v['deleted_at'] !== null;

$tabs = ['overview' => ['Overview', true]];
$tabs['driver'] = ['Drivers', can('transport.driver_view') || can('transport.history')];
$tabs['deployment'] = ['Deployments', can('transport.deployment_view') || can('transport.history')];
$tabs['documents'] = ['Documents', can('transport.documents_view')];
$tabs['maintenance'] = ['Maintenance', can('transport.maintenance_view')];
$tabs['fuel'] = ['Fuel', can('transport.fuel_view')];
$tabs['identifiers'] = ['Tracker / RFID history', true];
$tabs['activity'] = ['Activity', can('transport.audit')];
$tabs = array_filter($tabs, static fn(array $t): bool => $t[1]);
$tab = isset($tabs[query('tab')]) ? query('tab') : 'overview';

$vid = (int)$v['id'];
$self = base_url('modules/transport/vehicle-view.php?id=' . $vid);
$here = 'modules/transport/vehicle-view.php?id=' . $vid . '&tab=' . $tab;
$statuses = can('transport.status') && !$archived ? trn_options('vehicle_status', (int)$v['status_id']) : [];

$pageTitle = 'Vehicle ' . $v['registration_no'];
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<section class="card">
    <div class="trn-profile">
        <div>
            <div class="trn-photo">
                <?php if ($v['picture_file']): ?><img src="<?= e(trn_file_url('photo', $vid)) ?>" alt="Photo of <?= e((string)$v['registration_no']) ?>" loading="lazy">
                <?php else: ?><?= icon('truck') ?><?php endif; ?>
            </div>
        </div>
        <div>
            <h2 class="trn-title"><?= e((string)$v['registration_no']) ?></h2>
            <div class="trn-badges">
                <span class="badge <?= e(trn_status_badge_class((string)$v['status_category'])) ?>"><?= e((string)$v['status_name']) ?></span>
                <span class="badge badge--inactive"><?= e((string)$v['type_name']) ?></span>
                <span class="badge badge--inactive"><?= e((string)$v['ownership_name']) ?></span>
                <?php if ($archived): ?><span class="badge badge--blocked">Archived <?= e(trn_date(substr((string)$v['deleted_at'], 0, 10))) ?></span><?php endif; ?>
            </div>
            <dl class="trn-facts">
                <div><dt>Make / model</dt><dd><?= e(trim((string)$v['make'] . ' ' . (string)$v['model']) ?: '—') ?></dd></div>
                <div><dt>Year</dt><dd><?= e((string)($v['manufacture_year'] ?: '—')) ?></dd></div>
                <div><dt>Colour</dt><dd><?= e((string)($v['color'] ?: '—')) ?></dd></div>
                <div><dt>Chassis number</dt><dd><?= e((string)($v['chassis_no'] ?: '—')) ?></dd></div>
                <div><dt>Engine number</dt><dd><?= e((string)($v['engine_no'] ?: '—')) ?></dd></div>
                <div><dt>Tracker number</dt><dd><?= e((string)($v['tracker_no'] ?: '—')) ?></dd></div>
                <div><dt>RFID number</dt><dd><?= e((string)($v['rfid_no'] ?: '—')) ?></dd></div>
                <div><dt>Current driver</dt><dd><?= $v['driver_name'] ? e((string)$v['driver_name']) . ' <small class="trn-muted">since ' . e(trn_date((string)$v['driver_since'])) . '</small>' : '<span class="trn-muted">None</span>' ?></dd></div>
                <div><dt>Current deployment</dt><dd><?= $v['uc_name'] ? e(trn_area_text($v)) : '<span class="trn-muted">Not deployed</span>' ?></dd></div>
                <div><dt>Added</dt><dd><?= e(trn_date(substr((string)$v['created_at'], 0, 10))) ?></dd></div>
            </dl>
            <?php if ($v['remarks']): ?><p class="trn-note" style="margin-top:12px"><?= e((string)$v['remarks']) ?></p><?php endif; ?>
            <?php if ($archived && $v['delete_reason']): ?><p class="trn-note">Archived because: <?= e((string)$v['delete_reason']) ?></p><?php endif; ?>

            <div class="trn-actions">
                <?php if (can('transport.update') && !$archived): ?><a class="btn btn--ghost" href="<?= e(base_url('modules/transport/vehicle-form.php?id=' . $vid)) ?>"><?= icon('pencil') ?><span class="btn__label">Edit</span></a><?php endif; ?>
                <?php if ($statuses): ?><button type="button" class="btn btn--ghost" data-toggle-target="status-panel" aria-expanded="false"><span class="btn__label">Change status</span></button><?php endif; ?>
                <?php if (can('transport.driver_assign') && !$archived): ?><a class="btn btn--ghost" href="<?= e(base_url('modules/transport/drivers.php?vehicle=' . $vid)) ?>"><span class="btn__label"><?= $v['driver_id'] ? 'Change driver' : 'Assign driver' ?></span></a><?php endif; ?>
                <?php if (can('transport.deployment_manage') && !$archived): ?><a class="btn btn--ghost" href="<?= e(base_url('modules/transport/deployments.php?vehicle=' . $vid)) ?>"><span class="btn__label"><?= $v['deployment_id'] ? 'Change deployment' : 'Deploy' ?></span></a><?php endif; ?>
                <?php if (can('transport.archive')): ?><button type="button" class="btn btn--ghost" data-toggle-target="archive-panel" aria-expanded="false"><span class="btn__label"><?= $archived ? 'Restore' : 'Archive' ?></span></button><?php endif; ?>
            </div>

            <?php if ($statuses): ?>
            <div class="trn-panel" id="status-panel" hidden>
                <form method="post" action="<?= e(base_url('modules/transport/process/vehicle-status.php')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $vid ?>">
                    <div class="field-row">
                        <?php trn_field_select('st_status', 'New status', $statuses, '', 'Choose a status…', ['name' => 'status_id', 'required' => true]); ?>
                        <?php field_input('st_reason', 'Reason (optional)', ['name' => 'reason', 'maxlength' => 255]); ?>
                    </div>
                    <button type="submit" class="btn btn--primary"><span class="btn__label">Change status</span></button>
                </form>
            </div>
            <?php endif; ?>
            <?php if (can('transport.archive')): ?>
            <div class="trn-panel" id="archive-panel" hidden>
                <form method="post" action="<?= e(base_url('modules/transport/process/vehicle-archive.php')) ?>"
                      data-confirm="<?= $archived ? 'Restore this vehicle?' : 'Archive this vehicle? Its history is kept.' ?>">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $vid ?>"><input type="hidden" name="action" value="<?= $archived ? 'restore' : 'archive' ?>">
                    <?php if (!$archived): ?><p class="trn-note">Archiving hides the vehicle from active lists and frees its number, tracker and RFID. Documents, maintenance, fuel and history are kept. The current driver and deployment must be ended first.</p><?php endif; ?>
                    <?php field_input('ar_reason', 'Reason (optional)', ['name' => 'reason', 'maxlength' => 255]); ?>
                    <button type="submit" class="btn btn--primary"><span class="btn__label"><?= $archived ? 'Restore vehicle' : 'Archive vehicle' ?></span></button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<nav class="trn-tabs" aria-label="Vehicle sections" style="margin-top:var(--space-4)">
    <?php foreach ($tabs as $key => [$label]): ?>
        <a class="trn-tab <?= $tab === $key ? 'is-active' : '' ?>" href="<?= e($self . '&tab=' . $key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php
/* ------------------------------ tab bodies ------------------------------ */
if ($tab === 'overview'):
    $m = Database::fetch('SELECT COALESCE(SUM(cost),0) AS cost, COUNT(*) AS n, MAX(maintenance_date) AS last_date, MIN(CASE WHEN next_maintenance_date >= :t THEN next_maintenance_date END) AS next_due FROM vehicle_maintenance WHERE vehicle_id = :v AND deleted_at IS NULL', ['v' => $vid, 't' => trn_today()]);
    $fu = Database::fetch('SELECT COALESCE(SUM(total_cost),0) AS cost, COALESCE(SUM(quantity),0) AS qty, COUNT(*) AS n FROM vehicle_fuel_records WHERE vehicle_id = :v AND deleted_at IS NULL', ['v' => $vid]);
    $eff = trn_fuel_efficiency('SELECT ' . $vid, [])[$vid] ?? null;
    $docSt = Database::fetchAll(
        'SELECT st, COUNT(*) AS n FROM (SELECT ' . trn_doc_status_sql('d', 't') . ' AS st FROM vehicle_documents d JOIN vehicle_document_types t ON t.id = d.document_type_id WHERE d.vehicle_id = :v AND d.current_flag = 1) x GROUP BY st',
        ['v' => $vid]
    );
    $docCount = [];
    foreach ($docSt as $d) { $docCount[$d['st']] = (int)$d['n']; }
    $missingDocs = Database::fetchAll(
        "SELECT t.name FROM vehicle_document_types t LEFT JOIN vehicle_documents d ON d.document_type_id = t.id AND d.vehicle_id = :v AND d.current_flag = 1
          WHERE t.is_required = 1 AND t.status = 'active' AND d.id IS NULL ORDER BY t.name", ['v' => $vid]);
?>
    <div class="grid grid--3">
        <section class="card"><h3 class="card__title">Maintenance</h3>
            <div class="trn-kv"><span>Records</span><strong><?= (int)$m['n'] ?></strong></div>
            <div class="trn-kv"><span>Total cost</span><strong class="trn-money"><?= e(trn_money($m['cost'])) ?></strong></div>
            <div class="trn-kv"><span>Last</span><strong><?= e(trn_date($m['last_date'])) ?></strong></div>
            <div class="trn-kv"><span>Next due</span><strong><?= e(trn_date($m['next_due'])) ?></strong></div></section>
        <section class="card"><h3 class="card__title">Fuel</h3>
            <div class="trn-kv"><span>Fills</span><strong><?= (int)$fu['n'] ?></strong></div>
            <div class="trn-kv"><span>Total <?= e(trn_volume_unit()) ?></span><strong><?= e(trn_num($fu['qty'], 2)) ?></strong></div>
            <div class="trn-kv"><span>Total cost</span><strong class="trn-money"><?= e(trn_money($fu['cost'])) ?></strong></div>
            <div class="trn-kv"><span><?= e(trn_distance_unit()) ?> per <?= e(trn_volume_unit()) ?></span><strong><?= $eff && $eff['kmpl'] ? e(trn_num($eff['kmpl'], 2)) : '—' ?></strong></div>
            <?php if (!$eff): ?><p class="field__hint">Needs two full-tank fills with odometer readings.</p><?php endif; ?></section>
        <section class="card"><h3 class="card__title">Documents</h3>
            <div class="trn-kv"><span>Valid</span><strong><?= (int)($docCount['valid'] ?? 0) + (int)($docCount['none'] ?? 0) ?></strong></div>
            <div class="trn-kv"><span>Expiring soon</span><strong><?= (int)($docCount['expiring'] ?? 0) ?></strong></div>
            <div class="trn-kv"><span>Expired</span><strong><?= (int)($docCount['expired'] ?? 0) ?></strong></div>
            <div class="trn-kv"><span>Required, missing</span><strong><?= count($missingDocs) ?></strong></div>
            <?php if ($missingDocs): ?><p class="field__hint"><?= e(implode(', ', array_column($missingDocs, 'name'))) ?></p><?php endif; ?></section>
    </div>

<?php elseif ($tab === 'driver'):
    $rows = Database::fetchAll('SELECT a.*, e.name AS driver_name, e.employee_code, u.name AS by_name FROM vehicle_driver_assignments a JOIN employees e ON e.id = a.employee_id LEFT JOIN users u ON u.id = a.assigned_by WHERE a.vehicle_id = :v ORDER BY a.assigned_date DESC, a.id DESC', ['v' => $vid]); ?>
    <section class="card"><h3 class="card__title">Driver assignment history</h3>
    <?php if (!$rows): ?><div class="empty-state"><?= icon('users') ?><p style="margin:0">No driver has been assigned yet.</p></div><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Driver</th><th>From</th><th>To</th><th>Assigned by</th><th>Notes</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr>
        <td><strong><?= e((string)$r['driver_name']) ?></strong><span class="cell-sub"><?= e((string)$r['employee_code']) ?></span></td>
        <td><?= e(trn_date((string)$r['assigned_date'])) ?></td>
        <td><?= $r['end_date'] ? e(trn_date((string)$r['end_date'])) : '<span class="badge badge--active">Current</span>' ?></td>
        <td><?= e((string)$r['by_name']) ?></td><td class="is-wrap"><?= e(trim((string)$r['remarks'] . ' ' . ($r['end_reason'] ? '[' . $r['end_reason'] . ']' : ''))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>

<?php elseif ($tab === 'deployment'):
    $rows = Database::fetchAll('SELECT d.*, rg.name AS region, ds.name AS district, th.name AS tehsil, uc.name AS uc, bt.name AS beat, u.name AS by_name FROM vehicle_deployments d JOIN regions rg ON rg.id = d.region_id JOIN districts ds ON ds.id = d.district_id JOIN tehsils th ON th.id = d.tehsil_id JOIN ucs uc ON uc.id = d.uc_id LEFT JOIN beats bt ON bt.id = d.beat_id LEFT JOIN users u ON u.id = d.assigned_by WHERE d.vehicle_id = :v ORDER BY d.start_date DESC, d.id DESC', ['v' => $vid]); ?>
    <section class="card"><h3 class="card__title">Deployment history</h3>
    <?php if (!$rows): ?><div class="empty-state"><?= icon('calendar') ?><p style="margin:0">This vehicle has never been deployed.</p></div><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Area</th><th>From</th><th>To</th><th>By</th><th>Notes</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr>
        <td class="is-wrap"><strong><?= e((string)$r['uc']) ?><?= $r['beat'] ? ' / ' . e((string)$r['beat']) : '' ?></strong><span class="cell-sub"><?= e((string)$r['tehsil']) ?>, <?= e((string)$r['district']) ?>, <?= e((string)$r['region']) ?></span></td>
        <td><?= e(trn_date((string)$r['start_date'])) ?></td>
        <td><?= $r['end_date'] ? e(trn_date((string)$r['end_date'])) : '<span class="badge badge--active">Current</span>' ?></td>
        <td><?= e((string)$r['by_name']) ?></td><td class="is-wrap"><?= e(trim((string)$r['remarks'] . ' ' . ($r['end_reason'] ? '[' . $r['end_reason'] . ']' : ''))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>

<?php elseif ($tab === 'documents'):
    $docs = Database::fetchAll('SELECT d.*, t.name AS type_name, ' . trn_doc_status_sql('d', 't') . ' AS st FROM vehicle_documents d JOIN vehicle_document_types t ON t.id = d.document_type_id WHERE d.vehicle_id = :v AND d.current_flag = 1 ORDER BY t.name', ['v' => $vid]);
    $hist = Database::fetchAll("SELECT d.*, t.name AS type_name, u.name AS by_name FROM vehicle_documents d JOIN vehicle_document_types t ON t.id = d.document_type_id LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.vehicle_id = :v AND d.state <> 'current' ORDER BY d.uploaded_at DESC LIMIT 50", ['v' => $vid]);
    $missingDocs = Database::fetchAll("SELECT t.name FROM vehicle_document_types t LEFT JOIN vehicle_documents d ON d.document_type_id = t.id AND d.vehicle_id = :v AND d.current_flag = 1 WHERE t.is_required = 1 AND t.status = 'active' AND d.id IS NULL ORDER BY t.name", ['v' => $vid]);
?>
    <section class="card"><h3 class="card__title">Current documents</h3>
        <?php if (can('transport.documents_manage') && !$archived): ?><p class="card__subtitle"><a href="<?= e(base_url('modules/transport/documents.php?vehicle=' . $vid)) ?>">Upload, replace or update documents</a></p><?php endif; ?>
        <div class="trn-doc-grid">
        <?php foreach ($docs as $d): ?>
            <div class="trn-doc"><h4><?= e((string)$d['type_name']) ?><span class="badge <?= e(trn_doc_status_badge((string)$d['st'])) ?>"><?= e(trn_doc_status_label((string)$d['st'])) ?></span></h4>
                <p>Issued: <?= e(trn_date($d['issue_date'])) ?></p><p>Expires: <?= e(trn_date($d['expiry_date'])) ?></p><p><?= e((string)$d['original_name']) ?></p>
                <div class="trn-actions"><?php if (can('transport.documents_view')): ?><a class="btn btn--ghost" target="_blank" rel="noopener" href="<?= e(trn_file_url('doc', (int)$d['id'])) ?>"><span class="btn__label">View</span></a><?php endif; ?>
                <?php if (can('transport.documents_download')): ?><a class="btn btn--ghost" href="<?= e(trn_file_url('doc', (int)$d['id'], true)) ?>"><span class="btn__label">Download</span></a><?php endif; ?></div></div>
        <?php endforeach; ?>
        <?php foreach ($missingDocs as $mm): ?><div class="trn-doc trn-doc--missing"><h4><?= e((string)$mm['name']) ?><span class="badge badge--blocked">Missing</span></h4><p>Required — not uploaded yet.</p></div><?php endforeach; ?>
        </div>
        <?php if (!$docs && !$missingDocs): ?><div class="empty-state"><?= icon('file-text') ?><p style="margin:0">No documents on file.</p></div><?php endif; ?>
    </section>
    <?php if ($hist): ?><section class="card" style="margin-top:var(--space-4)"><h3 class="card__title">Document history</h3>
    <div class="table-wrap"><table class="table"><thead><tr><th>Document</th><th>File</th><th>Expiry</th><th>Uploaded</th><th>Outcome</th></tr></thead><tbody>
    <?php foreach ($hist as $h): ?><tr><td><?= e((string)$h['type_name']) ?></td><td class="is-wrap"><?= e((string)$h['original_name']) ?></td><td><?= e(trn_date($h['expiry_date'])) ?></td>
        <td><?= e(trn_date(substr((string)$h['uploaded_at'], 0, 10))) ?><span class="cell-sub"><?= e((string)$h['by_name']) ?></span></td>
        <td><span class="badge badge--inactive"><?= $h['state'] === 'replaced' ? 'Replaced' : 'Deleted' ?></span>
            <?php if (can('transport.documents_view')): ?> <a href="<?= e(trn_file_url('doc', (int)$h['id'])) ?>" target="_blank" rel="noopener">View</a><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section><?php endif; ?>

<?php elseif ($tab === 'maintenance'):
    $rows = Database::fetchAll('SELECT m.*, t.name AS type_name FROM vehicle_maintenance m JOIN maintenance_types t ON t.id = m.maintenance_type_id WHERE m.vehicle_id = :v AND m.deleted_at IS NULL ORDER BY m.maintenance_date DESC, m.id DESC LIMIT 50', ['v' => $vid]); ?>
    <section class="card"><h3 class="card__title">Maintenance</h3>
    <?php if (can('transport.maintenance_manage') && !$archived): ?><p class="card__subtitle"><a href="<?= e(base_url('modules/transport/maintenance.php?new=1&vehicle=' . $vid)) ?>">Add a maintenance record</a></p><?php endif; ?>
    <?php if (!$rows): ?><div class="empty-state"><?= icon('wrench') ?><p style="margin:0">No maintenance recorded.</p></div><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Type</th><th>Work</th><th>Cost</th><th>Next due</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?= e(trn_date((string)$r['maintenance_date'])) ?></td><td><?= e((string)$r['type_name']) ?></td>
        <td class="is-wrap"><?= e((string)$r['description']) ?><span class="cell-sub"><?= e((string)$r['workshop']) ?></span></td>
        <td class="trn-money"><?= e(trn_money($r['cost'])) ?></td><td><?= e(trn_date($r['next_maintenance_date'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>

<?php elseif ($tab === 'fuel'):
    $rows = Database::fetchAll('SELECT r.*, t.name AS type_name, e.name AS driver_name FROM vehicle_fuel_records r JOIN fuel_types t ON t.id = r.fuel_type_id LEFT JOIN employees e ON e.id = r.driver_id WHERE r.vehicle_id = :v AND r.deleted_at IS NULL ORDER BY r.fuel_date DESC, r.id DESC LIMIT 50', ['v' => $vid]); ?>
    <section class="card"><h3 class="card__title">Fuel</h3>
    <?php if (can('transport.fuel_manage') && !$archived): ?><p class="card__subtitle"><a href="<?= e(base_url('modules/transport/fuel.php?new=1&vehicle=' . $vid)) ?>">Add a fuel record</a></p><?php endif; ?>
    <?php if (!$rows): ?><div class="empty-state"><?= icon('fuel') ?><p style="margin:0">No fuel recorded.</p></div><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Fuel</th><th><?= e(trn_volume_unit()) ?></th><th>Rate</th><th>Total</th><th>Odometer</th><th>Driver</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?= e(trn_date((string)$r['fuel_date'])) ?></td><td><?= e((string)$r['type_name']) ?></td><td><?= e(trn_num($r['quantity'], 2)) ?></td>
        <td class="trn-money"><?= e(trn_num($r['rate'], 2)) ?></td><td class="trn-money"><?= e(trn_money($r['total_cost'])) ?></td><td><?= $r['odometer'] !== null ? e(trn_num($r['odometer'], 1)) : '—' ?></td><td><?= e((string)$r['driver_name'] ?: '—') ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>

<?php elseif ($tab === 'identifiers'):
    $rows = Database::fetchAll('SELECT h.*, u.name AS by_name FROM vehicle_identifier_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.vehicle_id = :v ORDER BY h.changed_at DESC, h.id DESC', ['v' => $vid]); ?>
    <section class="card"><h3 class="card__title">Registration, tracker and RFID history</h3>
    <?php if (!$rows): ?><div class="empty-state"><?= icon('tag') ?><p style="margin:0">No changes recorded yet.</p></div><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>What</th><th>Change</th><th>By</th><th>Reason</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?= e(trn_date(substr((string)$r['changed_at'], 0, 10))) ?></td>
        <td><?= e(ucfirst((string)$r['kind'])) ?> <?= e((string)$r['action']) ?></td>
        <td class="is-wrap"><?= $r['old_value'] !== null ? e((string)$r['old_value']) : '—' ?> → <?= $r['new_value'] !== null ? e((string)$r['new_value']) : '—' ?></td>
        <td><?= e((string)$r['by_name']) ?></td><td class="is-wrap"><?= e((string)$r['reason']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>

<?php elseif ($tab === 'activity'):
    $rows = Database::fetchAll("SELECT a.id, a.action, a.description, a.created_at, u.name AS user_name, c.old_value, c.new_value, c.reason
        FROM transport_audit_changes c JOIN activity_log a ON a.id = c.activity_log_id LEFT JOIN users u ON u.id = a.user_id
        WHERE c.vehicle_id = :v ORDER BY a.id DESC LIMIT 100", ['v' => $vid]); ?>
    <section class="card"><h3 class="card__title">Activity on this vehicle</h3>
    <?php if (!$rows): ?><div class="empty-state"><?= icon('clock') ?><p style="margin:0">No activity recorded.</p></div><?php else: ?>
    <?php foreach ($rows as $r): ?><div class="trn-kv" style="display:block"><strong><?= e((string)$r['description']) ?></strong>
        <div class="trn-diff"><?= e(format_datetime((string)$r['created_at'], 'd M Y, H:i')) ?> · <?= e((string)$r['user_name']) ?>
        <?php if ($r['reason']): ?> · Reason: <?= e((string)$r['reason']) ?><?php endif; ?></div>
        <?php if ($r['old_value'] || $r['new_value']): ?><div class="trn-diff"><?= e(trn_diff_text($r['old_value'], $r['new_value'])) ?></div><?php endif; ?></div><?php endforeach; ?>
    <?php endif; ?></section>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
