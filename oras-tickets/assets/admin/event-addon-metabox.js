(function () {
	'use strict';

	function activateTab(container, tabName) {
		var tabs = container.querySelectorAll('.oras-events-addon__tab');
		var panels = container.querySelectorAll('.oras-events-addon__panel');

		tabs.forEach(function (tab) {
			var isActive = tab.getAttribute('data-tab') === tabName;
			tab.classList.toggle('is-active', isActive);
			tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});

		panels.forEach(function (panel) {
			var isActive = panel.getAttribute('data-panel') === tabName;
			panel.classList.toggle('is-active', isActive);
			panel.hidden = !isActive;
		});

		var postId = container.getAttribute('data-post-id');
		if (postId) {
			window.localStorage.setItem('orasEventsAddonTab:' + postId, tabName);
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var container = document.getElementById('oras-events-addon');
		if (!container) {
			return;
		}

		var defaultTab = 'tickets';
		var postId = container.getAttribute('data-post-id');
		if (postId) {
			var storedTab = window.localStorage.getItem('orasEventsAddonTab:' + postId);
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
			activateTab(container, tab.getAttribute('data-tab'));
		});

		activateTab(container, defaultTab);
	});
})();
