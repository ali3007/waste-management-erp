<?php
/**
 * HR → Reports (spec section 21).
 *
 * Filters combine with AND and drive everything on the page: the headline
 * total, the eight count breakdowns and the matching employee list. The
 * same filters feed the CSV exports (report-export.php), so what is on
 * screen is what is downloaded.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('hr.reports');

$filters = hr_read_filters($_GET);
[$whereSql, $params] = hr_filter_sql($filters);

$total = (int)Database::fetchValue("SELECT COUNT(*) FROM employees e WHERE {$whereSql}", $params);
$breakdowns = hr_report_breakdowns($filters);
$titles = hr_report_titles();

$page = max(1, (int)query('page', '1'));
$perPage = 25;
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$rows = Database::fetchAll(hr_employee_rows_sql($whereSql, 'e.name ASC, e.id ASC', 'LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)), $params);

$filterOptions = hr_filter_options($filters);
$filterAction = base_url('modules/hr/reports.php');
$canExport = can('hr.reports_export');
$exportLink = static fn(string $type): string => base_url('modules/hr/report-export.php') . hr_filter_query($filters, ['type' => $type]);
$hasFilters = !empty(hr_active_filters($filters));
$unassignedCount = (int)Database::fetchValue(
    "SELECT COUNT(*) FROM employees e WHERE {$whereSql} AND NOT EXISTS (SELECT 1 FROM employee_assignments x WHERE x.employee_id = e.id AND x.unassigned_at IS NULL)",
    $params
);

$pageTitle = 'HR Reports';
$layout = 'app';
$extraScripts = ['hr.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="toolbar no-print">
    <div class="toolbar__spacer" style="margin-left:0"></div>
    <button type="button" class="btn btn--ghost" onclick="window.print()"><?= icon('printer') ?><span class="btn__label">Print</span></button>
    <?php if ($canExport): ?>
        <a class="btn" href="<?= e($exportLink('summary')) ?>"><?= icon('download') ?><span class="btn__label">Export summary (CSV)</span></a>
        <a class="btn btn--primary" href="<?= e($exportLink('employees')) ?>"><?= icon('download') ?><span class="btn__label">Export employee list (CSV)</span></a>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/filter-form.php'; ?>

<div class="grid grid--3" style="margin-bottom:var(--space-4)">
    <div class="card card--flat stat-card">
        <span class="stat-card__icon"><?= icon('users') ?></span>
        <div class="stat__label"><?= $hasFilters ? 'Employees matching filters' : 'Total employees' ?></div>
        <div class="stat__value"><?= number_format($total) ?></div>
        <div class="stat__meta"><?= $hasFilters ? 'All filters must match' : 'No filters applied' ?></div>
    </div>
    <div class="card card--flat stat-card stat-card--accent-info">
        <span class="stat-card__icon"><?= icon('layers') ?></span>
        <div class="stat__label">Assigned</div>
        <div class="stat__value"><?= number_format($total - $unassignedCount) ?></div>
        <div class="stat__meta">Currently have a UC</div>
    </div>
    <div class="card card--flat stat-card stat-card--accent-warn">
        <span class="stat-card__icon"><?= icon('clock') ?></span>
        <div class="stat__label">Unassigned</div>
        <div class="stat__value"><?= number_format($unassignedCount) ?></div>
        <div class="stat__meta">No current UC/Beat</div>
    </div>
</div>

<?php if ($total === 0): ?>
    <div class="card"><div class="empty-state">
        <?= icon('search') ?>
        <h3 style="margin-bottom:6px">No employees match these filters</h3>
        <p style="margin:0">Loosen one of the filters to see results.</p>
    </div></div>
<?php else: ?>

    <h2 class="section-title">Employee counts</h2>
    <div class="grid grid--2">
        <?php foreach ($titles as $key => $title): ?>
            <?php $list = $breakdowns[$key] ?? []; ?>
            <section class="card">
                <h3 class="card__title">By <?= e($title) ?></h3>
                <?php if (empty($list)): ?>
                    <p class="card__subtitle" style="margin:0">
                        <?= in_array($key, ['uc', 'beat'], true) ? 'No matching employees have a current ' . ($key === 'uc' ? 'UC' : 'Beat') . ' assignment.' : 'Nothing to show.' ?>
                    </p>
                <?php else: ?>
                    <?php $max = max(1, ...array_column($list, 'count')); ?>
                    <div style="max-height:340px;overflow:auto">
                        <?php foreach ($list as $item): ?>
                            <?php $href = $filterAction . hr_filter_query($filters, array_merge($item['link'], ['page' => ''])); ?>
                            <a class="type-row" href="<?= e($href) ?>" style="color:inherit;text-decoration:none">
                                <span class="type-row__name" style="flex-basis:46%" title="<?= e($item['label'] . ($item['sub'] !== '' ? ' — ' . $item['sub'] : '')) ?>">
                                    <?= e($item['label']) ?><?php if ($item['sub'] !== ''): ?> <small style="color:var(--text-faint)">· <?= e($item['sub']) ?></small><?php endif; ?>
                                </span>
                                <span class="type-row__bar"><i style="width:<?= (int)round($item['count'] / $max * 100) ?>%"></i></span>
                                <span class="type-row__count"><?= number_format($item['count']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($list) > 8): ?><p class="filter-hint"><?= count($list) ?> rows — scroll inside the box.</p><?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>

    <h2 class="section-title">Matching employees</h2>
    <div class="table-meta">
        <span><?= number_format($total) ?> employee<?= $total === 1 ? '' : 's' ?></span>
        <span>Page <?= $page ?> of <?= $totalPages ?></span>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>ID</th><th>Employee</th><th>CNIC</th><th>Pool</th><th>Type</th><th>Region</th><th>District</th><th>Tehsil</th><th>Current assignment</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e((string)$row['employee_code']) ?></td>
                        <td class="is-wrap"><a href="<?= e(base_url('modules/hr/employee-view.php?id=' . (int)$row['id'])) ?>"><strong><?= e((string)$row['name']) ?></strong></a></td>
                        <td><?= e(Security::formatCnic((string)$row['cnic'])) ?></td>
                        <td><?= e((string)$row['pool_name']) ?></td>
                        <td><?= e((string)$row['type_name']) ?></td>
                        <td><?= e((string)$row['region_name']) ?></td>
                        <td><?= e((string)$row['district_name']) ?></td>
                        <td><?= e((string)$row['tehsil_name']) ?></td>
                        <td class="is-wrap"><?= $row['is_assigned'] ? e((string)$row['assignment_text']) : ($row['assignment_mode'] === 'none' ? '<span class="cell-sub">Not needed</span>' : 'Unassigned') ?></td>
                        <td><span class="badge <?= e(hr_status_badge((string)$row['status'])) ?>"><?= e(hr_status_label((string)$row['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
            <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
                <?php if ($p === $page): ?><span class="is-current"><?= $p ?></span>
                <?php else: ?><a href="<?= e($filterAction . hr_filter_query($filters, ['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
