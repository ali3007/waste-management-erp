<?php
/**
 * AJAX: active children of one structure level, for the Region → District →
 * Tehsil → UC → Beat cascade on the complaint forms and filters. Reads the
 * existing Phase 2 tables; one level per request so a large national
 * dataset is never shipped in one page.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    json_response(['ok' => false, 'message' => 'Not installed.'], 503);
}
Auth::requireLogin();
if (!can('complaint.view') && !can('complaint.create') && !can('complaint.assign')) {
    json_response(['ok' => false, 'message' => 'You do not have access to this data.'], 403);
}

$level = query('level');
$parent = (int)query('parent');
if (!in_array($level, ['district', 'tehsil', 'uc', 'beat'], true)) {
    json_response(['ok' => false, 'message' => 'Unknown level.'], 400);
}

$items = cmp_geography_children($level, $parent);

/* A field user (Supervisor / Monitoring Officer) only needs their own UCs. */
if ($level === 'uc' && ($ids = cmp_scope_uc_ids()) !== null) {
    $items = array_values(array_filter($items, static fn($r) => in_array((int)$r['id'], $ids, true)));
}
json_response(['ok' => true, 'items' => $items]);
