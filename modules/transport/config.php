<?php
/**
 * Transport Configuration — every list and setting the module needs.
 * Nothing here is pre-filled: the starter suggestions are an explicit,
 * optional step (Quick start) that an authorised person confirms.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.config');

$reg = trn_config_registry();
$tabs = ['start' => 'Quick start', 'vehicle_status' => 'Vehicle Statuses', 'ownership_type' => 'Ownership Types', 'document_type' => 'Document Types',
    'maintenance_type' => 'Maintenance Types', 'fuel_type' => 'Fuel Types', 'settings' => 'Settings'];

$anyData = false;
foreach ($reg as $r) {
    if (Database::exists("SELECT id FROM `{$r['table']}` LIMIT 1")) { $anyData = true; break; }
}
$tab = query('tab');
if (!isset($tabs[$tab])) {
    $tab = $anyData ? 'vehicle_status' : 'start';
}

$pageTitle = 'Transport Configuration';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<nav class="trn-tabs" aria-label="Configuration sections">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="trn-tab <?= $tab === $key ? 'is-active' : '' ?>" href="<?= e(base_url('modules/transport/config.php?tab=' . $key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if (can('transport.types_view')): ?><a class="trn-tab" href="<?= e(base_url('modules/transport/vehicle-types.php')) ?>">Vehicle Types ↗</a><?php endif; ?>
</nav>

<?php if ($tab === 'start'): ?>
    <?php $starter = json_decode((string)@file_get_contents(__DIR__ . '/data/starter.json'), true) ?: []; ?>
    <section class="card">
        <h3 class="card__title">Quick start (optional)</h3>
        <p class="card__subtitle">Transport starts completely empty. Tick any suggestions you want to create, then confirm — you can rename, change or deactivate everything afterwards. Items that already exist are skipped. Vehicle types are created here too; day-to-day management is on the Vehicle Types page.</p>
        <form method="post" action="<?= e(base_url('modules/transport/process/starter-apply.php')) ?>" data-confirm="Create the ticked entries?">
            <?= csrf_field() ?>
            <div class="grid grid--3">
            <?php foreach ($reg as $kind => $r): ?>
                <div>
                    <div class="field__label"><?= e($r['plural']) ?></div>
                    <?php foreach (($starter[$kind] ?? []) as $i => $item): ?>
                        <?php $exists = Database::exists("SELECT id FROM `{$r['table']}` WHERE name = :n", ['n' => (string)$item['name']]); ?>
                        <label class="checkbox">
                            <input type="checkbox" name="pick[<?= e($kind) ?>][]" value="<?= (int)$i ?>" <?= $exists ? 'disabled' : 'checked' ?>>
                            <span class="checkbox__box"></span>
                            <span><?= e((string)$item['name']) ?><?= $exists ? ' (already exists)' : '' ?>
                                <?php if ($kind === 'vehicle_status'): ?><small class="cell-sub"><?= e(trn_status_categories()[$item['category'] ?? 'active']['label'] ?? '') ?></small><?php endif; ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn--primary" style="margin-top:16px"><span class="btn__label">Create ticked entries</span></button>
        </form>
    </section>

<?php elseif ($tab === 'settings'): ?>
    <?php
    $pools = Database::fetchAll("SELECT id, name, slug FROM hr_staff_pools WHERE status = 'active' ORDER BY name ASC");
    $types = Database::fetchAll("SELECT t.id, t.name, p.name AS pool_name FROM hr_staff_types t JOIN hr_staff_pools p ON p.id = t.staff_pool_id WHERE t.status = 'active' ORDER BY p.name, t.name");
    $selPools = trn_id_list(trn_setting('transport_driver_pool_ids'));
    $selTypes = trn_id_list(trn_setting('transport_driver_type_ids'));
    $configured = trn_driver_rules_configured();
    ?>
    <form class="card" method="post" action="<?= e(base_url('modules/transport/process/settings-save.php')) ?>">
        <?= csrf_field() ?>
        <h3 class="card__title">Documents</h3>
        <?php field_input('set_days', 'Warn this many days before a document expires', ['name' => 'doc_warning_days', 'type' => 'number', 'value' => (string)trn_warning_days(), 'required' => true, 'attrs' => 'min="0" max="3650"', 'hint' => 'Default for every document type. A document type can override it.']); ?>

        <h3 class="card__title" style="margin-top:24px">Who counts as a driver</h3>
        <p class="card__subtitle">Drivers are existing HR employees — nothing is duplicated. Choose the HR staff pool(s) and/or staff type(s) whose employees may be assigned to vehicles. When you choose both, an employee must match both.</p>
        <?php if (!$configured): ?><div class="alert alert--warning"><?= icon('alert') ?><span>No driver rule is saved yet, so nobody can be assigned as a driver.</span></div><?php endif; ?>
        <div class="grid grid--2">
            <div><div class="field__label">Staff pools</div>
                <?php foreach ($pools as $p): ?>
                    <label class="checkbox"><input type="checkbox" name="driver_pools[]" value="<?= (int)$p['id'] ?>" <?= in_array((int)$p['id'], $selPools, true) || (!$configured && $p['slug'] === 'transport-staff') ? 'checked' : '' ?>><span class="checkbox__box"></span><span><?= e((string)$p['name']) ?></span></label>
                <?php endforeach; ?>
                <?php if (!$pools): ?><p class="field__hint">No HR staff pools exist yet.</p><?php endif; ?></div>
            <div><div class="field__label">Staff types (optional)</div>
                <?php foreach ($types as $t): ?>
                    <label class="checkbox"><input type="checkbox" name="driver_types[]" value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $selTypes, true) ? 'checked' : '' ?>><span class="checkbox__box"></span><span><?= e((string)$t['name']) ?> <small class="cell-sub" style="display:inline">· <?= e((string)$t['pool_name']) ?></small></span></label>
                <?php endforeach; ?></div>
        </div>
        <label class="checkbox" style="margin-top:12px"><input type="checkbox" name="driver_multi" value="1" <?= trn_setting('transport_driver_multi') === '1' ? 'checked' : '' ?>><span class="checkbox__box"></span><span>Allow one driver to hold more than one vehicle at the same time</span></label>

        <h3 class="card__title" style="margin-top:24px">Display</h3>
        <div class="field-row">
            <?php field_input('set_cur', 'Currency label (optional)', ['name' => 'currency', 'value' => trn_currency(), 'maxlength' => 12, 'hint' => 'Shown before costs, for example the currency code.']); ?>
            <?php field_input('set_dist', 'Distance unit', ['name' => 'distance_unit', 'value' => trn_distance_unit(), 'maxlength' => 10]); ?>
            <?php field_input('set_vol', 'Fuel volume unit', ['name' => 'volume_unit', 'value' => trn_volume_unit(), 'maxlength' => 10]); ?>
        </div>
        <button type="submit" class="btn btn--primary" style="margin-top:12px"><span class="btn__label">Save settings</span></button>
    </form>

<?php else: ?>
    <?php $kind = $tab; $pageUrl = 'modules/transport/config.php?tab=' . $tab; $canManage = true; require __DIR__ . '/partials/lookup-manager.php'; ?>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
