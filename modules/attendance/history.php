<?php
/**
 * Employee attendance history. A person may always open their OWN history (with
 * attendance.history_view); anyone else's only if the employee is currently
 * inside their scope. Includes the correction history for that employee.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
att_require('attendance.history_view');
$ctx = att_ctx();

/* Other people's history needs the reports permission or a UC scope; otherwise only your own. */
$canOthers = $ctx['is_super'] || can('attendance.reports') || att_scope_uc_ids($ctx) !== null;
$employeeId = $canOthers ? (int)query('employee') : 0;
if ($employeeId <= 0 && $ctx['employee'] !== null) {
    $employeeId = (int)$ctx['employee']['id'];
}
$employee = $employeeId > 0 ? att_load_employee($employeeId) : null;
if ($employee !== null && !att_is_self($ctx, $employeeId) && !att_employee_in_scope($ctx, $employeeId)) {
    att_deny();
}
$f = att_read_report_filters(['period' => 'range', 'from' => query('from', (new DateTimeImmutable(att_today()))->modify('-29 days')->format('Y-m-d')), 'to' => query('to', att_today())]);

$rows = [];
$corrections = [];
$pg = ['page' => 1, 'pages' => 1, 'per' => 31, 'offset' => 0];
if ($employee !== null) {
    $where = 'a.employee_id = :e AND a.attendance_date BETWEEN :f AND :t';
    $params = ['e' => $employeeId, 'f' => $f['from'], 't' => $f['to']];
    $total = (int)Database::fetchValue("SELECT COUNT(*) FROM attendance_records a WHERE {$where}", $params);
    $pg = att_paginate($total, 31);
    $rows = Database::fetchAll(att_record_select() . " WHERE {$where} ORDER BY a.attendance_date DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
    $corrections = att_correction_history($employeeId, 20);
}
$matches = [];
$q = trim(query('q'));
if ($canOthers && $q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
    $scopeSql = att_scope_sql($ctx, 'e.id');
    $matches = Database::fetchAll("SELECT e.id, e.name, e.employee_code FROM employees e WHERE (e.name LIKE :a OR e.employee_code LIKE :b)" . ($scopeSql !== '' ? " AND {$scopeSql}" : '') . ' ORDER BY e.name LIMIT 15', ['a' => $like, 'b' => $like]);
}
$keep = ['employee' => $employeeId, 'from' => $f['from'], 'to' => $f['to']];

$pageTitle = 'Employee Attendance History';
$layout = 'app';
$extraScripts = ['attendance.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<form method="get" class="card filter-panel">
    <div class="att-form-grid">
        <?php if ($canOthers): ?><div class="field"><label class="field__label" for="q">Find an employee</label><div class="control"><input class="input" type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Name or employee ID"></div></div><?php endif; ?>
        <input type="hidden" name="employee" value="<?= (int)$employeeId ?>">
        <div class="field"><label class="field__label" for="from">From</label><div class="control"><input class="input" type="date" id="from" name="from" value="<?= e($f['from']) ?>"></div></div>
        <div class="field"><label class="field__label" for="to">To</label><div class="control"><input class="input" type="date" id="to" name="to" value="<?= e($f['to']) ?>"></div></div>
        <div><button class="btn btn--primary" type="submit">Show</button></div>
    </div>
    <?php if ($matches): ?><p class="att-note" style="margin-top:var(--space-3)">Matches:
        <?php foreach ($matches as $m): ?><a href="<?= e(base_url('modules/attendance/history.php' . att_qs(['employee' => (int)$m['id'], 'from' => $f['from'], 'to' => $f['to']]))) ?>"><?= e($m['name']) ?> (<?= e((string)$m['employee_code']) ?>)</a> · <?php endforeach; ?></p>
    <?php endif; ?>
</form>

<?php if ($employee === null): ?>
    <div class="card"><p class="att-empty">Search for an employee to see their attendance history.</p></div>
<?php else: ?>
    <section class="card">
        <h2 class="card__title"><?= e($employee['name']) ?> <small>· <?= e((string)$employee['employee_code']) ?> · <?= e($employee['pool_name']) ?> / <?= e($employee['type_name']) ?></small></h2>
        <?php if (!$rows): ?><p class="att-empty">No attendance records in this period.</p><?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>UC then</th><th>Check-in</th><th>Check-out</th><th>Status</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><?= e(att_fmt_date($r['attendance_date'])) ?></td><td><?= e((string)($r['uc_name'] ?? '—')) ?></td>
                    <td><?= e(att_clock($r['checkin_at'])) ?></td><td><?= e(att_clock($r['checkout_at'])) ?></td>
                    <td><?= att_status_badge((string)$r['status']) ?></td><td class="is-wrap"><?= e(att_record_note($r)) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?= att_pager($pg, base_url('modules/attendance/history.php'), $keep) ?>
        <?php endif; ?>
    </section>
    <?php if ($corrections): ?>
    <section class="card" style="margin-top:var(--space-4)"><h2 class="card__title">Correction history</h2>
        <div class="table-wrap"><table class="table"><thead><tr><th>Corrected</th><th>For date</th><th>By</th><th>Reason</th><th>Before → After</th></tr></thead><tbody>
        <?php foreach ($corrections as $c): $o = json_decode((string)$c['original_value'], true) ?: []; $n = json_decode((string)$c['corrected_value'], true) ?: []; ?>
            <tr><td><?= e(att_fmt_dt($c['corrected_at'])) ?></td><td><?= e(att_fmt_date($c['attendance_date'])) ?></td><td><?= e((string)$c['by_name']) ?></td><td class="is-wrap"><?= e($c['reason']) ?></td>
                <td class="is-wrap"><?= e(att_status_label((string)($o['status'] ?? 'none'))) ?> → <?= e(att_status_label((string)($n['status'] ?? ''))) ?></td></tr>
        <?php endforeach; ?></tbody></table></div></section>
    <?php endif; ?>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
