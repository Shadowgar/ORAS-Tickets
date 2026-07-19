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

	function suppressAutofill(container) {
		if (!container) {
			return;
		}

		const fields = container.querySelectorAll('input, select, textarea');
		fields.forEach(function (field) {
			if (field.type === 'hidden' || field.type === 'checkbox' || field.type === 'radio') {
				return;
			}

			field.setAttribute('autocomplete', 'new-password');
			field.setAttribute('autocorrect', 'off');
			field.setAttribute('autocapitalize', 'none');
			field.setAttribute('data-lpignore', 'true');
			field.setAttribute('data-1p-ignore', 'true');
			field.setAttribute('data-bwignore', 'true');
			field.setAttribute('data-form-type', 'other');
		});
	}

	function setupAutofillSuppression(container) {
		suppressAutofill(container);

		if (typeof MutationObserver !== 'function') {
			return;
		}

		const observer = new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				mutation.addedNodes.forEach(function (node) {
					if (node.nodeType === 1) {
						suppressAutofill(node);
					}
				});
			});
		});

		observer.observe(container, {
			childList: true,
			subtree: true,
		});
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
					try {
						focusTarget.focus({ preventScroll: true });
					} catch (focusError) {
						focusTarget.focus();
					}
				}
			});
		});
	}

	function setupResizeSuppression(container) {
		if (typeof globalThis.jQuery !== 'function') {
			return;
		}

		var suppressUntil = 0;
		var suppressRemaining = 0;
		var $document = globalThis.jQuery(document);

		function armSuppression() {
			suppressUntil = Date.now() + 1000;
			suppressRemaining = 4;
		}

		container.addEventListener(
			'mousedown',
			function (event) {
				var interactive = event.target.closest('button, [role="tab"], input, select, textarea, label');
				if (!interactive || !container.contains(interactive)) {
					return;
				}

				armSuppression();
			},
			true
		);

		$document.on('wp-window-resized.orasEventsAddon', function (event) {
			if (suppressRemaining < 1 || Date.now() > suppressUntil) {
				return;
			}

			suppressRemaining -= 1;
			event.stopImmediatePropagation();
		});
	}

	function setupTecAdminScrollGuard(container) {
		if (!document.body || !document.body.classList.contains('post-type-tribe_events')) {
			return;
		}

		var lastState = null;
		var restoreTimers = [];
		var interactiveSelector = [
			'button',
			'[role="button"]',
			'a',
			'select',
			'input[type="checkbox"]',
			'input[type="radio"]',
			'.select2-selection',
			'.tribe-dependent',
			'[class*="virtual"]',
			'[class*="Virtual"]',
			'[id*="virtual"]',
			'[id*="Virtual"]',
		].join(',');
		var scrollContainerSelector = [
			'html',
			'body',
			'#wpbody-content',
			'#poststuff',
			'.interface-interface-skeleton__content',
			'.edit-post-layout__content',
			'.edit-post-sidebar',
			'.components-panel',
		].join(',');

		function windowScroll() {
			var doc = document.documentElement || {};
			var body = document.body || {};
			return {
				x: globalThis.pageXOffset || globalThis.scrollX || doc.scrollLeft || body.scrollLeft || 0,
				y: globalThis.pageYOffset || globalThis.scrollY || doc.scrollTop || body.scrollTop || 0,
			};
		}

		function isScrollableElement(element) {
			if (!element || element === document || element === globalThis) {
				return false;
			}

			return element.scrollHeight > element.clientHeight + 8 || element.scrollWidth > element.clientWidth + 8;
		}

		function addScrollTarget(targets, element) {
			if (!element || targets.some(function (target) { return target.element === element; })) {
				return;
			}

			if (!isScrollableElement(element) && element.scrollTop < 1 && element.scrollLeft < 1) {
				return;
			}

			targets.push({
				element: element,
				x: element.scrollLeft || 0,
				y: element.scrollTop || 0,
			});
		}

		function collectScrollState(control) {
			var targets = [];
			var win = windowScroll();
			var state = {
				x: win.x,
				y: win.y,
				targets: targets,
				at: Date.now(),
			};

			toArray(document.querySelectorAll(scrollContainerSelector)).forEach(function (element) {
				addScrollTarget(targets, element);
			});

			var current = control;
			while (current && current !== document.body && current !== document.documentElement) {
				addScrollTarget(targets, current);
				current = current.parentElement;
			}

			return state;
		}

		function clearRestoreTimers() {
			restoreTimers.forEach(function (timer) {
				globalThis.clearTimeout(timer);
			});
			restoreTimers = [];
		}

		function isSubmitControl(control) {
			if (!control) {
				return false;
			}

			var tagName = control.tagName ? control.tagName.toLowerCase() : '';
			var type = (control.getAttribute('type') || '').toLowerCase();
			if (tagName === 'button' && (type === 'submit' || type === '')) {
				return control.closest('#poststuff') === null;
			}

			return type === 'submit' || control.id === 'publish' || control.id === 'save-post';
		}

		function isNavigationLink(control) {
			if (!control || (control.tagName ? control.tagName.toLowerCase() : '') !== 'a') {
				return false;
			}

			var href = (control.getAttribute('href') || '').trim();
			if (href === '' || href === '#' || href.charAt(0) === '#') {
				return false;
			}

			return href.indexOf('javascript:') !== 0;
		}

		function getWatchedControl(target) {
			var control = target && typeof target.closest === 'function' ? target.closest(interactiveSelector) : null;
			if (!control) {
				return null;
			}

			if (container && container.contains(control)) {
				return null;
			}

			if (isSubmitControl(control) || isNavigationLink(control)) {
				return null;
			}

			return control.closest('#post, #poststuff, .interface-interface-skeleton__content, .edit-post-layout, [class*="tribe"], [id*="tribe"]');
		}

		function rememberInteraction(event) {
			var control = getWatchedControl(event.target);
			if (!control) {
				return;
			}

			var state = collectScrollState(control);
			var hasMeaningfulScroll = state.y > 120 || state.targets.some(function (target) {
				return target.y > 120;
			});

			if (!hasMeaningfulScroll) {
				return;
			}

			lastState = state;
		}

		function maybeRestore() {
			if (!lastState || Date.now() - lastState.at > 2500) {
				return;
			}

			var position = windowScroll();
			if (position.y <= 40 && lastState.y > 120) {
				globalThis.scrollTo(lastState.x, lastState.y);
			}

			lastState.targets.forEach(function (target) {
				if (!target.element || !document.documentElement.contains(target.element)) {
					return;
				}

				var currentY = target.element.scrollTop || 0;
				if (currentY <= 40 && target.y > 120) {
					target.element.scrollTop = target.y;
					target.element.scrollLeft = target.x;
				}
			});
		}

		function scheduleRestore(event) {
			if (!getWatchedControl(event.target) || !lastState) {
				return;
			}

			clearRestoreTimers();
			globalThis.requestAnimationFrame(function () {
				globalThis.requestAnimationFrame(maybeRestore);
			});
			[80, 180, 360, 700, 1200].forEach(function (delay) {
				restoreTimers.push(globalThis.setTimeout(maybeRestore, delay));
			});
		}

		document.addEventListener('pointerdown', rememberInteraction, true);
		document.addEventListener('mousedown', rememberInteraction, true);
		document.addEventListener('focusin', rememberInteraction, true);
		document.addEventListener('click', scheduleRestore, true);
		document.addEventListener('pointerup', scheduleRestore, true);
		document.addEventListener('change', scheduleRestore, true);
	}

	function tabButtons(container) {
		return toArray(container.querySelectorAll('.oras-events-addon__tab[role="tab"]'));
	}

	function activateTab(container, tabName, shouldFocus) {
		var previousScrollX = globalThis.pageXOffset || globalThis.scrollX || 0;
		var previousScrollY = globalThis.pageYOffset || globalThis.scrollY || 0;
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

		globalThis.requestAnimationFrame(function () {
			globalThis.scrollTo(previousScrollX, previousScrollY);
		});
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

	function agendaField(slotRow, suffix) {
		return slotRow.querySelector('[name$="[' + suffix + ']"]');
	}

	function agendaFieldValue(slotRow, suffix) {
		var field = agendaField(slotRow, suffix);
		return field ? String(field.value || '').trim() : '';
	}

	function agendaTimeLabel(slotRow) {
		var mode = agendaFieldValue(slotRow, 'schedule_mode');
		if (mode === 'ongoing') {
			return 'All-day / ongoing';
		}
		if (mode === 'tbd') {
			return 'Time TBD';
		}
		var start = agendaFieldValue(slotRow, 'start');
		var end = agendaFieldValue(slotRow, 'end');
		return start ? start + (end ? ' - ' + end : '') : 'Time not set';
	}

	function updateAgendaSlotSummary(slotRow) {
		var summaryRow = slotRow.previousElementSibling;
		if (!summaryRow || !summaryRow.classList.contains('oras-agenda-slot-summary')) {
			return;
		}

		var title = agendaFieldValue(slotRow, 'title') || 'Untitled session';
		var type = agendaFieldValue(slotRow, 'type') || 'other';
		var location = agendaFieldValue(slotRow, 'location') || 'Location not set';
		var visibility = agendaFieldValue(slotRow, 'visibility') || 'public';
		summaryRow.querySelector('.oras-agenda-slot-summary__time').textContent = agendaTimeLabel(slotRow);
		summaryRow.querySelector('.oras-agenda-slot-summary__title').textContent = title;
		summaryRow.querySelector('.oras-agenda-slot-summary__type').textContent = type;
		summaryRow.querySelector('.oras-agenda-slot-summary__location').textContent = location;
		summaryRow.querySelector('.oras-agenda-slot-summary__visibility').textContent = visibility;
		summaryRow.setAttribute('data-search-text', (title + ' ' + type + ' ' + location + ' ' + slotRow.textContent).toLowerCase());
		summaryRow.setAttribute('data-session-type', type.toLowerCase());
		summaryRow.setAttribute('data-location', location.toLowerCase());
	}

	function closeAgendaEditorDrawer(slotRow) {
		var activeRow = slotRow || document.querySelector('.oras-agenda-slot-row.is-editor-open');
		if (!activeRow) {
			return;
		}
		activeRow.classList.remove('is-editor-open');
		activeRow.hidden = true;
		document.body.classList.remove('oras-agenda-editor-is-open');
		var backdrop = document.querySelector('.oras-agenda-editor-backdrop');
		if (backdrop) {
			backdrop.hidden = true;
		}
		updateAgendaSlotSummary(activeRow);
		var trigger = activeRow.previousElementSibling && activeRow.previousElementSibling.querySelector('.oras-agenda-open-editor');
		if (trigger) {
			trigger.focus();
		}
	}

	function openAgendaEditorDrawer(slotRow) {
		closeAgendaEditorDrawer();
		if (!slotRow) {
			return;
		}
		slotRow.classList.add('is-editor-open');
		slotRow.hidden = false;
		document.body.classList.add('oras-agenda-editor-is-open');
		var backdrop = document.querySelector('.oras-agenda-editor-backdrop');
		if (backdrop) {
			backdrop.hidden = false;
		}
		var titleField = agendaField(slotRow, 'title');
		if (titleField) {
			titleField.focus();
		}
	}

	function agendaSummaryHtml() {
		return '<td colspan="9"><div class="oras-agenda-slot-summary__main">' +
			'<span class="oras-agenda-slot-summary__time"></span>' +
			'<strong class="oras-agenda-slot-summary__title"></strong>' +
			'<span class="oras-agenda-slot-summary__badges"><span class="oras-agenda-slot-summary__type"></span>' +
			'<span class="oras-agenda-slot-summary__location"></span><span class="oras-agenda-slot-summary__visibility"></span>' +
			'<span class="oras-agenda-slot-summary__conflict" hidden>Conflict</span></span></div>' +
			'<div class="oras-agenda-slot-summary__actions"><button type="button" class="button button-primary oras-agenda-open-editor">Edit</button>' +
			'<button type="button" class="button oras-agenda-duplicate-slot">Duplicate</button>' +
			'<button type="button" class="button oras-agenda-move-slot-up" aria-label="Move session up">Up</button>' +
			'<button type="button" class="button oras-agenda-move-slot-down" aria-label="Move session down">Down</button>' +
			'<button type="button" class="button-link-delete oras-agenda-admin-remove">Remove</button></div></td>';
	}

	function enhanceSlotRow(slotRow) {
		if (!slotRow || slotRow.getAttribute('data-oras-slot-enhanced') === '1') {
			return;
		}
		var cells = slotRow.querySelectorAll(':scope > td');
		if (cells.length < 9) {
			return;
		}

		var labels = ['Schedule', 'Start time', 'End time', 'Session title', 'Description, speakers, and resources', 'Session type', 'Location', 'Visibility', 'Actions'];
		toArray(cells).forEach(function (cell, index) {
			cell.setAttribute('data-field-label', labels[index] || 'Session field');
		});
		cells[4].classList.add('oras-agenda-slot-details');

		var heading = document.createElement('td');
		heading.className = 'oras-agenda-editor-drawer__heading';
		heading.colSpan = 9;
		heading.innerHTML = '<div><strong>Edit session</strong><span>Changes are saved when you update the event.</span></div>' +
			'<button type="button" class="button oras-agenda-close-editor">Done</button>';
		slotRow.insertBefore(heading, slotRow.firstChild);

		var summaryRow = document.createElement('tr');
		summaryRow.className = 'oras-agenda-slot-summary';
		summaryRow.innerHTML = agendaSummaryHtml();
		slotRow.parentNode.insertBefore(summaryRow, slotRow);
		slotRow.setAttribute('data-oras-slot-enhanced', '1');
		updateAgendaSlotSummary(slotRow);
	}

	function nextAgendaSlotIndex(day) {
		var maximum = -1;
		toArray(day.querySelectorAll('.oras-agenda-slot-row')).forEach(function (row) {
			maximum = Math.max(maximum, Number.parseInt(row.getAttribute('data-slot-index') || '-1', 10));
		});
		return maximum + 1;
	}

	function duplicateAgendaSlot(slotRow) {
		var day = slotRow.closest('.oras-agenda-day');
		if (!day) {
			return;
		}
		var oldIndex = slotRow.getAttribute('data-slot-index');
		var newIndex = String(nextAgendaSlotIndex(day));
		var clone = slotRow.cloneNode(true);
		clone.classList.remove('is-editor-open');
		clone.removeAttribute('data-oras-slot-enhanced');
		clone.setAttribute('data-slot-index', newIndex);
		var heading = clone.querySelector('.oras-agenda-editor-drawer__heading');
		if (heading) {
			heading.remove();
		}
		toArray(clone.querySelectorAll('[name]')).forEach(function (field) {
			field.name = field.name.replace('[slots][' + oldIndex + ']', '[slots][' + newIndex + ']');
		});
		slotRow.parentNode.insertBefore(clone, slotRow.nextSibling);
		enhanceSlotRow(clone);
		updateDaySummary(day);
		validateAgendaConflicts(day);
		var agenda = day.closest('#oras-agenda-metabox');
		if (agenda) {
			refreshAgendaLocationFilter(agenda);
			applyAgendaAdminFilters(agenda);
		}
		openAgendaEditorDrawer(clone);
	}

	function moveAgendaSlot(slotRow, direction) {
		var summary = slotRow.previousElementSibling;
		var rows = toArray(slotRow.parentNode.querySelectorAll('.oras-agenda-slot-row'));
		var index = rows.indexOf(slotRow);
		var target = rows[index + direction];
		if (!summary || !target) {
			return;
		}
		var targetSummary = target.previousElementSibling;
		if (direction < 0) {
			slotRow.parentNode.insertBefore(summary, targetSummary);
			slotRow.parentNode.insertBefore(slotRow, targetSummary);
		} else {
			slotRow.parentNode.insertBefore(summary, target.nextSibling);
			slotRow.parentNode.insertBefore(slotRow, summary.nextSibling);
		}
	}

	function agendaMinutes(value) {
		var parts = String(value || '').split(':');
		return parts.length === 2 ? Number.parseInt(parts[0], 10) * 60 + Number.parseInt(parts[1], 10) : -1;
	}

	function validateAgendaConflicts(day) {
		var rows = toArray(day.querySelectorAll('.oras-agenda-slot-row'));
		var conflicts = new Map();
		rows.forEach(function (row) {
			conflicts.set(row, []);
		});
		rows.forEach(function (first, firstIndex) {
			if (agendaFieldValue(first, 'schedule_mode') === 'tbd') {
				return;
			}
			var firstStart = agendaMinutes(agendaFieldValue(first, 'start'));
			var firstEnd = agendaMinutes(agendaFieldValue(first, 'end'));
			if (firstStart < 0 || firstEnd <= firstStart) {
				return;
			}
			rows.slice(firstIndex + 1).forEach(function (second) {
				var secondStart = agendaMinutes(agendaFieldValue(second, 'start'));
				var secondEnd = agendaMinutes(agendaFieldValue(second, 'end'));
				if (secondStart < 0 || secondEnd <= secondStart || firstStart >= secondEnd || secondStart >= firstEnd) {
					return;
				}
				var firstLocation = agendaFieldValue(first, 'location').toLowerCase();
				var secondLocation = agendaFieldValue(second, 'location').toLowerCase();
				var firstSpeakers = toArray(first.querySelectorAll('[name*="[speakers]"][name$="[speaker_id]"]')).map(function (field) { return field.value; }).filter(Boolean);
				var secondSpeakers = toArray(second.querySelectorAll('[name*="[speakers]"][name$="[speaker_id]"]')).map(function (field) { return field.value; }).filter(Boolean);
				var sameSpeaker = firstSpeakers.some(function (speakerId) { return secondSpeakers.indexOf(speakerId) !== -1; });
				if ((firstLocation && firstLocation === secondLocation) || sameSpeaker) {
					var reason = sameSpeaker ? 'Speaker is scheduled twice' : 'Location is double-booked';
					conflicts.get(first).push(reason);
					conflicts.get(second).push(reason);
				}
			});
		});
		conflicts.forEach(function (reasons, row) {
			var summary = row.previousElementSibling;
			var badge = summary && summary.querySelector('.oras-agenda-slot-summary__conflict');
			if (!badge) {
				return;
			}
			badge.hidden = reasons.length === 0;
			badge.title = Array.from(new Set(reasons)).join('; ');
			summary.classList.toggle('has-agenda-conflict', reasons.length > 0);
		});
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
		validateAgendaConflicts(day);

		if (!day.hasAttribute('data-oras-day-initialized')) {
			day.setAttribute('data-oras-day-initialized', '1');
			setDayExpanded(day, false);
		}
	}

	function refreshAgendaLocationFilter(agenda) {
		var filter = agenda.querySelector('.oras-agenda-admin-location-filter');
		if (!filter) {
			return;
		}
		var selected = filter.value;
		var locations = new Set();
		toArray(agenda.querySelectorAll('.oras-agenda-slot-row')).forEach(function (row) {
			var location = agendaFieldValue(row, 'location');
			if (location) {
				locations.add(location);
			}
		});
		filter.innerHTML = '<option value="">All locations</option>';
		Array.from(locations).sort().forEach(function (location) {
			var option = document.createElement('option');
			option.value = location.toLowerCase();
			option.textContent = location;
			filter.appendChild(option);
		});
		filter.value = selected;
	}

	function applyAgendaAdminFilters(agenda) {
		var search = agenda.querySelector('.oras-agenda-admin-search');
		var typeFilter = agenda.querySelector('.oras-agenda-admin-type-filter');
		var locationFilter = agenda.querySelector('.oras-agenda-admin-location-filter');
		var query = search ? search.value.trim().toLowerCase() : '';
		var type = typeFilter ? typeFilter.value.toLowerCase() : '';
		var location = locationFilter ? locationFilter.value.toLowerCase() : '';
		var shown = 0;
		var total = 0;

		toArray(agenda.querySelectorAll('.oras-agenda-slot-summary')).forEach(function (summary) {
			total++;
			var matches = (!query || (summary.getAttribute('data-search-text') || '').indexOf(query) !== -1) &&
				(!type || summary.getAttribute('data-session-type') === type) &&
				(!location || summary.getAttribute('data-location') === location);
			summary.hidden = !matches;
			var row = summary.nextElementSibling;
			if (row && row.classList.contains('oras-agenda-slot-row') && !row.classList.contains('is-editor-open')) {
				row.hidden = true;
			}
			if (matches) {
				shown++;
			}
		});

		var status = agenda.querySelector('.oras-agenda-editor-status');
		if (status) {
			status.textContent = shown === total ? total + ' sessions' : 'Showing ' + shown + ' of ' + total + ' sessions';
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

		if (!agenda.querySelector('.oras-agenda-editor-backdrop')) {
			var backdrop = document.createElement('button');
			backdrop.type = 'button';
			backdrop.className = 'oras-agenda-editor-backdrop';
			backdrop.setAttribute('aria-label', 'Close session editor');
			backdrop.hidden = true;
			agenda.appendChild(backdrop);
		}

		var refresh = function () {
			toArray(daysWrap.querySelectorAll('.oras-agenda-day')).forEach(enhanceAgendaDay);
			refreshAgendaLocationFilter(agenda);
			applyAgendaAdminFilters(agenda);
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

			var openEditor = event.target.closest('.oras-agenda-open-editor');
			if (openEditor) {
				event.preventDefault();
				var summary = openEditor.closest('.oras-agenda-slot-summary');
				var row = summary ? summary.nextElementSibling : null;
				if (!row) {
					return;
				}
				openAgendaEditorDrawer(row);
				return;
			}

			var closeEditor = event.target.closest('.oras-agenda-close-editor');
			if (closeEditor || event.target.closest('.oras-agenda-editor-backdrop')) {
				event.preventDefault();
				closeAgendaEditorDrawer();
				return;
			}

			var duplicate = event.target.closest('.oras-agenda-duplicate-slot');
			if (duplicate) {
				var duplicateSummary = duplicate.closest('.oras-agenda-slot-summary');
				duplicateAgendaSlot(duplicateSummary ? duplicateSummary.nextElementSibling : null);
				return;
			}

			var moveUp = event.target.closest('.oras-agenda-move-slot-up');
			var moveDown = event.target.closest('.oras-agenda-move-slot-down');
			if (moveUp || moveDown) {
				var moveSummary = (moveUp || moveDown).closest('.oras-agenda-slot-summary');
				moveAgendaSlot(moveSummary ? moveSummary.nextElementSibling : null, moveUp ? -1 : 1);
				return;
			}

			var remove = event.target.closest('.oras-agenda-admin-remove');
			if (remove) {
				var removeSummary = remove.closest('.oras-agenda-slot-summary');
				var removeRow = removeSummary ? removeSummary.nextElementSibling : null;
				if (removeRow && window.confirm('Remove this agenda session?')) {
					var removeDay = removeRow.closest('.oras-agenda-day');
					removeRow.remove();
					removeSummary.remove();
					updateDaySummary(removeDay);
					validateAgendaConflicts(removeDay);
					applyAgendaAdminFilters(agenda);
				}
				return;
			}

			if (event.target.closest('.oras-agenda-expand-days') || event.target.closest('.oras-agenda-collapse-days')) {
				var expand = !!event.target.closest('.oras-agenda-expand-days');
				toArray(daysWrap.querySelectorAll('.oras-agenda-day')).forEach(function (day) { setDayExpanded(day, expand); });
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

			var slotRow = target.closest('.oras-agenda-slot-row');
			if (slotRow) {
				updateAgendaSlotSummary(slotRow);
				validateAgendaConflicts(slotRow.closest('.oras-agenda-day'));
			}
			applyAgendaAdminFilters(agenda);
		});

		agenda.addEventListener('change', function (event) {
			var row = event.target.closest('.oras-agenda-slot-row');
			if (row) {
				updateAgendaSlotSummary(row);
				validateAgendaConflicts(row.closest('.oras-agenda-day'));
				refreshAgendaLocationFilter(agenda);
			}
			applyAgendaAdminFilters(agenda);
		});

		toArray(agenda.querySelectorAll('.oras-agenda-admin-search, .oras-agenda-admin-type-filter, .oras-agenda-admin-location-filter')).forEach(function (filter) {
			filter.addEventListener('input', function () { applyAgendaAdminFilters(agenda); });
			filter.addEventListener('change', function () { applyAgendaAdminFilters(agenda); });
		});

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && document.body.classList.contains('oras-agenda-editor-is-open')) {
				closeAgendaEditorDrawer();
			}
		}, true);

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
		var container = document.getElementById('oras-events-addon-root');
		if (!container) {
			return;
		}

		setupTabs(container);
		setupAutofillSuppression(container);
		setupResizeSuppression(container);
		setupTecAdminScrollGuard(container);
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
