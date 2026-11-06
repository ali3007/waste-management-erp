<?php
/**
 * Leave Requests. Office Staff request their own leave; a Supervisor requests it
 * on behalf of assigned Field / Transport staff; users with the approve permission
 * decide. Only Approved leave becomes Leave on the attendance record.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
att_require_any(['attendance.leave_view', 'attendance.leave_submit_self', 'attendance.leave_submit_proxy', 'attendance.leave_approve']);
$ctx = att_ctx();

$status = in_array(query('status'), ['pending', 'approved', 'rejected'], true) ? query('status') : '';
$requests = att_leave_list($ctx, $status, 100);
$submittable = att_leave_submittable($ctx);
$canDecide = can('attendance.leave_approve');
$returnTo = 'modules/attendance/leave.php' . att_qs(['status' => $status]);

$pageTitle = 'Leave Requests';
$layout = 'app';
$extraScripts = ['attendance.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<?php if ($submittable): ?>
<section class="card" style="margin-bottom:var(--space-4)">
    <h2 class="card__title">New leave request</h2>
    <form method="post" action="<?= e(base_url('modules/attendance/process/leave-submit.php')) ?>">
        <?= att_form_fields($returnTo) ?>
        <div class="att-form-grid">
            <div class="field"><label class="field__label" for="employee_id">Employee</label>
                <div class="control"><select class="select" id="employee_id" name="employee_id" required>
                    <?php foreach ($submittable as $e): ?><option value="<?= (int)$e['id'] ?>"><?= e($e['name']) ?> (<?= e((string)$e['employee_code']) ?>)</option><?php endforeach; ?>
                </select></div></div>
            <div class="field"><label class="field__label" for="start_date">From</label><div class="control"><input class="input" type="date" id="start_date" name="start_date" min="<?= e(att_today()) ?>" required></div></div>
            <div class="field"><label class="field__label" for="end_date">To</label><div class="control"><input class="input" type="date" id="end_date" name="end_date" min="<?= e(att_today()) ?>" required></div></div>
            <div class="field" style="grid-column:span 2"><label class="field__label" for="reason">Reason</label><div class="control"><input class="input" type="text" id="reason" name="reason" maxlength="500" required></div></div>
            <div><button class="btn btn--primary" type="submit">Submit request</button></div>
        </div>
        <p class="att-note" style="margin-top:var(--space-3)">Leave starts today or later. It does not change attendance until approved, and it never reopens a Check-in or Check-out window.</p>
    </form>
</section>
<?php endif; ?>

<div class="att-tabs">
    <?php foreach (['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $l): ?>
        <a class="<?= $status === $k ? 'is-active' : '' ?>" href="<?= e(base_url('modules/attendance/leave.php' . att_qs(['status' => $k]))) ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
</div>

<section class="card">
    <?php if (!$requests): ?><p class="att-empty">No leave requests.</p><?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Employee</th><th>Dates</th><th>Reason</th><th>Submitted</th><th>Status</th><th class="is-num">Decision</th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <?php $own = att_is_self($ctx, (int)$r['employee_id']); ?>
            <tr>
                <td class="is-wrap"><strong><?= e($r['employee_name']) ?></strong><span class="cell-sub"><?= e((string)$r['employee_code']) ?> · <?= e($r['pool_name']) ?><?= $r['uc_name'] ? ' · ' . e($r['uc_name']) : '' ?></span></td>
                <td><?= e(att_fmt_date($r['start_date'])) ?><?= $r['end_date'] !== $r['start_date'] ? '<span class="cell-sub">to ' . e(att_fmt_date($r['end_date'])) . '</span>' : '' ?></td>
                <td class="is-wrap"><?= e($r['reason']) ?></td>
                <td><?= e(att_fmt_dt($r['submitted_at'])) ?><span class="cell-sub">by <?= e((string)($r['submitted_by_name'] ?? '—')) ?></span></td>
                <td><span class="badge <?= $r['status'] === 'approved' ? 'badge--active' : ($r['status'] === 'rejected' ? 'badge--blocked' : 'badge--pending') ?>"><?= e(ucfirst($r['status'])) ?></span>
                    <?php if ($r['decided_by_name']): ?><span class="cell-sub"><?= e($r['decided_by_name']) ?>, <?= e(att_fmt_dt($r['decided_at'])) ?></span><?php endif; ?>
                    <?php if ($r['status'] === 'rejected' && $r['decision_note']): ?><span class="cell-sub is-wrap">Reason: <?= e($r['decision_note']) ?></span><?php endif; ?></td>
                <td>
                    <?php if ($r['status'] === 'pending' && $canDecide && !$own): ?>
                        <form method="post" action="<?= e(base_url('modules/attendance/process/leave-decide.php')) ?>" class="cell-actions" style="flex-wrap:wrap">
                            <?= att_form_fields($returnTo) ?>
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input class="input" type="text" name="note" maxlength="500" placeholder="Reason (required to reject)" style="min-width:170px">
                            <button class="btn btn--primary" name="decision" value="approve" type="submit">Approve</button>
                            <button class="btn btn--danger" name="decision" value="reject" type="submit">Reject</button>
                        </form>
                    <?php elseif ($r['status'] === 'pending' && $own): ?><span class="cell-sub">Your own request — someone else must decide.</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
