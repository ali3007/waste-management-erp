<?php
/**
 * Add Employee.
 *
 * Region/District/Tehsil and Staff Pool/Staff Type are required here —
 * the spec's field list for this form doesn't explicitly relist Staff
 * Type (it's introduced in the HR Types section instead), but every
 * later screen (profile, dashboard, reports) filters and displays by
 * it, so it has to be set at creation. Flagging that inference here
 * since it isn't spelled out verbatim in the brief.
 *
 * No UC/Beat here — that's Employee Assignment, a separate step and a
 * separate table (Stage 2). Every new employee starts Unassigned.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('employee.create');

$regions = Database::fetchAll("SELECT id, name FROM regions WHERE status = 'active' ORDER BY name ASC");
$districts = Database::fetchAll("SELECT id, name, region_id FROM districts WHERE status = 'active' ORDER BY name ASC");
$tehsils = Database::fetchAll("SELECT id, name, district_id FROM tehsils WHERE status = 'active' ORDER BY name ASC");
$pools = hr_staff_pools();
$types = Database::fetchAll("SELECT id, name, staff_pool_id FROM hr_staff_types WHERE status = 'active' ORDER BY name ASC");

$pageTitle = 'Add employee';
$layout = 'app';
$extraScripts = ['hr.js'];

require INCLUDES_PATH . '/header.php';
?>

<a class="btn btn--ghost" href="<?= e(base_url('modules/hr/employees.php')) ?>" style="margin-bottom:16px">
    <?= icon('arrow-left') ?>
    <span class="btn__label">Back to employees</span>
</a>

<div class="card" style="max-width:760px">
    <h2 class="card__title">Add an employee</h2>
    <p class="card__subtitle">
        They start Unassigned — use Employee Assignment afterward to give them a UC (and Beat, if their type needs one).
    </p>

    <?php require INCLUDES_PATH . '/components/alerts.php'; ?>

    <form method="post" id="employee-form" action="<?= e(base_url('modules/hr/process/employee-save.php')) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>

        <h3 style="margin:0 0 12px; font-size:var(--text-base)">Personal information</h3>
        <div class="field-row">
            <?php field_input('name', 'Employee Name', ['required' => true, 'icon' => 'user', 'value' => old('name'), 'maxlength' => 120]); ?>
            <?php field_input('father_name', "Father's Name", ['required' => true, 'value' => old('father_name'), 'maxlength' => 120]); ?>
        </div>
        <div class="field-row">
            <?php field_input('cnic', 'CNIC', ['required' => true, 'icon' => 'id-card', 'value' => old('cnic'), 'placeholder' => '00000-0000000-0']); ?>
            <?php field_input('cnic_issue_date', 'CNIC Issue Date (optional)', ['type' => 'date', 'value' => old('cnic_issue_date')]); ?>
        </div>
        <div class="field-row">
            <?php field_input('dob', 'Date of Birth (optional)', ['type' => 'date', 'value' => old('dob')]); ?>
            <?php field_input('phone', 'Phone (optional)', ['icon' => 'phone', 'value' => old('phone')]); ?>
        </div>
        <?php field_textarea('address', 'Address (optional)', ['value' => old('address'), 'rows' => 2]); ?>

        <h3 style="margin:24px 0 12px; font-size:var(--text-base)">Administrative area</h3>
        <div class="field-row">
            <div class="field">
                <label class="field__label" for="region_id">Region <span class="field__required">*</span></label>
                <div class="control">
                    <select class="select" id="region_id" name="region_id" required>
                        <option value="">Choose a region…</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?= (int)$region['id'] ?>" <?= old('region_id') === (string)$region['id'] ? 'selected' : '' ?>><?= e($region['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="field__error"></p>
            </div>
            <div class="field">
                <label class="field__label" for="district_id">District <span class="field__required">*</span></label>
                <div class="control">
                    <select class="select" id="district_id" name="district_id" required disabled>
                        <option value="">Choose a region first…</option>
                    </select>
                </div>
                <p class="field__error"></p>
            </div>
        </div>
        <div class="field">
            <label class="field__label" for="tehsil_id">Tehsil <span class="field__required">*</span></label>
            <div class="control">
                <select class="select" id="tehsil_id" name="tehsil_id" required disabled>
                    <option value="">Choose a district first…</option>
                </select>
            </div>
            <p class="field__error"></p>
        </div>

        <h3 style="margin:24px 0 12px; font-size:var(--text-base)">HR classification</h3>
        <div class="field-row">
            <div class="field">
                <label class="field__label" for="staff_pool_id">Staff Pool <span class="field__required">*</span></label>
                <div class="control">
                    <select class="select" id="staff_pool_id" name="staff_pool_id" required>
                        <option value="">Choose a pool…</option>
                        <?php foreach ($pools as $pool): ?>
                            <option value="<?= (int)$pool['id'] ?>" <?= old('staff_pool_id') === (string)$pool['id'] ? 'selected' : '' ?>><?= e($pool['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="field__error"></p>
            </div>
            <div class="field">
                <label class="field__label" for="staff_type_id">Staff Type <span class="field__required">*</span></label>
                <div class="control">
                    <select class="select" id="staff_type_id" name="staff_type_id" required disabled>
                        <option value="">Choose a pool first…</option>
                    </select>
                </div>
                <?php if (empty($types)): ?>
                    <p class="field__hint">No HR Types exist yet — <a href="<?= e(base_url('modules/hr/types.php')) ?>">add one</a> first.</p>
                <?php endif; ?>
                <p class="field__error"></p>
            </div>
        </div>

        <?php if (can('employee.doc_create')): ?>
            <?php $uploadEmployee = null; require __DIR__ . '/partials/employee-uploads.php'; ?>
        <?php endif; ?>

        <button type="submit" class="btn btn--primary" style="margin-top:12px">
            <span class="spinner"></span>
            <span class="btn__label">Create employee</span>
        </button>
    </form>
</div>

<script>
    window.HR_CASCADE_DATA = {
        districts: <?= json_encode($districts) ?>,
        tehsils: <?= json_encode($tehsils) ?>,
        types: <?= json_encode($types) ?>
    };
</script>

<?php require INCLUDES_PATH . '/footer.php'; ?>
