<?php
/** Communication navigation — one definition for the sidebar and the landing page. No DB access. */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** @return array<int,array{label:string,href:string,script:string,permission:string}> */
function comms_nav_items(): array
{
    if (!can('comms.access')) {
        return [];
    }
    $items = [['label' => 'Workspace', 'href' => base_url('modules/comms/index.php'), 'script' => 'comms/index.php', 'permission' => 'comms.access']];
    $items[] = ['label' => 'Tasks', 'href' => base_url('modules/comms/tasks.php'), 'script' => 'comms/tasks.php', 'permission' => 'comms.access'];
    if (can('comms.template_manage')) {
        $items[] = ['label' => 'Templates', 'href' => base_url('modules/comms/templates.php'), 'script' => 'comms/templates.php', 'permission' => 'comms.template_manage'];
    }
    if (can('comms.settings')) {
        $items[] = ['label' => 'Settings', 'href' => base_url('modules/comms/settings.php'), 'script' => 'comms/settings.php', 'permission' => 'comms.settings'];
    }
    /* The Audit log entry is added when that page exists (Phase 5). */
    return $items;
}
