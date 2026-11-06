<?php
/**
 * All Sites — a cross-hierarchy browser.
 *
 * index.php only ever shows one UC's Sites at a time, by design (that's
 * what keeps "a Site from one UC must never appear under another" true
 * by construction, not just validation). But the spec also asks to
 * filter Sites by Site Type — a question that spans every UC — which a
 * pure drill-down can't answer. This page is that: search, and narrow
 * by Region → District → Tehsil → UC and/or Site Type and Status, any
 * combination. Editing still happens through index.php's existing
 * modal; this page only searches and links there.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('structure.view');

$regionId = (int)query('region');
$districtId = (int)query('district');
$tehsilId = (int)query('tehsil');
$ucId = (int)query('uc');
$typeId = (int)query('type');
$statusFilter = query('status');
$search = Security::cleanText(query('q'), 190);
$page = max(1, (int)query('page', '1'));
$perPage = 20;

/* Cascading dropdown data — each level's options depend on the one
   above it actually being selected. */
$regions = Database::fetchAll('SELECT id, name FROM regions ORDER BY name ASC');
$districts = $regionId > 0
    ? Database::fetchAll('SELECT id, name FROM districts WHERE region_id = :r ORDER BY name ASC', ['r' => $regionId])
    : [];
$tehsils = $districtId > 0
    ? Database::fetchAll('SELECT id, name FROM tehsils WHERE district_id = :d ORDER BY name ASC', ['d' => $districtId])
    : [];
$ucs = $tehsilId > 0
    ? Database::fetchAll('SELECT id, name FROM ucs WHERE tehsil_id = :t ORDER BY name ASC', ['t' => $tehsilId])
    : [];
$siteTypes = structure_site_types();

/* Build the query — join all the way up so a Region-only filter still
   reaches every Site under it, not just direct children. */
$where = ['1=1'];
$params = [];

if ($ucId > 0) {
    $where[] = 's.uc_id = :uc';
    $params['uc'] = $ucId;
} elseif ($tehsilId > 0) {
    $where[] = 't.id = :tehsil';
    $params['tehsil'] = $tehsilId;
} elseif ($districtId > 0) {
    $where[] = 'd.id = :district';
    $params['district'] = $districtId;
} elseif ($regionId > 0) {
    $where[] = 'r.id = :region';
    $params['region'] = $regionId;
}
if ($typeId > 0) {
    $where[] = 's.site_type_id = :type';
    $params['type'] = $typeId;
}
if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = 's.status = :status';
    $params['status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(s.name LIKE :search OR s.code LIKE :search)';
    $params['search'] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

$fromSql = 'FROM sites s
    JOIN ucs u ON u.id = s.uc_id
    JOIN tehsils t ON t.id = u.tehsil_id
    JOIN districts d ON d.id = t.district_id
    JOIN regions r ON r.id = d.region_id
    LEFT JOIN site_types st ON st.id = s.site_type_id';

$total = (int)Database::fetchValue("SELECT COUNT(*) {$fromSql} WHERE {$whereSql}", $params);
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$rows = Database::fetchAll(
    "SELECT s.*, st.name AS site_type_name, u.name AS uc_name, t.name AS tehsil_name, d.name AS district_name, r.name AS region_name
     {$fromSql} WHERE {$whereSql} ORDER BY s.name ASC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

/** Keep every current filter, override just what's passed. */
function sites_link(array $overrides = []): string
{
    $current = [
        'region' => query('region'), 'district' => query('district'), 'tehsil' => query('tehsil'),
        'uc' => query('uc'), 'type' => query('type'), 'status' => query('status'), 'q' => query('q'),
    ];
    $params = array_filter(array_merge($current, $overrides), static fn($v) => $v !== null && $v !== '');
    return base_url('modules/structure/sites.php') . ($params ? '?' . http_build_query($params) : '');
}

$pageTitle = 'All Sites';
$layout = 'app';
$extraStyles = ['structure.css'];

require INCLUDES_PATH . '/header.php';
?>

<a class="btn btn--ghost" href="<?= e(base_url('modules/structure/index.php')) ?>" style="margin-bottom:16px">
    <?= icon('arrow-left') ?>
    <span class="btn__label">Back to Structure</span>
</a>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="card" style="margin-bottom:20px">
    <h3 class="card__title">Filter Sites across the whole hierarchy</h3>
    <p class="card__subtitle">Narrow by any combination. Selecting a Region limits the Districts offered, and so on down the chain.</p>

    <form method="get" action="<?= e(base_url('modules/structure/sites.php')) ?>">
        <div class="field-row">
            <div class="field">
                <label class="field__label" for="region">Region</label>
                <div class="control">
                    <select class="select" id="region" name="region" onchange="this.form.submit()">
                        <option value="">All regions</option>
                        <?php foreach ($regions as $r): ?>
                            <option value="<?= (int)$r['id'] ?>" <?= $regionId === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="district">District</label>
                <div class="control">
                    <select class="select" id="district" name="district" onchange="this.form.submit()" <?= empty($districts) ? 'disabled' : '' ?>>
                        <option value="">All districts</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $districtId === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="field__label" for="tehsil">Tehsil</label>
                <div class="control">
                    <select class="select" id="tehsil" name="tehsil" onchange="this.form.submit()" <?= empty($tehsils) ? 'disabled' : '' ?>>
                        <option value="">All tehsils</option>
                        <?php foreach ($tehsils as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $tehsilId === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="uc">UC</label>
                <div class="control">
                    <select class="select" id="uc" name="uc" onchange="this.form.submit()" <?= empty($ucs) ? 'disabled' : '' ?>>
                        <option value="">All UCs</option>
                        <?php foreach ($ucs as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= $ucId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="field__label" for="type">Site Type</label>
                <div class="control">
                    <select class="select" id="type" name="type" onchange="this.form.submit()">
                        <option value="">All types</option>
                        <?php foreach ($siteTypes as $st): ?>
                            <option value="<?= (int)$st['id'] ?>" <?= $typeId === (int)$st['id'] ? 'selected' : '' ?>><?= e($st['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="status">Status</label>
                <div class="control">
                    <select class="select" id="status" name="status" onchange="this.form.submit()">
                        <option value="">All statuses</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="field">
            <label class="field__label" for="q">Search by name or Site Number</label>
            <div class="control">
                <span class="control__icon"><?= icon('search') ?></span>
                <input class="input" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Type and press Enter">
            </div>
        </div>

        <div style="display:flex; gap:10px; margin-top:8px">
            <button type="submit" class="btn btn--primary"><span class="btn__label">Apply filters</span></button>
            <a class="btn btn--ghost" href="<?= e(base_url('modules/structure/sites.php')) ?>">Clear all</a>
        </div>
    </form>
</div>

<p class="card__subtitle"><?= e((string)$total) ?> site<?= $total === 1 ? '' : 's' ?> found</p>

<?php if (empty($rows)): ?>
    <div class="card">
        <div class="empty-state">
            <?= icon('layers') ?>
            <h3 style="margin-bottom:6px">No sites match these filters</h3>
            <p style="margin:0">Clear a filter, or add sites from inside a UC's Sites list.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($rows as $row): ?>
        <div class="role-card">
            <div>
                <div class="role-card__name"><?= e((string)$row['name']) ?></div>
                <div class="role-card__meta">
                    Site Number: <?= e((string)$row['code']) ?> · <?= e((string)($row['site_type_name'] ?? '—')) ?>
                    <br><?= e((string)$row['region_name']) ?> / <?= e((string)$row['district_name']) ?> / <?= e((string)$row['tehsil_name']) ?> / <?= e((string)$row['uc_name']) ?>
                </div>
            </div>
            <span class="badge <?= e(structure_status_badge((string)$row['status'])) ?>" style="margin-left:var(--space-3)">
                <?= e(structure_status_label((string)$row['status'])) ?>
            </span>
            <div class="role-card__spacer"></div>
            <div class="role-card__actions">
                <a class="icon-btn" href="<?= e(base_url('modules/structure/site-view.php?id=' . (int)$row['id'])) ?>" title="View">
                    <?= icon('search') ?>
                </a>
                <?php if (can('structure.update')): ?>
                    <a class="icon-btn" href="<?= e(base_url('modules/structure/index.php') . '?' . http_build_query(['level' => 'site', 'parent' => (int)$row['uc_id'], 'edit' => (int)$row['id']])) ?>" title="Edit">
                        <?= icon('pencil') ?>
                    </a>
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
                    <a href="<?= e(sites_link(['page' => (string)$p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
