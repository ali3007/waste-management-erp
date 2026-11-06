<?php
/**
 * Site — full detail view.
 *
 * Region/District/Tehsil/UC/Beat are compact enough that their list row
 * doubles as their "view" — a Site carries more (type, coordinates,
 * address), so it gets its own page, exactly as the spec asks for.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('structure.view');

$id = (int)query('id');
$site = $id > 0 ? Database::fetch(
    'SELECT s.*, st.name AS site_type_name
       FROM sites s LEFT JOIN site_types st ON st.id = s.site_type_id
      WHERE s.id = :id',
    ['id' => $id]
) : null;

if ($site === null) {
    flash('error', 'That site could not be found.');
    redirect('modules/structure/sites.php');
}

$ancestors = structure_ancestors('site', (int)$site['uc_id']);
$ucRow = end($ancestors)['row'] ?? null;
$mapLink = structure_map_link($site['latitude'], $site['longitude']);

/* Where "back" and "edit" should return to — the UC's own Site list,
   not the global browser, so context (which UC) isn't lost. */
$backToList = base_url('modules/structure/index.php') . '?' . http_build_query(['level' => 'site', 'parent' => (int)$site['uc_id']]);

$pageTitle = $site['name'];
$layout = 'app';
$extraStyles = ['structure.css'];

require INCLUDES_PATH . '/header.php';
?>

<a class="btn btn--ghost" href="<?= e($backToList) ?>" style="margin-bottom:16px">
    <?= icon('arrow-left') ?>
    <span class="btn__label">Back to Sites</span>
</a>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<nav class="crumb" aria-label="Breadcrumb">
    <?php foreach ($ancestors as $ancestor): ?>
        <span><?= e($ancestor['row']['name']) ?></span>
        <span class="crumb__sep">/</span>
    <?php endforeach; ?>
    <span class="crumb__current"><?= e((string)$site['name']) ?></span>
</nav>

<div class="grid grid--2">
    <section class="card">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px">
            <h2 style="margin:0"><?= e((string)$site['name']) ?></h2>
            <span class="badge <?= e(structure_status_badge((string)$site['status'])) ?>">
                <?= e(structure_status_label((string)$site['status'])) ?>
            </span>
        </div>

        <dl class="definition">
            <dt>Site Number</dt>
            <dd><?= e((string)$site['code']) ?></dd>

            <dt>Site Type</dt>
            <dd><?= e((string)($site['site_type_name'] ?? '—')) ?></dd>

            <dt>UC</dt>
            <dd><?= e((string)($ucRow['name'] ?? '—')) ?></dd>

            <dt>Address</dt>
            <dd><?= e((string)($site['address'] ?: '—')) ?></dd>

            <dt>Description</dt>
            <dd><?= e((string)($site['description'] ?: '—')) ?></dd>

            <dt>Coordinates</dt>
            <dd>
                <?php if ($site['latitude'] !== null && $site['longitude'] !== null): ?>
                    <?= e((string)$site['latitude']) ?>, <?= e((string)$site['longitude']) ?>
                    <?php if ($mapLink !== ''): ?>
                        · <a href="<?= e($mapLink) ?>" target="_blank" rel="noopener">View on map</a>
                    <?php endif; ?>
                <?php else: ?>
                    Not recorded
                <?php endif; ?>
            </dd>
        </dl>

        <?php if (can('structure.update')): ?>
            <a class="btn" href="<?= e($backToList . '&edit=' . $id) ?>" style="margin-top:20px">
                <?= icon('pencil') ?>
                <span class="btn__label">Edit</span>
            </a>
        <?php endif; ?>
    </section>

    <section class="card">
        <h3 class="card__title">Where this Site sits</h3>
        <p class="card__subtitle">The full administrative chain this Site belongs to.</p>
        <dl class="definition">
            <?php foreach ($ancestors as $ancestor): ?>
                <dt><?= e(ucfirst($ancestor['level'])) ?></dt>
                <dd><?= e((string)$ancestor['row']['name']) ?></dd>
            <?php endforeach; ?>
        </dl>

        <h3 class="card__title" style="margin-top:24px">Record</h3>
        <dl class="definition">
            <dt>Created</dt>
            <dd><?= e(format_datetime($site['created_at'] ?? null)) ?></dd>
            <dt>Last updated</dt>
            <dd><?= e(format_datetime($site['updated_at'] ?? null)) ?></dd>
        </dl>
    </section>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
