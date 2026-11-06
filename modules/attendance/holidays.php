<?php
/**
 * Office Staff holiday calendar. Holidays apply to Office Staff only; Sunday is
 * a separate weekly Off Day for everyone. Only today-or-later holidays can be
 * added, changed or removed.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
att_require_any(['attendance.holidays_manage', 'attendance.mark_self']);
$manage = can('attendance.holidays_manage');
$year = (int)query('year', (string)(int)att_now()->format('Y'));
$year = min(max($year, 2020), 2100);
$holidays = att_holidays_for_year($year);
$today = att_today();

$pageTitle = 'Office Staff Holidays';
$layout = 'app';
$extraScripts = ['attendance.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<p class="att-note">Holidays apply to Office Staff only. Sundays are a weekly Off Day for everyone and are not listed here.</p>
<div class="att-tabs">
    <a href="<?= e(base_url('modules/attendance/holidays.php?year=' . ($year - 1))) ?>">‹ <?= $year - 1 ?></a>
    <a class="is-active" href="#"><?= $year ?></a>
    <a href="<?= e(base_url('modules/attendance/holidays.php?year=' . ($year + 1))) ?>"><?= $year + 1 ?> ›</a>
</div>

<?php if ($manage): ?>
<section class="card" style="margin-bottom:var(--space-4)"><h2 class="card__title">Add a holiday</h2>
    <form method="post" action="<?= e(base_url('modules/attendance/process/holiday-save.php')) ?>">
        <?= att_form_fields('modules/attendance/holidays.php?year=' . $year) ?>
        <div class="att-form-grid">
            <div class="field"><label class="field__label" for="holiday_date">Date</label><div class="control"><input class="input" type="date" id="holiday_date" name="holiday_date" min="<?= e($today) ?>" required></div></div>
            <div class="field"><label class="field__label" for="title">Holiday name</label><div class="control"><input class="input" type="text" id="title" name="title" maxlength="150" required></div></div>
            <div class="field"><label class="field__label" for="description">Note (optional)</label><div class="control"><input class="input" type="text" id="description" name="description" maxlength="500"></div></div>
            <div><button class="btn btn--primary" type="submit">Add holiday</button></div>
        </div>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <?php if (!$holidays): ?><p class="att-empty">No holidays defined for <?= $year ?>.</p><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Holiday</th><th>Note</th><?php if ($manage): ?><th class="is-num">Actions</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($holidays as $h): $past = $h['holiday_date'] < $today; ?>
        <tr><td><?= e(att_fmt_date($h['holiday_date'])) ?><?= att_is_sunday($h['holiday_date']) ? '<span class="cell-sub">Sunday — counted as Off Day</span>' : '' ?></td>
            <td><?= e($h['title']) ?></td><td class="is-wrap"><?= e((string)$h['description']) ?></td>
            <?php if ($manage): ?><td>
                <?php if ($past): ?><span class="cell-sub">Past — locked</span><?php else: ?>
                <div class="cell-actions">
                    <details><summary class="btn btn--ghost">Edit</summary>
                        <form method="post" action="<?= e(base_url('modules/attendance/process/holiday-save.php')) ?>" style="margin-top:var(--space-2)">
                            <?= att_form_fields('modules/attendance/holidays.php?year=' . $year) ?><input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                            <input class="input" type="date" name="holiday_date" value="<?= e($h['holiday_date']) ?>" min="<?= e($today) ?>" required>
                            <input class="input" type="text" name="title" value="<?= e($h['title']) ?>" maxlength="150" required>
                            <input class="input" type="text" name="description" value="<?= e((string)$h['description']) ?>" maxlength="500">
                            <button class="btn btn--primary" type="submit">Save</button>
                        </form></details>
                    <form method="post" action="<?= e(base_url('modules/attendance/process/holiday-delete.php')) ?>" data-confirm="Remove this holiday?">
                        <?= att_form_fields('modules/attendance/holidays.php?year=' . $year) ?><input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                        <button class="btn btn--danger" type="submit"><?= icon('trash') ?></button></form>
                </div><?php endif; ?></td><?php endif; ?></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
