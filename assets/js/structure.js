/**
 * structure.js — Administrative Structure module.
 *
 * Modal open/close and confirm-before-submit are already handled by the
 * shared app.js. The one thing this module needs on top: when the page
 * loads with ?add=1 or ?edit=ID in the URL (index.php rendered the modal
 * already filled in for that case), open it automatically instead of
 * waiting for a click that already happened on the previous page.
 */
(function (window, document) {
    'use strict';

    function init() {
        var modal = document.getElementById('node-modal');
        if (!modal) { return; }

        var params = new URLSearchParams(window.location.search);
        if (params.has('add') || params.has('edit')) {
            modal.classList.add('is-open');
            var firstField = modal.querySelector('input:not([type=hidden])');
            if (firstField) { firstField.focus(); }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
