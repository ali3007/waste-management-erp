<?php
/** Chat tab markup. Included by index.php; all behaviour is in assets/js/comms-chat.js. */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');
?>
<div class="comms-chat" data-comms-chat
     data-url="<?= e(base_url('modules/comms/process/chat.php')) ?>"
     data-csrf="<?= e(Security::csrfToken()) ?>"
     data-me="<?= (int)Auth::id() ?>"
     data-interval="<?= (int)max(2, min(60, (int)comms_setting('comms_poll_interval_sec'))) ?>"
     data-can-private="<?= can('comms.chat_private') ? '1' : '0' ?>"
     data-can-group="<?= can('comms.group_create') ? '1' : '0' ?>"
     data-can-send="<?= can('comms.send_message') ? '1' : '0' ?>"
     data-can-file="<?= can('comms.send_file') ? '1' : '0' ?>"
     data-can-voice="<?= can('comms.send_voice') ? '1' : '0' ?>"
     data-max-mb="<?= (int)comms_setting('comms_max_file_mb') ?>"
     data-voice-max="<?= (int)comms_setting('comms_voice_max_sec') ?>"
     data-exts="<?= e(implode(',', array_intersect(comms_allowed_extensions(), array_keys(comms_file_types())))) ?>">

    <aside class="comms-chat__side card">
        <div class="comms-chat__tools">
            <?php if (can('comms.chat_private')): ?><button type="button" class="btn btn--primary" data-comms-new-private><span class="btn__label">New chat</span></button><?php endif; ?>
            <?php if (can('comms.group_create')): ?><button type="button" class="btn btn--ghost" data-comms-new-group><span class="btn__label">New group</span></button><?php endif; ?>
        </div>
        <div class="control"><input class="input" type="search" placeholder="Search messages and files" data-comms-search></div>
        <div class="comms-conn" data-comms-conn role="status" aria-live="polite">Connecting…</div>
        <div class="comms-chat__list" data-comms-results hidden></div>
        <div class="comms-chat__list" data-comms-list><p class="comms-muted">Loading…</p></div>
    </aside>

    <section class="comms-chat__main card">
        <div class="comms-chat__empty" data-comms-placeholder><p class="comms-muted">Select a conversation or start a new chat.</p></div>
        <div class="comms-chat__pane" data-comms-pane hidden>
            <header class="comms-chat__head">
                <button type="button" class="btn btn--ghost comms-chat__back" data-comms-back>Back</button>
                <img class="comms-avatar" alt="" data-comms-avatar hidden>
                <div class="comms-chat__title"><strong data-comms-title></strong><small data-comms-sub></small></div>
                <button type="button" class="btn btn--ghost" data-comms-members hidden>Members</button>
            </header>
            <div class="comms-pinbar" data-comms-pins hidden></div>
            <div class="comms-chat__msgs" data-comms-msgs tabindex="0" aria-live="polite"></div>
            <div class="comms-chat__typing" data-comms-typing></div>
            <div class="comms-chat__reply" data-comms-replybar hidden><span data-comms-replytext></span><button type="button" class="comms-link" data-comms-replycancel>Cancel</button></div>
            <div class="comms-banner" data-comms-storage hidden></div>
            <div class="comms-rec" data-comms-rec hidden>
                <span class="comms-rec__dot"></span><span data-comms-rec-time>0:00</span>
                <audio controls data-comms-rec-audio hidden></audio>
                <button type="button" class="btn btn--ghost" data-comms-rec-stop>Stop</button>
                <button type="button" class="btn btn--primary" data-comms-rec-send hidden><span class="btn__label">Send</span></button>
                <button type="button" class="btn btn--ghost" data-comms-rec-cancel>Discard</button>
            </div>
            <form class="comms-chat__composer" data-comms-form>
                <input type="file" data-comms-file hidden>
                <?php if (can('comms.send_file')): ?><button type="button" class="btn btn--ghost" data-comms-attach title="Attach a file" aria-label="Attach a file"><span class="btn__label">Attach</span></button><?php endif; ?>
                <?php if (can('comms.send_voice')): ?><button type="button" class="btn btn--ghost" data-comms-mic title="Record a voice message" aria-label="Record a voice message"><span class="btn__label">Voice</span></button><?php endif; ?>
                <textarea class="input" rows="1" maxlength="<?= COMMS_MAX_BODY ?>" placeholder="Type a message (Urdu or English)" data-comms-input <?= can('comms.send_message') ? '' : 'disabled' ?>></textarea>
                <button type="submit" class="btn btn--primary" <?= can('comms.send_message') ? '' : 'disabled' ?>><span class="btn__label">Send</span></button>
            </form>
        </div>
    </section>
</div>

<div class="modal" id="comms-modal-private" aria-hidden="true">
    <div class="modal__dialog" role="dialog" aria-label="New chat">
        <h3 class="card__title">New chat</h3>
        <div class="control"><input class="input" type="search" placeholder="Search by name or employee code" data-comms-dir-q="private"></div>
        <div class="comms-pick" data-comms-dir="private"></div>
        <div class="modal__actions"><button type="button" class="btn btn--ghost" data-modal-close>Close</button></div>
    </div>
</div>

<div class="modal" id="comms-modal-group" aria-hidden="true">
    <div class="modal__dialog" role="dialog" aria-label="New group">
        <h3 class="card__title">New group</h3>
        <div class="control"><input class="input" type="text" maxlength="80" placeholder="Group name" data-comms-group-name></div>
        <div class="control"><input class="input" type="search" placeholder="Search people to add" data-comms-dir-q="group"></div>
        <div class="comms-pick" data-comms-dir="group"></div>
        <p class="comms-muted" data-comms-group-count>0 selected</p>
        <p class="comms-error" data-comms-group-error hidden></p>
        <div class="modal__actions"><button type="button" class="btn btn--ghost" data-modal-close>Cancel</button><button type="button" class="btn btn--primary" data-comms-group-create><span class="btn__label">Create group</span></button></div>
    </div>
</div>

<div class="modal" id="comms-modal-members" aria-hidden="true">
    <div class="modal__dialog" role="dialog" aria-label="Group members">
        <h3 class="card__title">Members</h3>
        <div data-comms-managewrap hidden>
            <div class="control"><input class="input" type="text" maxlength="80" data-comms-rename placeholder="Group name"></div>
            <div class="modal__actions"><button type="button" class="btn btn--ghost" data-comms-rename-go>Rename</button><button type="button" class="btn btn--ghost" data-comms-photo-go>Change picture</button></div>
            <input type="file" accept="image/jpeg,image/png,image/webp" data-comms-photo hidden>
        </div>
        <div class="comms-pick" data-comms-memberlist></div>
        <div data-comms-addwrap hidden>
            <div class="control"><input class="input" type="search" placeholder="Add a person" data-comms-dir-q="add"></div>
            <div class="comms-pick" data-comms-dir="add"></div>
        </div>
        <p class="comms-error" data-comms-members-error hidden></p>
        <div class="modal__actions"><button type="button" class="btn btn--ghost" data-modal-close>Close</button></div>
    </div>
</div>

<div class="modal" id="comms-modal-forward" aria-hidden="true">
    <div class="modal__dialog" role="dialog" aria-label="Forward message">
        <h3 class="card__title">Forward to…</h3>
        <p class="comms-muted">Choose up to 5 conversations.</p>
        <div class="comms-pick" data-comms-forward-list></div>
        <p class="comms-error" data-comms-forward-error hidden></p>
        <div class="modal__actions"><button type="button" class="btn btn--ghost" data-modal-close>Cancel</button><button type="button" class="btn btn--primary" data-comms-forward-go><span class="btn__label">Forward</span></button></div>
    </div>
</div>
<div class="modal" id="comms-modal-readers" aria-hidden="true">
    <div class="modal__dialog" role="dialog" aria-label="Read by">
        <h3 class="card__title">Read by</h3>
        <div data-comms-readers></div>
        <div class="modal__actions"><button type="button" class="btn btn--ghost" data-modal-close>Close</button></div>
    </div>
</div>
<div class="modal" id="comms-modal-preview" aria-hidden="true">
    <div class="modal__dialog comms-preview" role="dialog" aria-label="Preview">
        <div data-comms-preview></div>
        <div class="modal__actions"><button type="button" class="btn btn--ghost" data-comms-preview-dl>Download</button><button type="button" class="btn btn--primary" data-modal-close>Close</button></div>
    </div>
</div>
