(function () {
    'use strict';

    let observer = null;

    function appendCta(title) {
        if (!title || title.querySelector('.oras-event-list-view-link')) {
            return;
        }

        const titleLink = title.querySelector('.tribe-events-calendar-list__event-title-link');
        if (!titleLink?.href) {
            return;
        }

        const cta = document.createElement('a');
        cta.href = titleLink.href;
        cta.className = 'tribe-common-anchor-thin oras-event-list-view-link';
        cta.textContent = globalThis.orasEventListView?.ctaText || 'View Event Details';

        title.appendChild(document.createTextNode(' '));
        title.appendChild(cta);
    }

    function init() {
        const titles = document.querySelectorAll('.tribe-events-calendar-list__event-title');
        if (!titles.length) {
            return;
        }

        titles.forEach(appendCta);
    }

    function observeListUpdates() {
        if (observer) {
            observer.disconnect();
        }

        observer = new MutationObserver(function (mutations) {
            for (const mutation of mutations) {
                if (mutation.addedNodes.length) {
                    init();
                    break;
                }
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init();
            observeListUpdates();
        });
    } else {
        init();
        observeListUpdates();
    }
})();