<?php
/**
 * In-app notifications (rows only; the bell and list screen arrive in a later phase).
 * Titles are deliberately generic ("Task T-00012 assigned to you"): no message text, no task
 * description, no reasons — previews can never leak restricted content.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/**
 * Notify active users once each, never the person who caused the event.
 * @param int[] $userIds
 */
function comms_notify(array $userIds, string $type, string $title, ?string $subjectType = null, ?int $subjectId = null, string $priority = 'normal', ?int $exceptUser = null): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $i): bool => $i > 0 && $i !== (int)$exceptUser)));
    if (!$ids) {
        return 0;
    }
    $active = array_column(Database::fetchAll('SELECT id FROM users WHERE status = \'active\' AND id IN (' . implode(',', $ids) . ')'), 'id');
    foreach ($active as $uid) {
        Database::insert('comms_notifications', [
            'user_id' => (int)$uid, 'type' => $type, 'priority' => $priority === 'high' ? 'high' : 'normal',
            'title' => mb_substr($title, 0, 160), 'subject_type' => $subjectType, 'subject_id' => $subjectId,
        ]);
    }
    return count($active);
}
