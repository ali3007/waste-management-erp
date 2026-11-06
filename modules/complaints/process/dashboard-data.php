<?php
/**
 * JSON for the dashboard: cards, every chart's dataset and the first
 * page of the listing (as HTML), for one filter set. Needs complaint.view;
 * the user's UC scope is applied inside the SQL (lib/filters.php).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    json_response(['ok' => false, 'message' => 'Not installed.'], 503);
}
Auth::requireLogin();
Permission::require('complaint.view');

$filters = cmp_read_filters($_GET);
$group = in_array(query('group'), ['day', 'week', 'month'], true) ? query('group') : 'auto';
$page = max(1, (int)query('page', '1'));
$perPage = 15;

try {
    $data = cmp_dashboard_data($filters, $group);
    $list = cmp_list($filters, $page, $perPage);
} catch (Throwable $e) {
    log_event('complaints', 'Dashboard data failed', ['error' => $e->getMessage()]);
    json_response(['ok' => false, 'message' => 'The dashboard could not be loaded.'], 500);
}

$base = $filters;
$data['ok'] = true;
$data['list_html'] = cmp_render_table($list['rows']);
$data['pager_html'] = cmp_render_pagination($list['page'], $list['total'], $perPage, static fn(int $p): string => '#page=' . $p, true);
$data['total'] = $list['total'];
$data['page'] = $list['page'];
$data['per_page'] = $perPage;
$data['filters'] = cmp_active_filters($filters);
json_response($data);
