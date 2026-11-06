/**
 * theme.js — light/dark switching.
 *
 * The stored choice is applied before paint by the inline bootstrap in
 * includes/header.php; this file handles the toggle and system changes.
 */
(function (window, document) {
    'use strict';

    var STORAGE_KEY = 'swms.theme';
    var LIGHT = 'light';
    var DARK = 'dark';

    function storedTheme() {
        try {
            var value = window.localStorage.getItem(STORAGE_KEY);
            return value === LIGHT || value === DARK ? value : null;
        } catch (error) {
            return null;
        }
    }

    function systemTheme() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? DARK : LIGHT;
    }

    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') || storedTheme() || systemTheme();
    }

    function apply(theme, persist) {
        document.documentElement.setAttribute('data-theme', theme);

        if (persist) {
            try {
                window.localStorage.setItem(STORAGE_KEY, theme);
            } catch (error) {
                /* Private browsing: the choice simply lasts for this page. */
            }
        }

        document.querySelectorAll('.theme-toggle').forEach(function (button) {
            button.setAttribute('aria-pressed', theme === DARK ? 'true' : 'false');
            button.setAttribute('aria-label', theme === DARK ? 'Switch to light theme' : 'Switch to dark theme');
        });

        document.dispatchEvent(new CustomEvent('swms:themechange', { detail: { theme: theme } }));
    }

    function toggle() {
        apply(currentTheme() === DARK ? LIGHT : DARK, true);
    }

    function init() {
        document.documentElement.classList.remove('theme-preload');

        document.querySelectorAll('.theme-toggle').forEach(function (button) {
            button.addEventListener('click', toggle);
        });

        /* Follow the operating system until the person makes a choice. */
        if (window.matchMedia) {
            var media = window.matchMedia('(prefers-color-scheme: dark)');
            var onChange = function (event) {
                if (!storedTheme()) {
                    apply(event.matches ? DARK : LIGHT, false);
                }
            };
            if (media.addEventListener) {
                media.addEventListener('change', onChange);
            } else if (media.addListener) {
                media.addListener(onChange);
            }
        }

        apply(currentTheme(), false);
    }

    window.SWMSTheme = { apply: apply, toggle: toggle, current: currentTheme };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
