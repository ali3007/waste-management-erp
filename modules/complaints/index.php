<?php
/**
 * Complaints Dashboard.
 *
 * Summary cards, six charts and the complaint listing — all driven by the
 * same filters, from live database values, and limited to the user's UC
 * scope. Changing a filter or clicking a chart re-queries
 * process/dashboard-data.php; the page never reloads.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('complaint.view');

$filters = cmp_read_filters($_GET);
$filterMode = 'dashboard';
$filterAction = base_url('modules/complaints/index.php');
$configured = (int)Database::fetchValue('SELECT COUNT(*) FROM complaint_sources') > 0
    && (int)Database::fetchValue('SELECT COUNT(*) FROM complaint_types') > 0
    && (int)Database::fetchValue('SELECT COUNT(*) FROM complaint_priorities') > 0;
$totalAll = (int)Database::fetchValue('SELECT COUNT(*) FROM complaints c WHERE c.deleted_at IS NULL' . (cmp_scope_sql('c') !== '' ? ' AND ' . cmp_scope_sql('c') : ''));

$pageTitle = 'Complaints dashboard';
$layout = 'app';
$extraScripts = ['complaints.js', 'vendor/chartjs/chart.umd.min.js', 'complaint-dashboard.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="toolbar no-print">
    <div class="toolbar__spacer" style="margin-left:0"></div>
    <a class="btn btn--ghost" href="<?= e(base_url('modules/complaints/complaints.php')) ?>" data-reports-link><?= icon('file-text') ?><span class="btn__label">Complaints list &amp; reports</span></a>
    <?php if (can('complaint.create')): ?>
        <a class="btn btn--primary" href="<?= e(base_url('modules/complaints/complaint-create.php')) ?>"><?= icon('plus') ?><span class="btn__label">Add complaint</span></a>
    <?php endif; ?>
</div>

<?php if (!$configured && can('complaint.config')): ?>
    <div class="alert alert--info"><?= icon('info') ?><span>Set up Complaint Sources, Types and Priorities before creating complaints. <a href="<?= e(base_url('modules/complaints/config.php')) ?>">Open configuration</a></span></div>
<?php endif; ?>

<?php if (cmp_is_scoped()): ?>
    <div class="alert alert--info" role="status"><?= icon('info') ?><span>You see only complaints of the UC(s) you are assigned to.</span></div>
<?php endif; ?>

<?php require __DIR__ . '/partials/filter-form.php'; ?>

<div id="cmp-dashboard" data-url="<?= e(base_url('modules/complaints/process/dashboard-data.php')) ?>" data-reports-url="<?= e(base_url('modules/complaints/complaints.php')) ?>" data-empty-all="<?= $totalAll === 0 ? '1' : '0' ?>">
    <div class="cmp-chips" data-chips></div>

    <div class="grid grid--4" data-cards>
        <div class="card card--flat stat-card"><span class="stat-card__icon"><?= icon('file-text') ?></span><div class="stat__label">Total complaints</div><div class="stat__value" data-total>—</div><div class="stat__meta">Matching the filters</div></div>
        <div class="card card--flat stat-card stat-card--accent-info"><span class="stat-card__icon"><?= icon('clock') ?></span><div class="stat__label">Within SLA</div><div class="stat__value" data-sla="within">—</div><div class="stat__meta">On track</div></div>
        <div class="card card--flat stat-card stat-card--accent-warn"><span class="stat-card__icon"><?= icon('alert') ?></span><div class="stat__label">Nearing SLA</div><div class="stat__value" data-sla="nearing">—</div><div class="stat__meta">Little time left</div></div>
        <div class="card card--flat stat-card stat-card--accent-danger"><span class="stat-card__icon"><?= icon('ban') ?></span><div class="stat__label">SLA breached</div><div class="stat__value" data-sla="breached">—</div><div class="stat__meta">Past the due time</div></div>
    </div>
    <div class="grid grid--6" style="margin-top:var(--space-4)" data-status-cards></div>

    <h2 class="section-title">Trends</h2>
    <section class="card">
        <div class="cmp-card-head">
            <h3 class="card__title">Complaints over time</h3>
            <div class="cmp-seg" data-group>
                <button type="button" data-g="auto" class="is-active">Auto</button><button type="button" data-g="day">Daily</button><button type="button" data-g="week">Weekly</button><button type="button" data-g="month">Monthly</button>
            </div>
        </div>
        <div class="cmp-chart-box"><canvas id="chart-trend" aria-label="Complaints over time"></canvas></div>
        <p class="filter-hint" data-trend-note></p>
    </section>

    <div class="grid grid--2" style="margin-top:var(--space-4)">
        <section class="card"><h3 class="card__title">Status distribution</h3><div class="cmp-chart-box"><canvas id="chart-status"></canvas></div></section>
        <section class="card"><h3 class="card__title">Complaints by source</h3><div class="cmp-chart-box"><canvas id="chart-source"></canvas></div></section>
        <section class="card"><h3 class="card__title">Complaints by type</h3><div class="cmp-chart-box cmp-chart-box--tall"><canvas id="chart-type"></canvas></div></section>
        <section class="card"><h3 class="card__title">UC-wise complaints <span class="cell-sub" data-uc-note></span></h3><div class="cmp-chart-box cmp-chart-box--tall"><canvas id="chart-uc"></canvas></div></section>
    </div>
    <section class="card" style="margin-top:var(--space-4)">
        <h3 class="card__title">UC-wise complaint status</h3>
        <div class="cmp-chart-box cmp-chart-box--tall"><canvas id="chart-ucstatus"></canvas></div>
        <p class="filter-hint">Pending = New + Assigned · In Progress includes Ready for Resolution. Click a bar to filter by that UC.</p>
    </section>

    <h2 class="section-title">Complaints</h2>
    <div class="table-meta"><span data-list-meta></span><span></span></div>
    <div data-list></div>
    <div data-pager></div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
