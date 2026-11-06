<?php
/**
 * Attendance Corrections — change a locked or historical record. Needs the
 * separate Attendance Edit permission and a reason; the original value is kept
 * and audited. This does not reopen marking windows and cannot be used on your
 * own attendance or on a day that is still being recorded.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
att_require('attendance.edit');
$ctx = att_ctx();

$q = trim(query('q'));
$employeeId = (int)query('employee');
$date = att_valid_date(query('date')) ?? '';
$employee = $employeeId > 0 ? att_load_employee($employeeId) : null;
if ($employee !== null && !att_employee_in_scope($ctx, $employeeId)) {
    att_deny();
}
$record = $employee !== null && $date !== '' ? att_find_record($employeeId, $date) : null;
$matches = [];
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
    $scopeSql = att_scope_sql($ctx, 'e.id');
    $matches = Database::fetchAll('SELECT e.id, e.name, e.employee_code FROM employees e WHERE (e.name LIKE :a OR e.employee_code LIKE :b)' . ($scopeSql !== '' ? " AND {$scopeSql}" : '') . ' ORDER BY e.name LIMIT 15', ['a' => $like, 'b' => $like]);
}
$recent = att_correction_history(null, 25);

$pageTitle = 'Attendance Corrections';
$layout = 'app';
$extraScripts = ['attendance.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<div class="att-alert-inline">Corrections change official records. Every change needs a reason and is kept with the original value in the audit trail. Today's open attendance cannot be corrected — it is completed by marking or review.</div>

<form method="get" class="card filter-panel" style="margin-bottom:var(--space-4)">
    <div class="att-form-grid">
        <div class="field"><label class="field__label" for="q">Find employee</label><div class="control"><input class="input" type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Name or employee ID"></div></div>
        <div class="field"><label class="field__label" for="date">Date</label><div class="control"><input class="input" type="date" id="date" name="date" value="<?= e($date) ?>" max="<?= e(att_today()) ?>"></div></div>
        <input type="hidden" name="employee" value="<?= (int)$employeeId ?>">
        <div><button class="btn btn--primary" type="submit">Find</button></div>
    </div>
    <?php if ($matches): ?><p class="att-note" style="margin-top:var(--space-3)">Choose:
        <?php foreach ($matches as $m): ?><a href="<?= e(base_url('modules/attendance/corrections.php' . att_qs(['employee' => (int)$m['id'], 'date' => $date]))) ?>"><?= e($m['name']) ?> (<?= e((string)$m['employee_code']) ?>)</a> · <?php endforeach; ?></p>
    <?php endif; ?>
</form>

<?php if ($employee !== null && $date !== ''): ?>
<section class="card" style="margin-bottom:var(--space-4)">
    <h2 class="card__title">Correct <?= e($employee['name']) ?> — <?= e(att_fmt_date($date)) ?></h2>
    <p class="att-note">Current: <?= $record ? att_status_badge((string)$record['status']) . ' · In ' . e(att_clock($record['checkin_at'])) . ' · Out ' . e(att_clock($record['checkout_at'])) : 'no record exists for this date' ?></p>
    <?php if ($employee['mode'] === null): ?>
        <p class="att-empty">This employee is not part of an attendance category.</p>
    <?php else: ?>
    <form method="post" action="<?= e(base_url('modules/attendance/process/correct-save.php')) ?>">
        <?= att_form_fields() ?>
        <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>"><input type="hidden" name="date" value="<?= e($date) ?>">
        <div class="att-form-grid">
            <div class="field"><label class="field__label" for="status">New status</label><div class="control"><select class="select" id="status" name="status" required>
                <?php foreach (att_correctable_statuses() as $s): ?><option value="<?= e($s) ?>" <?= ($record['status'] ?? '') === $s ? 'selected' : '' ?>><?= e(att_status_label($s)) ?></option><?php endforeach; ?></select></div></div>
            <div class="field"><label class="field__label" for="checkin">Check-in (HH:MM)</label><div class="control"><input class="input" type="time" id="checkin" name="checkin" value="<?= e($record && $record['checkin_at'] ? substr((string)$record['checkin_at'], 11, 5) : '') ?>"></div></div>
            <div class="field"><label class="field__label" for="checkout">Check-out (HH:MM)</label><div class="control"><input class="input" type="time" id="checkout" name="checkout" value="<?= e($record && $record['checkout_at'] ? substr((string)$record['checkout_at'], 11, 5) : '') ?>"></div></div>
            <div class="field" style="grid-column:span 2"><label class="field__label" for="reason">Reason for correction</label><div class="control"><input class="input" type="text" id="reason" name="reason" required minlength="5" maxlength="500"></div></div>
            <div><button class="btn btn--primary" type="submit">Save correction</button></div>
        </div>
        <p class="att-note" style="margin-top:var(--space-3)">Present needs both times; Incomplete needs a Check-in only; Leave / Off Day / Holiday take no times; a Check-out must be after its Check-in.</p>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card"><h2 class="card__title">Recent corrections</h2>
    <?php if (!$recent): ?><p class="att-empty">No corrections yet.</p><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>Employee</th><th>Date</th><th>By</th><th>Change</th><th>Reason</th></tr></thead><tbody>
    <?php foreach ($recent as $c): $o = json_decode((string)$c['original_value'], true) ?: []; $n = json_decode((string)$c['corrected_value'], true) ?: []; ?>
        <tr><td><?= e(att_fmt_dt($c['corrected_at'])) ?></td><td class="is-wrap"><?= e($c['employee_name']) ?><span class="cell-sub"><?= e((string)$c['employee_code']) ?></span></td><td><?= e(att_fmt_date($c['attendance_date'])) ?></td>
            <td><?= e((string)$c['by_name']) ?></td><td class="is-wrap"><?= e(att_status_label((string)($o['status'] ?? 'none'))) ?> → <?= e(att_status_label((string)($n['status'] ?? ''))) ?></td><td class="is-wrap"><?= e($c['reason']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
