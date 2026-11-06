<?php
/**
 * Edit Employee — same fields as Add, pre-filled.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('employee.update');

$id = (int)query('id');
$employee = $id > 0 ? hr_find_employee($id) : null;

if ($employee === null) {
    flash('error', 'That employee could not be found.');
    redirect('modules/hr/employees.php');
}
hr_require_employee_scope($id);

$regions = Database::fetchAll("SELECT id, name FROM regions WHERE status = 'active' OR id = :r ORDER BY name ASC", ['r' => $employee['region_id']]);
$districts = Database::fetchAll("SELECT id, name, region_id FROM districts WHERE status = 'active' OR id = :d ORDER BY name ASC", ['d' => $employee['district_id']]);
$tehsils = Database::fetchAll("SELECT id, name, district_id FROM tehsils WHERE status = 'active' OR id = :t ORDER BY name ASC", ['t' => $employee['tehsil_id']]);
$pools = Database::fetchAll("SELECT id, name FROM hr_staff_pools WHERE status = 'active' OR id = :p ORDER BY name ASC", ['p' => $employee['staff_pool_id']]);
$types = Database::fetchAll("SELECT id, name, staff_pool_id FROM hr_staff_types WHERE status = 'active' OR id = :t ORDER BY name ASC", ['t' => $employee['staff_type_id']]);

$name = old('name', (string)$employee['name']);
$fatherName = old('father_name', (string)$employee['father_name']);
$cnic = old('cnic', Security::formatCnic((string)$employee['cnic']));
$dob = old('dob', (string)($employee['dob'] ?? ''));
$phone = old('phone', (string)($employee['phone'] ?? ''));
$cnicIssueDate = old('cnic_issue_date', (string)($employee['cnic_issue_date'] ?? ''));
$address = old('address', (string)($employee['address'] ?? ''));
$regionId = old('region_id', (string)$employee['region_id']);
$districtId = old('district_id', (string)$employee['district_id']);
$tehsilId = old('tehsil_id', (string)$employee['tehsil_id']);
$poolId = old('staff_pool_id', (string)$employee['staff_pool_id']);
$typeId = old('staff_type_id', (string)$employee['staff_type_id']);
clear_old();

$pageTitle = 'Edit ' . $employee['name'];
$layout = 'app';
$extraScripts = ['hr.js'];

require INCLUDES_PATH . '/header.php';
?>

<a class="btn btn--ghost" href="<?= e(base_url('modules/hr/employee-view.php?id=' . $id)) ?>" style="margin-bottom:16px">
    <?= icon('arrow-left') ?>
    <span class="btn__label">Back to profile</span>
</a>

<div class="card" style="max-width:760px">
    <h2 class="card__title">Edit employee</h2>

    <?php require INCLUDES_PATH . '/components/alerts.php'; ?>

    <form method="post" id="employee-form" action="<?= e(base_url('modules/hr/process/employee-save.php')) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">

        <h3 style="margin:0 0 12px; font-size:var(--text-base)">Personal information</h3>
        <div class="field-row">
            <?php field_input('name', 'Employee Name', ['required' => true, 'icon' => 'user', 'value' => $name, 'maxlength' => 120]); ?>
            <?php field_input('father_name', "Father's Name", ['required' => true, 'value' => $fatherName, 'maxlength' => 120]); ?>
        </div>
        <div class="field-row">
            <?php field_input('cnic', 'CNIC', ['required' => true, 'icon' => 'id-card', 'value' => $cnic]); ?>
            <?php field_input('cnic_issue_date', 'CNIC Issue Date (optional)', ['type' => 'date', 'value' => $cnicIssueDate]); ?>
        </div>
        <div class="field-row">
            <?php field_input('dob', 'Date of Birth (optional)', ['type' => 'date', 'value' => $dob]); ?>
            <?php field_input('phone', 'Phone (optional)', ['icon' => 'phone', 'value' => $phone]); ?>
        </div>
        <?php field_textarea('address', 'Address (optional)', ['value' => $address, 'rows' => 2]); ?>

        <h3 style="margin:24px 0 12px; font-size:var(--text-base)">Administrative area</h3>
        <div class="field-row">
            <div class="field">
                <label class="field__label" for="region_id">Region <span class="field__required">*</span></label>
                <div class="control">
                    <select class="select" id="region_id" name="region_id" required>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?= (int)$region['id'] ?>" <?= $regionId === (string)$region['id'] ? 'selected' : '' ?>><?= e($region['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="district_id">District <span class="field__required">*</span></label>
                <div class="control">
                    <select class="select" id="district_id" name="district_id" required>
                        <option value="<?= (int)$employee['district_id'] ?>" selected><?= e($employee['district_name']) ?></option>
                    </select>
                </div>
            </div>
        </div>
        <div class="field">
            <label class="field__label" for="tehsil_id">Tehsil <span class="field__required">*</span></label>
            <div class="control">
                <select class="select" id="tehsil_id" name="tehsil_id" required>
                    <option value="<?= (int)$employee['tehsil_id'] ?>" selected><?= e($employee['tehsil_name']) ?></option>
                </select>
            </div>
        </div>

        <h3 style="margin:24px 0 12px; font-size:var(--text-base)">HR classification</h3>
        <div class="field-row">
            <div class="field">
                <label class="field__label" for="staff_pool_id">Staff Pool <span class="field__required">*</span></label>
                <div class="control">
                    <select class="select" id="staff_pool_id" name="staff_pool_id" required>
                        <?php foreach ($pools as $pool): ?>
                            <option value="<?= (int)$pool['id'] ?>" <?= $poolId === (string)$pool['id'] ? 'selected' : '' ?>><?= e($pool['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="staff_type_id">Staff Type <span class="field__required">*</span></label>
                <div class="control">
                    <select class="select" id="staff_type_id" name="staff_type_id" required>
                        <option value="<?= (int)$employee['staff_type_id'] ?>" selected><?= e($employee['staff_type_name']) ?></option>
                    </select>
                </div>
                <?php if ((string)$employee['assignment_mode'] !== 'none' && hr_is_assigned($id)): ?>
                    <p class="field__hint">
                        If the new Staff Type uses a different assignment rule, the current assignment is closed and the employee returns to Unassigned so it can be redone under the new rule.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (can('employee.doc_create')): ?>
            <?php $uploadEmployee = $employee; require __DIR__ . '/partials/employee-uploads.php'; ?>
        <?php endif; ?>

        <button type="submit" class="btn btn--primary" style="margin-top:12px">
            <span class="spinner"></span>
            <span class="btn__label">Save changes</span>
        </button>
    </form>
</div>

<?php require __DIR__ . '/partials/employee-login-account.php'; ?>

<script>
    window.HR_CASCADE_DATA = {
        districts: <?= json_encode($districts) ?>,
        tehsils: <?= json_encode($tehsils) ?>,
        types: <?= json_encode($types) ?>
    };
</script>

<?php require INCLUDES_PATH . '/footer.php'; ?>
