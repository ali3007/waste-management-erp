<?php
/**
 * Attendance Reports — daily / weekly / monthly / custom range, with filters,
 * a status summary and Excel / PDF export. Visibility = the viewer's own scope.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
att_require('attendance.reports');
$ctx = att_ctx();
att_lazy_sweep();

$f = att_read_report_filters($_GET);
$total = att_report_count($ctx, $f);
$pg = att_paginate($total, 50);
$rows = att_report_rows($ctx, $f, $pg['per'], $pg['offset']);
$summary = att_report_summary($ctx, $f);
$filterOptions = att_filter_options($ctx, $f);
$filterAction = base_url('modules/attendance/reports.php');
$keep = array_filter(['period' => $f['period'], 'date' => $f['date'], 'from' => $f['period'] === 'range' ? $f['from'] : '', 'to' => $f['period'] === 'range' ? $f['to'] : '',
    'q' => $f['q'], 'pool' => $f['pool'], 'region' => $f['region'], 'district' => $f['district'], 'tehsil' => $f['tehsil'], 'uc' => $f['uc'],
    'supervisor' => $f['supervisor'], 'status' => $f['status']], static fn($v) => $v !== '' && $v !== 0);
$exportBase = base_url('modules/attendance/report-export.php');

$pageTitle = att_report_title($f);
$layout = 'app';
$extraScripts = ['hr.js', 'attendance.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
require __DIR__ . '/partials/report-filters.php';
?>
<?php if ($f['error']): ?><div class="att-alert-inline"><?= e($f['error']) ?></div><?php endif; ?>
<div class="toolbar">
    <div class="toolbar__spacer" style="margin-left:0"></div>
    <?php if (can('attendance.export')): ?>
        <a class="btn btn--ghost" href="<?= e($exportBase . att_qs($keep + ['format' => 'xlsx'])) ?>"><?= icon('download') ?><span class="btn__label">Excel</span></a>
        <a class="btn btn--ghost" href="<?= e($exportBase . att_qs($keep + ['format' => 'pdf'])) ?>"><?= icon('download') ?><span class="btn__label">PDF</span></a>
    <?php endif; ?>
</div>

<div class="att-grid">
    <?php foreach (['present', 'absent', 'pending_review', 'incomplete', 'leave', 'off_day', 'holiday', 'not_marked', 'checked_in'] as $k): ?>
        <div class="card card--flat att-stat"><div class="att-stat__label"><?= e(att_status_label($k)) ?></div><div class="att-stat__value"><?= (int)$summary[$k] ?></div></div>
    <?php endforeach; ?>
</div>

<section class="card">
    <div class="table-meta"><span><?= e(att_fmt_date($f['from'])) ?><?= $f['to'] !== $f['from'] ? ' – ' . e(att_fmt_date($f['to'])) : '' ?> · <?= e(att_report_filter_text($f)) ?></span>
        <span><?= (int)$total ?> record(s)</span></div>
    <?php if (!$rows): ?>
        <p class="att-empty">No attendance records match these filters.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Date</th><th>Employee</th><th>Category</th><th>UC</th><th>Supervisor</th><th>Check-in</th><th>Check-out</th><th>Status</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e(att_fmt_date($r['attendance_date'])) ?></td>
                <td class="is-wrap"><?= can('attendance.history_view') ? '<a href="' . e(base_url('modules/attendance/history.php?employee=' . (int)$r['employee_id'])) . '">' . e($r['employee_name']) . '</a>' : e($r['employee_name']) ?>
                    <span class="cell-sub"><?= e((string)$r['employee_code']) ?> · <?= e($r['type_name']) ?></span></td>
                <td><?= e($r['pool_name']) ?></td>
                <td><?= e((string)($r['uc_name'] ?? '—')) ?></td>
                <td><?= e((string)($r['supervisor_name'] ?? '—')) ?></td>
                <td><?= e(att_clock($r['checkin_at'])) ?></td>
                <td><?= e(att_clock($r['checkout_at'])) ?></td>
                <td><?= att_status_badge((string)$r['status']) ?></td>
                <td class="is-wrap"><?= e(att_record_note($r)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?= att_pager($pg, $filterAction, $keep) ?>
    <?php endif; ?>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
