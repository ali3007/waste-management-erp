<?php
/**
 * Complaint form fields (create + edit).
 *
 * Expects: $form (current values), $isEdit, $sources/$types/$priorities
 * (lookup rows), $regions, $cascade (children lists for the current chain).
 * On edit the geography is read-only here — changing the UC is a
 * reassignment (own dialog, with a reason) so the audit trail is complete.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

$sel = static fn($a, $b): string => (string)$a === (string)$b ? 'selected' : '';
?>
<div class="cmp-form-grid">
    <fieldset class="cmp-fieldset">
        <legend>Complainant</legend>
        <div class="field">
            <label class="field__label" for="complainant_name">Complainant name <span class="field__required">*</span></label>
            <div class="control"><input class="input" id="complainant_name" name="complainant_name" maxlength="150" required value="<?= e((string)$form['complainant_name']) ?>"></div>
        </div>
        <div class="field-row">
            <div class="field">
                <label class="field__label" for="phone">Phone</label>
                <div class="control"><input class="input" id="phone" name="phone" maxlength="30" inputmode="tel" placeholder="03XX-XXXXXXX" value="<?= e((string)$form['phone']) ?>"></div>
            </div>
            <div class="field">
                <label class="field__label" for="complainant_address">Complainant address</label>
                <div class="control"><input class="input" id="complainant_address" name="complainant_address" maxlength="255" value="<?= e((string)$form['complainant_address']) ?>"></div>
            </div>
        </div>
    </fieldset>

    <fieldset class="cmp-fieldset">
        <legend>Complaint</legend>
        <div class="field-row">
            <div class="field">
                <label class="field__label" for="source_id">Source <span class="field__required">*</span></label>
                <div class="control"><select class="select" id="source_id" name="source_id" required>
                    <option value="">Select source</option>
                    <?php foreach ($sources as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $sel($form['source_id'], $r['id']) ?>><?= e((string)$r['name']) ?><?= ($r['status'] ?? '') === 'inactive' ? ' (inactive)' : '' ?></option><?php endforeach; ?>
                </select></div>
            </div>
            <div class="field">
                <label class="field__label" for="type_id">Type / category <span class="field__required">*</span></label>
                <div class="control"><select class="select" id="type_id" name="type_id" required>
                    <option value="">Select type</option>
                    <?php foreach ($types as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $sel($form['type_id'], $r['id']) ?>><?= e((string)$r['name']) ?><?= ($r['status'] ?? '') === 'inactive' ? ' (inactive)' : '' ?></option><?php endforeach; ?>
                </select></div>
            </div>
            <div class="field">
                <label class="field__label" for="priority_id">Priority <span class="field__required">*</span></label>
                <div class="control"><select class="select" id="priority_id" name="priority_id" required>
                    <option value="">Select priority</option>
                    <?php foreach ($priorities as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $sel($form['priority_id'], $r['id']) ?>><?= e((string)$r['name']) ?><?= ($r['status'] ?? '') === 'inactive' ? ' (inactive)' : '' ?></option><?php endforeach; ?>
                </select></div>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label class="field__label" for="complaint_date">Complaint date <span class="field__required">*</span></label>
                <div class="control"><input class="input" type="date" id="complaint_date" name="complaint_date" required value="<?= e((string)$form['complaint_date']) ?>"></div>
                <p class="field__hint">The day the complaint was received — any past date is allowed.</p>
            </div>
            <div class="field">
                <label class="field__label" for="complaint_time">Complaint time</label>
                <div class="control"><input class="input" type="time" id="complaint_time" name="complaint_time" value="<?= e(substr((string)$form['complaint_time'], 0, 5)) ?>"></div>
            </div>
        </div>
        <div class="field">
            <label class="field__label" for="description">Description <span class="field__required">*</span></label>
            <textarea class="textarea" id="description" name="description" rows="4" maxlength="4000" required><?= e((string)$form['description']) ?></textarea>
        </div>
    </fieldset>

    <fieldset class="cmp-fieldset">
        <legend>Location</legend>
        <?php if (!$isEdit): ?>
            <div class="field-row cmp-cascade" data-cascade-url="<?= e(base_url('modules/complaints/process/cascade.php')) ?>">
                <div class="field">
                    <label class="field__label" for="region_id">Region <span class="field__required">*</span></label>
                    <div class="control"><select class="select" id="region_id" name="region_id" data-cascade-child="district" required>
                        <option value="">Select region</option>
                        <?php foreach ($regions as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $sel($form['region_id'], $r['id']) ?>><?= e((string)$r['name']) ?></option><?php endforeach; ?>
                    </select></div>
                </div>
                <div class="field">
                    <label class="field__label" for="district_id">District <span class="field__required">*</span></label>
                    <div class="control"><select class="select" id="district_id" name="district_id" data-cascade-child="tehsil" required>
                        <option value="">Select district</option>
                        <?php foreach ($cascade['district'] as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $sel($form['district_id'], $r['id']) ?>><?= e((string)$r['name']) ?></option><?php endforeach; ?>
                    </select></div>
                </div>
                <div class="field">
                    <label class="field__label" for="tehsil_id">Tehsil <span class="field__required">*</span></label>
                    <div class="control"><select class="select" id="tehsil_id" name="tehsil_id" data-cascade-child="uc" required>
                        <option value="">Select tehsil</option>
                        <?php foreach ($cascade['tehsil'] as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $sel($form['tehsil_id'], $r['id']) ?>><?= e((string)$r['name']) ?></option><?php endforeach; ?>
                    </select></div>
                </div>
                <div class="field">
                    <label class="field__label" for="uc_id">UC <span class="field__required">*</span></label>
                    <div class="control"><select class="select" id="uc_id" name="uc_id" data-cascade-child="beat" required>
                        <option value="">Select UC</option>
                        <?php foreach ($cascade['uc'] as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $sel($form['uc_id'], $r['id']) ?>><?= e((string)$r['name']) ?></option><?php endforeach; ?>
                    </select></div>
                </div>
                <div class="field">
                    <label class="field__label" for="beat_id">Beat (optional)</label>
                    <div class="control"><select class="select" id="beat_id" name="beat_id">
                        <option value="">No beat</option>
                        <?php foreach ($cascade['beat'] as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $sel($form['beat_id'], $r['id']) ?>><?= e((string)$r['name']) ?></option><?php endforeach; ?>
                    </select></div>
                </div>
            </div>
            <p class="field__hint">Choosing the UC assigns the complaint to it: the Supervisor and Monitoring Officer(s) assigned to that UC are notified and can see it automatically.</p>
        <?php else: ?>
            <p class="card__subtitle" style="margin:0 0 12px"><?= e((string)$complaint['region_name']) ?> → <?= e((string)$complaint['district_name']) ?> → <?= e((string)$complaint['tehsil_name']) ?> → <strong><?= e((string)$complaint['uc_name']) ?></strong><?= $complaint['beat_name'] ? ' → ' . e((string)$complaint['beat_name']) : '' ?>.
                To move the complaint to another UC/Beat use <em>Reassign</em> on the complaint page.</p>
        <?php endif; ?>

        <div class="field">
            <label class="field__label" for="location_address">Location / address details</label>
            <div class="control"><input class="input" id="location_address" name="location_address" maxlength="255" placeholder="Street, landmark, house…" value="<?= e((string)$form['location_address']) ?>"></div>
        </div>
        <div class="field-row">
            <div class="field">
                <label class="field__label" for="latitude">Latitude (optional)</label>
                <div class="control"><input class="input" id="latitude" name="latitude" inputmode="decimal" placeholder="31.5204" value="<?= e((string)$form['latitude']) ?>"></div>
            </div>
            <div class="field">
                <label class="field__label" for="longitude">Longitude (optional)</label>
                <div class="control"><input class="input" id="longitude" name="longitude" inputmode="decimal" placeholder="74.3587" value="<?= e((string)$form['longitude']) ?>"></div>
            </div>
            <div class="field cmp-gps">
                <button type="button" class="btn btn--ghost" data-gps><?= icon('map-pin') ?><span class="btn__label">Add GPS location</span></button>
                <span class="field__hint" data-gps-status></span>
            </div>
        </div>
    </fieldset>
</div>
