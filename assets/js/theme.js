/* Theme controller — light / dark / system.
   Stored value is the user's CHOICE; "system" follows the OS preference live.
   Backward compatible with previously stored 'light' | 'dark'. */
(function () {
    var KEY = 'sdo_theme';
    var ORDER = ['light', 'dark', 'system'];
    var ICON = { light: 'L', dark: 'D', system: 'A' };   // A = Auto (follow system)

    function systemPrefersDark() {
        return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }
    function getChoice() {
        var c = localStorage.getItem(KEY);
        return ORDER.indexOf(c) >= 0 ? c : 'light';
    }
    function effective(choice) {
        return choice === 'system' ? (systemPrefersDark() ? 'dark' : 'light') : choice;
    }
    function applyTheme(choice) {
        document.documentElement.setAttribute('data-theme', effective(choice));
    }
    function updateControls(choice) {
        document.querySelectorAll('[data-theme-icon]').forEach(function (e) {
            e.textContent = ICON[choice] || 'D';
        });
        document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
            b.setAttribute('title', 'Theme: ' + choice + ' — click to change');
            b.setAttribute('aria-label', 'Theme: ' + choice + '. Click to change.');
        });
    }

    // Apply before first paint (this script is loaded in <head>) to avoid a flash
    applyTheme(getChoice());

    // Re-apply automatically when the OS theme changes while on "system"
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        var onSystemChange = function () { if (getChoice() === 'system') applyTheme('system'); };
        if (mq.addEventListener) mq.addEventListener('change', onSystemChange);
        else if (mq.addListener) mq.addListener(onSystemChange); // older Safari
    }

    document.addEventListener('DOMContentLoaded', function () {
        updateControls(getChoice());
        document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
            b.addEventListener('click', function () {
                var next = ORDER[(ORDER.indexOf(getChoice()) + 1) % ORDER.length];
                localStorage.setItem(KEY, next);
                applyTheme(next);
                updateControls(next);
            });
        });
    });
})();
