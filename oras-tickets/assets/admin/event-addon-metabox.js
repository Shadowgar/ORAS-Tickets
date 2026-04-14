(function () {
	'use strict';

	function toArray(nodeList) {
		return Array.prototype.slice.call(nodeList || []);
	}

	function logViewportError(message, error) {
		if (globalThis.console && typeof globalThis.console.debug === 'function') {
			globalThis.console.debug(message, error);
		}
	}

	function viewportStorageKey(container) {
		const postId = container?.dataset?.postId || '';
		return postId ? 'orasEventsAddonViewport:' + postId : '';
	}

	function findFieldByName(container, fieldName) {
		if (!container || !fieldName) {
			return null;
		}

		const fields = container.querySelectorAll('[name]');
		for (const field of fields) {
			if (field.name === fieldName) {
				return field;
			}
		}

		return null;
	}

	function rememberViewport(container) {
		const storageKey = viewportStorageKey(container);
		if (!storageKey) {
			return;
		}

		const activeElement = document.activeElement;
		const state = {
			x: globalThis.pageXOffset || globalThis.scrollX || 0,
			y: globalThis.pageYOffset || globalThis.scrollY || 0,
			fieldName: '',
			rowIndex: '',
		};

		if (activeElement && container.contains(activeElement)) {
			if (typeof activeElement.name === 'string') {
				state.fieldName = activeElement.name;
			}

			const activeRow = activeElement.closest('tr.oras-ticket-row');
			if (activeRow?.dataset?.index) {
				state.rowIndex = activeRow.dataset.index;
			}
		}

		try {
			globalThis.sessionStorage.setItem(storageKey, JSON.stringify(state));
		} catch (storageError) {
			logViewportError('ORAS addon viewport state could not be saved.', storageError);
		}
	}

	function restoreViewport(container) {
		const storageKey = viewportStorageKey(container);
		if (!storageKey) {
			return;
		}

		let rawState;
		try {
			rawState = globalThis.sessionStorage.getItem(storageKey);
			globalThis.sessionStorage.removeItem(storageKey);
		} catch (storageError) {
			logViewportError('ORAS addon viewport state could not be restored.', storageError);
			return;
		}

		if (!rawState) {
			return;
		}

		let state;
		try {
			state = JSON.parse(rawState);
		} catch (parseError) {
			logViewportError('ORAS addon viewport state was invalid JSON.', parseError);
			return;
		}

		globalThis.requestAnimationFrame(function () {
			globalThis.requestAnimationFrame(function () {
				globalThis.scrollTo(state.x || 0, state.y || 0);

				let focusTarget = findFieldByName(container, state.fieldName);
				if (!focusTarget && state.rowIndex) {
					focusTarget = container.querySelector('.oras-ticket-row[data-index="' + state.rowIndex + '"] .oras-card-toggle');
				}

				if (focusTarget && typeof focusTarget.focus === 'function') {
					focusTarget.focus();
				}
			});
		});
	}

	function tabButtons(container) {
		return toArray(container.querySelectorAll('.oras-events-addon__tab[role="tab"]'));
	}

	function activateTab(container, tabName, shouldFocus) {
		var tabs = tabButtons(container);
		var panels = toArray(container.querySelectorAll('.oras-events-addon__panel[data-panel]'));

		tabs.forEach(function (tab) {
			var active = tab.dataset.tab === tabName;
			tab.classList.toggle('is-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
			tab.setAttribute('tabindex', active ? '0' : '-1');
			if (active && shouldFocus) {
				tab.focus();
			}
		});

		panels.forEach(function (panel) {
			var active = panel.dataset.panel === tabName;
			panel.classList.toggle('is-active', active);
			panel.hidden = !active;
		});

		var postId = container.dataset.postId;
		if (postId) {
			try {
				window.localStorage.setItem('orasEventsAddonTab:' + postId, tabName);
			} catch (e) {
				// ignore
			}
		}
	}

	function setupTabs(container) {
		container.addEventListener('click', function (event) {
			var tab = event.target.closest('.oras-events-addon__tab[role="tab"]');
			if (!tab || !container.contains(tab)) {
				return;
			}

			event.preventDefault();
			activateTab(container, tab.dataset.tab, false);
		});

		container.addEventListener('keydown', function (event) {
			var tab = event.target.closest('.oras-events-addon__tab[role="tab"]');
			if (!tab || !container.contains(tab)) {
				return;
			}

			var tabs = tabButtons(container);
			var current = tabs.indexOf(tab);
			if (current < 0) {
				return;
			}

			if (event.key === 'ArrowRight') {
				event.preventDefault();
				tabs[(current + 1) % tabs.length].focus();
				return;
			}

			if (event.key === 'ArrowLeft') {
				event.preventDefault();
				tabs[(current - 1 + tabs.length) % tabs.length].focus();
				return;
			}

			if (event.key === 'Home') {
				event.preventDefault();
				tabs[0].focus();
				return;
			}

			if (event.key === 'End') {
				event.preventDefault();
				tabs[tabs.length - 1].focus();
				return;
			}

			if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				activateTab(container, tab.dataset.tab, true);
			}
		});
	}

	function setSaveButtonsSaving(container, saving) {
		toArray(container.querySelectorAll('.oras-events-addon-save-trigger')).forEach(function (button) {
			const defaultLabel = button.dataset.defaultLabel || 'Save Event';
			const savingLabel = button.dataset.savingLabel || 'Saving…';
			button.disabled = !!saving;
			button.textContent = saving ? savingLabel : defaultLabel;
		});
	}

	function triggerEventSave(container) {
		rememberViewport(container);
		setSaveButtonsSaving(container, true);

		if (globalThis.tinyMCE && typeof globalThis.tinyMCE.triggerSave === 'function') {
			globalThis.tinyMCE.triggerSave();
		}

		const postForm = document.getElementById('post');
		if (postForm) {
			const publish = postForm.querySelector('#publish');
			if (publish && !publish.disabled) {
				if (typeof postForm.requestSubmit === 'function') {
					postForm.requestSubmit(publish);
				} else {
					publish.click();
				}
				return;
			}

			const saveDraft = postForm.querySelector('#save-post');
			if (saveDraft && !saveDraft.disabled) {
				if (typeof postForm.requestSubmit === 'function') {
					postForm.requestSubmit(saveDraft);
				} else {
					saveDraft.click();
				}
				return;
			}
		}

		if (typeof globalThis.wp?.data?.dispatch === 'function') {
			const editorDispatch = globalThis.wp.data.dispatch('core/editor');
			if (editorDispatch && typeof editorDispatch.savePost === 'function') {
				editorDispatch.savePost();
				return;
			}
		}

		setSaveButtonsSaving(container, false);
	}

	function setupSaveTriggers(container) {
		container.addEventListener('click', function (event) {
			const saveButton = event.target.closest('.oras-events-addon-save-trigger');
			if (!saveButton || !container.contains(saveButton)) {
				return;
			}

			event.preventDefault();
			if (saveButton.disabled) {
				return;
			}

			triggerEventSave(container);
		});

		globalThis.setInterval(function () {
			const publish = document.querySelector('#publish');
			const saveDraft = document.querySelector('#save-post');
			const canReset = (publish && !publish.disabled) || (saveDraft && !saveDraft.disabled);
			if (canReset) {
				setSaveButtonsSaving(container, false);
			}
		}, 1200);
	}

	function updateDaySummary(day) {
		var titleEl = day.querySelector('.oras-card__name');
		var metaEl = day.querySelector('.oras-card__meta');
		if (!titleEl || !metaEl) {
			return;
		}

		var dayLabelInput = day.querySelector('input[name*="[day_label]"]');
		var dateInput = day.querySelector('input[name*="[date]"]');
		var slots = day.querySelectorAll('tr.oras-agenda-slot-row');
		var fallbackIndex = Number.parseInt(day.getAttribute('data-day-index') || '0', 10) + 1;

		var label = dayLabelInput && dayLabelInput.value.trim() !== '' ? dayLabelInput.value.trim() : 'Day ' + fallbackIndex;
		var date = dateInput && dateInput.value ? dateInput.value : 'No date';
		var count = slots.length;
		var suffix = count === 1 ? 'item' : 'items';

		var nextTitle = label;
		var nextMeta = date + ' · ' + count + ' ' + suffix;
		if (titleEl.textContent !== nextTitle) {
			titleEl.textContent = nextTitle;
		}
		if (metaEl.textContent !== nextMeta) {
			metaEl.textContent = nextMeta;
		}
	}

	function setSlotExpanded(slotRow, expand) {
		var detailsCell = slotRow.querySelector('.oras-agenda-slot-details');
		var toggle = slotRow.querySelector('.oras-agenda-toggle-slot');
		if (!detailsCell || !toggle) {
			return;
		}

		detailsCell.hidden = !expand;
		toggle.setAttribute('aria-expanded', expand ? 'true' : 'false');
		toggle.textContent = expand ? 'Hide' : 'Edit';
	}

	function enhanceSlotRow(slotRow) {
		if (!slotRow || slotRow.getAttribute('data-oras-slot-enhanced') === '1') {
			return;
		}

		var detailsCell = slotRow.querySelector('td:nth-child(4)');
		var actionsCell = slotRow.querySelector('td:last-child');
		if (!detailsCell || !actionsCell) {
			return;
		}

		detailsCell.classList.add('oras-agenda-slot-details');
		var slotIndex = slotRow.getAttribute('data-slot-index') || String(Math.floor(Math.random() * 100000));
		var panelId = slotRow.id || ('oras-agenda-slot-details-' + slotIndex + '-' + Math.floor(Math.random() * 10000));
		detailsCell.id = panelId;

		var toggle = actionsCell.querySelector('.oras-agenda-toggle-slot');
		if (!toggle) {
			toggle = document.createElement('button');
			toggle.type = 'button';
			toggle.className = 'button button-small oras-agenda-toggle-slot';
			actionsCell.insertBefore(toggle, actionsCell.firstChild);
		}

		toggle.setAttribute('aria-controls', panelId);
		slotRow.setAttribute('data-oras-slot-enhanced', '1');
		setSlotExpanded(slotRow, false);
	}

	function setDayExpanded(day, expand) {
		var body = day.querySelector('.oras-card__body');
		var toggle = day.querySelector('.oras-agenda-day-toggle');
		if (!body || !toggle) {
			return;
		}

		body.hidden = !expand;
		toggle.setAttribute('aria-expanded', expand ? 'true' : 'false');
		toggle.textContent = expand ? 'Collapse' : 'Expand';
	}

	function enhanceAgendaDay(day) {
		if (!day) {
			return;
		}

		var header = day.querySelector('.oras-card__header');
		var body = day.querySelector('.oras-card__body');
		if (!header || !body) {
			return;
		}

		var dayIndex = day.getAttribute('data-day-index') || String(Math.floor(Math.random() * 10000));
		if (!body.id) {
			body.id = 'oras-agenda-day-body-' + dayIndex + '-' + Math.floor(Math.random() * 10000);
		}

		var actions = header.querySelector('.oras-card__actions');
		if (actions && !actions.querySelector('.oras-agenda-day-toggle')) {
			var toggle = document.createElement('button');
			toggle.type = 'button';
			toggle.className = 'button button-small oras-agenda-day-toggle';
			toggle.setAttribute('aria-controls', body.id);
			actions.insertBefore(toggle, actions.firstChild);
		}

		var dayToggle = day.querySelector('.oras-agenda-day-toggle');
		if (dayToggle) {
			dayToggle.setAttribute('aria-controls', body.id);
		}

		toArray(day.querySelectorAll('tr.oras-agenda-slot-row')).forEach(enhanceSlotRow);
		updateDaySummary(day);

		if (!day.hasAttribute('data-oras-day-initialized')) {
			day.setAttribute('data-oras-day-initialized', '1');
			setDayExpanded(day, true);
		}
	}

	function enhanceAgenda(container) {
		var agenda = container.querySelector('#oras-agenda-metabox');
		if (!agenda) {
			return;
		}

		var daysWrap = agenda.querySelector('#oras-agenda-days');
		if (!daysWrap) {
			return;
		}

		var refresh = function () {
			toArray(daysWrap.querySelectorAll('.oras-agenda-day')).forEach(enhanceAgendaDay);
		};
		var refreshQueued = false;
		var scheduleRefresh = function () {
			if (refreshQueued) {
				return;
			}
			refreshQueued = true;
			window.requestAnimationFrame(function () {
				refreshQueued = false;
				refresh();
			});
		};

		agenda.addEventListener('click', function (event) {
			var dayToggle = event.target.closest('.oras-agenda-day-toggle');
			if (dayToggle) {
				event.preventDefault();
				var day = dayToggle.closest('.oras-agenda-day');
				if (!day) {
					return;
				}
				var expanded = dayToggle.getAttribute('aria-expanded') === 'true';
				setDayExpanded(day, !expanded);
				return;
			}

			var slotToggle = event.target.closest('.oras-agenda-toggle-slot');
			if (slotToggle) {
				event.preventDefault();
				var row = slotToggle.closest('tr.oras-agenda-slot-row');
				if (!row) {
					return;
				}
				var expanded = slotToggle.getAttribute('aria-expanded') === 'true';
				setSlotExpanded(row, !expanded);
				return;
			}

			if (
				event.target.closest('.oras-agenda-add-slot') ||
				event.target.closest('.oras-agenda-add-speaker') ||
				event.target.closest('.oras-agenda-add-resource') ||
				event.target.closest('.oras-agenda-remove-slot') ||
				event.target.closest('.oras-agenda-remove-speaker') ||
				event.target.closest('.oras-agenda-remove-resource') ||
				event.target.closest('.oras-agenda-remove-day') ||
				event.target.closest('#oras-agenda-add-day') ||
				event.target.closest('.oras-agenda-add-day')
			) {
				scheduleRefresh();
			}
		});

		agenda.addEventListener('input', function (event) {
			var target = event.target;
			if (!target || typeof target.name !== 'string') {
				return;
			}

			if (target.name.indexOf('[day_label]') !== -1 || target.name.indexOf('[date]') !== -1) {
				var day = target.closest('.oras-agenda-day');
				if (day) {
					updateDaySummary(day);
				}
			}
		});

		refresh();
	}

	function enhanceRsvp(container) {
		var rsvp = container.querySelector('#oras-rsvp-metabox');
		if (!rsvp) {
			return;
		}

		var enabled = rsvp.querySelector('#oras_rsvp_enabled');
		var capacity = rsvp.querySelector('#oras_rsvp_capacity');
		if (!enabled || !capacity) {
			return;
		}

		var sync = function () {
			var active = !!enabled.checked;
			capacity.disabled = !active;
			capacity.setAttribute('aria-disabled', active ? 'false' : 'true');
			rsvp.classList.toggle('oras-rsvp-is-disabled', !active);
		};

		enabled.addEventListener('change', sync);
		sync();
	}

	document.addEventListener('DOMContentLoaded', function () {
		var container = document.getElementById('oras-events-addon');
		if (!container) {
			return;
		}

		setupTabs(container);
		setupSaveTriggers(container);

		var defaultTab = 'tickets';
		var postId = container.dataset.postId;
		if (postId) {
			try {
				var stored = window.localStorage.getItem('orasEventsAddonTab:' + postId);
				if (stored && container.querySelector('.oras-events-addon__tab[data-tab="' + stored + '"]')) {
					defaultTab = stored;
				}
			} catch (e) {
				// ignore
			}
		}

		activateTab(container, defaultTab, false);
		enhanceAgenda(container);
		enhanceRsvp(container);
		restoreViewport(container);
	});
})();
