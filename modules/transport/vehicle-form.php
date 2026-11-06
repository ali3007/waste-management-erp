<?php
/**
 * Add / Edit Vehicle.
 * Sections follow the spec: Basic Information, Identification, Tracker / RFID,
 * Operational Information (optional, creation only — later changes go through
 * Vehicle Deployment so history is kept). Every dropdown comes from the
 * database; nothing is hard-coded.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

$id = (int)query('id');
$isEdit = $id > 0;
Permission::require($isEdit ? 'transport.update' : 'transport.create');

$vehicle = null;
if ($isEdit) {
    $vehicle = trn_require_vehicle($id);
    if ($vehicle['deleted_at'] !== null) {
        trn_back('modules/transport/vehicle-view.php?id=' . $id, 'error', 'An archived vehicle cannot be edited. Restore it first.');
    }
}
$val = static fn(string $k, string $default = ''): string => old($k, (string)($vehicle[$k] ?? $default));

$missing = trn_missing_setup();
$canIdent = !$isEdit || can('transport.identifiers');
$canStatus = !$isEdit || can('transport.status');
$defaultStatus = !$isEdit ? (string)trn_default_status_id() : '';

$geoSel = [
    'region_id' => old('region_id', ''), 'district_id' => old('district_id', ''), 'tehsil_id' => old('tehsil_id', ''),
    'uc_id' => old('uc_id', ''), 'beat_id' => old('beat_id', ''),
];

$pageTitle = $isEdit ? 'Edit Vehicle' : 'Add Vehicle';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
trn_setup_notice($missing);
?>
<form class="card" method="post" action="<?= e(base_url('modules/transport/process/vehicle-save.php')) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $isEdit ? (int)$vehicle['id'] : 0 ?>">

    <fieldset class="trn-fieldset">
        <legend>Basic information</legend>
        <div class="trn-form-grid">
            <?php field_input('v_reg', 'Vehicle / registration number', ['name' => 'registration_no', 'value' => $val('registration_no'), 'required' => true, 'maxlength' => 40,
                'hint' => 'Spacing and dashes are ignored when checking for duplicates.']); ?>
            <?php trn_field_select('v_type', 'Vehicle type', trn_options('vehicle_type', (int)($vehicle['vehicle_type_id'] ?? 0)), $val('vehicle_type_id'), 'Choose a type…', ['name' => 'vehicle_type_id', 'required' => true]); ?>
            <?php field_input('v_make', 'Make', ['name' => 'make', 'value' => $val('make'), 'maxlength' => 80]); ?>
            <?php field_input('v_model', 'Model', ['name' => 'model', 'value' => $val('model'), 'maxlength' => 80]); ?>
            <?php field_input('v_year', 'Manufacturing year', ['name' => 'manufacture_year', 'value' => $val('manufacture_year'), 'type' => 'number', 'attrs' => 'min="1950" max="' . ((int)date('Y') + 1) . '"']); ?>
            <?php field_input('v_color', 'Colour', ['name' => 'color', 'value' => $val('color'), 'maxlength' => 40]); ?>
            <?php if ($canStatus): ?>
                <?php trn_field_select('v_status', 'Status', trn_options('vehicle_status', (int)($vehicle['status_id'] ?? 0)), $val('status_id', $defaultStatus), 'Choose a status…', ['name' => 'status_id', 'required' => true]); ?>
            <?php else: ?>
                <div class="field"><span class="field__label">Status</span><div class="control"><input class="input" value="<?= e((string)$vehicle['status_name']) ?>" disabled></div>
                    <p class="field__hint">Changing status needs the "Change Vehicle Status" permission.</p></div>
            <?php endif; ?>
            <?php trn_field_select('v_owner', 'Ownership type', trn_options('ownership_type', (int)($vehicle['ownership_type_id'] ?? 0)), $val('ownership_type_id'), 'Choose ownership…', ['name' => 'ownership_type_id', 'required' => true]); ?>
            <div class="field is-wide">
                <label class="field__label" for="v_picture">Vehicle picture</label>
                <div class="control"><input class="input" type="file" id="v_picture" name="picture" accept="image/jpeg,image/png,image/gif,image/webp"></div>
                <p class="field__hint">JPG, PNG, GIF or WEBP, up to <?= e(hr_size_label(TRN_PHOTO_MAX_BYTES)) ?>. It is resized automatically.</p>
                <?php if ($isEdit && $vehicle['picture_file']): ?>
                    <label class="checkbox"><input type="checkbox" name="remove_picture" value="1"><span class="checkbox__box"></span><span>Remove the current picture</span></label>
                <?php endif; ?>
            </div>
        </div>
    </fieldset>

    <fieldset class="trn-fieldset">
        <legend>Identification</legend>
        <div class="trn-form-grid">
            <?php field_input('v_chassis', 'Chassis number', ['name' => 'chassis_no', 'value' => $val('chassis_no'), 'maxlength' => 60]); ?>
            <?php field_input('v_engine', 'Engine number', ['name' => 'engine_no', 'value' => $val('engine_no'), 'maxlength' => 60]); ?>
            <div class="field is-wide"><label class="field__label" for="v_remarks">Remarks</label>
                <textarea class="textarea" id="v_remarks" name="remarks" rows="2" maxlength="500"><?= e($val('remarks')) ?></textarea></div>
        </div>
    </fieldset>

    <fieldset class="trn-fieldset">
        <legend>Tracker / RFID <small class="trn-muted">(optional)</small></legend>
        <?php if (!$canIdent): ?><p class="trn-note">These can only be changed by someone with the "Change Vehicle Tracker / RFID" permission.</p><?php endif; ?>
        <div class="trn-form-grid">
            <?php field_input('v_tracker', 'Tracker number', ['name' => 'tracker_no', 'value' => $val('tracker_no'), 'maxlength' => 60, 'attrs' => !$canIdent ? 'disabled' : '', 'hint' => 'Must not be fitted to another vehicle.']); ?>
            <?php field_input('v_rfid', 'RFID number', ['name' => 'rfid_no', 'value' => $val('rfid_no'), 'maxlength' => 60, 'attrs' => !$canIdent ? 'disabled' : '', 'hint' => 'Must not be fitted to another vehicle.']); ?>
            <?php if ($isEdit && $canIdent): ?>
                <?php field_input('v_reason', 'Reason for a number / status change (optional)', ['name' => 'change_reason', 'value' => '', 'maxlength' => 255, 'hint' => 'Recorded in the audit trail with the old and new values.']); ?>
            <?php endif; ?>
        </div>
    </fieldset>

    <?php if (!$isEdit): ?>
    <fieldset class="trn-fieldset">
        <legend>Operational information <small class="trn-muted">(optional)</small></legend>
        <p class="trn-note">Leave empty to add the vehicle now and deploy it later from Vehicle Deployment. If you choose an area, the vehicle is deployed there from the date below<?= trn_is_scoped() ? ' (required for you — it must be inside your area)' : '' ?>.</p>
        <?php trn_geo_fields($geoSel, false); ?>
        <?php field_input('v_depdate', 'Deployment start date', ['name' => 'deploy_date', 'type' => 'date', 'value' => old('deploy_date', trn_today()), 'attrs' => 'max="' . trn_today() . '"']); ?>
    </fieldset>
    <?php else: ?>
    <fieldset class="trn-fieldset">
        <legend>Operational information</legend>
        <p class="trn-note">
            <?php if ($vehicle['uc_name']): ?>Currently deployed to <strong><?= e(trn_area_text($vehicle)) ?></strong>.<?php else: ?>Not deployed.<?php endif; ?>
            <?php if (can('transport.deployment_manage')): ?><a href="<?= e(base_url('modules/transport/deployments.php?vehicle=' . $id)) ?>">Change deployment</a> — history is kept.<?php endif; ?>
        </p>
    </fieldset>
    <?php endif; ?>

    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" class="btn btn--primary"><span class="btn__label"><?= $isEdit ? 'Save changes' : 'Add vehicle' ?></span></button>
        <a class="btn btn--ghost" href="<?= e(base_url($isEdit ? 'modules/transport/vehicle-view.php?id=' . $id : 'modules/transport/vehicles.php')) ?>"><span class="btn__label">Cancel</span></a>
    </div>
</form>
<?php clear_old(); require INCLUDES_PATH . '/footer.php'; ?>
