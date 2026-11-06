<?php
/**
 * Closing markup and script tags.
 * $extraScripts holds filenames inside assets/js/ to load after app.js.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

$layout = $layout ?? 'auth';
$extraScripts = $extraScripts ?? [];
?>
<?php if ($layout === 'app'): ?>
            </main>
            <footer class="page-footer">
                <span><?= e(company()['name'] ?: APP_PRODUCT_NAME) ?> · <?= e(APP_PRODUCT_NAME) ?></span>
                <span>Version <?= e(APP_VERSION) ?></span>
            </footer>
        </div>
    </div>
<?php else: ?>
    </main>
<?php endif; ?>

<script src="<?= e(asset('js/theme.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if ($layout === 'app' && can('complaint.view')): ?>
    <script src="<?= e(asset('js/notifications.js')) ?>"></script>
<?php endif; ?>
<?php foreach ($extraScripts as $scriptFile): ?>
    <script src="<?= e(asset('js/' . $scriptFile)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
