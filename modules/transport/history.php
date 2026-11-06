<?php
/** Assignment History — every driver assignment and every deployment, past and present. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.history');

$tab = query('tab') === 'deployment' ? 'deployment' : 'driver';
$key = $tab === 'driver' ? 'drivers_history' : 'deploy_history';
$f = trn_read_report_filters($_GET, $key);
$pv = (int)query('vehicle');
$f['q'] = $f['q'] !== '' ? $f['q'] : '';

$pg0 = trn_report_run($key, $f, 1, 0);
$pg = trn_paginate($pg0['total'], 30);
$report = trn_report_run($key, $f, $pg['per'], $pg['offset']);

$filterAction = base_url('modules/transport/history.php');
$filterDates = true;
$filterShowBeat = true;
$filterKeep = ['tab' => $tab];
$filterHelp = 'Dates filter by when the assignment started. Archived vehicles are included. Region to Beat match the vehicle\'s CURRENT deployment.';

$pageTitle = 'Assignment History';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<nav class="trn-tabs"><a class="trn-tab <?= $tab === 'driver' ? 'is-active' : '' ?>" href="<?= e($filterAction . '?tab=driver') ?>">Driver assignments</a>
    <a class="trn-tab <?= $tab === 'deployment' ? 'is-active' : '' ?>" href="<?= e($filterAction . '?tab=deployment') ?>">Deployments</a></nav>
<?php require __DIR__ . '/partials/vehicle-filter.php'; ?>
<div class="toolbar"><span class="trn-muted"><?= number_format($report['total']) ?> record<?= $report['total'] === 1 ? '' : 's' ?></span><div class="toolbar__spacer"></div>
    <?php if (can('transport.reports_export')): foreach (['xlsx' => 'Excel', 'csv' => 'CSV', 'pdf' => 'PDF'] as $fmt => $lbl): ?>
        <a class="btn btn--ghost" href="<?= e(base_url('modules/transport/report-export.php') . trn_report_query($f, ['report' => $key, 'format' => $fmt])) ?>"><?= icon('download') ?><span class="btn__label"><?= e($lbl) ?></span></a>
    <?php endforeach; endif; ?></div>
<?php trn_render_report_table($report); ?>
<?php trn_pagination($pg, static fn(int $p): string => $filterAction . trn_report_query($f, ['tab' => $tab, 'page' => $p])); ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
