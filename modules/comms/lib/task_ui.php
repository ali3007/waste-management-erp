<?php
/** Display helpers for task screens (labels, badges, formatting). No database access. */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

function comms_assignee_status_label(string $s): string
{
    return ['pending_acceptance' => 'Pending acceptance', 'accepted' => 'Accepted', 'declined' => 'Declined', 'in_progress' => 'In progress', 'submitted' => 'Submitted for review',
            'rejected' => 'Rejected – correction needed', 'approved' => 'Approved', 'cancelled' => 'Cancelled'][$s] ?? $s;
}

function comms_assignee_status_badge(string $s): string
{
    return ['pending_acceptance' => 'badge--pending', 'accepted' => 'badge--active', 'in_progress' => 'badge--active', 'approved' => 'badge--active',
            'submitted' => 'badge--pending', 'declined' => 'badge--blocked', 'rejected' => 'badge--blocked', 'cancelled' => 'badge--inactive'][$s] ?? 'badge--inactive';
}

function comms_task_status_label(string $s): string
{
    return ['active' => 'Open', 'partially_completed' => 'Partially completed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'][$s] ?? $s;
}

function comms_task_status_badge(string $s): string
{
    return ['active' => 'badge--active', 'partially_completed' => 'badge--pending', 'completed' => 'badge--active', 'cancelled' => 'badge--inactive'][$s] ?? 'badge--inactive';
}

function comms_priority_badge(string $p): string
{
    return ['urgent' => 'badge--blocked', 'high' => 'badge--pending', 'medium' => 'badge--active', 'low' => 'badge--inactive'][$p] ?? 'badge--inactive';
}

/** "12 Mar 2027, 5:30 PM" or "12 Mar 2027" in the central timezone. */
function comms_fmt_deadline(?string $date, ?string $time): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, comms_tz());
    $out = $d ? $d->format('d M Y') : $date;
    if ($time !== null && $time !== '') {
        $t = DateTimeImmutable::createFromFormat('H:i:s', strlen($time) === 5 ? $time . ':00' : $time);
        $out .= $t ? ', ' . $t->format('h:i A') : '';
    }
    return $out;
}

/** Hours until the deadline (negative once passed); null when it cannot be computed. */
function comms_hours_to_deadline(string $date, ?string $time): ?float
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . ($time ? substr($time, 0, 5) : '23:59'), comms_tz());
    return $dt ? ($dt->getTimestamp() - time()) / 3600 : null;
}

/** "Open the chat with…" style link text for the optional chat link. */
function comms_my_conversations(int $me): array
{
    return array_map(static fn(array $c): array => ['id' => (int)$c['id'], 'title' => (string)$c['title']], comms_conversations($me));
}

function comms_task_url(int $id): string
{
    return base_url('modules/comms/task-view.php?id=' . $id);
}

function comms_uuid4(): string
{
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function comms_submission_status_label(string $s): string
{
    return ['pending_review' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Rejected – correction requested'][$s] ?? $s;
}
