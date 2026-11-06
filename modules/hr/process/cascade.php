<?php
/**
 * AJAX: active children of a Structure level, for the Assignment
 * screen's Region→District→Tehsil→UC→Beat cascade.
 *
 * The full hierarchy isn't embedded inline the way employee-create's
 * smaller Region/District/Tehsil cascade is — a country-wide UC/Beat
 * dataset can run into the thousands, and shipping all of it on every
 * page load is exactly what the spec's own performance section warns
 * against. This fetches one level's worth at a time instead, reusing
 * Structure's own level config (STRUCTURE_LEVELS) rather than
 * duplicating the table/parent-column mapping here.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/structure/bootstrap.php';

if (!IS_INSTALLED) {
    json_response(['ok' => false, 'message' => 'Not installed.'], 503);
}

Auth::requireLogin();

if (!can('assignment.view') && !can('assignment.create') && !can('assignment.update')) {
    json_response(['ok' => false, 'message' => 'You do not have access to this data.'], 403);
}

$level = query('level');
$parentId = (int)query('parent');

/* Beat isn't in STRUCTURE_LEVELS' generic config (it's Structure's own
   concept, added there in that module's Stage 2) — same lookup shape,
   asked for directly since it's the one level this endpoint needs that
   Structure's config already fully describes anyway. */
if ($level === 'beat') {
    if ($parentId <= 0) {
        json_response(['ok' => true, 'items' => []]);
    }
    $rows = Database::fetchAll(
        "SELECT id, name FROM beats WHERE uc_id = :parent AND status = 'active' ORDER BY name ASC",
        ['parent' => $parentId]
    );
    json_response(['ok' => true, 'items' => $rows]);
}

$config = structure_level_config($level);
if ($config === null || $config['parent_column'] === null) {
    json_response(['ok' => false, 'message' => 'Unknown level.'], 400);
}

if ($parentId <= 0) {
    json_response(['ok' => true, 'items' => []]);
}

$rows = Database::fetchAll(
    "SELECT id, name FROM `{$config['table']}` WHERE `{$config['parent_column']}` = :parent AND status = 'active' ORDER BY name ASC",
    ['parent' => $parentId]
);

json_response(['ok' => true, 'items' => $rows]);
