<?php
/**
 * Evidence housekeeping: save a drag-and-drop order, or remove one picture.
 *
 *   op=reorder  kind=before|after  ids[]=<picture ids in the new order>
 *   op=delete   kind=before|after  id=<picture id>
 *
 * The order saved here is the order the gallery shows and the order the
 * PowerPoint uses. Before and After are independent sequences.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    json_response(['ok' => false, 'message' => 'Not installed.'], 503);
}
if (!is_post()) {
    json_response(['ok' => false, 'message' => 'Invalid request.'], 405);
}
Auth::requireLogin();
if (!Security::verifyCsrf()) {
    json_response(['ok' => false, 'message' => 'Your session expired. Reload the page and try again.'], 419);
}

$complaintId = (int)post('complaint_id');
$kind = post('kind');
$op = post('op');
if (!in_array($kind, ['before', 'after'], true) || !in_array($op, ['reorder', 'delete'], true)) {
    json_response(['ok' => false, 'message' => 'Invalid request.'], 422);
}

$complaint = cmp_require_access($complaintId, 'complaint.view');
cmp_require_action($complaint, $kind === 'before' ? 'evidence_before' : 'evidence_after');

try {
    if ($op === 'reorder') {
        $ids = $_POST['ids'] ?? [];
        $ids = is_array($ids) ? $ids : [];
        $n = cmp_reorder_evidence($complaintId, $kind, $ids);
        cmp_timeline($complaintId, $kind . '_reordered', ($kind === 'before' ? 'Before' : 'After') . ' pictures reordered', null, $n . ' pictures');
        json_response(['ok' => true, 'count' => $n]);
    }

    $pictureId = (int)post('id');
    $row = Database::fetch('SELECT * FROM complaint_evidence WHERE id = :id AND complaint_id = :c AND kind = :k', ['id' => $pictureId, 'c' => $complaintId, 'k' => $kind]);
    if ($row === null) {
        json_response(['ok' => false, 'message' => 'Picture not found.'], 404);
    }
    cmp_delete_evidence($row);
    cmp_timeline($complaintId, $kind . '_removed', ($kind === 'before' ? 'Before' : 'After') . ' picture removed', (string)$row['original_name'], null);
    $remaining = array_map(static fn($r) => (int)$r['id'], cmp_evidence_list($complaintId, $kind));
    json_response(['ok' => true, 'remaining' => $remaining]);
} catch (Throwable $e) {
    log_event('complaints', 'Evidence order/delete failed', ['error' => $e->getMessage()]);
    json_response(['ok' => false, 'message' => 'The change could not be saved.'], 500);
}
