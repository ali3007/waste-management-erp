<?php
/**
 * Attendance Setup — which staff pools/types behave how, and the scheduler.
 * Categories are DATA (HR staff pools and types), not code: change them here
 * as HR changes, with no developer involved.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
att_require('attendance.config');

$cfg = att_config_cache();
$pools = Database::fetchAll('SELECT id, name FROM hr_staff_pools ORDER BY name');
$types = Database::fetchAll("SELECT t.id, CONCAT(p.name, ' › ', t.name) AS name FROM hr_staff_types t JOIN hr_staff_pools p ON p.id = t.staff_pool_id WHERE t.status = 'active' ORDER BY p.name, t.name");
$runs = att_recent_job_runs(15);
$lastSweep = (int)att_setting('attendance_last_sweep_at', '0');
$pending = att_pending_dates();
$newToken = $_SESSION['att_new_token'] ?? '';
unset($_SESSION['att_new_token']);
$hasToken = att_setting('attendance_cron_token_hash') !== null;
$cliCmd = 'php ' . ROOT_PATH . '/modules/attendance/cron/sweep.php';

$check = static fn(array $list, int $id): string => in_array($id, $list, true) ? 'checked' : '';
$pageTitle = 'Attendance Setup';
$layout = 'app';
$extraScripts = ['attendance.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<section class="card" style="margin-bottom:var(--space-4)">
    <h2 class="card__title">Staff categories</h2>
    <p class="att-note">Choose how each HR staff pool or type records attendance. Changes apply from the next row generation (tomorrow's rows, and any row not yet created).</p>
    <form method="post" action="<?= e(base_url('modules/attendance/process/config-save.php')) ?>">
        <?= att_form_fields() ?><input type="hidden" name="action" value="categories">
        <div class="att-grid att-grid--2">
            <fieldset><legend class="field__label">Self-attendance pools (the employee marks their own; company timing; company holidays apply)</legend>
                <?php foreach ($pools as $p): ?><label style="display:block"><input type="checkbox" name="self_pools[]" value="<?= (int)$p['id'] ?>" <?= $check($cfg['self_pools'], (int)$p['id']) ?>> <?= e($p['name']) ?></label><?php endforeach; ?></fieldset>
            <fieldset><legend class="field__label">Supervisor-marked pools (their Supervisor marks them; UC timing)</legend>
                <?php foreach ($pools as $p): ?><label style="display:block"><input type="checkbox" name="supervised_pools[]" value="<?= (int)$p['id'] ?>" <?= $check($cfg['supervised_pools'], (int)$p['id']) ?>> <?= e($p['name']) ?></label><?php endforeach; ?></fieldset>
            <fieldset><legend class="field__label">Staff types that are Supervisors (they mark others; not listed for marking)</legend>
                <?php foreach ($types as $t): ?><label style="display:block"><input type="checkbox" name="supervisor_types[]" value="<?= (int)$t['id'] ?>" <?= $check($cfg['supervisor_types'], (int)$t['id']) ?>> <?= e($t['name']) ?></label><?php endforeach; ?>
                <?php if (!$types): ?><span class="att-note">No staff types exist yet.</span><?php endif; ?></fieldset>
            <fieldset><legend class="field__label">Staff types that mark their own attendance whatever their pool (by default the Supervisor types — they use My Attendance)</legend>
                <?php foreach ($types as $t): ?><label style="display:block"><input type="checkbox" name="self_types[]" value="<?= (int)$t['id'] ?>" <?= $check($cfg['self_types'], (int)$t['id']) ?>> <?= e($t['name']) ?></label><?php endforeach; ?></fieldset>
        </div>
        <button class="btn btn--primary" type="submit">Save categories</button>
    </form>
</section>

<section class="card" style="margin-bottom:var(--space-4)">
    <h2 class="card__title">Scheduler</h2>
    <p class="att-note">The scheduler creates each day's attendance rows, moves missed windows to Pending Review / Incomplete, and at 12:00 AM (Asia/Karachi) turns unresolved cases into Absent. Run it every 5 minutes from the server's scheduler. It is safe to run as often as you like, and it catches up automatically after downtime.</p>
    <p>Last run: <strong><?= $lastSweep > 0 ? e(date('d M Y, h:i:s A', $lastSweep)) : 'never' ?></strong> · Days waiting to be finalised: <strong><?= count($pending) ?></strong></p>
    <p><b>Linux cron (recommended)</b><br><code class="att-mono">*/5 * * * * <?= e(PHP_BINARY ?: 'php') ?> <?= e(ROOT_PATH) ?>/modules/attendance/cron/sweep.php &gt;/dev/null 2&gt;&amp;1</code></p>
    <p><b>Windows (XAMPP) Task Scheduler</b> — run every 5 minutes:<br><code class="att-mono">C:\xampp\php\php.exe <?= e(ROOT_PATH) ?>\modules\attendance\cron\sweep.php</code></p>
    <p><b>Hosts that can only call a URL</b>: <code class="att-mono"><?= e(base_url('modules/attendance/cron/sweep.php')) ?>?token=YOUR_TOKEN</code></p>
    <?php if ($newToken !== ''): ?>
        <div class="att-alert-inline">Your new scheduler token (shown once — copy it now): <code class="att-mono"><?= e($newToken) ?></code></div>
    <?php endif; ?>
    <div style="display:flex;gap:var(--space-2);flex-wrap:wrap">
        <form method="post" action="<?= e(base_url('modules/attendance/process/config-save.php')) ?>"><?= att_form_fields() ?><input type="hidden" name="action" value="run_sweep"><button class="btn btn--primary" type="submit">Run scheduler now</button></form>
        <form method="post" action="<?= e(base_url('modules/attendance/process/config-save.php')) ?>" data-confirm="Create a new URL token? The old one stops working."><?= att_form_fields() ?><input type="hidden" name="action" value="new_token"><button class="btn btn--ghost" type="submit"><?= $hasToken ? 'Regenerate' : 'Create' ?> URL token</button></form>
    </div>
    <?php if ($runs): ?>
    <div class="table-wrap" style="margin-top:var(--space-4)"><table class="table"><thead><tr><th>Date finalised</th><th>Result</th><th class="is-num">Rows</th><th class="is-num">Attempts</th><th>Finished</th><th>Error</th></tr></thead><tbody>
    <?php foreach ($runs as $r): ?>
        <tr><td><?= e(att_fmt_date($r['attendance_date'])) ?></td><td><span class="badge <?= $r['status'] === 'success' ? 'badge--active' : ($r['status'] === 'failed' ? 'badge--blocked' : 'badge--pending') ?>"><?= e($r['status']) ?></span></td>
            <td class="is-num"><?= (int)$r['rows_affected'] ?></td><td class="is-num"><?= (int)$r['attempts'] ?></td><td><?= e(att_fmt_dt($r['finished_at'])) ?></td><td class="is-wrap"><?= e((string)$r['error_message']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
