<?php
/**
 * Shared employee filter panel (All Employees + Reports).
 *
 * Expects: $filters (hr_read_filters), $filterAction (absolute URL),
 * $filterOptions (hr_filter_options). Dependent dropdowns re-render
 * server-side: changing a parent clears its children and submits.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** One <select> of id/name rows. */
$hrSelect = static function (string $name, string $label, array $rows, int|string $current, string $placeholder, string $reset = '', bool $disabled = false) use ($filters): void {
    ?>
    <div class="field">
        <label class="field__label" for="f_<?= e($name) ?>"><?= e($label) ?></label>
        <div class="control">
            <select class="select" id="f_<?= e($name) ?>" name="<?= e($name) ?>" data-autosubmit
                    <?= $reset !== '' ? 'data-reset="' . e($reset) . '"' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
                <option value=""><?= e($placeholder) ?></option>
                <?php foreach ($rows as $row): ?>
                    <option value="<?= (int)$row['id'] ?>" <?= (string)$current === (string)$row['id'] ? 'selected' : '' ?>>
                        <?= e((string)$row['name']) ?><?= isset($row['tehsil_name']) && $filters['tehsil'] === 0 ? ' — ' . e((string)$row['tehsil_name']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php
};
?>
<form class="card filter-panel" method="get" action="<?= e($filterAction) ?>" id="hr-filter-form">
    <div class="filter-panel__grid">
        <div class="field filter-panel__search">
            <label class="field__label" for="f_q">Search</label>
            <div class="control">
                <span class="control__icon"><?= icon('search') ?></span>
                <input class="input" type="search" id="f_q" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, CNIC, employee ID or phone">
            </div>
        </div>

        <?php $hrSelect('pool', 'Staff Pool', $filterOptions['pools'], $filters['pool'], 'All pools', 'type'); ?>
        <?php $hrSelect('type', 'Staff Type', $filterOptions['types'], $filters['type'], 'All types'); ?>
        <?php $hrSelect('region', 'Region', $filterOptions['regions'], $filters['region'], 'All regions', 'district,tehsil,uc,beat'); ?>
        <?php $hrSelect('district', 'District', $filterOptions['districts'], $filters['district'], $filters['region'] > 0 ? 'All districts' : 'Pick a region first', 'tehsil,uc,beat', $filters['region'] === 0); ?>
        <?php $hrSelect('tehsil', 'Tehsil', $filterOptions['tehsils'], $filters['tehsil'], $filters['district'] > 0 ? 'All tehsils' : 'Pick a district first', 'uc,beat', $filters['district'] === 0); ?>
        <?php if (empty($filterHideUcBeat)): ?>
            <?php $hrSelect('uc', 'UC (current assignment)', $filterOptions['ucs'], $filters['uc'], 'All UCs', 'beat'); ?>
            <?php $hrSelect('beat', 'Beat (current assignment)', $filterOptions['beats'], $filters['beat'], $filters['uc'] > 0 ? 'All beats' : 'Pick a UC first', '', $filters['uc'] === 0); ?>
        <?php endif; ?>

        <div class="field">
            <label class="field__label" for="f_status">Employee Status</label>
            <div class="control">
                <select class="select" id="f_status" name="status" data-autosubmit>
                    <option value="">All statuses</option>
                    <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>

        <?php if (empty($filterHideAssign)): ?>
        <div class="field">
            <label class="field__label" for="f_assign">Assignment</label>
            <div class="control">
                <select class="select" id="f_assign" name="assign" data-autosubmit>
                    <option value="">Assigned &amp; unassigned</option>
                    <option value="assigned" <?= $filters['assign'] === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                    <option value="unassigned" <?= $filters['assign'] === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                </select>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="filter-panel__actions">
        <button type="submit" class="btn btn--primary"><?= icon('search') ?><span class="btn__label">Apply filters</span></button>
        <?php if (!empty(hr_active_filters($filters))): ?>
            <a class="btn btn--ghost" href="<?= e($filterAction) ?>"><span class="btn__label">Clear all</span></a>
        <?php endif; ?>
    </div>
    <?php if (!empty($filterOptions['ucs_truncated'])): ?>
        <p class="filter-hint">Only the first 500 UCs are listed — choose a Region, District or Tehsil to narrow the UC list.</p>
    <?php endif; ?>
    <p class="filter-hint"><?= e($filterHelp ?? 'Region, District and Tehsil match where the employee is registered; UC and Beat match their current assignment. Every filter you set must match.') ?></p>
</form>
