<?php
/**
 * Transport — small view helpers (form controls, pickers, geography selects,
 * pagination). Markup only; no business logic and no data hard-coded.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** A labelled <select> built from id/name rows. */
function trn_field_select(string $id, string $label, array $rows, string|int|null $current, string $placeholder, array $o = []): void
{
    $name = $o['name'] ?? $id;
    $labelKey = $o['label_key'] ?? 'name';
    ?>
    <div class="field">
        <label class="field__label" for="<?= e($id) ?>"><?= e($label) ?><?= !empty($o['required']) ? ' <span class="field__required">*</span>' : '' ?></label>
        <div class="control">
            <select class="select" id="<?= e($id) ?>" name="<?= e($name) ?>" <?= !empty($o['required']) ? 'required' : '' ?>
                    <?= !empty($o['disabled']) ? 'disabled' : '' ?> <?= $o['attrs'] ?? '' ?>>
                <option value=""><?= e($placeholder) ?></option>
                <?php foreach ($rows as $row): ?>
                    <option value="<?= (int)$row['id'] ?>" <?= (string)$current === (string)$row['id'] ? 'selected' : '' ?>>
                        <?= e((string)$row[$labelKey]) ?><?= isset($row['status']) && $row['status'] === 'inactive' ? ' (inactive)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (!empty($o['hint'])): ?><p class="field__hint"><?= e($o['hint']) ?></p><?php endif; ?>
        <p class="field__error"></p>
    </div>
    <?php
}

/**
 * A search-as-you-type picker (vehicle or driver). Keeps a hidden id input;
 * transport.js fills the suggestions from process/lookup.php. Used instead of
 * a giant <select>, so it stays fast with thousands of vehicles.
 */
function trn_picker(string $kind, string $name, string $label, ?int $selectedId = null, string $selectedText = '', bool $required = true, string $hint = ''): void
{
    $id = 'pick_' . $name;
    ?>
    <div class="field">
        <label class="field__label" for="<?= e($id) ?>"><?= e($label) ?><?= $required ? ' <span class="field__required">*</span>' : '' ?></label>
        <div class="picker" data-picker data-kind="<?= e($kind) ?>" data-url="<?= e(base_url('modules/transport/process/lookup.php')) ?>">
            <div class="control">
                <span class="control__icon"><?= icon('search') ?></span>
                <input class="input" type="text" id="<?= e($id) ?>" autocomplete="off" value="<?= e($selectedText) ?>"
                       placeholder="<?= $kind === 'driver' ? 'Type a driver name, CNIC or employee ID…' : 'Type a vehicle number, tracker or RFID…' ?>"
                       role="combobox" aria-expanded="false" aria-autocomplete="list">
            </div>
            <input type="hidden" name="<?= e($name) ?>" value="<?= $selectedId ? (int)$selectedId : '' ?>" <?= $required ? 'data-required' : '' ?>>
            <ul class="picker__list" role="listbox" hidden></ul>
        </div>
        <?php if ($hint !== ''): ?><p class="field__hint"><?= e($hint) ?></p><?php endif; ?>
        <p class="field__error"></p>
    </div>
    <?php
}

/**
 * Region → District → Tehsil → UC → Beat selects for forms. Pre-selected
 * values render their option lists on the server (works without JavaScript on
 * first paint); changing a parent loads its children from process/cascade.php.
 *
 * @param array{region_id?:mixed,district_id?:mixed,tehsil_id?:mixed,uc_id?:mixed,beat_id?:mixed} $sel
 */
function trn_geo_fields(array $sel = [], bool $required = false): void
{
    $get = static fn(string $k): int => (int)($sel[$k] ?? 0);
    $region = $get('region_id'); $district = $get('district_id'); $tehsil = $get('tehsil_id'); $uc = $get('uc_id'); $beat = $get('beat_id');

    $regions = Database::fetchAll("SELECT id, name FROM regions WHERE status = 'active' ORDER BY name ASC");
    $districts = $region ? Database::fetchAll("SELECT id, name FROM districts WHERE region_id = :p AND status = 'active' ORDER BY name ASC", ['p' => $region]) : [];
    $tehsils = $district ? Database::fetchAll("SELECT id, name FROM tehsils WHERE district_id = :p AND status = 'active' ORDER BY name ASC", ['p' => $district]) : [];
    $ucSql = "SELECT id, name FROM ucs WHERE tehsil_id = :p AND status = 'active'";
    $scope = hr_accessible_uc_ids();
    if ($scope !== null) {
        $ucSql .= $scope ? ' AND id IN (' . implode(',', array_map('intval', $scope)) . ')' : ' AND 1 = 0';
    }
    $ucs = $tehsil ? Database::fetchAll($ucSql . ' ORDER BY name ASC', ['p' => $tehsil]) : [];
    $beats = $uc ? Database::fetchAll("SELECT id, name FROM beats WHERE uc_id = :p AND status = 'active' ORDER BY name ASC", ['p' => $uc]) : [];

    $levels = [
        ['region_id', 'Region', $regions, $region, 'Choose a region…', true],
        ['district_id', 'District', $districts, $district, $region ? 'Choose a district…' : 'Choose a region first…', true],
        ['tehsil_id', 'Tehsil', $tehsils, $tehsil, $district ? 'Choose a tehsil…' : 'Choose a district first…', true],
        ['uc_id', 'UC', $ucs, $uc, $tehsil ? 'Choose a UC…' : 'Choose a tehsil first…', true],
        ['beat_id', 'Beat (optional)', $beats, $beat, $uc ? 'No specific beat' : 'Choose a UC first…', false],
    ];
    $levelNames = ['region_id' => 'region', 'district_id' => 'district', 'tehsil_id' => 'tehsil', 'uc_id' => 'uc', 'beat_id' => 'beat'];
    ?>
    <div class="geo-fields" data-geo data-url="<?= e(base_url('modules/transport/process/cascade.php')) ?>">
        <div class="field-row">
            <?php foreach (array_slice($levels, 0, 2) as [$name, $label, $rows, $cur, $ph, $req]): ?>
                <?php trn_field_select('geo_' . $name, $label, $rows, $cur, $ph, ['name' => $name, 'required' => $required && $req,
                    'disabled' => $cur === 0 && $name !== 'region_id' && empty($rows), 'attrs' => 'data-geo-level="' . $levelNames[$name] . '"']); ?>
            <?php endforeach; ?>
        </div>
        <div class="field-row">
            <?php foreach (array_slice($levels, 2, 2) as [$name, $label, $rows, $cur, $ph, $req]): ?>
                <?php trn_field_select('geo_' . $name, $label, $rows, $cur, $ph, ['name' => $name, 'required' => $required && $req,
                    'disabled' => empty($rows), 'attrs' => 'data-geo-level="' . $levelNames[$name] . '"']); ?>
            <?php endforeach; ?>
        </div>
        <?php [$name, $label, $rows, $cur, $ph] = $levels[4]; ?>
        <?php trn_field_select('geo_' . $name, $label, $rows, $cur, $ph, ['name' => $name, 'disabled' => empty($rows), 'attrs' => 'data-geo-level="beat"']); ?>
    </div>
    <?php
}

/** Filter-panel geography selects (server-side cascade, submit on change). */
function trn_filter_select(string $name, string $label, array $rows, int|string $current, string $placeholder, string $reset = '', bool $disabled = false): void
{
    ?>
    <div class="field">
        <label class="field__label" for="f_<?= e($name) ?>"><?= e($label) ?></label>
        <div class="control">
            <select class="select" id="f_<?= e($name) ?>" name="<?= e($name) ?>" data-autosubmit <?= $reset !== '' ? 'data-reset="' . e($reset) . '"' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
                <option value=""><?= e($placeholder) ?></option>
                <?php foreach ($rows as $row): ?>
                    <option value="<?= (int)$row['id'] ?>" <?= (string)$current === (string)$row['id'] ? 'selected' : '' ?>>
                        <?= e((string)$row['name']) ?><?= !empty($row['employee_code']) ? ' (' . e((string)$row['employee_code']) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php
}

/** Numbered pagination links; $base is a URL that may already carry a query. */
function trn_pagination(array $pg, callable $urlFor): void
{
    if ($pg['pages'] <= 1) {
        return;
    }
    $page = $pg['page'];
    $from = max(1, $page - 3);
    $to = min($pg['pages'], $page + 3);
    ?>
    <nav class="pagination" aria-label="Pagination">
        <?php if ($from > 1): ?><a href="<?= e($urlFor(1)) ?>">1</a><?php if ($from > 2): ?><span>…</span><?php endif; ?><?php endif; ?>
        <?php for ($p = $from; $p <= $to; $p++): ?>
            <?php if ($p === $page): ?><span class="is-current"><?= $p ?></span><?php else: ?><a href="<?= e($urlFor($p)) ?>"><?= $p ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($to < $pg['pages']): ?><?php if ($to < $pg['pages'] - 1): ?><span>…</span><?php endif; ?><a href="<?= e($urlFor($pg['pages'])) ?>"><?= $pg['pages'] ?></a><?php endif; ?>
    </nav>
    <?php
}

/** Global Transport search box, shown at the top of list pages. */
function trn_search_bar(string $value = ''): void
{
    if (!can('transport.view')) {
        return;
    }
    ?>
    <form class="trn-search" method="get" action="<?= e(base_url('modules/transport/search.php')) ?>" role="search">
        <span class="control__icon"><?= icon('search') ?></span>
        <input class="input" type="search" name="q" value="<?= e($value) ?>" placeholder="Search vehicle no., tracker, RFID, chassis, engine, driver name or CNIC…" aria-label="Search Transport">
        <button type="submit" class="btn btn--ghost"><span class="btn__label">Search</span></button>
    </form>
    <?php
}

/** Warning card shown when a required list has no active entries yet. */
function trn_setup_notice(array $missing): void
{
    if (!$missing) {
        return;
    }
    ?>
    <div class="alert alert--warning" role="status">
        <?= icon('alert') ?>
        <span>Set up first: add at least one <?= e(implode(', ', array_map('strtolower', $missing))) ?>.
            <?php if (can('transport.config')): ?><a href="<?= e(base_url('modules/transport/config.php')) ?>">Open Transport Configuration</a>.<?php else: ?>Ask an administrator to configure them.<?php endif; ?></span>
    </div>
    <?php
}

/** Plain table for a report result (columns + rows). */
function trn_render_report_table(array $report): void
{
    if (!$report['rows']) {
        ?><div class="card"><div class="empty-state"><?= icon('file-text') ?><h3 style="margin-bottom:6px">No data</h3><p style="margin:0">Nothing matches these filters.</p></div></div><?php
        return;
    }
    ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><?php foreach ($report['columns'] as $c): ?><th><?= e((string)$c) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($report['rows'] as $row): ?>
            <tr><?php foreach ($row as $i => $cell): ?>
                <?php $is = is_int($cell) || is_float($cell) || (is_string($cell) && $cell !== '' && is_numeric($cell) && !preg_match('/^0\d/', $cell) && strlen($cell) < 13); ?>
                <td class="<?= $is ? 'trn-money' : 'is-wrap' ?>"><?= e((string)$cell) ?></td>
            <?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($report['note'] !== ''): ?><p class="filter-hint"><?= e($report['note']) ?></p><?php endif;
}
