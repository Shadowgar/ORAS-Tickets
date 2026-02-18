(function() {
    'use strict';

    function update() {
        const html = document.documentElement;
        const body = document.body;
        const isDark = html.hasAttribute('data-wp-dark-mode-active') || html.classList.contains('wp-dark-mode-active') || body.classList.contains('wp-dark-mode-active');
        html.classList.toggle('oras-dark-on', isDark);
    }

    document.addEventListener('DOMContentLoaded', function() {
        update();

        const observer = new MutationObserver(update);
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class', 'data-wp-dark-mode-active']
        });
        observer.observe(document.body, {
            attributes: true,
            attributeFilter: ['class']
        });
    });
})();