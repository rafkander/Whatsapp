/**
 * Chat Widget Loader
 * Usage: <script src="https://yourchat.com/widget/loader.js" data-color="#2563eb"></script>
 */
(function () {
    'use strict';

    const scripts    = document.getElementsByTagName('script');
    const thisScript = scripts[scripts.length - 1];
    const src        = thisScript.src;
    const apiBase    = src.replace(/\/widget\/loader\.js.*$/, '');

    window.__ChatWidgetConfig = {
        apiBase,
        color:    thisScript.dataset.color    || '',
        position: thisScript.dataset.position || 'bottom-right',
        brand:    thisScript.dataset.brand    || '', // 'rcuk' | 'alfonica' | '' (auto-detect)
    };

    const s  = document.createElement('script');
    s.src    = apiBase + '/widget/widget.js';
    s.async  = true;
    document.head.appendChild(s);
})();
