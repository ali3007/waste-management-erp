<?php
/**
 * Pending Review — cases where the Check-in or Check-out window closed with no
 * event. A reviewer records a decision (Present / Absent) with a reason. This
 * cannot add a Check-in or Check-out time. Anything undecided at 12:00 AM
 * (Asia/Karachi) becomes Absent automatically.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
att_require('attendance.review');
$ctx = att_ctx();
att_lazy_sweep();
$cases = att_review_cases($ctx);

$pageTitle = 'Pending Review';
$layout = 'app';
$extraScripts = ['attendance.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<p class="att-note">Decide each case before 12:00 AM (Asia/Karachi); undecided cases become Absent automatically. A decision records who, when and why — it does not add a Check-in or Check-out time.</p>
<section class="card">
    <?php if (!$cases): ?><p class="att-empty">Nothing is waiting for review.</p><?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Employee</th><th>Date</th><th>Recorded</th><th>Status</th><th class="is-num">Decision</th></tr></thead>
        <tbody>
        <?php foreach ($cases as $c): $own = att_is_self($ctx, (int)$c['employee_id']); ?>
            <tr>
                <td class="is-wrap"><strong><?= e($c['employee_name']) ?></strong><span class="cell-sub"><?= e((string)$c['employee_code']) ?> · <?= e($c['pool_name']) ?><?= $c['uc_name'] ? ' · ' . e($c['uc_name']) : '' ?></span></td>
                <td><?= e(att_fmt_date($c['attendance_date'])) ?></td>
                <td>In: <?= e(att_clock($c['checkin_at'])) ?> · Out: <?= e(att_clock($c['checkout_at'])) ?></td>
                <td><?= att_status_badge((string)$c['status']) ?><span class="cell-sub"><?= e(att_record_note($c)) ?></span></td>
                <td>
                    <?php if ($own): ?><span class="cell-sub">Your own record — someone else must review it.</span>
                    <?php else: ?>
                    <form method="post" action="<?= e(base_url('modules/attendance/process/review-decide.php')) ?>" class="cell-actions" style="flex-wrap:wrap">
                        <?= att_form_fields('modules/attendance/review.php') ?>
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <input class="input" type="text" name="reason" required minlength="5" maxlength="500" placeholder="Reason (required)" style="min-width:190px">
                        <button class="btn btn--primary" name="decision" value="present" type="submit">Present</button>
                        <button class="btn btn--danger" name="decision" value="absent" type="submit">Absent</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
