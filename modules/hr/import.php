<?php
/**
 * Bulk Employee Import (spec sections 22–25).
 *
 * One page, three states, selected by ?step=
 *   (none)   download template + upload
 *   preview  validation summary, row errors, skipped duplicates, confirm
 *   done     result of the import
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('hr.import');

$step = query('step');
$token = query('t');
$staged = null;

if ($step === 'preview') {
    $staged = hr_import_load($token);
    if ($staged === null) {
        flash('error', 'That import preview has expired or was already used. Upload the file again.');
        redirect('modules/hr/import.php');
    }
} elseif ($step === 'done') {
    $done = $_SESSION['hr_import_done'] ?? null;
    unset($_SESSION['hr_import_done']);
    if (!is_array($done)) {
        redirect('modules/hr/import.php');
    }
} else {
    $step = 'upload';
}

$columns = hr_import_columns();
$reference = $step === 'upload' ? hr_import_reference() : null;
$canCreate = can('employee.create');
$previewLimit = 25;
$listLimit = 300;

$pageTitle = 'Bulk employee import';
$layout = 'app';

require INCLUDES_PATH . '/header.php';

$stepClass = static fn(int $n, int $current): string => $n < $current ? 'is-done' : ($n === $current ? 'is-current' : '');
$current = $step === 'upload' ? 1 : ($step === 'preview' ? 3 : 5);
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<ol class="steps">
    <li class="<?= $stepClass(1, $current) ?>">Download template</li>
    <li class="<?= $stepClass(2, $current) ?>">Upload CSV</li>
    <li class="<?= $stepClass(3, $current) ?>">Validate &amp; preview</li>
    <li class="<?= $stepClass(4, $current) ?>">Confirm</li>
    <li class="<?= $stepClass(5, $current) ?>">Imported</li>
</ol>

<?php if ($step === 'upload'): ?>

    <div class="grid grid--2">
        <section class="card">
            <h2 class="card__title">1. Get the template</h2>
            <p class="card__subtitle">A CSV with the right column headings. Fill in one employee per row.</p>
            <a class="btn btn--primary" href="<?= e(base_url('modules/hr/process/import-template.php')) ?>">
                <?= icon('download') ?><span class="btn__label">Download CSV template</span>
            </a>

            <h3 style="margin:24px 0 8px;font-size:var(--text-base)">Columns</h3>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Column</th><th>Required</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach ($columns as [$key, $label, $required, $note]): ?>
                            <tr>
                                <td><strong><?= e($label) ?></strong></td>
                                <td><?= $required ? 'Yes' : 'No' ?></td>
                                <td class="is-wrap"><?= e($note) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="filter-hint">UC and Beat are not part of the file. Imported employees go to <strong>Unassigned Employees</strong> and are assigned afterwards.</p>
        </section>

        <section class="card">
            <h2 class="card__title">2. Upload your file</h2>
            <p class="card__subtitle">Nothing is saved yet — you'll see a preview first.</p>

            <?php if (!$canCreate): ?>
                <div class="alert alert--warning"><?= icon('alert') ?><span>You can open this page but need the "Add employees" permission to import.</span></div>
            <?php else: ?>
                <form method="post" action="<?= e(base_url('modules/hr/process/import-upload.php')) ?>" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="field__label" for="csv">CSV file <span class="field__required">*</span></label>
                        <input class="file-input" type="file" id="csv" name="csv" accept=".csv,text/csv" required>
                        <p class="field__hint">.csv, up to <?= e(hr_size_label(HR_IMPORT_MAX_BYTES)) ?> and <?= number_format(HR_IMPORT_MAX_ROWS) ?> rows. In Excel use Save As → CSV UTF-8.</p>
                    </div>
                    <button type="submit" class="btn btn--primary" style="margin-top:12px">
                        <?= icon('upload') ?><span class="btn__label">Validate &amp; preview</span>
                    </button>
                </form>
            <?php endif; ?>

            <h3 style="margin:24px 0 8px;font-size:var(--text-base)">Rules that are checked</h3>
            <ul style="margin:0;padding-left:18px;font-size:var(--text-sm);color:var(--text-muted);line-height:1.7">
                <li>Name, father's name and a valid 13-digit CNIC are required.</li>
                <li>Region → District → Tehsil must exist and belong together.</li>
                <li>Staff Pool → Staff Type must exist and belong together.</li>
                <li>A CNIC that already exists (or repeats in the file) is skipped, never duplicated.</li>
                <li>Bad rows are listed with their row number and are never imported silently.</li>
            </ul>
        </section>
    </div>

    <details class="card" style="margin-top:var(--space-4)">
        <summary>Valid names to use in the file</summary>
        <div style="margin-top:var(--space-4)">
            <h4 style="margin:0 0 8px">Region / District / Tehsil</h4>
            <?php if (empty($reference['regions'])): ?>
                <p class="card__subtitle">No active Regions/Districts exist yet — add them under Structure first.</p>
            <?php else: ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Region</th><th>District</th><th>Tehsils</th></tr></thead>
                    <tbody>
                        <?php foreach ($reference['regions'] as $r): ?>
                            <tr><td><?= e((string)$r['region']) ?></td><td><?= e((string)$r['district']) ?></td><td class="is-wrap"><?= e((string)($r['tehsils'] ?: '—')) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>

            <h4 style="margin:20px 0 8px">Staff Pool / Staff Type</h4>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Staff Pool</th><th>Staff Types</th></tr></thead>
                <tbody>
                    <?php foreach ($reference['types'] as $r): ?>
                        <tr><td><?= e((string)$r['pool']) ?></td><td class="is-wrap"><?= e((string)($r['types'] ?: 'No types yet — add them under HR Types')) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </details>

<?php elseif ($step === 'preview'): ?>
    <?php
    $res = $staged['result'];
    $valid = $res['valid'];
    $skipped = $res['skipped'];
    $errors = $res['errors'];
    ?>

    <div class="grid grid--4" style="margin-bottom:var(--space-4)">
        <div class="card card--flat stat-card">
            <span class="stat-card__icon"><?= icon('file-text') ?></span>
            <div class="stat__label">Total rows</div>
            <div class="stat__value"><?= number_format((int)$res['total']) ?></div>
            <div class="stat__meta"><?= e((string)$staged['file']) ?></div>
        </div>
        <div class="card card--flat stat-card">
            <span class="stat-card__icon"><?= icon('check-big') ?></span>
            <div class="stat__label">Valid — will be imported</div>
            <div class="stat__value"><?= number_format(count($valid)) ?></div>
            <div class="stat__meta">Ready to create</div>
        </div>
        <div class="card card--flat stat-card stat-card--accent-warn">
            <span class="stat-card__icon"><?= icon('copy') ?></span>
            <div class="stat__label">Skipped — duplicate CNIC</div>
            <div class="stat__value"><?= number_format(count($skipped)) ?></div>
            <div class="stat__meta">Never duplicated</div>
        </div>
        <div class="card card--flat stat-card stat-card--accent-danger">
            <span class="stat-card__icon"><?= icon('alert') ?></span>
            <div class="stat__label">Error rows</div>
            <div class="stat__value"><?= number_format(count($errors)) ?></div>
            <div class="stat__meta">Not imported</div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <section class="card" style="margin-bottom:var(--space-4)">
            <h3 class="card__title">Errors (<?= number_format(count($errors)) ?>)</h3>
            <p class="card__subtitle">These rows will <strong>not</strong> be imported. Row numbers match your spreadsheet (row 1 is the header).</p>
            <ul class="error-list">
                <?php foreach (array_slice($errors, 0, $listLimit) as $item): ?>
                    <li><strong>Row <?= (int)$item['row'] ?>:</strong> <?= e((string)$item['reason']) ?>
                        <?php if ($item['name'] !== ''): ?><span class="cell-sub"><?= e((string)$item['name']) ?></span><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($errors) > $listLimit): ?><p class="filter-hint">Showing the first <?= $listLimit ?> of <?= number_format(count($errors)) ?>. Download the full list below.</p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($skipped)): ?>
        <section class="card" style="margin-bottom:var(--space-4)">
            <h3 class="card__title">Skipped duplicates (<?= number_format(count($skipped)) ?>)</h3>
            <p class="card__subtitle">The CNIC already exists, so no second record is created.</p>
            <ul class="error-list error-list--skip">
                <?php foreach (array_slice($skipped, 0, $listLimit) as $item): ?>
                    <li><strong>Row <?= (int)$item['row'] ?>:</strong> <?= e((string)$item['reason']) ?>
                        <span class="cell-sub"><?= e((string)$item['name']) ?> · <?= e(Security::formatCnic((string)$item['cnic'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($skipped) > $listLimit): ?><p class="filter-hint">Showing the first <?= $listLimit ?> of <?= number_format(count($skipped)) ?>.</p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($valid)): ?>
        <section class="card" style="margin-bottom:var(--space-4)">
            <h3 class="card__title">Preview of valid rows</h3>
            <p class="card__subtitle">First <?= min($previewLimit, count($valid)) ?> of <?= number_format(count($valid)) ?>. All of them will start in Unassigned Employees.</p>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Row</th><th>Name</th><th>Father</th><th>CNIC</th><th>Area</th><th>Pool / Type</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($valid, 0, $previewLimit) as $v): ?>
                            <tr>
                                <td><?= (int)$v['row'] ?></td>
                                <td><strong><?= e((string)$v['name']) ?></strong></td>
                                <td><?= e((string)$v['father_name']) ?></td>
                                <td><?= e(Security::formatCnic((string)$v['cnic'])) ?></td>
                                <td><?= e((string)$v['tehsil']) ?>, <?= e((string)$v['district']) ?></td>
                                <td><?= e((string)$v['type']) ?><span class="cell-sub"><?= e((string)$v['pool']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <div class="toolbar">
        <?php if (!empty($valid) && $canCreate): ?>
            <form method="post" action="<?= e(base_url('modules/hr/process/import-confirm.php')) ?>"
                  data-confirm="Import <?= count($valid) ?> employee<?= count($valid) === 1 ? '' : 's' ?>? This creates real employee records.">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <button type="submit" class="btn btn--primary"><?= icon('check-big') ?><span class="btn__label">Confirm import of <?= number_format(count($valid)) ?> valid row<?= count($valid) === 1 ? '' : 's' ?></span></button>
            </form>
        <?php elseif (empty($valid)): ?>
            <span class="badge badge--inactive">Nothing valid to import — fix the file and upload again</span>
        <?php endif; ?>
        <a class="btn btn--ghost" href="<?= e(base_url('modules/hr/import.php')) ?>"><span class="btn__label">Cancel &amp; upload another file</span></a>
        <?php if (!empty($errors) || !empty($skipped)): ?>
            <a class="btn btn--ghost" href="<?= e(base_url('modules/hr/process/import-problems.php?t=' . rawurlencode($token))) ?>"><?= icon('download') ?><span class="btn__label">Download problem rows (CSV)</span></a>
        <?php endif; ?>
    </div>

<?php else: /* done */ ?>

    <div class="grid grid--3" style="margin-bottom:var(--space-4)">
        <div class="card card--flat stat-card">
            <span class="stat-card__icon"><?= icon('check-big') ?></span>
            <div class="stat__label">Imported</div>
            <div class="stat__value"><?= number_format((int)$done['imported']) ?></div>
            <div class="stat__meta">New employees created</div>
        </div>
        <div class="card card--flat stat-card stat-card--accent-warn">
            <span class="stat-card__icon"><?= icon('copy') ?></span>
            <div class="stat__label">Skipped</div>
            <div class="stat__value"><?= number_format((int)$done['skipped']) ?></div>
            <div class="stat__meta">Duplicate CNICs</div>
        </div>
        <div class="card card--flat stat-card stat-card--accent-danger">
            <span class="stat-card__icon"><?= icon('alert') ?></span>
            <div class="stat__label">Errors</div>
            <div class="stat__value"><?= number_format((int)$done['errors']) ?></div>
            <div class="stat__meta">Not imported</div>
        </div>
    </div>

    <div class="card">
        <h2 class="card__title">Import finished</h2>
        <p class="card__subtitle"><?= number_format((int)$done['imported']) ?> employee<?= (int)$done['imported'] === 1 ? ' was' : 's were' ?> created from "<?= e((string)$done['file']) ?>" and <?= (int)$done['imported'] === 1 ? 'is' : 'are' ?> now in the Unassigned Employees list.</p>
        <?php if (!empty($done['race'])): ?>
            <div class="alert alert--warning"><?= icon('alert') ?><span>Rows <?= e(implode(', ', array_map('strval', $done['race']))) ?> were skipped because the same CNIC was added by someone else while you were reviewing.</span></div>
        <?php endif; ?>
        <div class="toolbar" style="margin:16px 0 0">
            <?php if (can('assignment.view')): ?>
                <a class="btn btn--primary" href="<?= e(base_url('modules/hr/unassigned.php')) ?>"><span class="btn__label">Go to Unassigned Employees →</span></a>
            <?php endif; ?>
            <a class="btn btn--ghost" href="<?= e(base_url('modules/hr/import.php')) ?>"><span class="btn__label">Import another file</span></a>
            <a class="btn btn--ghost" href="<?= e(base_url('modules/hr/employees.php')) ?>"><span class="btn__label">All employees</span></a>
        </div>
    </div>

<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
