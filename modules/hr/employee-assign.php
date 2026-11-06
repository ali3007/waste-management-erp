<?php
/**
 * Assign / Reassign an employee.
 *
 * The UI genuinely differs by the employee's Staff Type assignment_mode:
 *   - none:   nothing to do here — sent back with an explanation.
 *   - single: one cascading Region→District→Tehsil→UC→Beat(optional)
 *             form. Submitting it is Assign the first time, Reassign
 *             every time after (closes the old row, opens a new one).
 *   - multi:  a list of current UC assignments, each individually
 *             removable, plus a form to add another UC (no Beat — the
 *             spec's Monitoring Officer example assigns UCs only).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('assignment.view');

$id = (int)query('id');
$employee = $id > 0 ? hr_find_employee($id) : null;

if ($employee === null) {
    flash('error', 'That employee could not be found.');
    redirect('modules/hr/employees.php');
}

hr_require_employee_scope($id);

if ($employee['status'] !== 'active') {
    flash('error', 'Inactive employees cannot be assigned. Activate ' . $employee['name'] . ' first.');
    redirect('modules/hr/employee-view.php?id=' . $id);
}

if ($employee['assignment_mode'] === 'none') {
    flash('info', $employee['name'] . '\'s staff type ("' . $employee['staff_type_name'] . '") doesn\'t need a UC/Beat assignment.');
    redirect('modules/hr/employee-view.php?id=' . $id);
}

$currentAssignments = hr_current_assignments($id);

$pageTitle = 'Assign ' . $employee['name'];
$layout = 'app';
$extraScripts = ['hr.js'];

require INCLUDES_PATH . '/header.php';
?>

<a class="btn btn--ghost" href="<?= e(base_url('modules/hr/employee-view.php?id=' . $id)) ?>" style="margin-bottom:16px">
    <?= icon('arrow-left') ?>
    <span class="btn__label">Back to profile</span>
</a>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="card" style="margin-bottom:20px">
    <h2 class="card__title"><?= e((string)$employee['name']) ?></h2>
    <p class="card__subtitle">
        <?= e((string)$employee['staff_type_name']) ?> ·
        <?= $employee['assignment_mode'] === 'multi' ? 'Can be assigned to several UCs at once' : 'One UC, optionally one Beat' ?>
    </p>
</div>

<?php if (!empty($currentAssignments)): ?>
    <div class="card" style="margin-bottom:20px">
        <h3 class="card__title">Current assignment<?= count($currentAssignments) > 1 ? 's' : '' ?></h3>
        <?php foreach ($currentAssignments as $assignment): ?>
            <div class="role-card">
                <div>
                    <div class="role-card__name"><?= e((string)$assignment['uc_name']) ?></div>
                    <div class="role-card__meta">
                        <?= $assignment['beat_name'] ? 'Beat: ' . e((string)$assignment['beat_name']) . ' · ' : '' ?>
                        Since <?= e(format_datetime((string)$assignment['assigned_at'], 'd M Y')) ?>
                    </div>
                </div>
                <div class="role-card__spacer"></div>
                <?php if ($employee['assignment_mode'] === 'multi' && can('assignment.update')): ?>
                    <div class="role-card__actions">
                        <form method="post" action="<?= e(base_url('modules/hr/process/assignment-remove.php')) ?>"
                              data-confirm="Remove <?= e((string)$employee['name']) ?> from <?= e((string)$assignment['uc_name']) ?>?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>">
                            <input type="hidden" name="employee_id" value="<?= $id ?>">
                            <button type="submit" class="icon-btn icon-btn--danger" title="Remove">
                                <?= icon('ban') ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (can('assignment.create') || can('assignment.update')): ?>
    <div class="card">
        <h3 class="card__title">
            <?= $employee['assignment_mode'] === 'multi' ? 'Add another UC' : (empty($currentAssignments) ? 'Assign' : 'Reassign') ?>
        </h3>
        <?php if ($employee['assignment_mode'] === 'single' && !empty($currentAssignments)): ?>
            <p class="card__subtitle">Choosing a new UC here replaces the current one — the old assignment is closed, not deleted, so history is kept.</p>
        <?php endif; ?>

        <form method="post" action="<?= e(base_url('modules/hr/process/assignment-save.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="employee_id" value="<?= $id ?>">

            <div class="field-row">
                <div class="field">
                    <label class="field__label" for="assign_region_id">Region <span class="field__required">*</span></label>
                    <div class="control">
                        <select class="select" id="assign_region_id" required>
                            <option value="">Choose a region…</option>
                            <?php foreach (Database::fetchAll("SELECT id, name FROM regions WHERE status = 'active' ORDER BY name ASC") as $region): ?>
                                <option value="<?= (int)$region['id'] ?>"><?= e($region['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label class="field__label" for="assign_district_id">District <span class="field__required">*</span></label>
                    <div class="control">
                        <select class="select" id="assign_district_id" required disabled>
                            <option value="">Choose a region first…</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label class="field__label" for="assign_tehsil_id">Tehsil <span class="field__required">*</span></label>
                    <div class="control">
                        <select class="select" id="assign_tehsil_id" required disabled>
                            <option value="">Choose a district first…</option>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label class="field__label" for="assign_uc_id">UC <span class="field__required">*</span></label>
                    <div class="control">
                        <select class="select" id="assign_uc_id" name="uc_id" required disabled>
                            <option value="">Choose a tehsil first…</option>
                        </select>
                    </div>
                    <p class="field__error"></p>
                </div>
            </div>

            <?php if ($employee['assignment_mode'] === 'single'): ?>
                <div class="field">
                    <label class="field__label" for="assign_beat_id">Beat (optional)</label>
                    <div class="control">
                        <select class="select" id="assign_beat_id" name="beat_id" disabled>
                            <option value="">Choose a UC first…</option>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn--primary" style="margin-top:12px">
                <span class="btn__label"><?= $employee['assignment_mode'] === 'multi' ? 'Add UC' : (empty($currentAssignments) ? 'Assign' : 'Reassign') ?></span>
            </button>
        </form>
    </div>
<?php endif; ?>

<script>window.HR_ASSIGNMENT_MODE = true;</script>

<?php require INCLUDES_PATH . '/footer.php'; ?>
