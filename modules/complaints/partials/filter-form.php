<?php
/**
 * Complaint filter panel (dashboard + reports).
 *
 * Expects $filters (cmp_read_filters), $filterAction (URL), $filterMode:
 *   'dashboard' — compact; the page's JS applies changes live (no reload)
 *   'reports'   — full set, normal GET form
 * Region → Tehsil → UC → Beat are chained by assets/js/complaints.js
 * (one level per request); the current chain is rendered server-side so a
 * shared link opens with the right dropdowns.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

$reports = ($filterMode ?? 'dashboard') === 'reports';
$sel = static fn($a, $b): string => (string)$a === (string)$b && (string)$a !== '' && (string)$a !== '0' ? 'selected' : '';
$regionsAll = Database::fetchAll("SELECT id, name FROM regions WHERE status = 'active' ORDER BY name ASC");
$chain = [
    'district' => cmp_geography_children('district', $filters['region']),
    'tehsil' => cmp_geography_children('tehsil', $filters['district']),
    'uc' => cmp_geography_children('uc', $filters['tehsil']),
    'beat' => cmp_geography_children('beat', $filters['uc']),
];
if (($ids = cmp_scope_uc_ids()) !== null) {
    $chain['uc'] = array_values(array_filter($chain['uc'], static fn($r) => in_array((int)$r['id'], $ids, true)));
}
$sources = Database::fetchAll('SELECT id, name FROM complaint_sources ORDER BY sort_order, name');
$types = Database::fetchAll('SELECT id, name FROM complaint_types ORDER BY sort_order, name');
$priorities = Database::fetchAll('SELECT id, name FROM complaint_priorities ORDER BY sort_order, name');
$supervisors = cmp_staff_options('supervisor');
$monitors = cmp_staff_options('monitor');

$select = static function (string $name, string $label, array $rows, $current, string $placeholder, string $child = '') use ($sel): void { ?>
    <div class="field">
        <label class="field__label" for="f_<?= e($name) ?>"><?= e($label) ?></label>
        <div class="control"><select class="select" id="f_<?= e($name) ?>" name="<?= e($name) ?>" <?= $child !== '' ? 'data-cascade-child="' . e($child) . '"' : '' ?>>
            <option value=""><?= e($placeholder) ?></option>
            <?php foreach ($rows as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $sel($current, $r['id']) ?>><?= e((string)$r['name']) ?><?= isset($r['employee_code']) && $r['employee_code'] ? ' (' . e((string)$r['employee_code']) . ')' : '' ?></option><?php endforeach; ?>
        </select></div>
    </div>
<?php };
?>
<form class="card filter-panel" id="cmp-filter-form" method="get" action="<?= e($filterAction) ?>">
    <?php if (!$reports): ?>
        <div class="cmp-range" data-range-buttons>
            <?php foreach (['' => 'All time', 'today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'last_month' => 'Last month', 'custom' => 'Custom range'] as $k => $label): ?>
                <button type="button" data-range="<?= e($k) ?>" class="<?= $filters['range'] === $k && ($k !== '' || $filters['date_from'] === '') ? 'is-active' : '' ?>"><?= e($label) ?></button>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="range" value="<?= e($filters['range']) ?>">
    <?php else: ?>
        <input type="hidden" name="range" value="<?= e($filters['range']) ?>">
    <?php endif; ?>

    <div class="filter-panel__grid cmp-cascade" data-cascade-url="<?= e(base_url('modules/complaints/process/cascade.php')) ?>">
        <div class="field filter-panel__search">
            <label class="field__label" for="f_q">Search</label>
            <div class="control"><span class="control__icon"><?= icon('search') ?></span>
                <input class="input" type="search" id="f_q" name="q" value="<?= e($filters['q']) ?>" placeholder="Number, name, phone, address, description"></div>
        </div>
        <div class="field"><label class="field__label" for="f_date_from">From date</label><div class="control"><input class="input" type="date" id="f_date_from" name="date_from" value="<?= e($filters['date_from']) ?>"></div></div>
        <div class="field"><label class="field__label" for="f_date_to">To date</label><div class="control"><input class="input" type="date" id="f_date_to" name="date_to" value="<?= e($filters['date_to']) ?>"></div></div>

        <?php if ($reports): ?>
            <div class="field"><label class="field__label" for="f_number">Complaint number</label><div class="control"><input class="input" id="f_number" name="number" value="<?= e($filters['number']) ?>"></div></div>
            <div class="field"><label class="field__label" for="f_complainant">Complainant</label><div class="control"><input class="input" id="f_complainant" name="complainant" value="<?= e($filters['complainant']) ?>"></div></div>
            <div class="field"><label class="field__label" for="f_phone">Phone</label><div class="control"><input class="input" id="f_phone" name="phone" value="<?= e($filters['phone']) ?>"></div></div>
        <?php endif; ?>

        <?php $select('region', 'Region', $regionsAll, $filters['region'], 'All regions', 'district'); ?>
        <?php $select('district', 'District', $chain['district'], $filters['district'], 'All districts', 'tehsil'); ?>
        <?php $select('tehsil', 'Tehsil', $chain['tehsil'], $filters['tehsil'], 'All tehsils', 'uc'); ?>
        <?php $select('uc', 'UC', $chain['uc'], $filters['uc'], 'All UCs', 'beat'); ?>
        <?php $select('beat', 'Beat', $chain['beat'], $filters['beat'], 'All beats'); ?>
        <?php $select('source', 'Source', $sources, $filters['source'], 'All sources'); ?>
        <?php $select('type', 'Type', $types, $filters['type'], 'All types'); ?>
        <?php $select('priority', 'Priority', $priorities, $filters['priority'], 'All priorities'); ?>

        <div class="field"><label class="field__label" for="f_status">Status</label>
            <div class="control"><select class="select" id="f_status" name="status"><option value="">All statuses</option>
                <?php foreach (cmp_statuses() as $slug => $st): ?><option value="<?= e((string)$slug) ?>" <?= $filters['status'] === $slug ? 'selected' : '' ?>><?= e((string)$st['label']) ?></option><?php endforeach; ?>
            </select></div></div>

        <?php if (!cmp_is_scoped()): ?>
            <?php $select('supervisor', 'Supervisor', $supervisors, $filters['supervisor'], 'All supervisors'); ?>
            <?php $select('monitor', 'Monitoring Officer', $monitors, $filters['monitor'], 'All monitoring officers'); ?>
        <?php endif; ?>

        <div class="field"><label class="field__label" for="f_sla">SLA</label>
            <div class="control"><select class="select" id="f_sla" name="sla"><option value="">Any SLA state</option>
                <option value="within" <?= $filters['sla'] === 'within' ? 'selected' : '' ?>>Within SLA</option>
                <option value="nearing" <?= $filters['sla'] === 'nearing' ? 'selected' : '' ?>>Nearing SLA</option>
                <option value="breached" <?= $filters['sla'] === 'breached' ? 'selected' : '' ?>>SLA breached</option>
            </select></div></div>

        <?php if ($reports && can('complaint.restore')): ?>
            <div class="field"><label class="field__label" for="f_deleted">Deleted complaints</label>
                <div class="control"><select class="select" id="f_deleted" name="deleted"><option value="">Hide deleted</option><option value="1" <?= $filters['deleted'] === '1' ? 'selected' : '' ?>>Show deleted only</option></select></div></div>
        <?php endif; ?>
    </div>

    <div class="filter-panel__actions">
        <button type="submit" class="btn btn--primary"><?= icon('search') ?><span class="btn__label">Apply filters</span></button>
        <a class="btn btn--ghost" href="<?= e($filterAction) ?>" data-clear-filters><span class="btn__label">Clear all</span></a>
    </div>
    <p class="filter-hint">All filters combine. Dates use the complaint's own date, so any past day or range works.<?= cmp_is_scoped() ? ' You only see complaints of your own UC(s).' : '' ?></p>
</form>
