(function () {
	'use strict';

	function getTabs(container) {
		return Array.prototype.slice.call(container.querySelectorAll('.oras-events-addon__tab'));
	}

	function activateTab(container, tabName) {
		var tabs = getTabs(container);
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
			try {
				globalThis.localStorage.setItem('orasEventsAddonTab:' + postId, tabName);
			} catch (e) {
				// ignore storage errors
			}
		}
	}

	function setupAccordion(root) {
		// make any element with .oras-card collapsible if it has .oras-card__header
		var cards = root.querySelectorAll('.oras-card');
		cards.forEach(function (card) {
			var header = card.querySelector('.oras-card__header');
			var body = Array.prototype.slice.call(card.children).filter(function (c) { return c !== header; });
			if (!header || body.length === 0) return;

			header.setAttribute('tabindex', '0');
			header.setAttribute('role', 'button');
			header.setAttribute('aria-expanded', 'true');
			header.style.cursor = 'pointer';

			header.addEventListener('click', function () {
				var expanded = header.getAttribute('aria-expanded') === 'true';
				header.setAttribute('aria-expanded', expanded ? 'false' : 'true');
				body.forEach(function (el) { el.hidden = expanded; });
			});
			header.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					header.click();
				}
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var container = document.getElementById('oras-events-addon');
		if (!container) return;

		var defaultTab = 'tickets';
		var postId = container.dataset.postId;
		if (postId) {
			try {
				var storedTab = globalThis.localStorage.getItem('orasEventsAddonTab:' + postId);
				if (storedTab && container.querySelector('.oras-events-addon__tab[data-tab="' + storedTab + '"]')) {
					defaultTab = storedTab;
				}
			} catch (e) {
				// ignore storage read errors
			}
		}

		// click handler for tabs
		container.addEventListener('click', function (event) {
			var tab = event.target.closest('.oras-events-addon__tab');
			if (!tab) return;
			event.preventDefault();
			activateTab(container, tab.dataset.tab);
			// setup accordions for newly shown panel
			var panel = container.querySelector('.oras-events-addon__panel[data-panel="' + tab.dataset.tab + '"]');
			if (panel) setupAccordion(panel);
		});

		// keyboard navigation for tabs: ArrowLeft/ArrowRight/Home/End
		getTabs(container).forEach(function (tab, idx, tabs) {
			tab.addEventListener('keydown', function (e) {
				var tabsList = getTabs(container);
				var index = tabsList.indexOf(e.currentTarget);
				if (e.key === 'ArrowRight') {
					e.preventDefault();
					var next = tabsList[(index + 1) % tabsList.length];
					next.focus();
				} else if (e.key === 'ArrowLeft') {
					e.preventDefault();
					var prev = tabsList[(index - 1 + tabsList.length) % tabsList.length];
					prev.focus();
				} else if (e.key === 'Home') {
					e.preventDefault();
					tabsList[0].focus();
				} else if (e.key === 'End') {
					e.preventDefault();
					tabsList[tabsList.length - 1].focus();
				} else if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					activateTab(container, e.currentTarget.dataset.tab);
				}
			});
		});

		// initial activation and accordion setup for default panel
		activateTab(container, defaultTab);
		var initialPanel = container.querySelector('.oras-events-addon__panel[data-panel="' + defaultTab + '"]');
		if (initialPanel) setupAccordion(initialPanel);
	});
})();
