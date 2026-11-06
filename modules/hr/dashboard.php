<?php
/**
 * HR Dashboard (spec sections 19–20): summary cards, type-wise counts and
 * five live charts. All numbers come from the database; the charts are
 * drawn client-side from the JSON embedded below (assets/js/hr-charts.js).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/stats.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('hr.dashboard');

$stats = hr_dashboard_stats();
$scoped = hr_accessible_uc_ids() !== null;

$listLink = static fn(array $q): string => base_url('modules/hr/employees.php') . ($q ? '?' . http_build_query($q) : '');

/* Chart datasets. UC/Beat charts show the 12 busiest; the full breakdown
   lives in Reports. */
$topN = 12;
$poolSeries = array_map(static fn($p) => ['label' => (string)$p['name'], 'value' => (int)$p['cnt']], $stats['pools']);
$typeSeries = [];
foreach ($stats['pools'] as $p) {
    foreach ($stats['types_by_pool'][(int)$p['id']] ?? [] as $t) {
        $typeSeries[] = ['label' => (string)$t['name'], 'value' => (int)$t['cnt'], 'group' => (string)$p['name']];
    }
}
usort($typeSeries, static fn($a, $b) => $b['value'] <=> $a['value']);
$typeSeries = array_slice($typeSeries, 0, 15);
$ucSeries = array_map(static fn($u) => ['label' => (string)$u['name'], 'value' => (int)$u['cnt'], 'group' => (string)$u['tehsil_name']], array_slice($stats['ucs'], 0, $topN));
$beatSeries = array_map(static fn($b) => ['label' => (string)$b['name'], 'value' => (int)$b['cnt'], 'group' => (string)$b['uc_name']], array_slice($stats['beats'], 0, $topN));
$statusSeries = [
    ['label' => 'Active', 'value' => $stats['active']],
    ['label' => 'Inactive', 'value' => $stats['inactive']],
];

$pageTitle = 'HR Dashboard';
$layout = 'app';
$extraScripts = ['hr-charts.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<?php if ($scoped): ?>
    <div class="alert alert--info" role="status">
        <?= icon('info') ?><span>These figures cover only employees currently assigned to your own UC(s).</span>
    </div>
<?php endif; ?>

<?php if ($stats['total'] === 0): ?>
    <div class="card">
        <div class="empty-state">
            <?= icon('users') ?>
            <h3 style="margin-bottom:6px">No employees yet</h3>
            <p style="margin:0 0 16px">The dashboard fills in as soon as employees exist.</p>
            <?php if (can('employee.create')): ?>
                <a class="btn btn--primary" href="<?= e(base_url('modules/hr/employee-create.php')) ?>"><?= icon('plus') ?><span class="btn__label">Add employee</span></a>
            <?php endif; ?>
            <?php if (can('hr.import')): ?>
                <a class="btn btn--ghost" href="<?= e(base_url('modules/hr/import.php')) ?>"><?= icon('upload') ?><span class="btn__label">Bulk import (CSV)</span></a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>

<!-- Summary cards -->
<div class="grid grid--4">
    <div class="card card--flat stat-card">
        <span class="stat-card__icon"><?= icon('users') ?></span>
        <div class="stat__label">Total Employees</div>
        <div class="stat__value"><?= number_format($stats['total']) ?></div>
        <div class="stat__meta">Everyone on the HR record</div>
        <a class="stat-card__link" href="<?= e($listLink([])) ?>" aria-label="View all employees"></a>
    </div>
    <div class="card card--flat stat-card">
        <span class="stat-card__icon"><?= icon('check-big') ?></span>
        <div class="stat__label">Active Employees</div>
        <div class="stat__value"><?= number_format($stats['active']) ?></div>
        <div class="stat__meta"><?= $stats['total'] > 0 ? round($stats['active'] / $stats['total'] * 100) : 0 ?>% of total</div>
        <a class="stat-card__link" href="<?= e($listLink(['status' => 'active'])) ?>" aria-label="View active employees"></a>
    </div>
    <div class="card card--flat stat-card stat-card--accent-danger">
        <span class="stat-card__icon"><?= icon('ban') ?></span>
        <div class="stat__label">Inactive Employees</div>
        <div class="stat__value"><?= number_format($stats['inactive']) ?></div>
        <div class="stat__meta">Deactivated, history kept</div>
        <a class="stat-card__link" href="<?= e($listLink(['status' => 'inactive'])) ?>" aria-label="View inactive employees"></a>
    </div>
    <div class="card card--flat stat-card stat-card--accent-info">
        <span class="stat-card__icon"><?= icon('layers') ?></span>
        <div class="stat__label">Assigned Employees</div>
        <div class="stat__value"><?= number_format($stats['assigned']) ?></div>
        <div class="stat__meta">Have a UC (and Beat)</div>
        <a class="stat-card__link" href="<?= e($listLink(['assign' => 'assigned'])) ?>" aria-label="View assigned employees"></a>
    </div>
    <div class="card card--flat stat-card stat-card--accent-warn">
        <span class="stat-card__icon"><?= icon('clock') ?></span>
        <div class="stat__label">Unassigned Employees</div>
        <div class="stat__value"><?= number_format($stats['unassigned']) ?></div>
        <div class="stat__meta"><?= $stats['no_assignment_needed'] > 0 ? number_format($stats['no_assignment_needed']) . ' of them need no assignment' : 'Waiting for a UC/Beat' ?></div>
        <a class="stat-card__link" href="<?= e($listLink(['assign' => 'unassigned'])) ?>" aria-label="View unassigned employees"></a>
    </div>

    <?php foreach ($stats['pools'] as $pool): ?>
        <?php if ($pool['status'] === 'inactive' && (int)$pool['cnt'] === 0) { continue; } ?>
        <div class="card card--flat stat-card">
            <span class="stat-card__icon"><?= icon('tag') ?></span>
            <div class="stat__label"><?= e((string)$pool['name']) ?></div>
            <div class="stat__value"><?= number_format((int)$pool['cnt']) ?></div>
            <div class="stat__meta">Staff pool</div>
            <a class="stat-card__link" href="<?= e($listLink(['pool' => (int)$pool['id']])) ?>" aria-label="View <?= e((string)$pool['name']) ?>"></a>
        </div>
    <?php endforeach; ?>
</div>

<!-- Type-wise counts -->
<h2 class="section-title">Employees by HR Type</h2>
<div class="grid grid--3">
    <?php foreach ($stats['pools'] as $pool): ?>
        <?php
        $types = $stats['types_by_pool'][(int)$pool['id']] ?? [];
        if (empty($types) && $pool['status'] === 'inactive') { continue; }
        $max = max(1, ...array_map(static fn($t) => (int)$t['cnt'], $types ?: [['cnt' => 1]]));
        ?>
        <section class="card pool-block">
            <div class="pool-block__head"><?= e((string)$pool['name']) ?> <span><?= number_format((int)$pool['cnt']) ?></span></div>
            <?php if (empty($types)): ?>
                <p class="card__subtitle" style="margin:0">No HR types in this pool yet.</p>
            <?php endif; ?>
            <?php foreach ($types as $type): ?>
                <a class="type-row" href="<?= e($listLink(['type' => (int)$type['id']])) ?>" style="color:inherit;text-decoration:none">
                    <span class="type-row__name" title="<?= e((string)$type['name']) ?>"><?= e((string)$type['name']) ?></span>
                    <span class="type-row__bar"><i style="width:<?= (int)round((int)$type['cnt'] / $max * 100) ?>%"></i></span>
                    <span class="type-row__count"><?= number_format((int)$type['cnt']) ?></span>
                </a>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</div>

<!-- Charts -->
<h2 class="section-title">Distribution</h2>
<div class="grid grid--2">
    <section class="card">
        <h3 class="card__title">Employees by Staff Pool</h3>
        <div class="chart" data-chart="donut" data-series="<?= e(json_encode($poolSeries, JSON_UNESCAPED_UNICODE)) ?>" data-center="Employees"></div>
    </section>
    <section class="card">
        <h3 class="card__title">Active vs Inactive</h3>
        <div class="chart" data-chart="donut" data-series="<?= e(json_encode($statusSeries)) ?>" data-center="Employees" data-colors="active,inactive"></div>
    </section>
    <section class="card" style="grid-column:1 / -1">
        <h3 class="card__title">Employees by HR Type</h3>
        <div class="chart" data-chart="bars" data-series="<?= e(json_encode($typeSeries, JSON_UNESCAPED_UNICODE)) ?>"></div>
        <?php if (count($typeSeries) >= 15): ?><p class="filter-hint">Showing the 15 largest types.</p><?php endif; ?>
    </section>
    <section class="card">
        <h3 class="card__title">UC-wise active employees</h3>
        <div class="chart" data-chart="bars" data-series="<?= e(json_encode($ucSeries, JSON_UNESCAPED_UNICODE)) ?>" data-empty="No employees are assigned to a UC yet."></div>
        <?php if (count($stats['ucs']) > $topN): ?><p class="filter-hint">Top <?= $topN ?> of <?= count($stats['ucs']) ?> UCs — see Reports for the full list.</p><?php endif; ?>
    </section>
    <section class="card">
        <h3 class="card__title">Beat-wise active employees</h3>
        <div class="chart" data-chart="bars" data-series="<?= e(json_encode($beatSeries, JSON_UNESCAPED_UNICODE)) ?>" data-empty="No employees are assigned to a Beat yet."></div>
        <?php if (count($stats['beats']) > $topN): ?><p class="filter-hint">Top <?= $topN ?> of <?= count($stats['beats']) ?> Beats — see Reports for the full list.</p><?php endif; ?>
    </section>
</div>
<p class="filter-hint">UC and Beat charts count each employee once per place they are currently assigned to, and include active employees only.</p>

<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
