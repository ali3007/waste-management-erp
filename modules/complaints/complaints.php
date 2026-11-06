<?php
/**
 * Complaints list & reports: every filter, results table, exports.
 * Filtering and exports share one definition (lib/filters.php), so the
 * download always equals the table on screen.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('complaint.view');

$filters = cmp_read_filters($_GET);
$filterMode = 'reports';
$filterAction = base_url('modules/complaints/complaints.php');
$page = max(1, (int)query('page', '1'));
$perPage = 25;
$list = cmp_list($filters, $page, $perPage);
$canExport = can('complaint.reports');
$exportUrl = static fn(string $fmt): string => base_url('modules/complaints/export.php') . cmp_filter_query($filters, ['format' => $fmt]);

$pageTitle = 'Complaints';
$layout = 'app';
$extraScripts = ['complaints.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="toolbar no-print">
    <div class="toolbar__spacer" style="margin-left:0"></div>
    <a class="btn btn--ghost" href="<?= e(base_url('modules/complaints/index.php') . cmp_filter_query($filters)) ?>"><?= icon('chart') ?><span class="btn__label">Dashboard</span></a>
    <?php if ($canExport): ?>
        <a class="btn" href="<?= e($exportUrl('csv')) ?>"><?= icon('download') ?><span class="btn__label">CSV</span></a>
        <a class="btn" href="<?= e($exportUrl('xlsx')) ?>"><?= icon('sheet') ?><span class="btn__label">Excel</span></a>
        <a class="btn" href="<?= e($exportUrl('pdf')) ?>"><?= icon('file-text') ?><span class="btn__label">PDF</span></a>
    <?php endif; ?>
    <?php if (can('complaint.create')): ?>
        <a class="btn btn--primary" href="<?= e(base_url('modules/complaints/complaint-create.php')) ?>"><?= icon('plus') ?><span class="btn__label">Add complaint</span></a>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/filter-form.php'; ?>

<div class="table-meta"><span><?= number_format($list['total']) ?> complaint<?= $list['total'] === 1 ? '' : 's' ?> found</span><span>Page <?= (int)$list['page'] ?></span></div>
<?= cmp_render_table($list['rows']) ?>
<?= cmp_render_pagination($list['page'], $list['total'], $perPage, static fn(int $p): string => base_url('modules/complaints/complaints.php') . cmp_filter_query($filters, ['page' => $p])) ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
