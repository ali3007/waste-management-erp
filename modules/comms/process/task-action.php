<?php
/**
 * Task form actions (POST → redirect back with a message). Every action re-checks the actor's rights
 * inside lib/tasks.php / lib/task_work.php; this file only reads input and reports the outcome.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
comms_require();
if (!is_post()) {
    redirect('modules/comms/tasks.php');
}
// A body larger than PHP's post_max_size arrives empty (so the CSRF token is missing too): explain that instead.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('error', 'The upload is larger than this server allows (PHP limit ' . ini_get('post_max_size') . '). Use smaller files or ask the administrator to raise post_max_size and upload_max_filesize.');
    redirect('modules/comms/tasks.php');
}
Security::requireCsrf('modules/comms/tasks.php');

$me = (int)Auth::id();
$taskId = (int)post('task_id');
$back = 'modules/comms/task-view.php?id=' . $taskId;
$action = post('action');
$ok = null;

switch ($action) {
    case 'accept':
        $r = comms_task_accept($me, $taskId);
        break;
    case 'decline':
        $r = comms_task_decline($me, $taskId, (string)($_POST['reason'] ?? ''));
        break;
    case 'start':
        $r = comms_task_start($me, $taskId);
        break;
    case 'progress':
        $r = comms_task_progress_update($me, $taskId, (int)post('pct'), (string)($_POST['comment'] ?? ''));
        break;
    case 'step':
        $r = comms_task_step_toggle($me, $taskId, post('key'), post('done') === '1');
        break;
    case 'comment':
        $r = comms_comment_add($me, $taskId, (string)($_POST['body'] ?? ''), (int)post('parent_id'), post('important') === '1', array_map('intval', (array)($_POST['mentions'] ?? [])));
        $back .= '#comments';
        break;
    case 'comment_edit':
        $r = comms_comment_edit($me, (int)post('comment_id'), (string)($_POST['body'] ?? ''));
        $back .= '#comments';
        break;
    case 'progress_edit':
        $r = comms_progress_comment_edit($me, (int)post('progress_id'), (string)($_POST['comment'] ?? ''));
        break;
    case 'cancel':
        $r = comms_task_cancel($me, $taskId, (string)($_POST['reason'] ?? ''));
        break;
    case 'submit':
        $files = [];
        $f = $_FILES['evidence'] ?? null;
        if (is_array($f) && is_array($f['name'])) {
            foreach ($f['name'] as $i => $n) {
                $err = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($err !== UPLOAD_ERR_OK) {
                    flash('error', in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'A file is larger than this server allows (PHP limit ' . ini_get('upload_max_filesize') . ').' : 'A file could not be uploaded. Please try again.');
                    redirect($back);
                }
                $files[] = ['tmp' => (string)$f['tmp_name'][$i], 'name' => (string)$n];
            }
        }
        $r = comms_task_submit($me, $taskId, (string)($_POST['comment'] ?? ''), $files, post('uuid'), true);
        break;
    case 'review':
        $r = comms_review_decide($me, (int)post('submission_id'), post('decision'), (string)($_POST['comment'] ?? ''));
        break;
    case 'reassign':
        $r = comms_reviewer_reassign($me, $taskId, (int)post('new_reviewer'), (string)($_POST['reason'] ?? ''));
        break;
    case 'reopen':
        $r = comms_task_reopen($me, $taskId, array_map('intval', (array)($_POST['employees'] ?? [])), (string)($_POST['reason'] ?? ''), (string)($_POST['details'] ?? ''));
        break;
    case 'ext_request':
        $r = comms_extension_request($me, $taskId, post('date'), post('time'), (string)($_POST['reason'] ?? ''));
        break;
    case 'ext_decide':
        $r = comms_extension_decide($me, (int)post('ext_id'), post('decision') === 'approve', (string)($_POST['comment'] ?? ''));
        break;
    case 'ext_cancel':
        $r = comms_extension_cancel($me, (int)post('ext_id'));
        break;
    default:
        redirect($back);
}
$labels = ['accept' => 'You accepted the task.', 'decline' => 'You declined the task.', 'start' => 'Marked as In progress.', 'progress' => 'Progress updated.', 'step' => '', 'comment' => 'Comment posted.',
           'comment_edit' => 'Comment updated.', 'progress_edit' => 'Update corrected.', 'cancel' => 'The task was cancelled.',
           'submit' => 'Submitted for review. You will be notified of the decision.', 'review' => 'Decision recorded.', 'reassign' => 'The reviewer was changed.', 'reopen' => 'The task was reopened for correction.',
           'ext_request' => 'Extension requested.', 'ext_decide' => 'Decision recorded.', 'ext_cancel' => 'Request withdrawn.'];
if ($r['ok']) {
    if (($labels[$action] ?? '') !== '') {
        flash('success', $labels[$action]);
    }
} else {
    flash('error', $r['message'] !== '' ? $r['message'] : 'That could not be done.');
}
redirect($back);
