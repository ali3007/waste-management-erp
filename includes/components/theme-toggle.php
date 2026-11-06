<?php
/**
 * Light/dark switcher. Behaviour lives in assets/js/theme.js.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');
?>
<button type="button" class="theme-toggle" aria-pressed="false" aria-label="Switch theme">
    <span class="theme-toggle__track-icons" aria-hidden="true">
        <?= icon('sun') ?>
        <?= icon('moon') ?>
    </span>
    <span class="theme-toggle__knob">
        <span class="theme-toggle__icon theme-toggle__icon--sun"><?= icon('sun') ?></span>
        <span class="theme-toggle__icon theme-toggle__icon--moon"><?= icon('moon') ?></span>
    </span>
</button>
