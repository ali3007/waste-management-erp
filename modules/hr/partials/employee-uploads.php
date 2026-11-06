<?php
/**
 * Upload inputs for the Add/Edit Employee forms: Employee Picture,
 * CNIC Picture, Other Documents (any number). Shown only to users who
 * hold employee.doc_create — and the server re-checks it, so hiding this
 * block is convenience, not the control.
 *
 * Expects $uploadEmployee (employee row on edit, null on create).
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

$photoRules = hr_upload_rules('photo');
$cnicRules = hr_upload_rules('cnic');
$docRules = hr_upload_rules('doc');
?>
<h3 style="margin:24px 0 12px; font-size:var(--text-base)">Pictures &amp; documents</h3>
<p class="card__subtitle" style="margin-top:-6px">All files are stored privately and are only visible to users with document access.</p>

<div class="field-row">
    <div class="field">
        <label class="field__label" for="employee_picture">Employee Picture (optional)</label>
        <input class="file-input" type="file" id="employee_picture" name="employee_picture" accept="image/png,image/jpeg,image/gif,image/webp">
        <p class="field__hint">JPG, PNG, GIF or WEBP, up to <?= e(hr_size_label($photoRules['max'])) ?>.</p>
        <?php if (!empty($uploadEmployee['employee_picture'])): ?>
            <p class="field__hint">
                <img src="<?= e(hr_file_url((int)$uploadEmployee['id'], 'photo')) ?>" alt="" style="width:46px;height:56px;object-fit:cover;border-radius:8px;vertical-align:middle;margin-right:8px">
                A picture is on file — choosing a new one replaces it.
                <label style="margin-left:8px"><input type="checkbox" name="remove_employee_picture" value="1"> Remove it</label>
            </p>
        <?php endif; ?>
    </div>

    <div class="field">
        <label class="field__label" for="cnic_picture">CNIC Picture (optional)</label>
        <input class="file-input" type="file" id="cnic_picture" name="cnic_picture" accept="image/png,image/jpeg,image/gif,image/webp,application/pdf">
        <p class="field__hint">Image or PDF, up to <?= e(hr_size_label($cnicRules['max'])) ?>.</p>
        <?php if (!empty($uploadEmployee['cnic_picture'])): ?>
            <p class="field__hint">
                A CNIC file is on file — choosing a new one replaces it.
                <label style="margin-left:8px"><input type="checkbox" name="remove_cnic_picture" value="1"> Remove it</label>
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="field">
    <label class="field__label" for="other_documents">Other Documents (optional, as many as needed)</label>
    <input class="file-input" type="file" id="other_documents" name="other_documents[]" multiple
           accept="application/pdf,.doc,.docx,image/png,image/jpeg,image/gif,image/webp">
    <p class="field__hint">Joining document, contract, certificates, supporting papers… PDF, DOC/DOCX or image, up to <?= e(hr_size_label($docRules['max'])) ?> each.
        <?php if (!empty($uploadEmployee)): ?>Existing documents are managed on the employee's profile.<?php endif; ?></p>
</div>
