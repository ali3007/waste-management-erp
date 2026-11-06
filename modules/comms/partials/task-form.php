<?php
/**
 * Task form fields (shared by create and edit).
 * Expects: $form (values), $errors, $mode ('create'|'edit'), $templates, $reviewers, $convs, $people (selected: id,name), $reviewerName.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');
$val = static fn(string $k, string $d = ''): string => (string)($GLOBALS['form'][$k] ?? $d);
$err = static fn(string $k): string => isset($GLOBALS['errors'][$k]) ? '<p class="field__error">' . e($GLOBALS['errors'][$k]) . '</p>' : '';
$form = $GLOBALS['form'];
$checklist = $form['checklist'] ?? [];
?>
<?php if ($mode === 'create' && $templates): ?>
<div class="field">
    <label class="field__label" for="template_id">Start from a template (optional)</label>
    <div class="control">
        <select class="select" id="template_id" name="template_id" data-template-select data-templates="<?= e(json_encode(array_map(static fn($t) => ['id' => (int)$t['id'], 'title' => $t['title'], 'description' => $t['description'], 'priority' => $t['priority'], 'evidence' => (int)$t['evidence_required'], 'checklist' => $t['checklist']], $templates), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
            <option value="">— None —</option>
            <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"<?= (int)$val('template_id', '0') === (int)$t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <p class="field__hint">Fills the fields below. You can change anything before confirming.</p>
</div>
<?php elseif ($val('template_id') !== ''): ?>
    <input type="hidden" name="template_id" value="<?= e($val('template_id')) ?>">
<?php endif; ?>

<div class="field">
    <label class="field__label" for="title">Title <span class="field__required">*</span></label>
    <div class="control"><input class="input" id="title" name="title" type="text" maxlength="200" required value="<?= e($val('title')) ?>"></div>
    <?= $err('title') ?>
</div>
<div class="field">
    <label class="field__label" for="description">Detailed description <span class="field__required">*</span></label>
    <div class="control"><textarea class="input" id="description" name="description" rows="5" maxlength="5000" required><?= e($val('description')) ?></textarea></div>
    <?= $err('description') ?>
</div>

<div class="grid grid--2">
    <div class="field">
        <label class="field__label" for="priority">Priority <span class="field__required">*</span></label>
        <div class="control"><select class="select" id="priority" name="priority">
            <?php foreach (COMMS_PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= $val('priority', 'medium') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
        <?= $err('priority') ?>
    </div>
    <div class="field">
        <label class="field__label" for="evidence_required">Completion evidence</label>
        <div class="control"><select class="select" id="evidence_required" name="evidence_required">
            <option value="0"<?= $val('evidence_required', '0') === '0' ? ' selected' : '' ?>>Optional</option>
            <option value="1"<?= $val('evidence_required', '0') === '1' ? ' selected' : '' ?>>Required</option>
        </select></div>
        <p class="field__hint">If required, employees cannot submit completion without a valid file.</p>
    </div>
</div>

<div class="grid grid--2">
    <div class="field">
        <label class="field__label" for="due_date">Due date <span class="field__required">*</span></label>
        <div class="control"><input class="input" id="due_date" name="due_date" type="date" required min="<?= e(comms_today()) ?>" value="<?= e($val('due_date')) ?>"></div>
        <?= $err('due_date') ?>
    </div>
    <div class="field">
        <label class="field__label" for="due_time">Due time (optional)</label>
        <div class="control"><input class="input" id="due_time" name="due_time" type="time" value="<?= e(substr($val('due_time'), 0, 5)) ?>"></div>
        <?= $err('due_time') ?>
    </div>
</div>
<div class="grid grid--2">
    <div class="field">
        <label class="field__label" for="review_due_date">Review deadline (optional)</label>
        <div class="control"><input class="input" id="review_due_date" name="review_due_date" type="date" value="<?= e($val('review_due_date')) ?>"></div>
        <?= $err('review_due_date') ?>
    </div>
    <div class="field">
        <label class="field__label" for="review_due_time">Review time (optional)</label>
        <div class="control"><input class="input" id="review_due_time" name="review_due_time" type="time" value="<?= e(substr($val('review_due_time'), 0, 5)) ?>"></div>
    </div>
</div>
<div class="field">
    <label class="field__label" for="reminder_minutes">Advance reminder (optional)</label>
    <div class="control"><select class="select" id="reminder_minutes" name="reminder_minutes">
        <option value="">No reminder</option>
        <?php foreach (COMMS_REMINDER_CHOICES as $m => $l): ?><option value="<?= (int)$m ?>"<?= $val('reminder_minutes') === (string)$m ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select></div>
    <?= $err('reminder_minutes') ?>
</div>

<div class="field">
    <label class="field__label">Assign to <span class="field__required">*</span></label>
    <div data-assignee-picker data-url="<?= e(base_url('modules/comms/process/task-ajax.php')) ?>" data-csrf="<?= e(Security::csrfToken()) ?>">
        <div class="comms-chips" data-chips>
            <?php foreach ($people as $p): ?>
                <span class="comms-chip" data-id="<?= (int)$p['id'] ?>"><?= e($p['name']) ?><button type="button" aria-label="Remove <?= e($p['name']) ?>" data-chip-remove>×</button><input type="hidden" name="assignees[]" value="<?= (int)$p['id'] ?>"></span>
            <?php endforeach; ?>
        </div>
        <div class="control"><input class="input" type="search" placeholder="Search employees by name or employee code" data-people-q autocomplete="off"></div>
        <div class="comms-pick" data-people-results></div>
    </div>
    <?= $err('assignees') ?>
</div>

<div class="field">
    <label class="field__label" for="reviewer_id">Reviewer <span class="field__required">*</span></label>
    <?php if ($mode === 'edit'): ?>
        <div class="control"><input class="input" type="text" value="<?= e($reviewerName) ?>" disabled></div>
        <p class="field__hint">The reviewer can only be changed through reviewer reassignment.</p>
    <?php else: ?>
        <div class="control"><select class="select" id="reviewer_id" name="reviewer_id" required>
            <option value="">— Select the reviewer —</option>
            <?php foreach ($reviewers as $r): ?><option value="<?= (int)$r['id'] ?>"<?= (int)$val('reviewer_id', '0') === (int)$r['id'] ? ' selected' : '' ?>><?= e($r['name'] . ' — ' . $r['role'] . ($r['employee_code'] ? ' (' . $r['employee_code'] . ')' : '')) ?></option><?php endforeach; ?>
        </select></div>
        <p class="field__hint">An independent person who approves or rejects each submission. They cannot also be assigned the task.</p>
        <?= $err('reviewer_id') ?>
    <?php endif; ?>
</div>

<div class="field">
    <label class="field__label">Checklist (optional)</label>
    <div data-checklist-editor>
        <div data-checklist-rows>
            <?php foreach ($checklist as $i => $it): ?>
                <div class="comms-clrow">
                    <input type="hidden" data-f="key" name="checklist[<?= (int)$i ?>][key]" value="<?= e((string)($it['key'] ?? '')) ?>">
                    <input class="input" data-f="text" type="text" maxlength="255" name="checklist[<?= (int)$i ?>][text]" value="<?= e((string)$it['text']) ?>" placeholder="Step">
                    <label class="comms-check"><input data-f="required" type="checkbox" name="checklist[<?= (int)$i ?>][required]" value="1"<?= !empty($it['required']) ? ' checked' : '' ?>> Required</label>
                    <button type="button" class="comms-link" data-cl-up>↑</button><button type="button" class="comms-link" data-cl-down>↓</button><button type="button" class="comms-link" data-cl-del>Remove</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn--ghost" data-cl-add><span class="btn__label">Add a step</span></button>
    </div>
    <p class="field__hint">Progress is calculated from the required steps each employee completes. Changing the checklist later creates a new version.</p>
    <?= $err('checklist') ?>
</div>

<div class="field">
    <label class="field__label">Link to an ERP record (optional)</label>
    <div data-link-picker data-url="<?= e(base_url('modules/comms/process/task-ajax.php')) ?>" data-csrf="<?= e(Security::csrfToken()) ?>">
        <input type="hidden" name="link_type" value="<?= e($val('link_type')) ?>" data-link-type-h>
        <input type="hidden" name="link_id" value="<?= e($val('link_id')) ?>" data-link-id-h>
        <div class="comms-linkrow">
            <select class="select" data-link-type><option value="">— No link —</option>
                <?php foreach (comms_link_types() as $k => $t): if (!comms_actor_can_slug((int)Auth::id(), $t['perm'])) { continue; } ?><option value="<?= e($k) ?>"<?= $val('link_type') === $k ? ' selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?>
            </select>
            <input class="input" type="search" placeholder="Search…" data-link-q hidden>
        </div>
        <p class="comms-muted" data-link-chosen><?= $val('link_type') !== '' ? e((string)($GLOBALS['linkLabel'] ?? 'Linked record')) : '' ?></p>
        <div class="comms-pick" data-link-results></div>
    </div>
    <p class="field__hint">A link never gives anyone access to the record; people only see it if they already have access.</p>
    <?= $err('link') ?>
</div>

<?php if ($convs): ?>
<div class="field">
    <label class="field__label" for="conversation_id">Related chat (optional)</label>
    <div class="control"><select class="select" id="conversation_id" name="conversation_id">
        <option value="">— None —</option>
        <?php foreach ($convs as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$val('conversation_id', '0') === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['title']) ?></option><?php endforeach; ?>
    </select></div>
    <p class="field__hint">Only people who belong to that chat will see the link.</p>
    <?= $err('conversation_id') ?>
</div>
<?php endif; ?>
