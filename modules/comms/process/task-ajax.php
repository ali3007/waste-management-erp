<?php
/** JSON helpers for the task form: employee search and ERP-record search. POST, CSRF header, login + permission re-checked. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED || !is_post()) {
    json_response(['ok' => false, 'message' => 'Invalid request.'], 405);
}
if (!Auth::check()) {
    json_response(['ok' => false, 'message' => 'Please sign in again.'], 401);
}
if (!can('comms.access')) {
    json_response(['ok' => false, 'message' => 'No access.'], 403);
}
if (!Security::verifyCsrf()) {
    json_response(['ok' => false, 'message' => 'Reload the page and try again.'], 419);
}
$me = (int)Auth::id();
session_write_close();

switch (post('action')) {
    case 'people':
        if (!comms_actor_can($me, 'comms.task_create')) {
            json_response(['ok' => true, 'people' => []]);
        }
        $canOthers = comms_actor_can($me, 'comms.task_assign');
        $canSelf = comms_actor_can($me, 'comms.task_self_assign');
        $rows = comms_eligible_users('comms.task_progress', mb_substr(post('q'), 0, 60), 40);
        $people = [];
        foreach ($rows as $r) {
            $self = (int)$r['id'] === $me;
            if (($self && !$canSelf) || (!$self && !$canOthers)) {
                continue;
            }
            $people[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'role' => $r['role'], 'code' => $r['employee_code'], 'self' => $self];
        }
        json_response(['ok' => true, 'people' => $people]);

    case 'link_search':
        json_response(['ok' => true, 'results' => comms_link_search($me, post('type'), post('q'))]);

    default:
        json_response(['ok' => false, 'message' => 'Unknown action.'], 400);
}
