<?php
/**
 * Timing Settings — Check-in / Check-out windows, one independent configuration per
 * staff category (Office, Field, Transport …), with optional per-UC overrides for the
 * Supervisor-marked categories and a company default that is the fallback for any
 * category without its own. A save is effective today (immediately); a later date
 * schedules a future change. Each category is managed by the global permission or its
 * own "Manage <category> attendance timing" permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('attendance.access');
$manageable = att_manageable_pools();
$manageAll = can('attendance.timing_manage');
$viewAll = $manageAll || can('attendance.timing_view');
if (!$viewAll && !$manageable) {
    Permission::require('attendance.timing_view');           // standard 403
}

$today = att_today();
$company = att_company_config($today);
$hasCompany = Database::exists("SELECT id FROM attendance_timing_configs WHERE scope_key = 'company'");
$tracked = att_tracked_pools();
$manageableIds = array_map(static fn($p) => (int)$p['id'], $manageable);
$supervisedIds = att_supervised_pool_ids();
$ucs = Database::fetchAll("SELECT id, CONCAT(name, ' (', code, ')') AS label FROM ucs WHERE status = 'active' ORDER BY name");
$overridePools = array_values(array_filter($manageable, static fn($p) => in_array((int)$p['id'], $supervisedIds, true)));
$editPool = (int)query('pool');
$editUc = (int)query('uc');
if (!in_array($editPool, array_map(static fn($p) => (int)$p['id'], $overridePools), true)) {
    $editPool = 0;
}
$versions = array_values(array_filter(att_timing_overview(), static fn(array $v): bool =>
    $viewAll || ($v['staff_pool_id'] !== null && in_array((int)$v['staff_pool_id'], $manageableIds, true))));

$field = static fn(string $k, ?array $row): string => $row !== null && !empty($row[$k]) ? substr((string)$row[$k], 0, 5) : '';
$sourceLabel = ['company' => 'company default', 'category' => 'its own category timing', 'uc' => 'a UC override'];

$pageTitle = 'Attendance Timing Settings';
$layout = 'app';
$extraScripts = ['attendance.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';

$form = static function (string $title, ?int $ucId, ?int $poolId, ?array $row, ?string $inheritLabel, string $returnQuery = '') use ($field, $today): void {
    ?>
    <section class="card" style="margin-bottom:var(--space-4)">
        <h2 class="card__title"><?= e($title) ?></h2>
        <form method="post" action="<?= e(base_url('modules/attendance/process/timing-save.php')) ?>">
            <?= att_form_fields() ?>
            <?php if ($ucId !== null): ?><input type="hidden" name="uc_id" value="<?= (int)$ucId ?>"><?php endif; ?>
            <?php if ($poolId !== null): ?><input type="hidden" name="pool_id" value="<?= (int)$poolId ?>"><?php endif; ?>
            <div class="att-form-grid">
                <div class="field"><label class="field__label">Check-in opens</label><div class="control"><input class="input" type="time" name="checkin_start" value="<?= e($field('checkin_start', $row)) ?>"></div></div>
                <div class="field"><label class="field__label">Check-in closes</label><div class="control"><input class="input" type="time" name="checkin_end" value="<?= e($field('checkin_end', $row)) ?>"></div></div>
                <div class="field"><label class="field__label">Check-out opens</label><div class="control"><input class="input" type="time" name="checkout_start" value="<?= e($field('checkout_start', $row)) ?>"></div></div>
                <div class="field"><label class="field__label">Check-out closes</label><div class="control"><input class="input" type="time" name="checkout_end" value="<?= e($field('checkout_end', $row)) ?>"></div></div>
                <div class="field"><label class="field__label">Effective from</label>
                    <div class="control"><input class="input" type="date" name="effective_from" value="<?= e($today) ?>" min="<?= e($today) ?>"></div>
                    <span class="field__hint">Today = takes effect immediately. A later date schedules the change.</span></div>
                <div style="display:flex;gap:var(--space-2);flex-wrap:wrap">
                    <button class="btn btn--primary" type="submit">Save timing</button>
                    <?php if ($inheritLabel !== null): ?><button class="btn btn--ghost" type="submit" name="inherit" value="1" data-att-confirm="<?= e($inheritLabel) ?>">Remove this level</button><?php endif; ?>
                </div>
            </div>
        </form>
    </section>
    <?php
};
?>
<p class="att-note">Times are Pakistan Standard Time (Asia/Karachi). A window is open from its start time up to and including its end time. Each staff category has its own timing — changing one never changes another. A save is effective <strong>today</strong> by default (the attendance screens and the server use it immediately) and never alters attendance already recorded or past days; pick a later date to schedule a future change.</p>

<?php if (!$hasCompany): ?>
    <div class="att-alert-inline">Attendance cannot start until a company default timing exists. It is the fallback for every category that has no timing of its own.</div>
<?php endif; ?>

<?php if ($manageAll): ?>
    <?php $form($hasCompany ? 'Company default (fallback for any category without its own timing)' : 'Set the company default timing (required first)', null, null, $company, null); ?>
<?php endif; ?>

<?php if ($hasCompany): ?>
    <?php foreach ($tracked as $p): $pid = (int)$p['id']; $eff = att_config_for($pid, null, $today); $mine = in_array($pid, $manageableIds, true); ?>
        <?php if ($mine): ?>
            <?php $own = att_timing_version(att_scope_key(null, $pid), $today); $ownActive = $own !== null && (int)$own['inherit_company'] === 0; ?>
            <?php $form($p['name'] . ' — timing' . ($ownActive ? '' : ' (currently using the company default)'), null, $pid, $ownActive ? $own : $eff, $ownActive ? 'Remove this category\'s own timing and follow the company default from the chosen date?' : null); ?>
        <?php elseif ($viewAll && $eff !== null): ?>
            <section class="card" style="margin-bottom:var(--space-4)"><h2 class="card__title"><?= e($p['name']) ?></h2>
                <p class="att-window"><span>Check-in: <strong><?= e(att_fmt_window($eff, 'checkin')) ?></strong></span><span>Check-out: <strong><?= e(att_fmt_window($eff, 'checkout')) ?></strong></span><span>(<?= e($sourceLabel[$eff['source']]) ?>)</span></p></section>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($overridePools): ?>
        <section class="card" style="margin-bottom:var(--space-4)">
            <h2 class="card__title">UC-specific timing for one category</h2>
            <form method="get" class="att-form-grid">
                <div class="field"><label class="field__label" for="pool">Category</label>
                    <div class="control"><select class="select" id="pool" name="pool" data-att-autosubmit><?= att_options($overridePools, $editPool, 'Choose a category') ?></select></div></div>
                <div class="field"><label class="field__label" for="uc">UC</label>
                    <div class="control"><select class="select" id="uc" name="uc" data-att-autosubmit><?= att_options($ucs, $editUc, 'Choose a UC', 'label') ?></select></div></div>
            </form>
        </section>
        <?php if ($editPool > 0 && $editUc > 0 && Database::exists('SELECT id FROM ucs WHERE id = :id', ['id' => $editUc])): ?>
            <?php $uv = att_timing_version(att_scope_key($editUc, $editPool), $today); $effUc = att_config_for($editPool, $editUc, $today);
            $form(att_scope_label($editUc, $editPool) . ($uv === null || (int)$uv['inherit_company'] === 1 ? ' (currently follows the category timing)' : ''), $editUc, $editPool, $effUc, 'Remove this UC override and follow the category timing from the chosen date?'); ?>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<section class="card">
    <h2 class="card__title">Timing versions</h2>
    <?php if (!$versions): ?><p class="att-empty">No timing has been configured yet.</p><?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Applies to</th><th>Effective from</th><th>Check-in</th><th>Check-out</th><th>Saved by</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($versions as $v): $inForce = $v['effective_from'] <= $today; ?>
            <tr>
                <td><?= e($v['scope_key'] === 'company' ? 'Company default' : ($v['pool_name'] ? ($v['uc_name'] ? $v['pool_name'] . ' in ' . $v['uc_name'] : $v['pool_name']) : 'UC ' . ($v['uc_name'] ?? '') . ' (all categories, legacy)')) ?></td>
                <td><?= e(att_fmt_date($v['effective_from'])) ?></td>
                <?php if ((int)$v['inherit_company'] === 1): ?><td colspan="2">Follows the next level up</td>
                <?php else: ?><td><?= e(att_fmt_window($v, 'checkin')) ?></td><td><?= e(att_fmt_window($v, 'checkout')) ?></td><?php endif; ?>
                <td><?= e((string)($v['by_name'] ?? '—')) ?><span class="cell-sub"><?= e(att_fmt_dt($v['created_at'])) ?></span></td>
                <td><span class="badge <?= $inForce ? 'badge--active' : 'badge--pending' ?>"><?= $inForce ? 'In force / past' : 'Scheduled' ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
