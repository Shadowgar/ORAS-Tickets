(function () {
	'use strict';

	function activateTab(container, tabName) {
		var tabs = container.querySelectorAll('.oras-events-addon__tab');
		var panels = container.querySelectorAll('.oras-events-addon__panel');

		tabs.forEach(function (tab) {
			var isActive = tab.dataset.tab === tabName;
			tab.classList.toggle('is-active', isActive);
			tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});

		panels.forEach(function (panel) {
			var isActive = panel.dataset.panel === tabName;
			panel.classList.toggle('is-active', isActive);
			panel.hidden = !isActive;
		});

		var postId = container.dataset.postId;
		if (postId) {
			globalThis.localStorage.setItem('orasEventsAddonTab:' + postId, tabName);
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var container = document.getElementById('oras-events-addon');
		if (!container) {
			return;
		}

		var defaultTab = 'tickets';
		var postId = container.dataset.postId;
		if (postId) {
			var storedTab = globalThis.localStorage.getItem('orasEventsAddonTab:' + postId);
			if (storedTab && container.querySelector('.oras-events-addon__tab[data-tab="' + storedTab + '"]')) {
				defaultTab = storedTab;
			}
		}

		container.addEventListener('click', function (event) {
			var tab = event.target.closest('.oras-events-addon__tab');
				if (!tab) {
					return;
				}
				event.preventDefault();
				activateTab(container, tab.dataset.tab);
			});

		activateTab(container, defaultTab);
	});
})();
