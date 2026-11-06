<?php
/**
 * Add / edit / search / activate-deactivate one configurable list.
 * Expects: $kind (registry key), $pageUrl (this page, relative), $canManage (bool).
 * The same partial serves Vehicle Types and every Configuration tab.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

$reg = trn_config_registry()[$kind];
$table = $reg['table'];
$q = Security::cleanText(query('q'), 100);
$stateFilter = in_array(query('state'), ['active', 'inactive'], true) ? query('state') : '';
$editId = $canManage ? (int)query('edit') : 0;
$editing = $editId > 0 ? Database::fetch("SELECT * FROM `{$table}` WHERE id = :id", ['id' => $editId]) : null;

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(name LIKE :q OR description LIKE :q2)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $params['q'] = $params['q2'] = $like;
}
if ($stateFilter !== '') {
    $where[] = 'status = :st';
    $params['st'] = $stateFilter;
}
$rows = Database::fetchAll("SELECT * FROM `{$table}` WHERE " . implode(' AND ', $where) . ' ORDER BY status ASC, name ASC LIMIT 500', $params);
$totalAll = (int)Database::fetchValue("SELECT COUNT(*) FROM `{$table}`");
$cats = trn_status_categories();

$fName = old('name', (string)($editing['name'] ?? ''));
$fDesc = old('description', (string)($editing['description'] ?? ''));
$fCat = old('category', (string)($editing['category'] ?? 'active'));
$fDefault = old('is_default', (string)($editing['is_default'] ?? '0'));
$fReq = old('is_required', (string)($editing['is_required'] ?? '0'));
$fExp = old('requires_expiry', (string)($editing['requires_expiry'] ?? '0'));
$fWarn = old('warning_days', isset($editing['warning_days']) ? (string)$editing['warning_days'] : '');
clear_old();
$self = base_url($pageUrl);
$sep = str_contains($pageUrl, '?') ? '&' : '?';
?>
<div class="grid grid--2 trn-split">
    <section class="card">
        <h3 class="card__title"><?= e($reg['plural']) ?> <span class="cell-sub" style="display:inline"><?= $totalAll ?></span></h3>
        <p class="card__subtitle"><?= e($reg['hint']) ?></p>

        <form method="get" action="<?= e(base_url(explode('?', $pageUrl)[0])) ?>" class="trn-inline-filter">
            <?php foreach (array_filter(['tab' => query('tab')]) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
            <div class="control"><span class="control__icon"><?= icon('search') ?></span>
                <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search <?= e(strtolower($reg['plural'])) ?>…" aria-label="Search"></div>
            <select class="select" name="state" data-autosubmit aria-label="Status">
                <option value="">All</option>
                <option value="active" <?= $stateFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $stateFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </form>

        <?php if (!$rows): ?>
            <div class="empty-state"><?= icon('tag') ?>
                <h3 style="margin-bottom:6px"><?= $totalAll === 0 ? 'Nothing here yet' : 'No matches' ?></h3>
                <p style="margin:0"><?= $totalAll === 0 ? ($canManage ? 'Add the first one using the form.' : 'An administrator has not added any yet.') : 'Try a different search.' ?></p>
            </div>
        <?php endif; ?>

        <?php foreach ($rows as $row): ?>
            <?php $uses = trn_lookup_usage($kind, (int)$row['id']); ?>
            <div class="role-card">
                <div>
                    <div class="role-card__name"><?= e((string)$row['name']) ?></div>
                    <div class="role-card__meta">
                        <?php if ($kind === 'vehicle_status'): ?>
                            <?= e($cats[$row['category']]['label'] ?? $row['category']) ?><?= (int)$row['is_default'] ? ' · Default for new vehicles' : '' ?> ·
                        <?php elseif ($kind === 'document_type'): ?>
                            <?= (int)$row['is_required'] ? 'Required' : 'Optional' ?> · <?= (int)$row['requires_expiry'] ? 'Has expiry' : 'No expiry' ?>
                            <?= $row['warning_days'] !== null ? ' · Warn ' . (int)$row['warning_days'] . ' days ahead' : '' ?> ·
                        <?php endif; ?>
                        <?= $uses ?> in use<?= $row['description'] ? ' · ' . e((string)$row['description']) : '' ?>
                    </div>
                </div>
                <span class="badge <?= e(trn_active_badge((string)$row['status'])) ?>" style="margin-left:var(--space-3)"><?= $row['status'] === 'active' ? 'Active' : 'Inactive' ?></span>
                <div class="role-card__spacer"></div>
                <?php if ($canManage): ?>
                    <div class="role-card__actions">
                        <a class="icon-btn" href="<?= e($self . $sep . 'edit=' . (int)$row['id']) ?>" title="Edit" aria-label="Edit"><?= icon('pencil') ?></a>
                        <form method="post" action="<?= e(base_url('modules/transport/process/config-status.php')) ?>"
                              data-confirm="<?= $row['status'] === 'active' ? 'Deactivate' : 'Activate' ?> &quot;<?= e((string)$row['name']) ?>&quot;?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <input type="hidden" name="status" value="<?= $row['status'] === 'active' ? 'inactive' : 'active' ?>">
                            <input type="hidden" name="return_to" value="<?= e($pageUrl) ?>">
                            <button type="submit" class="icon-btn <?= $row['status'] === 'active' ? 'icon-btn--danger' : '' ?>" title="<?= $row['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"
                                    aria-label="<?= $row['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"><?= icon($row['status'] === 'active' ? 'ban' : 'check') ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if ($canManage): ?>
    <section class="card">
        <h3 class="card__title"><?= $editing ? 'Edit ' . e(strtolower($reg['label'])) : 'Add ' . e(strtolower($reg['label'])) ?></h3>
        <form method="post" action="<?= e(base_url('modules/transport/process/config-save.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
            <input type="hidden" name="return_to" value="<?= e($pageUrl) ?>">
            <?php field_input('cfg_name', 'Name', ['name' => 'name', 'value' => $fName, 'required' => true, 'maxlength' => 100]); ?>
            <?php field_textarea('cfg_desc', 'Description (optional)', ['name' => 'description', 'value' => $fDesc, 'rows' => 2]); ?>

            <?php if ($kind === 'vehicle_status'): ?>
                <div class="field">
                    <label class="field__label" for="cfg_cat">Behaviour <span class="field__required">*</span></label>
                    <div class="control"><select class="select" id="cfg_cat" name="category" required>
                        <?php foreach ($cats as $key => $c): ?><option value="<?= e($key) ?>" <?= $fCat === $key ? 'selected' : '' ?>><?= e($c['label']) ?> — <?= e($c['help']) ?></option><?php endforeach; ?>
                    </select></div>
                    <p class="field__hint">This tells the system how to treat the status, for dashboard counts and for blocking invalid actions.</p>
                </div>
                <label class="checkbox"><input type="checkbox" name="is_default" value="1" <?= $fDefault === '1' ? 'checked' : '' ?>><span class="checkbox__box"></span><span>Use as the default for new vehicles</span></label>
            <?php elseif ($kind === 'document_type'): ?>
                <label class="checkbox"><input type="checkbox" name="is_required" value="1" <?= $fReq === '1' ? 'checked' : '' ?>><span class="checkbox__box"></span><span>Required for every vehicle (reported when missing)</span></label>
                <label class="checkbox"><input type="checkbox" name="requires_expiry" value="1" <?= $fExp === '1' ? 'checked' : '' ?>><span class="checkbox__box"></span><span>Has an expiry date (the date becomes mandatory)</span></label>
                <?php field_input('cfg_warn', 'Warn this many days before expiry (optional)', ['name' => 'warning_days', 'value' => $fWarn, 'type' => 'number', 'attrs' => 'min="0" max="3650"', 'hint' => 'Leave empty to use the default set in Settings.']); ?>
            <?php endif; ?>

            <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
                <button type="submit" class="btn btn--primary"><span class="btn__label"><?= $editing ? 'Save changes' : 'Add ' . e(strtolower($reg['label'])) ?></span></button>
                <?php if ($editing): ?><a class="btn btn--ghost" href="<?= e($self) ?>"><span class="btn__label">Cancel</span></a><?php endif; ?>
            </div>
        </form>
    </section>
    <?php endif; ?>
</div>
