(function () {
	'use strict';

	var container = document.getElementById('oras-event-speakers-metabox');
	if (!container) {
		return;
	}

	var rowsWrapper = container.querySelector('.oras-event-speakers-rows');
	var addButton = container.querySelector('#oras-add-speaker-row');
	var template = container.querySelector('#oras-speaker-row-template');
	if (!rowsWrapper || !addButton || !template) {
		return;
	}

	function updateCompensationVisibility(row) {
		var compensation = row.querySelector('.oras-event-speaker-compensation');
		if (!compensation) {
			return;
		}

		var feeField = row.querySelector('[data-compensation="fee"]');
		var membershipField = row.querySelector('[data-compensation="membership"]');
		if (feeField) {
			feeField.style.display = compensation.value === 'fee' ? '' : 'none';
		}
		if (membershipField) {
			membershipField.style.display = compensation.value === 'membership' ? '' : 'none';
		}
	}

	function selectedSpeakerName(row) {
		var select = row.querySelector('select[name*="[speaker_id]"]');
		if (!select) {
			return 'Speaker';
		}
		var option = select.options[select.selectedIndex];
		if (!option || option.value === '0') {
			return 'Speaker';
		}
		return option.textContent.trim() || 'Speaker';
	}

	function updateHeaderBadges(row) {
		var badges = row.querySelector('.oras-speaker-badges');
		if (!badges) {
			return;
		}

		badges.innerHTML = '';

		var primary = row.querySelector('input[name*="[is_primary]"]');
		if (primary && primary.checked) {
			var primaryBadge = document.createElement('span');
			primaryBadge.className = 'oras-speaker-badge is-primary';
			primaryBadge.textContent = 'Primary';
			badges.appendChild(primaryBadge);
		}

		var fulfilled = row.querySelector('input[name*="[fulfilled]"]');
		var statusBadge = document.createElement('span');
		statusBadge.className = 'oras-speaker-badge' + (fulfilled && fulfilled.checked ? ' is-fulfilled' : '');
		statusBadge.textContent = fulfilled && fulfilled.checked ? 'Fulfilled' : 'Pending';
		badges.appendChild(statusBadge);
	}

	function updateHeader(row) {
		var nameEl = row.querySelector('.oras-card__name');
		var roleEl = row.querySelector('.oras-speaker-role');
		var roleInput = row.querySelector('input[name*="[role]"]');

		if (nameEl) {
			nameEl.textContent = selectedSpeakerName(row);
		}
		if (roleEl) {
			var roleValue = roleInput ? roleInput.value.trim() : '';
			roleEl.textContent = roleValue !== '' ? roleValue : 'Role not set';
		}

		updateHeaderBadges(row);
	}

	function setExpanded(row, expanded) {
		var body = row.querySelector('.oras-card__body');
		var toggle = row.querySelector('.oras-card-toggle');
		if (!body || !toggle) {
			return;
		}

		body.hidden = !expanded;
		toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
		toggle.textContent = expanded ? 'Close' : 'Edit';
	}

	function wireRow(row, startCollapsed) {
		if (!row || row.getAttribute('data-oras-speaker-wired') === '1') {
			return;
		}

		row.setAttribute('data-oras-speaker-wired', '1');

		var removeButton = row.querySelector('.oras-remove-speaker-row');
		if (removeButton) {
			removeButton.addEventListener('click', function () {
				row.remove();
			});
		}

		var toggleButton = row.querySelector('.oras-card-toggle');
		if (toggleButton) {
			toggleButton.addEventListener('click', function () {
				var expanded = toggleButton.getAttribute('aria-expanded') === 'true';
				setExpanded(row, !expanded);
			});
		}

		row.addEventListener('input', function () {
			updateHeader(row);
		});
		row.addEventListener('change', function () {
			updateCompensationVisibility(row);
			updateHeader(row);
		});

		updateCompensationVisibility(row);
		updateHeader(row);
		setExpanded(row, !startCollapsed ? true : false);
	}

	function addRow() {
		var nextIndex = Number(rowsWrapper.dataset.nextIndex || 0);
		var html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex));
		var wrapper = document.createElement('div');
		wrapper.innerHTML = html.trim();
		var row = wrapper.firstElementChild;
		if (!row) {
			return;
		}

		rowsWrapper.appendChild(row);
		rowsWrapper.dataset.nextIndex = String(nextIndex + 1);
		wireRow(row, false);
	}

	Array.prototype.slice.call(rowsWrapper.querySelectorAll('.oras-event-speaker-row')).forEach(function (row) {
		wireRow(row, true);
	});

	addButton.addEventListener('click', addRow);
})();
