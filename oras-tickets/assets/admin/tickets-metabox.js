(function () {
	'use strict';

	function toArray(nodeList) {
		return Array.prototype.slice.call(nodeList || []);
	}

	function focusElement(element, preventScroll) {
		if (!element || typeof element.focus !== 'function') {
			return;
		}

		if (preventScroll) {
			try {
				element.focus({ preventScroll: true });
				return;
			} catch (error) {
				// Fallback for browsers without focus options support.
			}
		}

		element.focus();
	}

	function getWindowScrollPosition() {
		return {
			x: window.pageXOffset || window.scrollX || 0,
			y: window.pageYOffset || window.scrollY || 0,
		};
	}

	function restoreWindowScroll(position) {
		if (!position) {
			return;
		}

		var currentX = window.pageXOffset || window.scrollX || 0;
		var currentY = window.pageYOffset || window.scrollY || 0;
		if (Math.abs(currentX - position.x) < 1 && Math.abs(currentY - position.y) < 1) {
			return;
		}

		window.scrollTo(position.x, position.y);
	}

	function preserveWindowScroll(callback) {
		var position = getWindowScrollPosition();
		callback();
		window.requestAnimationFrame(function () {
			window.requestAnimationFrame(function () {
				restoreWindowScroll(position);
			});
		});
	}

	function parseLocalDateTime(value) {
		if (!value) {
			return null;
		}
		var dt = new Date(value);
		if (Number.isNaN(dt.getTime())) {
			return null;
		}
		return dt.getTime();
	}

	function saleStatus(startValue, endValue) {
		var startTs = parseLocalDateTime(startValue);
		var endTs = parseLocalDateTime(endValue);
		if (startTs === null && endTs === null) {
			return 'Always';
		}
		var now = Date.now();
		if (startTs !== null && now < startTs) {
			return 'Scheduled';
		}
		if (endTs !== null && now > endTs) {
			return 'Ended';
		}
		return 'On sale';
	}

	function saleWindow(startValue, endValue) {
		if (!startValue && !endValue) {
			return 'Always';
		}
		if (startValue && endValue) {
			return startValue.replace('T', ' ') + ' → ' + endValue.replace('T', ' ');
		}
		if (startValue) {
			return 'Starts ' + startValue.replace('T', ' ');
		}
		return 'Ends ' + endValue.replace('T', ' ');
	}

	function ticketRows(root) {
		return toArray(root.querySelectorAll('#oras-tickets-table .oras-ticket-row'));
	}

	function nextIndex(root) {
		var inputs = root.querySelectorAll('input[name="oras_tickets_index[]"]');
		var max = -1;
		toArray(inputs).forEach(function (input) {
			var value = Number.parseInt(input.value, 10);
			if (!Number.isNaN(value) && value > max) {
				max = value;
			}
		});
		return max + 1;
	}

	function replaceTokenInFragment(fragment, token, value) {
		var walker = document.createTreeWalker(fragment, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, null);
		var node = walker.currentNode;
		while (node) {
			if (node.nodeType === Node.ELEMENT_NODE) {
				toArray(node.attributes || []).forEach(function (attr) {
					if (attr.value && attr.value.indexOf(token) !== -1) {
						node.setAttribute(attr.name, attr.value.replaceAll(token, value));
					}
				});
			}
			if (node.nodeType === Node.TEXT_NODE && node.nodeValue && node.nodeValue.indexOf(token) !== -1) {
				node.nodeValue = node.nodeValue.replaceAll(token, value);
			}
			node = walker.nextNode();
		}
	}

	function getRowIndex(row) {
		return String(row.getAttribute('data-index') || '');
	}

	function getInput(row, field) {
		var idx = getRowIndex(row);
		if (!idx) {
			return null;
		}
		return row.querySelector('[name="oras_tickets_tickets[' + idx + '][' + field + ']"]');
	}

	function currentName(row) {
		var idx = getRowIndex(row);
		var nameInput = getInput(row, 'name');
		var value = nameInput ? nameInput.value.trim() : '';
		return value !== '' ? value : 'Ticket #' + idx;
	}

	function getAttendanceMode(row) {
		var input = getInput(row, 'attendance_mode');
		return input ? String(input.value || '').trim() : '';
	}

	function attendanceModeLabel(mode) {
		if (mode === 'virtual') {
			return 'Virtual';
		}
		if (mode === 'onsite') {
			return 'On-site';
		}
		return 'Choose type';
	}

	function normalizePrice(value) {
		var parsed = Number.parseFloat(String(value || '').replace(',', '.'));
		if (Number.isNaN(parsed) || parsed < 0) {
			return 0;
		}
		return parsed;
	}

	function hasMeaningfulContent(row) {
		var idx = getRowIndex(row);
		if (!idx) {
			return false;
		}

		var description = getInput(row, 'description');
		var name = getInput(row, 'name');
		var price = getInput(row, 'price');
		var capacity = getInput(row, 'capacity');
		var start = getInput(row, 'sale_start');
		var end = getInput(row, 'sale_end');
		var hide = row.querySelector('[name="oras_tickets_tickets[' + idx + '][hide_sold_out]"]');

		if (name && name.value.trim() !== '') {
			return true;
		}
		if (description && description.value.trim() !== '') {
			return true;
		}
		if (price && normalizePrice(price.value) > 0) {
			return true;
		}
		if (capacity && Number.parseInt(capacity.value || '0', 10) > 0) {
			return true;
		}
		if (start && start.value) {
			return true;
		}
		if (end && end.value) {
			return true;
		}
		if (hide && hide.checked) {
			return true;
		}

		return false;
	}

	function clearFieldError(input) {
		if (!input) {
			return;
		}
		var wrapper = input.closest('.oras-field-block') || input.parentElement;
		if (!wrapper) {
			return;
		}
		var error = wrapper.querySelector('.oras-field-error[data-for="' + input.name + '"]');
		if (error) {
			error.remove();
		}
		input.removeAttribute('aria-invalid');
	}

	function setFieldError(input, message) {
		if (!input) {
			return;
		}
		var wrapper = input.closest('.oras-field-block') || input.parentElement;
		if (!wrapper) {
			return;
		}
		var selector = '.oras-field-error[data-for="' + input.name + '"]';
		var error = wrapper.querySelector(selector);
		if (!error) {
			error = document.createElement('p');
			error.className = 'oras-field-error';
			error.setAttribute('data-for', input.name);
			wrapper.appendChild(error);
		}
		error.textContent = message;
		input.setAttribute('aria-invalid', 'true');
	}

	function validateRow(row) {
		var issues = [];
		var hasContent = hasMeaningfulContent(row);
		var nameInput = getInput(row, 'name');
		var priceInput = getInput(row, 'price');
		var capacityInput = getInput(row, 'capacity');
		var startInput = getInput(row, 'sale_start');
		var endInput = getInput(row, 'sale_end');
		var attendanceModeInput = getInput(row, 'attendance_mode');

		[nameInput, priceInput, capacityInput, startInput, endInput, attendanceModeInput].forEach(clearFieldError);

		if (hasContent && nameInput && nameInput.value.trim() === '') {
			setFieldError(nameInput, 'Ticket name is required.');
			issues.push('Ticket name is required.');
		}

		if (hasContent && attendanceModeInput && attendanceModeInput.value === '') {
			setFieldError(attendanceModeInput, 'Ticket type is required.');
			issues.push('Ticket type is required.');
		}

		if (priceInput) {
			var rawPrice = Number.parseFloat(String(priceInput.value || '').replace(',', '.'));
			if (String(priceInput.value || '').trim() !== '' && Number.isNaN(rawPrice)) {
				setFieldError(priceInput, 'Price must be numeric.');
				issues.push('Price must be numeric.');
			}
			if (!Number.isNaN(rawPrice) && rawPrice < 0) {
				setFieldError(priceInput, 'Price cannot be negative.');
				issues.push('Price cannot be negative.');
			}
		}

		if (capacityInput && capacityInput.value !== '' && Number.parseInt(capacityInput.value, 10) < 0) {
			setFieldError(capacityInput, 'Inventory cannot be negative.');
			issues.push('Inventory cannot be negative.');
		}

		var startTs = startInput ? parseLocalDateTime(startInput.value) : null;
		var endTs = endInput ? parseLocalDateTime(endInput.value) : null;
		if (startInput && startInput.value && startTs === null) {
			setFieldError(startInput, 'Invalid start date/time.');
			issues.push('Invalid start date/time.');
		}
		if (endInput && endInput.value && endTs === null) {
			setFieldError(endInput, 'Invalid end date/time.');
			issues.push('Invalid end date/time.');
		}
		if (startTs !== null && endTs !== null && endTs < startTs) {
			setFieldError(endInput, 'Sale end must be later than sale start.');
			issues.push('Sale end must be later than sale start.');
		}

		return issues;
	}

	function setValidationNotice(root) {
		var notice = root.querySelector('#oras-ticket-validation');
		if (!notice) {
			return;
		}

		var messages = [];
		ticketRows(root).forEach(function (row) {
			messages = messages.concat(validateRow(row));
		});

		var messageTarget = notice.querySelector('p');
		if (!messageTarget) {
			return;
		}

		if (messages.length === 0) {
			notice.hidden = true;
			messageTarget.textContent = '';
			return;
		}

		notice.hidden = false;
		messageTarget.textContent = messages.length === 1 ? messages[0] : messages.length + ' fields need attention before saving.';
	}

	function setRowExpanded(row, expand) {
		var panel = row.querySelector('.oras-ticket-panel');
		var body = row.querySelector('.oras-card__body');
		var toggle = row.querySelector('.oras-card-toggle');
		if (!panel || !body || !toggle) {
			return;
		}

		panel.classList.remove('is-hidden');
		panel.classList.add('is-active');
		body.hidden = !expand;
		toggle.setAttribute('aria-expanded', expand ? 'true' : 'false');
		toggle.textContent = expand ? 'Close' : 'Edit';
	}

	function syncPhaseToggle(phaseItem) {
		if (!phaseItem) {
			return;
		}
		var toggle = phaseItem.querySelector('.oras-phase-toggle');
		if (!toggle) {
			return;
		}
		toggle.textContent = phaseItem.classList.contains('is-collapsed') ? 'Advanced' : 'Hide advanced';
	}

	function initPhaseToggles(root) {
		toArray(root.querySelectorAll('.oras-phase-item')).forEach(syncPhaseToggle);
	}

	function updateCardHeader(row) {
		var nameEl = row.querySelector('.oras-card__name');
		var metaEl = row.querySelector('.oras-card__meta');
		if (!nameEl || !metaEl) {
			return;
		}

		var name = currentName(row);
		var priceInput = getInput(row, 'price');
		var startInput = getInput(row, 'sale_start');
		var endInput = getInput(row, 'sale_end');
		var price = priceInput ? normalizePrice(priceInput.value).toFixed(2) : '0.00';
		var attendanceMode = attendanceModeLabel(getAttendanceMode(row));
		var status = saleStatus(startInput ? startInput.value : '', endInput ? endInput.value : '');

		nameEl.textContent = name;
		metaEl.textContent = '$' + price + ' · ' + attendanceMode + ' · ' + status;
	}

	function summaryDataForRow(row) {
		var idx = getRowIndex(row);
		var name = currentName(row);
		var priceInput = getInput(row, 'price');
		var price = priceInput ? normalizePrice(priceInput.value).toFixed(2) : '0.00';
		var type = attendanceModeLabel(getAttendanceMode(row));
		var capacityInput = getInput(row, 'capacity');
		var capacityNum = capacityInput ? Number.parseInt(capacityInput.value || '0', 10) : 0;
		var inventory = capacityNum > 0 ? String(capacityNum) : 'Unlimited';
		var startInput = getInput(row, 'sale_start');
		var endInput = getInput(row, 'sale_end');
		var startValue = startInput ? startInput.value : '';
		var endValue = endInput ? endInput.value : '';

		return {
			index: idx,
			name: name,
			price: '$' + price,
			type: type,
			inventory: inventory,
			saleWindow: saleWindow(startValue, endValue),
			status: saleStatus(startValue, endValue),
		};
	}

	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/\"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function syncSummary(root) {
		var summaryBody = root.querySelector('#oras-tickets-summary tbody');
		if (!summaryBody) {
			return;
		}

		var rows = ticketRows(root);
		summaryBody.innerHTML = '';
		if (rows.length === 0) {
			var emptyRow = document.createElement('tr');
			emptyRow.className = 'oras-ticket-summary-empty';
			emptyRow.innerHTML = '<td colspan="7">No tickets yet.</td>';
			summaryBody.appendChild(emptyRow);
			return;
		}

		rows.forEach(function (row) {
			var data = summaryDataForRow(row);
			var summaryRow = document.createElement('tr');
			summaryRow.setAttribute('data-ticket-index', data.index);
			summaryRow.innerHTML = '' +
				'<td>' + escapeHtml(data.name) + '</td>' +
				'<td>' + escapeHtml(data.price) + '</td>' +
				'<td>' + escapeHtml(data.type) + '</td>' +
				'<td>' + escapeHtml(data.inventory) + '</td>' +
				'<td>' + escapeHtml(data.saleWindow) + '</td>' +
				'<td>' + escapeHtml(data.status) + '</td>' +
				'<td class="oras-ticket-summary-actions">' +
				'<button type="button" class="button button-small oras-ticket-summary-edit" data-index="' + escapeHtml(data.index) + '">Edit</button> ' +
				'<button type="button" class="button button-small oras-ticket-summary-remove" data-index="' + escapeHtml(data.index) + '">Remove</button>' +
				'</td>';
			summaryBody.appendChild(summaryRow);
		});
	}

	function focusTicketName(row) {
		var nameInput = getInput(row, 'name');
		if (nameInput) {
			focusElement(nameInput, true);
		}
	}

	function addTicket(root) {
		var template = root.querySelector('#oras-ticket-template');
		var tbody = root.querySelector('#oras-tickets-table tbody');
		if (!template || !template.content || !tbody) {
			return;
		}

		var idx = nextIndex(root);
		var fragment = template.content.cloneNode(true);
		replaceTokenInFragment(fragment, '__INDEX__', String(idx));
		var row = fragment.querySelector('.oras-ticket-row');
		if (!row) {
			return;
		}

		tbody.appendChild(row);
		initializeRow(row, true);
		syncSummary(root);
		setValidationNotice(root);
		focusTicketName(row);
		updateEmptyState(root);
	}

	function updateEmptyState(root) {
		var emptyState = root.querySelector('#oras-tickets-empty');
		var table = root.querySelector('#oras-tickets-table');
		var rows = ticketRows(root);
		if (emptyState) {
			emptyState.classList.toggle('is-hidden', rows.length > 0);
		}
		if (table) {
			table.style.display = rows.length > 0 ? 'table' : 'none';
		}
	}

	function removeTicket(root, index) {
		var row = root.querySelector('#oras-tickets-table .oras-ticket-row[data-index="' + index + '"]');
		if (row) {
			row.remove();
		}

		syncSummary(root);
		setValidationNotice(root);
		updateEmptyState(root);
	}

	function nextPhaseIndex(list) {
		var max = -1;
		toArray(list.querySelectorAll('[data-phase-index]')).forEach(function (row) {
			var value = Number.parseInt(row.getAttribute('data-phase-index') || '', 10);
			if (!Number.isNaN(value) && value > max) {
				max = value;
			}
		});
		return max + 1;
	}

	function addPhase(button) {
		var ticketRow = button.closest('tr.oras-ticket-row');
		if (!ticketRow) {
			return;
		}
		var list = ticketRow.querySelector('.oras-phase-list');
		var template = ticketRow.querySelector('template.oras-phase-template');
		if (!list || !template || !template.content) {
			return;
		}

		var fragment = template.content.cloneNode(true);
		replaceTokenInFragment(fragment, '__PHASE__', String(nextPhaseIndex(list)));
		list.appendChild(fragment);
		initPhaseToggles(ticketRow);
	}

	function hasPhaseInputData(phaseItem) {
		return toArray(phaseItem.querySelectorAll('input[type="text"]')).some(function (input) {
			return (input.value || '').trim() !== '';
		});
	}

	function humanizeKey(value) {
		var text = String(value || '').replaceAll(/[_-]+/g, ' ').trim();
		if (text === '') {
			return '';
		}
		return text.replace(/\w\S*/g, function (word) {
			return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
		});
	}

	function initializeRow(row, expand) {
		if (!row) {
			return;
		}
		setRowExpanded(row, !!expand);
		updateCardHeader(row);
		initPhaseToggles(row);
	}

	function activateTicketInnerTab(row, panelSelector) {
		if (!row || !panelSelector || panelSelector.charAt(0) !== '#') {
			return;
		}

		var tabs = toArray(row.querySelectorAll('.oras-ticket-data-tabs li'));
		tabs.forEach(function (tabItem) {
			tabItem.classList.remove('active');
		});

		var selectedTabLink = row.querySelector('.oras-ticket-data-tabs a[href="' + panelSelector + '"]');
		if (selectedTabLink && selectedTabLink.parentElement) {
			selectedTabLink.parentElement.classList.add('active');
		}

		var panels = toArray(row.querySelectorAll('.oras-ticket-data .panel.woocommerce_options_panel'));
		panels.forEach(function (panel) {
			var isSelected = '#' + panel.id === panelSelector;
			panel.style.display = isSelected ? 'block' : 'none';
			panel.classList.toggle('oras-panel-hidden', !isSelected);
		});
	}

	function init() {
		var root = document.getElementById('oras-tickets-metabox');
		if (!root) {
			return;
		}

		ticketRows(root).forEach(function (row, index) {
			initializeRow(row, index === 0);
		});

		syncSummary(root);
		setValidationNotice(root);
		updateEmptyState(root);

		root.addEventListener('click', function (event) {
			var ticketTabLink = event.target.closest('.oras-ticket-data-tabs a');
			if (ticketTabLink) {
				event.preventDefault();
				var tabRow = ticketTabLink.closest('tr.oras-ticket-row');
				if (!tabRow) {
					return;
				}
				var href = ticketTabLink.getAttribute('href') || '';
				preserveWindowScroll(function () {
					activateTicketInnerTab(tabRow, href);
					focusElement(ticketTabLink, true);
				});
				return;
			}

			var addButton = event.target.closest('#oras-add-ticket, .oras-add-ticket-trigger');
			if (addButton) {
				event.preventDefault();
				addTicket(root);
				return;
			}

			var toggleButton = event.target.closest('.oras-card-toggle');
			if (toggleButton) {
				event.preventDefault();
				var row = toggleButton.closest('tr.oras-ticket-row');
				if (!row) {
					return;
				}
				var expanded = toggleButton.getAttribute('aria-expanded') === 'true';
				preserveWindowScroll(function () {
					setRowExpanded(row, !expanded);
					focusElement(toggleButton, true);
				});
				return;
			}

			var editSummaryButton = event.target.closest('.oras-ticket-summary-edit');
			if (editSummaryButton) {
				event.preventDefault();
				var index = editSummaryButton.getAttribute('data-index');
				var targetRow = root.querySelector('#oras-tickets-table .oras-ticket-row[data-index="' + index + '"]');
				if (!targetRow) {
					return;
				}
				setRowExpanded(targetRow, true);
				focusTicketName(targetRow);
				targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
				return;
			}

			var removeButton = event.target.closest('.oras-remove-ticket, .oras-ticket-summary-remove');
			if (removeButton) {
				event.preventDefault();
				var row = removeButton.closest('tr.oras-ticket-row');
				var index = row ? getRowIndex(row) : removeButton.getAttribute('data-index');
				if (index) {
					preserveWindowScroll(function () {
						removeTicket(root, index);
					});
				}
				return;
			}

			var phaseToggle = event.target.closest('.oras-phase-toggle');
			if (phaseToggle) {
				event.preventDefault();
				var phaseItem = phaseToggle.closest('.oras-phase-item');
				if (!phaseItem) {
					return;
				}
				preserveWindowScroll(function () {
					phaseItem.classList.toggle('is-collapsed');
					syncPhaseToggle(phaseItem);
					focusElement(phaseToggle, true);
				});
				return;
			}

			var phaseAddButton = event.target.closest('.oras-phase-add');
			if (phaseAddButton) {
				event.preventDefault();
				preserveWindowScroll(function () {
					addPhase(phaseAddButton);
					focusElement(phaseAddButton, true);
				});
				return;
			}

			var phaseRemoveButton = event.target.closest('.oras-phase-remove');
			if (phaseRemoveButton) {
				event.preventDefault();
				var phase = phaseRemoveButton.closest('.oras-phase-item');
				if (!phase) {
					return;
				}
				if (hasPhaseInputData(phase) && !window.confirm('Remove this pricing phase?')) {
					return;
				}
				preserveWindowScroll(function () {
					phase.remove();
				});
			}
		});

		var syncFromInput = function (event) {
			var target = event.target;
			if (!target || typeof target.name !== 'string') {
				return;
			}
			if (target.name.indexOf('oras_tickets_tickets[') !== 0) {
				return;
			}

			var row = target.closest('tr.oras-ticket-row');
			if (!row) {
				return;
			}

			updateCardHeader(row);
			syncSummary(root);
			setValidationNotice(root);
		};

		root.addEventListener('input', syncFromInput);
		root.addEventListener('change', syncFromInput);

		root.addEventListener('focusout', function (event) {
			var target = event.target;
			if (!target || typeof target.name !== 'string') {
				return;
			}
			if (target.name.indexOf('[price_phases]') === -1 || target.name.indexOf('[key]') === -1) {
				return;
			}

			var phaseItem = target.closest('.oras-phase-item');
			if (!phaseItem) {
				return;
			}
			var labelInput = phaseItem.querySelector('input[name*="[label]"]');
			if (labelInput && (labelInput.value || '').trim() === '') {
				var label = humanizeKey(target.value || '');
				if (label !== '') {
					labelInput.value = label;
				}
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
