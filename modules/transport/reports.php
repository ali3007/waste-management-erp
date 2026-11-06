<?php
/** Transport Reports — pick a report, filter it, export it (Excel, CSV, PDF). */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.reports');

$catalogue = trn_report_catalogue();
$key = query('r');
$pageTitle = 'Transport Reports';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];

if (!isset($catalogue[$key])) {
    require INCLUDES_PATH . '/header.php';
    require INCLUDES_PATH . '/components/alerts.php';
    $groups = [];
    foreach ($catalogue as $k => [$g, $title, $desc]) { $groups[$g][$k] = [$title, $desc]; }
    ?>
    <p class="trn-note">Choose a report. Every report can be filtered and exported, and shows only the vehicles you are allowed to see.</p>
    <div class="trn-report-groups">
        <?php foreach ($groups as $g => $items): ?>
            <section class="card"><h3 class="card__title"><?= e($g) ?></h3>
                <?php foreach ($items as $k => [$title, $desc]): ?>
                    <a class="trn-report-link" href="<?= e(base_url('modules/transport/reports.php?r=' . $k)) ?>"><?= e($title) ?><small><?= e($desc) ?></small></a>
                <?php endforeach; ?></section>
        <?php endforeach; ?>
    </div>
    <?php
    require INCLUDES_PATH . '/footer.php';
    exit;
}

$f = trn_read_report_filters($_GET, $key);
$total0 = trn_report_run($key, $f, 1, 0)['total'];
$pg = trn_paginate($total0, 30);
$report = trn_report_run($key, $f, $pg['per'], $pg['offset']);

$filterAction = base_url('modules/transport/reports.php');
$filterKeep = ['r' => $key];
$filterDates = (bool)$f['uses_dates'];
$filterDocTypes = str_starts_with($key, 'docs_') && $key !== 'docs_missing';
$filterShowOwnership = true;
$filterShowBeat = true;
$filterShowSearch = false;
$filterShowArchive = true;
$filterShowDeployment = !$f['uses_dates'];

require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<p><a href="<?= e(base_url('modules/transport/reports.php')) ?>">← All reports</a></p>
<h2 style="margin:0 0 var(--space-3)"><?= e($report['title']) ?></h2>
<?php require __DIR__ . '/partials/vehicle-filter.php'; ?>
<div class="toolbar"><span class="trn-muted"><?= number_format($report['total']) ?> row<?= $report['total'] === 1 ? '' : 's' ?></span><div class="toolbar__spacer"></div>
    <?php if (can('transport.reports_export') && $report['total'] > 0): foreach (['xlsx' => 'Excel', 'csv' => 'CSV', 'pdf' => 'PDF'] as $fmt => $lbl): ?>
        <a class="btn btn--ghost" href="<?= e(base_url('modules/transport/report-export.php') . trn_report_query($f, ['report' => $key, 'format' => $fmt])) ?>"><?= icon('download') ?><span class="btn__label"><?= e($lbl) ?></span></a>
    <?php endforeach; endif; ?></div>
<?php trn_render_report_table($report); ?>
<?php trn_pagination($pg, static fn(int $p): string => $filterAction . trn_report_query($f, ['r' => $key, 'page' => $p])); ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
