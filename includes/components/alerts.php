<?php
/**
 * Renders queued flash messages, plus an empty slot that JavaScript
 * reuses for client-side and AJAX messages.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

$icons = ['error' => 'alert', 'success' => 'check-big', 'warning' => 'alert', 'info' => 'info'];

foreach (take_flash() as $message):
    $type = in_array($message['type'], ['error', 'success', 'warning', 'info'], true) ? $message['type'] : 'info';
    ?>
    <div class="alert alert--<?= e($type) ?>" role="alert">
        <?= icon($icons[$type]) ?>
        <span><?= e($message['message']) ?></span>
    </div>
<?php endforeach; ?>
<div class="alert" id="form-alert" role="alert" hidden></div>
