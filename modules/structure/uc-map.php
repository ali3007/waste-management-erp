<?php
/**
 * UC boundary file — upload, replace, remove, and view on a map.
 *
 * Rendering is entirely client-side: Leaflet draws the map, an OSM tile
 * layer gives it something to sit on (no API key, works for any country),
 * and leaflet-omnivore fetches the UC's own .kml file and parses it
 * straight into shapes — the same plain KML regardless of whether the
 * original upload was a .kml or a .kmz, since Security::storeMapFile()
 * already normalised that at upload time.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('structure.view');

$ucId = (int)query('id');
$uc = $ucId > 0 ? structure_find('uc', $ucId) : null;

if ($uc === null) {
    flash('error', 'That UC could not be found.');
    redirect('modules/structure/index.php');
}

$ancestors = structure_ancestors('uc', (int)$uc['tehsil_id']);
$hasMap = !empty($uc['kml_file']);
$kmlUrl = $hasMap ? base_url(UC_MAP_URI . '/' . rawurlencode($uc['kml_file'])) : '';
$kmzUrl = !empty($uc['kmz_file']) ? base_url(UC_MAP_URI . '/' . rawurlencode($uc['kmz_file'])) : '';

$backToUcs = base_url('modules/structure/index.php') . '?' . http_build_query([
    'level' => 'uc', 'parent' => (int)$uc['tehsil_id'],
]);

$pageTitle = $uc['name'] . ' — Boundary map';
$layout = 'app';
$extraStyles = ['structure.css'];

require INCLUDES_PATH . '/header.php';
?>

<a class="btn btn--ghost" href="<?= e($backToUcs) ?>" style="margin-bottom:16px">
    <?= icon('arrow-left') ?>
    <span class="btn__label">Back to UCs</span>
</a>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<nav class="crumb" aria-label="Breadcrumb">
    <?php foreach ($ancestors as $ancestor): ?>
        <span><?= e($ancestor['row']['name']) ?></span>
        <span class="crumb__sep">/</span>
    <?php endforeach; ?>
    <span class="crumb__current"><?= e((string)$uc['name']) ?> · Boundary map</span>
</nav>

<div class="grid grid--2" style="margin-bottom:20px">
    <section class="card">
        <h3 class="card__title">Boundary file</h3>
        <?php if ($hasMap): ?>
            <dl class="definition">
                <dt>Uploaded</dt>
                <dd><?= e(format_datetime($uc['map_uploaded_at'] ?? null)) ?></dd>
                <dt>Format</dt>
                <dd><?= $kmzUrl !== '' ? 'KMZ (original kept)' : 'KML' ?></dd>
            </dl>
            <div style="display:flex; gap:10px; margin-top:16px; flex-wrap:wrap">
                <a class="btn" href="<?= e($kmzUrl !== '' ? $kmzUrl : $kmlUrl) ?>" download>
                    <?= icon('image') ?>
                    <span class="btn__label">Download original</span>
                </a>
                <?php if (can('structure.map')): ?>
                    <form method="post" action="<?= e(base_url('modules/structure/process/map-upload.php')) ?>"
                          data-confirm="Remove this UC's boundary file? The map will be empty until a new one is uploaded.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="uc_id" value="<?= $ucId ?>">
                        <input type="hidden" name="action" value="remove">
                        <button type="submit" class="btn btn--danger">
                            <?= icon('ban') ?>
                            <span class="btn__label">Remove</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="card__subtitle" style="margin-bottom:0">No boundary file uploaded yet for this UC.</p>
        <?php endif; ?>
    </section>

    <?php if (can('structure.map')): ?>
        <section class="card">
            <h3 class="card__title"><?= $hasMap ? 'Replace' : 'Upload' ?> KML or KMZ</h3>
            <p class="card__subtitle">
                Up to 10 MB, depending on this server's own upload limits.
                The file's actual content is checked — renaming a file doesn't bypass this.
            </p>
            <form method="post" action="<?= e(base_url('modules/structure/process/map-upload.php')) ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="uc_id" value="<?= $ucId ?>">
                <input type="hidden" name="action" value="upload">
                <div class="field">
                    <div class="control">
                        <input class="input" type="file" name="map_file" accept=".kml,.kmz" required>
                    </div>
                </div>
                <button type="submit" class="btn btn--primary">
                    <span class="btn__label"><?= $hasMap ? 'Replace file' : 'Upload file' ?></span>
                </button>
            </form>
        </section>
    <?php endif; ?>
</div>

<?php if ($hasMap): ?>
    <div class="card">
        <h3 class="card__title">Map</h3>
        <div id="uc-map-canvas" style="height:480px; border-radius:var(--radius-md); overflow:hidden; box-shadow:var(--shadow-inset-sm)"></div>
        <p class="field__hint" id="uc-map-status" style="margin-top:10px"></p>
    </div>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet-omnivore@0.3.4/leaflet-omnivore.min.js"></script>
    <script>
        (function () {
            var statusEl = document.getElementById('uc-map-status');
            var map = L.map('uc-map-canvas').setView([30.3753, 69.3451], 5); // Pakistan-wide default, overridden below once shapes load

            /* Tile source is a system setting, not hard-coded — see the
               comment in bootstrap.php's structure_ensure_schema() for
               why. To switch providers (e.g. to CARTO with your own free
               key, or any other provider), update the map_tile_url and
               map_tile_attribution rows in system_settings — no file
               changes needed. */
            L.tileLayer(<?= json_encode(setting('map_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png')) ?>, {
                maxZoom: 19,
                attribution: <?= json_encode(setting('map_tile_attribution', '&copy; OpenStreetMap contributors')) ?>
            }).addTo(map);

            var layer = omnivore.kml(<?= json_encode($kmlUrl) ?>)
                .on('ready', function () {
                    var bounds = layer.getBounds();
                    if (bounds.isValid()) {
                        map.fitBounds(bounds, { padding: [20, 20] });
                    } else {
                        statusEl.textContent = 'The file loaded but contained no drawable shapes.';
                    }
                })
                .on('error', function () {
                    statusEl.textContent = 'The boundary file could not be drawn on the map.';
                })
                .addTo(map);
        })();
    </script>
<?php endif; ?>

<?php if (Auth::isSuperAdmin()): ?>
    <div class="card" style="margin-top:20px">
        <h3 class="card__title">Map tile source</h3>
        <p class="card__subtitle">
            Applies to every UC's map, not just this one. Free tile providers have changed their terms
            without warning before — if maps start showing a block message or a watermark instead of a
            normal background, paste a working tile URL here rather than editing code.
        </p>
        <form method="post" action="<?= e(base_url('modules/structure/process/map-settings.php')) ?>">
            <?= csrf_field() ?>
            <?php field_input('map_tile_url', 'Tile URL template', [
                'value' => setting('map_tile_url', ''), 'required' => true,
                'hint' => 'Must contain {z}, {x} and {y}. Example: https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            ]); ?>
            <?php field_textarea('map_tile_attribution', 'Attribution (HTML allowed)', [
                'value' => setting('map_tile_attribution', ''), 'rows' => 2,
            ]); ?>
            <button type="submit" class="btn btn--primary">
                <span class="btn__label">Save tile source</span>
            </button>
        </form>
    </div>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
