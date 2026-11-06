<?php
/**
 * My Attendance — the Office Staff self-attendance screen.
 *
 * An Office Staff member records their OWN Check-in and Check-out here, from
 * their existing ERP login. The server decides the date, time, window and
 * eligibility; this page only shows the result and the buttons the rules allow.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
att_require('attendance.mark_self');
$ctx = att_ctx();
att_lazy_sweep();

$employee = $ctx['employee'] !== null ? att_load_employee((int)$ctx['employee']['id']) : null;
$problem = null;
if ($employee === null) {
    $problem = 'Your user account is not linked to an employee record, so attendance cannot be recorded. Ask HR to link your account.';
} elseif ($employee['status'] !== 'active') {
    $problem = 'Your employee record is inactive.';
} elseif ($employee['mode'] !== 'self') {
    $problem = 'Your staff category records attendance through your Supervisor, not on this screen.';
}

$view = $problem === null ? att_day_view($employee) : null;
$rec = $view['record'] ?? null;
$history = [];
if ($problem === null && can('attendance.history_view')) {
    $history = Database::fetchAll(att_record_select() . ' WHERE a.employee_id = :e AND a.attendance_date < :t ORDER BY a.attendance_date DESC LIMIT 14',
        ['e' => $employee['id'], 't' => att_today()]);
}
$holidays = Database::fetchAll('SELECT holiday_date, title FROM attendance_holidays WHERE holiday_date >= :t ORDER BY holiday_date LIMIT 5', ['t' => att_today()]);

$pageTitle = 'My Attendance';
$layout = 'app';
$extraScripts = ['attendance.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<div data-att-csrf="<?= e(csrf_token()) ?>" data-att-url="<?= e(base_url('modules/attendance/process/mark.php')) ?>">
<?php if ($problem !== null): ?>
    <div class="card"><p class="att-empty"><?= e($problem) ?></p></div>
<?php else: ?>
    <div class="att-clock" data-server-ms="<?= att_now()->getTimestamp() * 1000 ?>">
        <span class="att-clock__time" id="att-clock-time"><?= e(att_now()->format('H:i:s')) ?></span>
        <span class="att-clock__date"><?= e(att_fmt_date(att_today())) ?> · Pakistan Standard Time · <?= e($employee['name']) ?> (<?= e((string)$employee['employee_code']) ?>)</span>
    </div>

    <section class="card" data-att-row>
        <h2 class="card__title">Today</h2>
        <div class="att-own">
            <div class="att-own__cell"><span class="att-stat__label">Status</span><b data-cell="status"><?= att_status_badge((string)$view['state']) ?></b></div>
            <div class="att-own__cell"><span class="att-stat__label">Check-in</span><b data-cell="checkin"><?= e(att_clock($rec['checkin_at'] ?? null)) ?></b></div>
            <div class="att-own__cell"><span class="att-stat__label">Check-out</span><b data-cell="checkout"><?= e(att_clock($rec['checkout_at'] ?? null)) ?></b></div>
        </div>
        <?php if ($view['window'] !== null): ?>
            <p class="att-window">
                <span>Check-in window: <strong><?= e(att_fmt_window($view['window'], 'checkin')) ?></strong></span>
                <span>Check-out window: <strong><?= e(att_fmt_window($view['window'], 'checkout')) ?></strong></span>
            </p>
        <?php endif; ?>
        <p class="att-note" data-cell="hint"><?= e($view['hint']) ?></p>
        <div class="att-big-actions">
            <button type="button" class="btn btn--primary" data-att-event="checkin" data-att-own="1" data-att-name="yourself" <?= $view['can_checkin'] ? '' : 'disabled' ?>>Check-in</button>
            <button type="button" class="btn btn--primary" data-att-event="checkout" data-att-own="1" data-att-name="yourself" <?= $view['can_checkout'] ? '' : 'disabled' ?>>Check-out</button>
        </div>
        <p class="att-note" style="margin-top:var(--space-4)">
            A day counts as Present only when both a Check-in and a Check-out are recorded inside their windows.
            A missed window cannot be reopened; it goes to review and, if unresolved by midnight, becomes Absent.
        </p>
    </section>

    <?php if ($holidays): ?>
        <section class="card" style="margin-top:var(--space-4)"><h2 class="card__title">Upcoming holidays</h2>
            <?php foreach ($holidays as $h): ?><div><strong><?= e(att_fmt_date($h['holiday_date'])) ?></strong> — <?= e($h['title']) ?></div><?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($history): ?>
        <section class="card" style="margin-top:var(--space-4)"><h2 class="card__title">My recent attendance</h2>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Date</th><th>Check-in</th><th>Check-out</th><th>Status</th><th>Note</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr><td><?= e(att_fmt_date($h['attendance_date'])) ?></td><td><?= e(att_clock($h['checkin_at'])) ?></td><td><?= e(att_clock($h['checkout_at'])) ?></td>
                        <td><?= att_status_badge((string)$h['status']) ?></td><td class="is-wrap"><?= e(att_record_note($h)) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
        </section>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
