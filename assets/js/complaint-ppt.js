/** PowerPoint preview page: highlight the chosen template, show progress while generating. */
(function (document) {
    'use strict';
    var form = document.getElementById('ppt-form');
    if (!form) { return; }
    var cards = Array.prototype.slice.call(form.querySelectorAll('.cmp-ppt-card'));
    cards.forEach(function (card) {
        card.addEventListener('click', function () {
            cards.forEach(function (c) { c.classList.toggle('is-selected', c === card); });
        });
    });
    form.addEventListener('submit', function () {
        var btn = form.querySelector('[data-generate]');
        var hint = form.querySelector('[data-generate-hint]');
        if (btn) {
            btn.classList.add('is-loading');
            /* Re-enable after a while: the download keeps this page open. */
            window.setTimeout(function () { btn.classList.remove('is-loading'); if (hint) { hint.hidden = true; } }, 20000);
        }
        if (hint) { hint.hidden = false; }
    });
})(document);
