<?php
/**
 * Report filter panel. Expects $f (att_read_report_filters), $filterOptions
 * (att_filter_options) and $filterAction. Geography dropdowns re-render
 * server-side (a parent change clears its children and submits), like HR.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

$sel = static function (string $name, string $label, array $rows, int $current, string $placeholder, string $reset = '', bool $disabled = false): void {
    ?>
    <div class="field"><label class="field__label" for="f_<?= e($name) ?>"><?= e($label) ?></label>
        <div class="control"><select class="select" id="f_<?= e($name) ?>" name="<?= e($name) ?>" data-autosubmit <?= $reset !== '' ? 'data-reset="' . e($reset) . '"' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
            <option value=""><?= e($placeholder) ?></option>
            <?php foreach ($rows as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $current === (int)$r['id'] ? 'selected' : '' ?>><?= e((string)$r['name']) ?></option><?php endforeach; ?>
        </select></div></div>
    <?php
};
?>
<form class="card filter-panel" method="get" action="<?= e($filterAction) ?>">
    <div class="att-form-grid">
        <div class="field"><label class="field__label" for="f_period">Report</label>
            <div class="control"><select class="select" id="f_period" name="period" data-att-period>
                <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly (Mon–Sun)', 'monthly' => 'Monthly', 'range' => 'Custom date range'] as $k => $l): ?>
                    <option value="<?= e($k) ?>" <?= $f['period'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?></select></div></div>
        <div class="field" data-att-single><label class="field__label" for="f_date">Date</label>
            <div class="control"><input class="input" type="date" id="f_date" name="date" value="<?= e($f['date']) ?>"></div></div>
        <div class="field" data-att-range hidden><label class="field__label" for="f_from">From</label>
            <div class="control"><input class="input" type="date" id="f_from" name="from" value="<?= e($f['from']) ?>"></div></div>
        <div class="field" data-att-range hidden><label class="field__label" for="f_to">To</label>
            <div class="control"><input class="input" type="date" id="f_to" name="to" value="<?= e($f['to']) ?>"></div></div>
        <div class="field"><label class="field__label" for="f_q">Employee</label>
            <div class="control"><input class="input" type="search" id="f_q" name="q" value="<?= e($f['q']) ?>" placeholder="Name, employee ID or CNIC"></div></div>
        <?php $sel('pool', 'Category', $filterOptions['pools'], $f['pool'], 'All categories'); ?>
        <?php $sel('region', 'Region', $filterOptions['regions'], $f['region'], 'All regions', 'district,tehsil,uc'); ?>
        <?php $sel('district', 'District', $filterOptions['districts'], $f['district'], $f['region'] > 0 ? 'All districts' : 'Pick a region first', 'tehsil,uc', $f['region'] === 0); ?>
        <?php $sel('tehsil', 'Tehsil', $filterOptions['tehsils'], $f['tehsil'], $f['district'] > 0 ? 'All tehsils' : 'Pick a district first', 'uc', $f['district'] === 0); ?>
        <?php $sel('uc', 'UC', $filterOptions['ucs'], $f['uc'], 'All UCs'); ?>
        <?php $sel('supervisor', 'Supervisor', $filterOptions['supervisors'], $f['supervisor'], 'All supervisors'); ?>
        <div class="field"><label class="field__label" for="f_status">Status</label>
            <div class="control"><select class="select" id="f_status" name="status">
                <option value="">All statuses</option>
                <?php foreach (att_status_labels() as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select></div></div>
        <div style="display:flex;gap:var(--space-2)"><button class="btn btn--primary" type="submit">Apply</button>
            <a class="btn btn--ghost" href="<?= e($filterAction) ?>">Reset</a></div>
    </div>
</form>
