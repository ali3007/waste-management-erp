<?php
/**
 * Administrative Structure — Region / District / Tehsil / UC.
 *
 * One interface for the whole hierarchy: summary cards at the top, a
 * breadcrumb showing where you are, the list of records at the current
 * level, and a shared modal (node-modal) that handles both "add" and
 * "edit" for whichever level you're looking at. Beat and Site join this
 * same interface in a later stage — everything here is level-generic on
 * purpose, driven by STRUCTURE_LEVELS in bootstrap.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('structure.view');

/* ------------------------------------------------------------------ *
 | Resolve where we are                                                |
 * ------------------------------------------------------------------ */
$level = query('level', 'region');
if (structure_level_config($level) === null) {
    $level = 'region';
}
$config = structure_level_config($level);

$parentId = null;
$parentRow = null;
$ancestors = [];

if ($config['parent_column'] !== null) {
    $parentId = (int)query('parent');
    if ($parentId <= 0) {
        redirect('modules/structure/index.php');
    }
    $ancestors = structure_ancestors($level, $parentId);
    if (empty($ancestors)) {
        flash('error', 'That record could not be found.');
        redirect('modules/structure/index.php');
    }
    $parentRow = end($ancestors)['row'];
}

$childLevels = structure_child_levels($level);
$codeColumn = $config['code_column'] ?? 'code';
$hasDescription = $config['has_description'] ?? true;
$parentActive = $parentRow === null || (string)$parentRow['status'] === 'active';

/* ------------------------------------------------------------------ *
 | List: search, status filter, pagination                             |
 * ------------------------------------------------------------------ */
$search = Security::cleanText(query('q'), 190);
$statusFilter = query('status');
$page = max(1, (int)query('page', '1'));
$perPage = 20;

$where = ['1=1'];
$params = [];

/* Every reference uses the `t.` alias, whether or not a join is present
   below — site_types has its own `name` column, so once Sites join it,
   a bare `name` in WHERE becomes ambiguous SQL. Aliasing consistently
   here avoids that regardless of which level is being viewed. */
if ($config['parent_column'] !== null) {
    $where[] = "t.`{$config['parent_column']}` = :parent_id";
    $params['parent_id'] = $parentId;
}
if ($search !== '') {
    $where[] = "(t.name LIKE :search OR t.`{$codeColumn}` LIKE :search)";
    $params['search'] = '%' . $search . '%';
}
if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = 't.status = :status';
    $params['status'] = $statusFilter;
}
$hasSiteType = !empty($config['has_site_type']);
if ($hasSiteType) {
    $typeFilter = (int)query('type');
    if ($typeFilter > 0) {
        $where[] = 't.site_type_id = :type_id';
        $params['type_id'] = $typeFilter;
    }
}
$whereSql = implode(' AND ', $where);

$total = (int)Database::fetchValue("SELECT COUNT(*) FROM `{$config['table']}` t WHERE {$whereSql}", $params);
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$rows = Database::fetchAll(
    $hasSiteType
        ? "SELECT t.*, st.name AS site_type_name FROM `{$config['table']}` t
             LEFT JOIN site_types st ON st.id = t.site_type_id
            WHERE {$whereSql} ORDER BY t.name ASC LIMIT {$perPage} OFFSET {$offset}"
        : "SELECT t.* FROM `{$config['table']}` t WHERE {$whereSql} ORDER BY t.name ASC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

/* Child-record counts per child level, so a row shows "3 Beats · 2 Sites"
   — one query per child level, not N+1 per row. */
$childCounts = [];
if (!empty($childLevels) && !empty($rows)) {
    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    foreach ($childLevels as $cl) {
        $childConfig = structure_level_config($cl);
        $countRows = Database::fetchAll(
            "SELECT `{$childConfig['parent_column']}` AS parent_id, COUNT(*) AS n
               FROM `{$childConfig['table']}` WHERE `{$childConfig['parent_column']}` IN ({$placeholders}) GROUP BY `{$childConfig['parent_column']}`",
            $ids
        );
        foreach ($countRows as $countRow) {
            $childCounts[$cl][(int)$countRow['parent_id']] = (int)$countRow['n'];
        }
    }
}

/* ------------------------------------------------------------------ *
 | Add / edit modal state                                              |
 * ------------------------------------------------------------------ */
$modalLevel = $level;
$modalConfig = $config;
$editingId = (int)query('edit');
$editingRow = null;
$modalOpen = false;

if ($editingId > 0) {
    $editingRow = structure_find($level, $editingId);
    if ($editingRow !== null) {
        $modalOpen = true;
    }
} elseif (query('add') === '1' && $parentActive) {
    $modalOpen = true;
}

$formName = old('name', (string)($editingRow['name'] ?? ''));
$formCode = old('code', (string)($editingRow[$codeColumn] ?? ''));
$formDescription = old('description', (string)($editingRow['description'] ?? ''));
$formUcNumber = old('uc_number', (string)($editingRow['uc_number'] ?? ''));
$formSiteTypeId = old('site_type_id', (string)($editingRow['site_type_id'] ?? ''));
$formLatitude = old('latitude', (string)($editingRow['latitude'] ?? ''));
$formLongitude = old('longitude', (string)($editingRow['longitude'] ?? ''));
$formAddress = old('address', (string)($editingRow['address'] ?? ''));
clear_old();

/** Build a link back into this same page, keeping level/parent and the
    active filters (q/status/type), with anything in $overrides winning. */
function structure_link(string $level, ?int $parentId, array $overrides = []): string
{
    $params = array_filter(array_merge([
        'level' => $level,
        'parent' => $parentId !== null ? (string)$parentId : null,
        'q' => query('q') ?: null,
        'status' => query('status') ?: null,
        'type' => query('type') ?: null,
    ], $overrides), static fn($v) => $v !== null && $v !== '');

    return base_url('modules/structure/index.php') . ($params ? '?' . http_build_query($params) : '');
}

$pageTitle = 'Administrative Structure';
$layout = 'app';
$extraStyles = ['structure.css'];
$extraScripts = ['structure.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<?php if ($level === 'region'): ?>
    <?php $counts = structure_counts(); ?>
    <div class="grid grid--3" style="margin-bottom:24px">
        <?php foreach ([
            'region' => 'Regions', 'district' => 'Districts', 'tehsil' => 'Tehsils',
            'uc' => 'UCs', 'beat' => 'Beats', 'site' => 'Sites',
        ] as $key => $label): ?>
            <div class="card card--flat">
                <div class="stat__label"><?= e($label) ?></div>
                <div class="stat__value"><?= e((string)$counts[$key]) ?></div>
                <?php if ($key === 'site'): ?>
                    <a href="<?= e(base_url('modules/structure/sites.php')) ?>" class="stat__meta">Browse all Sites →</a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<nav class="crumb" aria-label="Breadcrumb">
    <a href="<?= e(structure_link('region', null, ['q' => '', 'status' => '', 'type' => ''])) ?>">All regions</a>
    <?php foreach ($ancestors as $index => $ancestor): ?>
        <span class="crumb__sep">/</span>
        <?php
        /* Link to whichever level actually follows this ancestor on the
           path we're currently viewing — the next ancestor in the chain,
           or the current level if this is the immediate parent. Using
           the known path (not a generic "child of" lookup) is what keeps
           this correct now that a UC can lead to more than one level. */
        $nextLevel = $ancestors[$index + 1]['level'] ?? $level;
        ?>
        <a href="<?= e(structure_link($nextLevel, (int)$ancestor['row']['id'], ['q' => '', 'status' => '', 'type' => ''])) ?>">
            <?= e($ancestor['row']['name']) ?>
        </a>
    <?php endforeach; ?>
    <span class="crumb__sep">/</span>
    <span class="crumb__current"><?= e($config['plural']) ?></span>
    <?php if (!$parentActive): ?>
        <span class="crumb__inactive">Parent is inactive — new records disabled</span>
    <?php endif; ?>
</nav>

<div class="toolbar">
    <form class="toolbar__search" method="get" action="<?= e(base_url('modules/structure/index.php')) ?>">
        <div class="control">
            <span class="control__icon"><?= icon('search') ?></span>
            <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search <?= e(mb_strtolower($config['plural'])) ?>">
        </div>
        <input type="hidden" name="level" value="<?= e($level) ?>">
        <?php if ($parentId !== null): ?><input type="hidden" name="parent" value="<?= $parentId ?>"><?php endif; ?>
        <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
    </form>

    <div class="toolbar__filter">
        <div class="control">
            <select class="select" onchange="window.location.href=this.value">
                <option value="<?= e(structure_link($level, $parentId, ['status' => ''])) ?>">All statuses</option>
                <option value="<?= e(structure_link($level, $parentId, ['status' => 'active'])) ?>" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="<?= e(structure_link($level, $parentId, ['status' => 'inactive'])) ?>" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
    </div>

    <?php if ($hasSiteType): ?>
        <div class="toolbar__filter">
            <div class="control">
                <select class="select" onchange="window.location.href=this.value">
                    <option value="<?= e(structure_link($level, $parentId, ['type' => ''])) ?>">All site types</option>
                    <?php foreach (structure_site_types() as $type): ?>
                        <option value="<?= e(structure_link($level, $parentId, ['type' => (string)$type['id']])) ?>" <?= (int)query('type') === (int)$type['id'] ? 'selected' : '' ?>>
                            <?= e($type['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    <?php endif; ?>

    <div class="toolbar__spacer"></div>

    <?php if (can('structure.create')): ?>
        <?php if ($parentActive): ?>
            <a class="btn btn--primary" href="<?= e(structure_link($level, $parentId, ['add' => '1'])) ?>">
                <?= icon('plus') ?>
                <span class="btn__label">Add <?= e($config['label']) ?></span>
            </a>
        <?php else: ?>
            <button class="btn" disabled title="The parent record is inactive">
                <?= icon('plus') ?>
                <span class="btn__label">Add <?= e($config['label']) ?></span>
            </button>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (empty($rows)): ?>
    <div class="card">
        <div class="empty-state">
            <?= icon('layers') ?>
            <h3 style="margin-bottom:6px">No <?= e(mb_strtolower($config['plural'])) ?> yet</h3>
            <p style="margin:0">
                <?= $total === 0 && $search === '' && $statusFilter === ''
                    ? 'Add the first one using the button above.'
                    : 'Try a different search term or clear the filters.' ?>
            </p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($rows as $row): ?>
        <div class="role-card">
            <div>
                <div class="role-card__name"><?= e((string)$row['name']) ?></div>
                <div class="role-card__meta">
                    <?= e($config['code_label']) ?>: <?= e((string)$row[$codeColumn]) ?>
                    <?php if (!empty($config['has_uc_number']) && $row['uc_number']): ?>
                        · UC No. <?= e((string)$row['uc_number']) ?>
                    <?php endif; ?>
                    <?php if ($hasSiteType && !empty($row['site_type_name'])): ?>
                        · <?= e((string)$row['site_type_name']) ?>
                    <?php endif; ?>
                    <?php $mapLink = $hasSiteType ? structure_map_link($row['latitude'] ?? null, $row['longitude'] ?? null) : ''; ?>
                    <?php if ($mapLink !== ''): ?>
                        · <a href="<?= e($mapLink) ?>" target="_blank" rel="noopener">View on map</a>
                    <?php endif; ?>
                    <?php foreach ($childLevels as $cl): ?>
                        <?php $n = (int)($childCounts[$cl][(int)$row['id']] ?? 0); ?>
                        · <?= $n ?> <?= e($n === 1 ? structure_level_config($cl)['label'] : structure_level_config($cl)['plural']) ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <span class="badge <?= e(structure_status_badge((string)$row['status'])) ?>" style="margin-left:var(--space-3)">
                <?= e(structure_status_label((string)$row['status'])) ?>
            </span>
            <div class="role-card__spacer"></div>
            <div class="role-card__actions">
                <?php foreach ($childLevels as $cl): ?>
                    <a class="icon-btn" href="<?= e(structure_link($cl, (int)$row['id'], ['q' => '', 'status' => '', 'type' => ''])) ?>"
                       title="Open <?= e(structure_level_config($cl)['plural']) ?>"
                       aria-label="Open <?= e(structure_level_config($cl)['plural']) ?> for <?= e((string)$row['name']) ?>">
                        <?= icon('search') ?>
                    </a>
                <?php endforeach; ?>
                <?php if ($level === 'uc'): ?>
                    <a class="icon-btn" href="<?= e(base_url('modules/structure/uc-map.php?id=' . (int)$row['id'])) ?>"
                       title="Boundary map" aria-label="Boundary map for <?= e((string)$row['name']) ?>">
                        <?= icon('image') ?>
                    </a>
                <?php endif; ?>
                <?php if (can('structure.update')): ?>
                    <a class="icon-btn" href="<?= e(structure_link($level, $parentId, ['edit' => (string)$row['id']])) ?>" title="Edit" aria-label="Edit <?= e((string)$row['name']) ?>">
                        <?= icon('pencil') ?>
                    </a>
                <?php endif; ?>
                <?php if (can('structure.status')): ?>
                    <form method="post" action="<?= e(base_url('modules/structure/process/node-status.php')) ?>"
                          data-confirm="<?= $row['status'] === 'active' ? 'Deactivate' : 'Activate' ?> &quot;<?= e((string)$row['name']) ?>&quot;?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="level" value="<?= e($level) ?>">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <input type="hidden" name="return_level" value="<?= e($level) ?>">
                        <?php if ($parentId !== null): ?><input type="hidden" name="return_parent" value="<?= $parentId ?>"><?php endif; ?>
                        <input type="hidden" name="status" value="<?= $row['status'] === 'active' ? 'inactive' : 'active' ?>">
                        <button type="submit" class="icon-btn <?= $row['status'] === 'active' ? 'icon-btn--danger' : '' ?>"
                                title="<?= $row['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                            <?= icon($row['status'] === 'active' ? 'ban' : 'check') ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="is-current"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(structure_link($level, $parentId, ['page' => (string)$p, 'q' => $search, 'status' => $statusFilter])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<!-- Add / edit modal — same form for both, level-generic -->
<div class="modal <?= $modalOpen ? 'is-open' : '' ?>" id="node-modal">
    <div class="modal__dialog">
        <h3 class="card__title">
            <?= $editingRow !== null ? 'Edit ' . e($config['label']) : 'Add ' . e($config['label']) ?>
        </h3>
        <?php if ($parentRow !== null): ?>
            <p class="card__subtitle">Under <?= e((string)$parentRow['name']) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= e(base_url('modules/structure/process/node-save.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="level" value="<?= e($level) ?>">
            <?php if ($parentId !== null): ?><input type="hidden" name="parent_id" value="<?= $parentId ?>"><?php endif; ?>
            <?php if ($editingRow !== null): ?><input type="hidden" name="id" value="<?= (int)$editingRow['id'] ?>"><?php endif; ?>

            <?php field_input('name', $config['label'] . ' Name', ['required' => true, 'value' => $formName, 'maxlength' => 120]); ?>
            <?php field_input('code', $config['code_label'], ['required' => true, 'value' => $formCode, 'maxlength' => 30]); ?>
            <?php if (!empty($config['has_uc_number'])): ?>
                <?php field_input('uc_number', 'UC Number (optional)', ['value' => $formUcNumber, 'maxlength' => 20]); ?>
            <?php endif; ?>

            <?php if ($hasSiteType): ?>
                <div class="field">
                    <label class="field__label" for="site_type_id">Site Type <span class="field__required">*</span></label>
                    <div class="control">
                        <select class="select" id="site_type_id" name="site_type_id" required>
                            <option value="">Choose a type…</option>
                            <?php foreach (structure_site_types() as $type): ?>
                                <option value="<?= (int)$type['id'] ?>" <?= $formSiteTypeId === (string)$type['id'] ? 'selected' : '' ?>>
                                    <?= e($type['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="field__error"></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($config['has_coordinates'])): ?>
                <div class="field-row">
                    <?php field_input('latitude', 'Latitude (optional)', ['value' => $formLatitude, 'placeholder' => '-90 to 90', 'inputmode' => 'decimal']); ?>
                    <?php field_input('longitude', 'Longitude (optional)', ['value' => $formLongitude, 'placeholder' => '-180 to 180', 'inputmode' => 'decimal']); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($config['has_address'])): ?>
                <?php field_input('address', 'Address / Location description (optional)', ['value' => $formAddress, 'maxlength' => 255]); ?>
            <?php endif; ?>
            <?php if ($hasDescription): ?>
                <?php field_textarea('description', 'Description (optional)', ['value' => $formDescription, 'rows' => 2]); ?>
            <?php endif; ?>

            <div class="modal__actions">
                <a class="btn btn--ghost" href="<?= e(structure_link($level, $parentId)) ?>">Cancel</a>
                <button type="submit" class="btn btn--primary">
                    <span class="btn__label"><?= $editingRow !== null ? 'Save changes' : 'Create ' . e($config['label']) ?></span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
