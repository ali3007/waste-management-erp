<?php
/**
 * Attendance Dashboard — live counts for one date, limited to the viewer's scope.
 * Status cards are a true partition of the day's records (they add up to the
 * total); the two "event" cards count recorded Check-ins / Check-outs and overlap
 * the status cards by design. Today refreshes itself every 30 seconds.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
att_require('attendance.dashboard');
$ctx = att_ctx();
att_lazy_sweep();

$date = att_valid_date(query('date')) ?? att_today();
$poolId = (int)query('pool');
$ucId = att_scoped_uc((int)query('uc'), $ctx);
$data = att_dashboard_data($ctx, $date, $poolId, $ucId);
$c = $data['cards'];
$pools = Database::fetchAll('SELECT id, name FROM hr_staff_pools WHERE id IN (' . att_in(array_merge(att_self_pool_ids(), att_supervised_pool_ids())) . ') ORDER BY name');
$ucs = att_actor_ucs($ctx);
$reportLink = static fn(string $status): string => base_url('modules/attendance/reports.php' . att_qs(['period' => 'daily', 'date' => $date, 'status' => $status, 'pool' => $poolId, 'uc' => $ucId]));

$cards = [
    ['Total employees', 'total', '', 'Tracked and active for this date', ''],
    ['Present', 'present', 'att-stat--present', 'Check-in and Check-out recorded', 'present'],
    ['Absent', 'absent', 'att-stat--absent', 'Final outcome', 'absent'],
    ['Pending Review', 'pending_review', 'att-stat--review', 'No Check-in, awaiting decision', 'pending_review'],
    ['Incomplete', 'incomplete', 'att-stat--incomplete', 'Check-in, no Check-out, awaiting decision', 'incomplete'],
    ['Leave', 'leave', 'att-stat--leave', 'Approved leave', 'leave'],
    ['Off Day', 'off_day', '', 'Sunday', 'off_day'],
    ['Holiday', 'holiday', '', 'Office Staff holiday', 'holiday'],
    ['Awaiting attendance', 'awaiting', '', 'Not marked yet, or checked in and waiting for Check-out', ''],
];

$pageTitle = 'Attendance Dashboard';
$layout = 'app';
$extraScripts = ['attendance.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<form method="get" class="card filter-panel">
    <div class="att-form-grid">
        <div class="field"><label class="field__label" for="date">Date</label>
            <div class="control"><input class="input" type="date" id="date" name="date" value="<?= e($date) ?>" max="<?= e(att_today()) ?>" data-att-autosubmit></div></div>
        <div class="field"><label class="field__label" for="pool">Category</label>
            <div class="control"><select class="select" id="pool" name="pool" data-att-autosubmit><?= att_options($pools, $poolId, 'All categories') ?></select></div></div>
        <?php if (count($ucs) > 1 || att_scope_uc_ids($ctx) === null): ?>
        <div class="field"><label class="field__label" for="uc">UC</label>
            <div class="control"><select class="select" id="uc" name="uc" data-att-autosubmit><?= att_options($ucs, $ucId, 'All UCs') ?></select></div></div>
        <?php endif; ?>
        <div><a class="btn btn--ghost" href="<?= e(base_url('modules/attendance/dashboard.php')) ?>">Today</a></div>
    </div>
</form>

<div data-att-dashboard="<?= e(base_url('modules/attendance/process/dashboard-data.php' . att_qs(['date' => $date, 'pool' => $poolId, 'uc' => $ucId]))) ?>" data-live="<?= $data['is_today'] ? '1' : '0' ?>">
    <p class="att-note"><?= e($data['date_label']) ?> ·
        <span id="att-updated">Updated <?= e($data['generated_at']) ?></span>
        <?php if ($data['is_today']): ?> · refreshes every 30 seconds · <button type="button" class="btn btn--ghost" id="att-refresh">Refresh now</button><?php endif; ?></p>

    <?php if (!$data['configured']): ?>
        <div class="att-alert-inline">Attendance timing has not been configured for this date, so no attendance rows exist. Set it under Timing Settings.</div>
    <?php endif; ?>
    <?php if ($data['open_reviews'] > 0 && can('attendance.review')): ?>
        <div class="att-alert-inline"><?= (int)$data['open_reviews'] ?> case(s) are waiting for review —
            <a href="<?= e(base_url('modules/attendance/review.php')) ?>">open Pending Review</a>. Unresolved cases become Absent at 12:00 AM.</div>
    <?php endif; ?>

    <div class="att-grid">
        <?php foreach ($cards as [$label, $key, $class, $meta, $status]): ?>
            <div class="card card--flat att-stat <?= e($class) ?>">
                <div class="att-stat__label"><?= e($label) ?></div>
                <div class="att-stat__value" data-card="<?= e($key) ?>"><?= (int)$c[$key] ?></div>
                <div class="att-stat__meta"><?= e($meta) ?><?php if ($status !== '' && can('attendance.reports')): ?> · <a href="<?= e($reportLink($status)) ?>">view</a><?php endif; ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="att-grid">
        <div class="card card--flat att-stat att-stat--event"><div class="att-stat__label">Check-in completed</div><div class="att-stat__value" data-card="checkin_done"><?= (int)$c['checkin_done'] ?></div><div class="att-stat__meta">Events recorded (overlaps the statuses above)</div></div>
        <div class="card card--flat att-stat att-stat--event"><div class="att-stat__label">Check-out completed</div><div class="att-stat__value" data-card="checkout_done"><?= (int)$c['checkout_done'] ?></div><div class="att-stat__meta">Events recorded (overlaps the statuses above)</div></div>
    </div>

    <?php if ($data['by_pool']): ?>
    <section class="card"><h2 class="card__title">By category</h2>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Category</th><th class="is-num">Total</th><th class="is-num">Present</th><th class="is-num">Absent</th><th class="is-num">In review</th><th class="is-num">Leave</th></tr></thead>
            <tbody>
            <?php foreach ($data['by_pool'] as $p): ?>
                <tr><td><?= e($p['name']) ?></td><td class="is-num"><?= (int)$p['total'] ?></td><td class="is-num"><?= (int)$p['present'] ?></td><td class="is-num"><?= (int)$p['absent'] ?></td><td class="is-num"><?= (int)$p['review'] ?></td><td class="is-num"><?= (int)$p['leave'] ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
    </section>
    <?php endif; ?>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
