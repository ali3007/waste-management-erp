<?php
/**
 * HR Staff Pools & Types.
 *
 * Pools are the three the spec names (Office/Field/Transport) but are
 * themselves a real, editable table — not a hard-coded PHP list — same
 * as Site Types in the Structure module. Types belong to a pool and
 * carry the assignment_mode that Stage 2 (Employee Assignment) will use
 * to decide whether a staff type gets no UC, one UC+Beat, or several
 * UCs at once.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('hrtype.view');

$editingKind = query('edit_kind');
$editingId = (int)query('edit');
$editingRow = null;

if ($editingId > 0 && in_array($editingKind, ['pool', 'type'], true)) {
    $table = $editingKind === 'pool' ? 'hr_staff_pools' : 'hr_staff_types';
    $editingRow = Database::fetch("SELECT * FROM `{$table}` WHERE id = :id", ['id' => $editingId]);
    if ($editingRow === null) {
        flash('error', 'That record could not be found.');
        redirect('modules/hr/types.php');
    }
}

$pools = Database::fetchAll(
    'SELECT p.*, (SELECT COUNT(*) FROM employees e WHERE e.staff_pool_id = p.id) AS employee_count
       FROM hr_staff_pools p ORDER BY p.name ASC'
);
$types = Database::fetchAll(
    'SELECT t.*, p.name AS pool_name, (SELECT COUNT(*) FROM employees e WHERE e.staff_type_id = t.id) AS employee_count
       FROM hr_staff_types t JOIN hr_staff_pools p ON p.id = t.staff_pool_id ORDER BY p.name ASC, t.name ASC'
);

$formKind = old('kind', $editingKind ?: 'type');
$formName = old('name', (string)($editingRow['name'] ?? ''));
$formDescription = old('description', (string)($editingRow['description'] ?? ''));
$formPoolId = old('staff_pool_id', (string)($editingRow['staff_pool_id'] ?? ''));
$formMode = old('assignment_mode', (string)($editingRow['assignment_mode'] ?? 'single'));
clear_old();

$pageTitle = 'HR Staff Pools & Types';
$layout = 'app';

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="grid grid--2">
    <section class="card">
        <h3 class="card__title">Staff Pools</h3>
        <p class="card__subtitle">The top level every HR Type belongs to.</p>
        <?php foreach ($pools as $pool): ?>
            <div class="role-card">
                <div>
                    <div class="role-card__name"><?= e($pool['name']) ?></div>
                    <div class="role-card__meta"><?= (int)$pool['employee_count'] ?> employee<?= (int)$pool['employee_count'] === 1 ? '' : 's' ?></div>
                </div>
                <span class="badge <?= e(hr_status_badge((string)$pool['status'])) ?>" style="margin-left:var(--space-3)">
                    <?= e(hr_status_label((string)$pool['status'])) ?>
                </span>
                <div class="role-card__spacer"></div>
                <div class="role-card__actions">
                    <?php if (can('hrtype.update')): ?>
                        <a class="icon-btn" href="<?= e(base_url('modules/hr/types.php?edit_kind=pool&edit=' . (int)$pool['id'])) ?>" title="Edit">
                            <?= icon('pencil') ?>
                        </a>
                        <form method="post" action="<?= e(base_url('modules/hr/process/hrtype-status.php')) ?>"
                              data-confirm="<?= $pool['status'] === 'active' ? 'Deactivate' : 'Activate' ?> &quot;<?= e($pool['name']) ?>&quot;?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="kind" value="pool">
                            <input type="hidden" name="id" value="<?= (int)$pool['id'] ?>">
                            <input type="hidden" name="status" value="<?= $pool['status'] === 'active' ? 'inactive' : 'active' ?>">
                            <button type="submit" class="icon-btn <?= $pool['status'] === 'active' ? 'icon-btn--danger' : '' ?>"
                                    title="<?= $pool['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                <?= icon($pool['status'] === 'active' ? 'ban' : 'check') ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <h3 class="card__title" style="margin-top:28px">Staff Types</h3>
        <p class="card__subtitle">What Employee Assignment (next stage) will use to decide UC/Beat rules.</p>
        <?php foreach ($types as $type): ?>
            <div class="role-card">
                <div>
                    <div class="role-card__name"><?= e($type['name']) ?></div>
                    <div class="role-card__meta">
                        <?= e($type['pool_name']) ?> ·
                        <?= match ($type['assignment_mode']) { 'none' => 'No UC/Beat needed', 'multi' => 'Multiple UCs', default => 'One UC (+ optional Beat)' } ?> ·
                        <?= (int)$type['employee_count'] ?> employee<?= (int)$type['employee_count'] === 1 ? '' : 's' ?>
                    </div>
                </div>
                <span class="badge <?= e(hr_status_badge((string)$type['status'])) ?>" style="margin-left:var(--space-3)">
                    <?= e(hr_status_label((string)$type['status'])) ?>
                </span>
                <div class="role-card__spacer"></div>
                <div class="role-card__actions">
                    <?php if (can('hrtype.update')): ?>
                        <a class="icon-btn" href="<?= e(base_url('modules/hr/types.php?edit_kind=type&edit=' . (int)$type['id'])) ?>" title="Edit">
                            <?= icon('pencil') ?>
                        </a>
                        <form method="post" action="<?= e(base_url('modules/hr/process/hrtype-status.php')) ?>"
                              data-confirm="<?= $type['status'] === 'active' ? 'Deactivate' : 'Activate' ?> &quot;<?= e($type['name']) ?>&quot;?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="kind" value="type">
                            <input type="hidden" name="id" value="<?= (int)$type['id'] ?>">
                            <input type="hidden" name="status" value="<?= $type['status'] === 'active' ? 'inactive' : 'active' ?>">
                            <button type="submit" class="icon-btn <?= $type['status'] === 'active' ? 'icon-btn--danger' : '' ?>"
                                    title="<?= $type['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                <?= icon($type['status'] === 'active' ? 'ban' : 'check') ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ((int)$type['employee_count'] === 0 && can('hrtype.delete')): ?>
                        <form method="post" action="<?= e(base_url('modules/hr/process/hrtype-delete.php')) ?>"
                              data-confirm="Delete the &quot;<?= e($type['name']) ?>&quot; type? This cannot be undone.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$type['id'] ?>">
                            <button type="submit" class="icon-btn icon-btn--danger" title="Delete">
                                <?= icon('trash') ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if (can('hrtype.create')): ?>
        <section class="card">
            <h3 class="card__title"><?= $editingRow !== null ? 'Edit ' . e($editingRow['name']) : 'Add a Pool or Type' ?></h3>

            <form method="post" action="<?= e(base_url('modules/hr/process/hrtype-save.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <?php if ($editingRow !== null): ?>
                    <input type="hidden" name="id" value="<?= (int)$editingRow['id'] ?>">
                    <input type="hidden" name="kind" value="<?= e($editingKind) ?>">
                <?php else: ?>
                    <div class="field">
                        <span class="field__label">What are you adding?</span>
                        <div style="display:flex; gap:16px; margin-top:4px">
                            <label class="checkbox">
                                <input type="radio" name="kind" value="type" <?= $formKind === 'type' ? 'checked' : '' ?>
                                       onchange="document.getElementById('pool-fields').hidden=false">
                                <span class="checkbox__box" aria-hidden="true"></span>
                                <span>HR Staff Type</span>
                            </label>
                            <label class="checkbox">
                                <input type="radio" name="kind" value="pool" <?= $formKind === 'pool' ? 'checked' : '' ?>
                                       onchange="document.getElementById('pool-fields').hidden=true">
                                <span class="checkbox__box" aria-hidden="true"></span>
                                <span>Staff Pool</span>
                            </label>
                        </div>
                    </div>
                <?php endif; ?>

                <?php field_input('name', 'Name', ['required' => true, 'value' => $formName, 'maxlength' => 80]); ?>
                <?php field_textarea('description', 'Description (optional)', ['value' => $formDescription, 'rows' => 2]); ?>

                <div id="pool-fields" <?= ($editingRow !== null ? $editingKind : $formKind) === 'pool' ? 'hidden' : '' ?>>
                    <div class="field">
                        <label class="field__label" for="staff_pool_id">Staff Pool <span class="field__required">*</span></label>
                        <div class="control">
                            <select class="select" id="staff_pool_id" name="staff_pool_id">
                                <?php foreach ($pools as $pool): ?>
                                    <option value="<?= (int)$pool['id'] ?>" <?= $formPoolId === (string)$pool['id'] ? 'selected' : '' ?>><?= e($pool['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label class="field__label" for="assignment_mode">Assignment rule</label>
                        <div class="control">
                            <select class="select" id="assignment_mode" name="assignment_mode">
                                <option value="single" <?= $formMode === 'single' ? 'selected' : '' ?>>One UC, optionally one Beat</option>
                                <option value="multi" <?= $formMode === 'multi' ? 'selected' : '' ?>>Multiple UCs</option>
                                <option value="none" <?= $formMode === 'none' ? 'selected' : '' ?>>No UC/Beat needed</option>
                            </select>
                        </div>
                        <p class="field__hint">Used by Employee Assignment to decide what this type can be assigned to.</p>
                    </div>
                </div>

                <div style="display:flex; gap:10px; margin-top:20px">
                    <button type="submit" class="btn btn--primary">
                        <span class="btn__label"><?= $editingRow !== null ? 'Save changes' : 'Create' ?></span>
                    </button>
                    <?php if ($editingRow !== null): ?>
                        <a class="btn btn--ghost" href="<?= e(base_url('modules/hr/types.php')) ?>">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    <?php endif; ?>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
