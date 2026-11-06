<?php
/**
 * Reusable form controls. Keeping the markup in one place means every
 * future module gets identical inputs for free.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/**
 * A labelled text input, optionally with a leading icon.
 *
 * @param array{
 *   type?:string, value?:string, placeholder?:string, icon?:string,
 *   required?:bool, hint?:string, autocomplete?:string, attrs?:string,
 *   inputmode?:string, maxlength?:int
 * } $options
 */
function field_input(string $id, string $label, array $options = []): void
{
    $type = $options['type'] ?? 'text';
    $required = !empty($options['required']);
    ?>
    <div class="field">
        <label class="field__label" for="<?= e($id) ?>">
            <?= e($label) ?><?= $required ? ' <span class="field__required">*</span>' : '' ?>
        </label>
        <div class="control">
            <?php if (!empty($options['icon'])): ?>
                <span class="control__icon"><?= icon($options['icon']) ?></span>
            <?php endif; ?>
            <input
                class="input"
                type="<?= e($type) ?>"
                id="<?= e($id) ?>"
                name="<?= e($options['name'] ?? $id) ?>"
                value="<?= e($options['value'] ?? '') ?>"
                placeholder="<?= e($options['placeholder'] ?? '') ?>"
                <?= isset($options['autocomplete']) ? 'autocomplete="' . e($options['autocomplete']) . '"' : '' ?>
                <?= isset($options['inputmode']) ? 'inputmode="' . e($options['inputmode']) . '"' : '' ?>
                <?= isset($options['maxlength']) ? 'maxlength="' . (int)$options['maxlength'] . '"' : '' ?>
                <?= $required ? 'required' : '' ?>
                <?= $options['attrs'] ?? '' ?>>
            <?php if ($type === 'password'): ?>
                <button type="button" class="control__action" data-password-toggle="<?= e($id) ?>" aria-label="Show password">
                    <span data-icon="eye"><?= icon('eye') ?></span>
                    <span data-icon="eye-off" style="display:none"><?= icon('eye-off') ?></span>
                </button>
            <?php endif; ?>
        </div>
        <?php if (!empty($options['hint'])): ?>
            <p class="field__hint" id="<?= e($id) ?>-hint"><?= e($options['hint']) ?></p>
        <?php endif; ?>
        <p class="field__error"></p>
    </div>
    <?php
}

/** A labelled textarea. */
function field_textarea(string $id, string $label, array $options = []): void
{
    ?>
    <div class="field">
        <label class="field__label" for="<?= e($id) ?>">
            <?= e($label) ?><?= !empty($options['required']) ? ' <span class="field__required">*</span>' : '' ?>
        </label>
        <div class="control">
            <textarea
                class="textarea"
                id="<?= e($id) ?>"
                name="<?= e($options['name'] ?? $id) ?>"
                rows="<?= (int)($options['rows'] ?? 3) ?>"
                placeholder="<?= e($options['placeholder'] ?? '') ?>"
                <?= !empty($options['required']) ? 'required' : '' ?>><?= e($options['value'] ?? '') ?></textarea>
        </div>
        <p class="field__error"></p>
    </div>
    <?php
}

/** A password strength meter, paired with a password field by JavaScript. */
function field_strength(): void
{
    ?>
    <div class="strength" data-level="0">
        <div class="strength__track"><div class="strength__bar"></div></div>
        <div class="strength__label"></div>
    </div>
    <?php
}

/** An image picker with a live preview. */
function field_uploader(string $id, string $label, string $hint, string $placeholderIcon = 'image'): void
{
    $placeholder = icon($placeholderIcon);
    ?>
    <div class="field">
        <span class="field__label"><?= e($label) ?></span>
        <div class="uploader" data-uploader>
            <div class="uploader__preview" data-placeholder="<?= e($placeholder) ?>"><?= $placeholder ?></div>
            <div class="uploader__body">
                <div class="uploader__name">No file selected</div>
                <p><?= e($hint) ?></p>
                <div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap">
                    <button type="button" class="btn btn--ghost" data-uploader-trigger>Choose image</button>
                    <button type="button" class="btn btn--ghost" data-uploader-clear hidden>Remove</button>
                </div>
            </div>
            <input type="file" id="<?= e($id) ?>" name="<?= e($id) ?>" accept="image/png,image/jpeg,image/gif,image/webp">
        </div>
        <p class="field__error"></p>
    </div>
    <?php
}
