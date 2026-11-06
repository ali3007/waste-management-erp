<?php
/** Communication settings page (inside the Communication menu, same look as other ERP forms). */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
comms_require('comms.settings');

$fields = comms_settings_fields();
$st = comms_storage_state();
$pct = min(100, (int)round($st['used'] / max(1, $st['limit']) * 100));

$pageTitle = 'Communication settings';
$layout = 'app';
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<form method="post" action="<?= e(base_url('modules/comms/process/settings-save.php')) ?>" class="card" data-confirm="Save these settings?">
    <?= csrf_field() ?>
    <h2 class="card__title">Communication settings</h2>
    <p class="card__subtitle">Changes apply to everyone straight away. Every change is recorded in the audit log.</p>
    <?php foreach ($fields as $key => $f): ?>
        <div class="field">
            <label class="field__label" for="<?= e($key) ?>"><?= e($f['label']) ?></label>
            <div class="control">
                <?php if ($key === 'comms_timezone'): ?>
                    <select class="select" id="<?= e($key) ?>" name="<?= e($key) ?>">
                        <?php $cur = old($key, comms_setting($key)); foreach (DateTimeZone::listIdentifiers() as $tz): ?>
                            <option value="<?= e($tz) ?>"<?= $tz === $cur ? ' selected' : '' ?>><?= e($tz) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input class="input" id="<?= e($key) ?>" name="<?= e($key) ?>" type="<?= $f['type'] === 'number' ? 'number' : 'text' ?>" value="<?= e(old($key, comms_setting($key))) ?>" required>
                <?php endif; ?>
            </div>
            <p class="field__hint"><?= e($f['hint']) ?></p>
        </div>
    <?php endforeach; ?>
    <p class="comms-muted">File and voice storage used: <strong><?= e(number_format($st['used'] / 1048576, 2)) ?> MB</strong> of <?= e(number_format($st['limit'] / 1048576)) ?> MB (<?= (int)$pct ?>%).
        <?= $st['full'] ? 'Storage is full: uploads are blocked.' : ($st['warn'] ? 'Storage is almost full.' : '') ?>
        Files are never deleted automatically. The largest upload also depends on PHP: upload_max_filesize is <?= e((string)ini_get('upload_max_filesize')) ?> and post_max_size is <?= e((string)ini_get('post_max_size')) ?> on this server.</p>
    <div class="modal__actions"><button type="submit" class="btn btn--primary"><span class="btn__label">Save settings</span></button></div>
</form>
<?php clear_old(); require INCLUDES_PATH . '/footer.php'; ?>
