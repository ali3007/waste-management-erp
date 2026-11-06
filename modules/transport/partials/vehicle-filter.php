<?php
/**
 * Shared vehicle filter panel (Vehicles list, Dashboard, Reports).
 * Expects: $f (trn_read_vehicle_filters), $filterAction (absolute URL).
 * Optional: $filterDates (bool) adds a date range; $filterDocTypes (bool);
 *           $filterIdAttr (form id); $filterShowSearch (default true);
 *           $filterShowArchive (default false); $filterShowDeployment (default false).
 * Dependent geography dropdowns re-render on the server, exactly like HR.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

$geo = trn_geo_options($f);
$types = Database::fetchAll('SELECT id, name FROM vehicle_types ORDER BY name ASC');
$statuses = Database::fetchAll('SELECT id, name FROM vehicle_statuses ORDER BY name ASC');
$owners = Database::fetchAll('SELECT id, name FROM vehicle_ownership_types ORDER BY name ASC');
$drivers = trn_driver_filter_options();
$showSearch = $filterShowSearch ?? true;
$activeCount = count(trn_active_vehicle_filters($f)) + (!empty($f['date_from']) ? 1 : 0) + (!empty($f['date_to']) ? 1 : 0) + (!empty($f['doc_type']) ? 1 : 0);
?>
<form class="card filter-panel trn-filters" method="get" action="<?= e($filterAction) ?>" id="<?= e($filterIdAttr ?? 'trn-filter-form') ?>">
    <?php if (!empty($filterKeep)): foreach ($filterKeep as $k => $v): ?><input type="hidden" name="<?= e((string)$k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; endif; ?>
    <div class="filter-panel__grid">
        <?php if ($showSearch): ?>
        <div class="field filter-panel__search">
            <label class="field__label" for="f_q">Search</label>
            <div class="control"><span class="control__icon"><?= icon('search') ?></span>
                <input class="input" type="search" id="f_q" name="q" value="<?= e($f['q']) ?>" placeholder="Vehicle no., tracker, RFID, chassis, engine, driver…"></div>
        </div>
        <?php endif; ?>
        <?php trn_filter_select('type', 'Vehicle type', $types, $f['type'], 'All types'); ?>
        <?php trn_filter_select('status', 'Status', $statuses, $f['status'], 'All statuses'); ?>
        <?php if (!empty($filterShowOwnership)): trn_filter_select('ownership', 'Ownership', $owners, $f['ownership'], 'All ownership types'); endif; ?>
        <?php trn_filter_select('region', 'Region', $geo['regions'], $f['region'], 'All regions', 'district,tehsil,uc,beat'); ?>
        <?php trn_filter_select('district', 'District', $geo['districts'], $f['district'], $f['region'] > 0 ? 'All districts' : 'Pick a region first', 'tehsil,uc,beat', $f['region'] === 0); ?>
        <?php trn_filter_select('tehsil', 'Tehsil', $geo['tehsils'], $f['tehsil'], $f['district'] > 0 ? 'All tehsils' : 'Pick a district first', 'uc,beat', $f['district'] === 0); ?>
        <?php trn_filter_select('uc', 'UC', $geo['ucs'], $f['uc'], $f['tehsil'] > 0 ? 'All UCs' : 'Pick a tehsil first', 'beat', $f['tehsil'] === 0); ?>
        <?php if (!empty($filterShowBeat)): trn_filter_select('beat', 'Beat', $geo['beats'], $f['beat'], $f['uc'] > 0 ? 'All beats' : 'Pick a UC first', '', $f['uc'] === 0); endif; ?>
        <?php trn_filter_select('driver', 'Driver', $drivers, $f['driver'], 'Any driver'); ?>

        <?php if (!empty($filterShowDeployment)): ?>
        <div class="field"><label class="field__label" for="f_deployed">Deployment</label>
            <div class="control"><select class="select" id="f_deployed" name="deployed" data-autosubmit>
                <option value="">Deployed &amp; not deployed</option>
                <option value="yes" <?= $f['deployed'] === 'yes' ? 'selected' : '' ?>>Deployed</option>
                <option value="no" <?= $f['deployed'] === 'no' ? 'selected' : '' ?>>Not deployed</option></select></div></div>
        <div class="field"><label class="field__label" for="f_has_driver">Driver</label>
            <div class="control"><select class="select" id="f_has_driver" name="has_driver" data-autosubmit>
                <option value="">With &amp; without a driver</option>
                <option value="yes" <?= $f['has_driver'] === 'yes' ? 'selected' : '' ?>>With a driver</option>
                <option value="no" <?= $f['has_driver'] === 'no' ? 'selected' : '' ?>>Without a driver</option></select></div></div>
        <?php endif; ?>
        <?php if (!empty($filterShowArchive)): ?>
        <div class="field"><label class="field__label" for="f_archived">Archived vehicles</label>
            <div class="control"><select class="select" id="f_archived" name="archived" data-autosubmit>
                <option value="">Hide archived</option>
                <option value="include" <?= $f['archived'] === 'include' ? 'selected' : '' ?>>Include archived</option>
                <option value="only" <?= $f['archived'] === 'only' ? 'selected' : '' ?>>Archived only</option></select></div></div>
        <?php endif; ?>
        <?php if (!empty($filterDates)): ?>
        <div class="field"><label class="field__label" for="f_date_from">From date</label>
            <div class="control"><input class="input" type="date" id="f_date_from" name="date_from" value="<?= e((string)($f['date_from'] ?? '')) ?>"></div></div>
        <div class="field"><label class="field__label" for="f_date_to">To date</label>
            <div class="control"><input class="input" type="date" id="f_date_to" name="date_to" value="<?= e((string)($f['date_to'] ?? '')) ?>"></div></div>
        <?php endif; ?>
        <?php if (!empty($filterDocStatus)): ?>
        <div class="field"><label class="field__label" for="f_doc_status">Document status</label>
            <div class="control"><select class="select" id="f_doc_status" name="doc_status" data-autosubmit>
                <option value="">Any status</option>
                <?php foreach (['valid' => 'Valid', 'expiring' => 'Expiring soon', 'expired' => 'Expired', 'none' => 'No expiry'] as $k => $l): ?>
                    <option value="<?= e($k) ?>" <?= ($docStatusSel ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?></select></div></div>
        <?php endif; ?>
        <?php if (!empty($filterDocTypes)): trn_filter_select('doc_type', 'Document type', Database::fetchAll('SELECT id, name FROM vehicle_document_types ORDER BY name ASC'), (int)($f['doc_type'] ?? 0), 'All document types'); endif; ?>
    </div>
    <div class="filter-panel__actions">
        <button type="submit" class="btn btn--primary"><?= icon('search') ?><span class="btn__label">Apply filters</span></button>
        <?php if ($activeCount > 0): ?><a class="btn btn--ghost" href="<?= e($filterAction . (!empty($filterKeep) ? trn_qs((array)$filterKeep) : '')) ?>"><span class="btn__label">Clear all</span></a><?php endif; ?>
    </div>
    <p class="filter-hint"><?= e($filterHelp ?? 'Region to Beat match where the vehicle is deployed now. Every filter you set must match.') ?></p>
</form>
