<?php
/**
 * Staff Attendance — the Supervisor screen.
 *
 * Lists the ACTIVE Field / Transport employees currently assigned to the
 * supervisor's UC (read live from HR assignments) and lets the supervisor record
 * Check-in / Check-out for ONE employee per click. There is no "mark all" and no
 * way to pick a date or time: the server stamps today and now. Leave requests for
 * these employees are submitted from the Leave Requests screen.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
att_require('attendance.mark_team');
$ctx = att_ctx();
att_lazy_sweep();

$ucs = att_actor_ucs($ctx);
$ucId = (int)query('uc');
if (!in_array($ucId, array_map(static fn($u) => (int)$u['id'], $ucs), true)) {
    $ucId = $ucs ? (int)$ucs[0]['id'] : 0;       // out-of-scope ids silently fall back to an allowed UC
}
$roster = $ucId > 0 ? att_supervised_roster($ucId) : [];
$pools = [];
foreach ($roster as $r) {
    $pools[(int)$r['staff_pool_id']] = $r['pool_name'];
}
$poolId = (int)query('pool');
if (!isset($pools[$poolId])) {
    $poolId = $pools ? (int)array_key_first($pools) : 0;
}

$today = att_today();
$records = [];
if ($roster) {
    $ids = att_in(array_map(static fn($r) => (int)$r['id'], $roster));
    foreach (Database::fetchAll("SELECT * FROM attendance_records WHERE attendance_date = :d AND employee_id IN ({$ids})", ['d' => $today]) as $rec) {
        $records[(int)$rec['employee_id']] = $rec;
    }
}
$hiddenInUc = 0;
if ($ucId > 0 && !$roster) {          // explain an empty list instead of leaving it a mystery
    $hiddenInUc = (int)Database::fetchValue("SELECT COUNT(DISTINCT e.id) FROM employee_assignments a JOIN employees e ON e.id = a.employee_id AND e.status = 'active' WHERE a.uc_id = :uc AND a.unassigned_at IS NULL", ['uc' => $ucId]);
    log_event('attendance', 'Supervisor roster is empty', ['user_id' => Auth::id(), 'uc_id' => $ucId, 'active_assigned' => $hiddenInUc]);
}
$cfgDefault = $ucId > 0 && $poolId > 0 ? att_config_for($poolId, $ucId, $today) : null;     // the SELECTED category's own windows
$ucName = '';
foreach ($ucs as $u) {
    if ((int)$u['id'] === $ucId) {
        $ucName = (string)$u['name'];
    }
}

$pageTitle = 'Staff Attendance';
$layout = 'app';
$extraScripts = ['attendance.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<div data-att-csrf="<?= e(csrf_token()) ?>" data-att-url="<?= e(base_url('modules/attendance/process/mark.php')) ?>">
    <div class="att-clock" data-server-ms="<?= att_now()->getTimestamp() * 1000 ?>">
        <span class="att-clock__time" id="att-clock-time"><?= e(att_now()->format('H:i:s')) ?></span>
        <span class="att-clock__date"><?= e(att_fmt_date($today)) ?> · Pakistan Standard Time<?= $ucName !== '' ? ' · UC: ' . e($ucName) : '' ?></span>
    </div>

    <?php if (!att_has_team_scope($ctx)): ?>
        <div class="card"><p class="att-empty">Your login is not linked to an employee record, so your UC cannot be determined and no staff are shown. Ask HR to link your account (Edit Employee → Login account), or make sure your CNIC matches your employee record.</p></div>
    <?php elseif (!$ucs): ?>
        <?php $inactiveUcs = array_column(Database::fetchAll('SELECT name FROM ucs WHERE status <> \'active\' AND id IN (' . att_in((array)att_scope_uc_ids($ctx)) . ')'), 'name'); ?>
        <div class="card"><p class="att-empty"><?= $inactiveUcs
            ? 'Your UC (' . e(implode(', ', $inactiveUcs)) . ') is currently marked inactive in Structure, so no staff list is shown. Ask an administrator to reactivate it.'
            : 'You are not currently assigned to a UC, so there is no staff list to show. Ask HR to assign you.' ?></p></div>
    <?php else: ?>
        <?php if (count($ucs) > 1): ?>
            <form method="get" class="card" style="margin-bottom:var(--space-4)">
                <div class="att-form-grid"><div class="field"><label class="field__label" for="uc">UC</label>
                    <div class="control"><select class="select" id="uc" name="uc" data-att-autosubmit><?= att_options($ucs, $ucId) ?></select></div></div></div>
            </form>
        <?php endif; ?>

        <?php if ($cfgDefault !== null): ?>
            <p class="att-window" style="margin-bottom:var(--space-4)">
                <span>Check-in window: <strong><?= e(att_fmt_window($cfgDefault, 'checkin')) ?></strong></span>
                <span>Check-out window: <strong><?= e(att_fmt_window($cfgDefault, 'checkout')) ?></strong></span>
                <span>(<?= $cfgDefault['source'] === 'uc' ? 'set for this UC' : ($cfgDefault['source'] === 'category' ? 'category timing' : 'company default') ?>)</span>
            </p>
        <?php elseif ($roster): ?>
            <div class="att-alert-inline">Attendance timing has not been configured yet. Ask an administrator to set it up.</div>
        <?php endif; ?>

        <?php if (count($pools) > 1): ?>
            <div class="att-tabs">
                <?php foreach ($pools as $pid => $pname): ?>
                    <a class="<?= $pid === $poolId ? 'is-active' : '' ?>" href="<?= e(base_url('modules/attendance/supervisor.php' . att_qs(['uc' => $ucId, 'pool' => $pid]))) ?>"><?= e($pname) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="card">
            <?php $shown = array_values(array_filter($roster, static fn($r) => (int)$r['staff_pool_id'] === $poolId)); ?>
            <?php if (!$shown): ?>
                <p class="att-empty">No active staff are currently assigned to this UC.</p>
                <?php if ($hiddenInUc > 0): ?><p class="att-note"><?= (int)$hiddenInUc ?> active employee(s) are assigned to this UC but belong to a staff category that is not Supervisor-marked (see Attendance Setup).</p><?php endif; ?>
            <?php else: ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Employee</th><th>Type</th><th>Status</th><th>Check-in</th><th>Check-out</th><th class="is-num">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($shown as $r): ?>
                        <?php
                        $emp = att_load_employee((int)$r['id'], [$ucId]);
                        $v = att_day_view($emp, $records[(int)$r['id']] ?? null);
                        $rec = $v['record'];
                        ?>
                        <tr data-att-row>
                            <td class="is-wrap"><strong><?= e($r['name']) ?></strong><span class="cell-sub"><?= e((string)$r['employee_code']) ?></span></td>
                            <td><?= e($r['type_name']) ?></td>
                            <td><span data-cell="status"><?= att_status_badge((string)$v['state']) ?></span><span class="att-hint" data-cell="hint"><?= e($v['hint']) ?></span></td>
                            <td data-cell="checkin"><?= e(att_clock($rec['checkin_at'] ?? null)) ?></td>
                            <td data-cell="checkout"><?= e(att_clock($rec['checkout_at'] ?? null)) ?></td>
                            <td><div class="att-actions">
                                <button type="button" class="btn btn--primary" data-att-event="checkin" data-att-employee="<?= (int)$r['id'] ?>" data-att-name="<?= e($r['name']) ?>" <?= $v['can_checkin'] ? '' : 'disabled' ?>>Check-in</button>
                                <button type="button" class="btn btn--primary" data-att-event="checkout" data-att-employee="<?= (int)$r['id'] ?>" data-att-name="<?= e($r['name']) ?>" <?= $v['can_checkout'] ? '' : 'disabled' ?>>Check-out</button>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
            <?php endif; ?>
        </section>
        <p class="att-note" style="margin-top:var(--space-4)">Each employee is marked individually. A closed window cannot be reopened — missed cases go to review and become Absent at midnight if unresolved.</p>
    <?php endif; ?>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
