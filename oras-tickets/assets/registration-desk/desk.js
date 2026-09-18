(() => {
	'use strict';

	const config = window.ORASRegistrationDesk || {};
	const root = document.getElementById('oras-registration-desk-root');
	const storageKey = 'orasRegistrationDeskStationV1';
	const state = {
		station: null,
		view: 'dashboard',
		busy: false,
		pendingRequest: '',
		pendingPayload: null,
	};

	const escapeHtml = (value) => String(value ?? '')
		.replaceAll('&', '&amp;')
		.replaceAll('<', '&lt;')
		.replaceAll('>', '&gt;')
		.replaceAll('"', '&quot;')
		.replaceAll("'", '&#039;');

	const uuid = () => window.crypto?.randomUUID?.() || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
		const random = Math.random() * 16 | 0;
		return (character === 'x' ? random : (random & 0x3) | 0x8).toString(16);
	});

	class DeskError extends Error {
		constructor(message, code = '', data = {}) {
			super(message);
			this.code = code;
			this.data = data || {};
		}
	}

	async function api(path, options = {}, requestId = '') {
		const headers = {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
			...(options.headers || {}),
		};
		if (state.station?.station_token && path !== '/station') {
			headers['X-ORAS-Desk-Station'] = state.station.station_token;
		}
		if (requestId) {
			headers['X-ORAS-Desk-Request'] = requestId;
		}
		let response;
		try {
			response = await fetch(`${config.restUrl}${path}`, {
				credentials: 'same-origin',
				...options,
				headers,
			});
		} catch (error) {
			throw new DeskError('The server could not be reached. Your request is saved on this device; retry without collecting payment again.', 'network_error');
		}
		const payload = await response.json().catch(() => ({}));
		if (!response.ok) {
			if (String(payload.code || '').startsWith('oras_desk_station_')) {
				clearStation();
			}
			throw new DeskError(payload.message || 'The request could not be completed.', payload.code, payload.data);
		}
		return payload;
	}

	function loadStation() {
		try {
			state.station = JSON.parse(window.localStorage.getItem(storageKey) || 'null');
		} catch (error) {
			state.station = null;
		}
	}

	function saveStation(station) {
		state.station = station;
		window.localStorage.setItem(storageKey, JSON.stringify(station));
	}

	function clearStation() {
		state.station = null;
		state.pendingRequest = '';
		state.pendingPayload = null;
		window.localStorage.removeItem(storageKey);
	}

	function notice(message, kind = '') {
		return `<div class="desk-notice ${kind ? `desk-notice-${kind}` : ''}" role="status">${escapeHtml(message)}</div>`;
	}

	function renderSetup(message = '') {
		root.innerHTML = `
			<div class="desk-modal-backdrop">
				<main class="desk-setup">
					<div class="desk-mark" aria-hidden="true">ORAS</div>
					<h1>Registration Desk</h1>
					<p class="desk-lede">Enter your name for this device. The shared WordPress login stays the same.</p>
					${message ? notice(message, 'error') : ''}
					<form id="desk-station-form" class="desk-form">
						<div class="desk-field">
							<label for="desk-operator">Volunteer name</label>
							<input id="desk-operator" name="operator_label" autocomplete="name" maxlength="100" required autofocus>
						</div>
						<button type="submit">Start this station</button>
					</form>
				</main>
			</div>`;
		root.querySelector('#desk-station-form').addEventListener('submit', startStation);
	}

	async function startStation(event) {
		event.preventDefault();
		const button = event.currentTarget.querySelector('button');
		button.disabled = true;
		try {
			const data = await api('/station', {
				method: 'POST',
				body: JSON.stringify({operator_label: new FormData(event.currentTarget).get('operator_label')}),
			});
			saveStation(data);
			renderShell();
			await showDashboard();
		} catch (error) {
			renderSetup(error.message);
		}
	}

	function renderShell() {
		const station = state.station;
		root.innerHTML = `
			<header class="desk-topbar">
				<div class="desk-brand"><span class="desk-mark" aria-hidden="true">ORAS</span><span><strong>Registration Desk</strong><small>${escapeHtml(station.event_title)}</small></span></div>
				<div class="desk-volunteer"><small>Volunteer on this device</small><strong>${escapeHtml(station.operator_label)}</strong></div>
				<button id="desk-change-volunteer" class="desk-secondary" type="button">Change volunteer</button>
				<a class="desk-button" href="${escapeHtml(station.logout_url)}">Log out</a>
			</header>
			<div class="desk-layout">
				<nav class="desk-nav" aria-label="Registration Desk">
					<button type="button" data-view="dashboard">Dashboard</button>
					<button type="button" data-view="search">Find registration</button>
					<button type="button" data-view="walk-in">Walk-in</button>
					${station.can_manage ? '<button type="button" data-view="complimentary">Complimentary / speaker</button>' : ''}
				</nav>
				<main id="desk-main" class="desk-main"></main>
			</div>`;
		root.querySelector('#desk-change-volunteer').addEventListener('click', () => {
			clearStation();
			renderSetup();
		});
		root.querySelectorAll('[data-view]').forEach((button) => button.addEventListener('click', () => navigate(button.dataset.view)));
	}

	function setCurrent(view) {
		state.view = view;
		root.querySelectorAll('[data-view]').forEach((button) => {
			if (button.dataset.view === view) {
				button.setAttribute('aria-current', 'page');
			} else {
				button.removeAttribute('aria-current');
			}
		});
	}

	async function navigate(view) {
		if (view === 'dashboard') return showDashboard();
		if (view === 'search') return showSearch();
		if (view === 'walk-in') return showManualForm(false);
		if (view === 'complimentary') return showManualForm(true);
	}

	function main() {
		return document.getElementById('desk-main');
	}

	async function showDashboard(message = '') {
		setCurrent('dashboard');
		main().innerHTML = '<div class="desk-loading">Loading dashboard…</div>';
		try {
			const data = await api('/dashboard');
			const summary = data.summary || {};
			main().innerHTML = `
				<h1>Today’s desk</h1>
				<p class="desk-lede">${escapeHtml(summary.local_date)} · Counts update from saved attendance records.</p>
				${message ? notice(message, 'success') : ''}
				<div class="desk-grid">
					<section class="desk-card desk-stat"><strong>${Number(summary.checked_in_today || 0)}</strong><span>people checked in today</span></section>
					<section class="desk-card desk-stat"><strong>${Number(summary.active_registrations || 0)}</strong><span>active registrations, not capacity remaining</span></section>
					<section class="desk-card desk-stat"><strong>${Number(summary.reversed_today || 0)}</strong><span>attendance reversals today</span></section>
				</div>
				<section class="desk-section">
					<div class="desk-section-heading"><h2>Recent arrivals</h2><button type="button" class="desk-secondary" id="desk-refresh">Refresh</button></div>
					<div class="desk-stack">${renderRecent(data.recent || [])}</div>
				</section>`;
			main().querySelector('#desk-refresh').addEventListener('click', () => showDashboard());
		} catch (error) {
			main().innerHTML = `<h1>Dashboard</h1>${notice(error.message, 'error')}`;
		}
	}

	function renderRecent(items) {
		if (!items.length) return '<div class="desk-card desk-empty">No saved arrivals yet today.</div>';
		return items.map((item) => `
			<article class="desk-arrival">
				<div><strong>${escapeHtml(item.display_name || 'Unnamed family attendee')}</strong><div class="desk-meta">${escapeHtml(item.source_contact_name || item.source_type)} · ${escapeHtml(item.checked_in_operator_label)}</div></div>
				<time datetime="${escapeHtml(item.checked_in_at_utc)}">${escapeHtml(item.checked_in_at_utc)} UTC</time>
			</article>`).join('');
	}

	function showSearch() {
		setCurrent('search');
		main().innerHTML = `
			<h1>Find a registration</h1>
			<p class="desk-lede">Search by purchaser name, email, phone, or registration reference.</p>
			<form id="desk-search-form" class="desk-search"><label class="desk-sr-only" for="desk-search">Search</label><input id="desk-search" name="q" minlength="2" autocomplete="off" required autofocus><button>Search</button></form>
			<div id="desk-search-message"></div><div id="desk-search-results" class="desk-stack desk-section"></div>`;
		main().querySelector('#desk-search-form').addEventListener('submit', searchRegistrations);
	}

	async function searchRegistrations(event) {
		event.preventDefault();
		const query = String(new FormData(event.currentTarget).get('q') || '').trim();
		const results = main().querySelector('#desk-search-results');
		results.innerHTML = '<div class="desk-loading">Searching…</div>';
		try {
			const data = await api(`/registrations?q=${encodeURIComponent(query)}`);
			main().querySelector('#desk-search-message').innerHTML = data.coverage_complete ? '' : notice('Online-order recovery is not yet complete; results may be incomplete.', 'warning');
			results.innerHTML = data.items.length ? data.items.map((item) => `
				<article class="desk-result">
					<div><strong>${escapeHtml(item.contact_name || 'No purchaser name')}</strong><div class="desk-meta">${escapeHtml(item.contact_email)} · ${escapeHtml(item.contact_phone)} · ${escapeHtml(item.source_type)}</div><span class="desk-pill">${escapeHtml(item.classification)}</span></div>
					<button type="button" data-registration="${escapeHtml(item.registration_uuid)}">Open</button>
				</article>`).join('') : '<div class="desk-card desk-empty">No matching registration found.</div>';
			results.querySelectorAll('[data-registration]').forEach((button) => button.addEventListener('click', () => showRegistration(button.dataset.registration)));
		} catch (error) {
			results.innerHTML = notice(error.message, 'error');
		}
	}

	async function showRegistration(registrationUuid) {
		main().innerHTML = '<div class="desk-loading">Loading registration…</div>';
		try {
			const data = await api(`/registrations/${encodeURIComponent(registrationUuid)}`);
			const registration = data.registration;
			state.station.local_date = data.local_date || state.station.local_date;
			saveStation(state.station);
			const option = (state.station.options || []).find((candidate) => candidate.option_uuid === registration.option_uuid) || {};
			const maximum = Number(option.max_attendees || 1);
			const existing = data.attendees || [];
			main().innerHTML = `
				<button type="button" class="desk-secondary" id="desk-back-search">← Search</button>
				<h1>${escapeHtml(registration.contact_name || 'Registration')}</h1>
				<p class="desk-lede">${escapeHtml(option.label || registration.classification)} · ${escapeHtml(registration.validity_type)}${registration.valid_local_date ? ` · ${escapeHtml(registration.valid_local_date)}` : ''}</p>
				<div id="desk-detail-message"></div>
				${data.admission?.payment_label ? notice(data.admission.payment_label, data.admission.requires_explicit_unpaid ? 'warning' : '') : ''}
				${data.admission && !data.admission.allowed ? notice(data.admission.message, 'error') : ''}
				<section class="desk-card">
					<h2>Who is arriving now?</h2>
					<p class="desk-help">Only selected people are checked in. Family registrations allow up to ${maximum}; the maximum is not counted as attendance.</p>
					<form id="desk-checkin-form" class="desk-form" data-maximum="${maximum}" data-classification="${escapeHtml(registration.classification)}">
						<div id="desk-arrival-rows" class="desk-stack">${existing.map((attendee) => renderExistingAttendee(attendee, registration)).join('')}</div>
						<div class="desk-actions"><button type="button" class="desk-secondary" id="desk-add-arrival">Add arriving person</button><button type="submit" ${data.admission && !data.admission.allowed ? 'disabled' : ''}>Check in selected arrivals</button></div>
					</form>
				</section>
				${state.station.can_manage && data.editable_registration ? renderCorrectionForm(data.editable_registration) : ''}`;
			main().querySelector('#desk-back-search').addEventListener('click', showSearch);
			const add = () => addArrivalRow(main().querySelector('#desk-arrival-rows'), registration.classification, maximum);
			main().querySelector('#desk-add-arrival').addEventListener('click', add);
			if (!existing.length) add();
			main().querySelector('#desk-checkin-form').addEventListener('submit', (event) => submitCheckIn(event, registration));
			main().querySelectorAll('[data-reverse-attendee]').forEach((button) => button.addEventListener('click', () => reverseAttendance(registration, button)));
			main().querySelector('#desk-correction-form')?.addEventListener('submit', (event) => saveCorrection(event, registration));
		} catch (error) {
			main().innerHTML = `<h1>Registration</h1>${notice(error.message, 'error')}`;
		}
	}

	function renderExistingAttendee(attendee) {
		const attendance = attendee.current_attendance;
		const checkedIn = attendance?.state === 'checked_in';
		return `<div class="desk-attendee-row"><input type="checkbox" name="selected" value="${escapeHtml(attendee.slot_key)}" ${checkedIn ? 'disabled' : ''}><span><strong>${escapeHtml(attendee.display_name || 'Unnamed family attendee')}</strong><small class="desk-meta"> ${checkedIn ? 'Already checked in today' : escapeHtml(attendee.slot_key)}</small></span><input type="hidden" data-first value="${escapeHtml(attendee.first_name)}"><input type="hidden" data-last value="${escapeHtml(attendee.last_name)}">${state.station.can_manage && checkedIn ? `<button type="button" class="desk-danger" data-reverse-attendee="${escapeHtml(attendee.attendee_uuid)}" data-version="${Number(attendance.record_version)}">Reverse</button>` : ''}</div>`;
	}

	function addArrivalRow(container, classification, maximum) {
		if (container.children.length >= maximum) return;
		const prefix = classification === 'individual' ? 'individual' : 'family';
		const used = new Set([...container.children].map((child) => child.dataset.slot || child.querySelector('[name="selected"]')?.value));
		let number = 1;
		while (used.has(`${prefix}-${number}`) && number <= maximum) number++;
		if (number > maximum) return;
		const row = document.createElement('div');
		row.className = 'desk-attendee-row desk-new-arrival';
		row.dataset.slot = `${prefix}-${number}`;
		row.innerHTML = `<span class="desk-pill">Arriving</span><div class="desk-field"><label>First name${classification === 'family' ? ' (optional)' : ''}</label><input data-first></div><div class="desk-field"><label>Last name${classification === 'family' ? ' (optional)' : ''}</label><input data-last></div><button type="button" class="desk-secondary" aria-label="Remove attendee">Remove</button>`;
		row.querySelector('button').addEventListener('click', () => row.remove());
		container.appendChild(row);
	}

	async function submitCheckIn(event, registration) {
		event.preventDefault();
		await performCheckIn(event.currentTarget, registration, false);
	}

	async function performCheckIn(form, registration, explicitUnpaid) {
		const arrivals = [];
		form.querySelectorAll('.desk-attendee-row').forEach((row) => {
			const checkbox = row.querySelector('[name="selected"]');
			if (checkbox && !checkbox.checked) return;
			arrivals.push({
				slot_key: checkbox?.value || row.dataset.slot,
				first_name: row.querySelector('[data-first]')?.value || '',
				last_name: row.querySelector('[data-last]')?.value || '',
			});
		});
		if (!arrivals.length) {
			main().querySelector('#desk-detail-message').innerHTML = notice('Select or add at least one actual arrival.', 'error');
			return;
		}
		const requestId = uuid();
		try {
			await api(`/registrations/${registration.registration_uuid}/check-in`, {
				method: 'POST',
				body: JSON.stringify({arrivals, attendance_local_date: state.station.local_date, explicit_unpaid: explicitUnpaid}),
			}, requestId);
			await showDashboard(`${arrivals.length} ${arrivals.length === 1 ? 'person' : 'people'} checked in.`);
		} catch (error) {
			const message = main().querySelector('#desk-detail-message');
			if (error.code === 'oras_desk_unpaid_confirmation_required') {
				message.innerHTML = `${notice(error.message, 'warning')}<button type="button" id="desk-explicit-unpaid">Check in as unpaid</button>`;
				message.querySelector('#desk-explicit-unpaid').addEventListener('click', () => performCheckIn(form, registration, true));
			} else {
				message.innerHTML = notice(error.message, 'error');
			}
		}
	}

	function renderCorrectionForm(editor) {
		const options = availableOptions(true);
		return `<details class="desk-card desk-section"><summary><strong>Administrator correction</strong></summary>
			<p class="desk-help">Correct contact or option details for this desk-created registration. Attendance changes use the separate Reverse action above.</p>
			<form id="desk-correction-form" class="desk-form desk-section">
				<input type="hidden" name="expected_record_version" value="${Number(editor.expected_record_version)}">
				<div class="desk-fields">
					<div class="desk-field"><label>First name</label><input name="first_name" value="${escapeHtml(editor.first_name)}" required></div>
					<div class="desk-field"><label>Last name</label><input name="last_name" value="${escapeHtml(editor.last_name)}" required></div>
					<div class="desk-field"><label>Email</label><input name="email" type="email" value="${escapeHtml(editor.email)}" required></div>
					<div class="desk-field"><label>Phone</label><input name="phone" type="tel" value="${escapeHtml(editor.phone)}" required></div>
					<div class="desk-field desk-field-wide"><label>Option</label><select name="option_uuid" required>${options.map((option) => `<option value="${escapeHtml(option.option_uuid)}" ${option.option_uuid === editor.option_uuid ? 'selected' : ''}>${escapeHtml(option.label)}</option>`).join('')}</select></div>
					<div class="desk-field"><label>One-day date</label><input name="valid_local_date" type="date" value="${escapeHtml(editor.valid_local_date)}"></div>
					<div class="desk-field"><label>Recorded statement</label><select name="payment_assertion"><option value="paid_card" ${editor.payment_assertion === 'paid_card' ? 'selected' : ''}>Paid—Card</option><option value="paid_cash" ${editor.payment_assertion === 'paid_cash' ? 'selected' : ''}>Paid—Cash</option><option value="paid_check" ${editor.payment_assertion === 'paid_check' ? 'selected' : ''}>Paid—Check</option><option value="unpaid" ${editor.payment_assertion === 'unpaid' ? 'selected' : ''}>Unpaid</option><option value="complimentary" ${editor.payment_assertion === 'complimentary' ? 'selected' : ''}>Complimentary / speaker</option></select></div>
				</div>
				<input type="hidden" name="address_1" value="${escapeHtml(editor.address_1)}"><input type="hidden" name="address_2" value="${escapeHtml(editor.address_2)}"><input type="hidden" name="city" value="${escapeHtml(editor.city)}"><input type="hidden" name="state" value="${escapeHtml(editor.state)}"><input type="hidden" name="postcode" value="${escapeHtml(editor.postcode)}">
				<button type="submit">Save correction</button>
			</form></details>`;
	}

	async function reverseAttendance(registration, button) {
		const reason = window.prompt('Reason for reversing this attendance record:');
		if (!reason?.trim()) return;
		button.disabled = true;
		try {
			await api(`/registrations/${registration.registration_uuid}/attendees/${button.dataset.reverseAttendee}/reverse`, {
				method: 'POST',
				body: JSON.stringify({attendance_local_date: state.station.local_date, expected_record_version: Number(button.dataset.version), reason: reason.trim()}),
			}, uuid());
			await showRegistration(registration.registration_uuid);
			main().querySelector('#desk-detail-message').innerHTML = notice('Attendance was reversed and audited.', 'success');
		} catch (error) {
			button.disabled = false;
			main().querySelector('#desk-detail-message').innerHTML = notice(error.message, 'error');
		}
	}

	async function saveCorrection(event, registration) {
		event.preventDefault();
		const form = event.currentTarget;
		const payload = Object.fromEntries(new FormData(form).entries());
		payload.additional_attendees = [];
		form.querySelector('button').disabled = true;
		try {
			await api(`/registrations/${registration.registration_uuid}/correct`, {method: 'POST', body: JSON.stringify(payload)}, uuid());
			await showRegistration(registration.registration_uuid);
			main().querySelector('#desk-detail-message').innerHTML = notice('Registration details were corrected and audited.', 'success');
		} catch (error) {
			form.querySelector('button').disabled = false;
			main().querySelector('#desk-detail-message').innerHTML = notice(error.message, 'error');
		}
	}

	function availableOptions(administrator) {
		return (state.station.options || []).filter((option) => administrator || option.available_for_new).filter((option) => ['individual', 'family'].includes(option.classification) && ['full_event', 'one_day'].includes(option.validity_type));
	}

	function showManualForm(administrator) {
		setCurrent(administrator ? 'complimentary' : 'walk-in');
		const options = availableOptions(administrator);
		main().innerHTML = `
			<h1>${administrator ? 'Complimentary / speaker registration' : 'Walk-in registration'}</h1>
			<p class="desk-lede">${administrator ? 'Administrator-only nonfinancial registration.' : 'Collect contact details, review, handle any sale separately in AlfaPOS, then record the volunteer statement.'}</p>
			<div id="desk-manual-message"></div>
			<form id="desk-manual-form" class="desk-form">
				<section class="desk-card"><h2>Contact</h2><div class="desk-fields">
					<div class="desk-field"><label>First name</label><input name="first_name" required></div><div class="desk-field"><label>Last name</label><input name="last_name" required></div>
					<div class="desk-field"><label>Email</label><input name="email" type="email" required></div><div class="desk-field"><label>Phone</label><input name="phone" type="tel" required></div>
				</div></section>
				<section class="desk-card"><h2>Registration</h2><div class="desk-fields">
					<div class="desk-field desk-field-wide"><label>Option</label><select id="desk-manual-option" name="option_uuid" required><option value="">Choose…</option>${options.map((option) => `<option value="${escapeHtml(option.option_uuid)}">${escapeHtml(option.label)} · ${escapeHtml(option.classification)} · ${escapeHtml(option.validity_type)}</option>`).join('')}</select></div>
					<div class="desk-field" id="desk-one-day-field" hidden><label>One-day date</label><input name="valid_local_date" type="date" value="${escapeHtml(state.station.local_date)}"></div>
					${administrator ? '<div class="desk-field"><label>Registration kind</label><select name="source_type"><option value="complimentary">Complimentary</option><option value="speaker">Speaker</option></select></div>' : ''}
				</div><div id="desk-family-controls" hidden><div id="desk-family-members" class="desk-stack desk-section"></div><button type="button" class="desk-secondary" id="desk-add-family">Add family member arriving now</button><p id="desk-family-limit" class="desk-help"></p></div></section>
				<details class="desk-card"><summary><strong>Optional mailing address</strong></summary><div class="desk-fields desk-section">
					<div class="desk-field desk-field-wide"><label>Address</label><input name="address_1"></div><div class="desk-field desk-field-wide"><label>Address line 2</label><input name="address_2"></div><div class="desk-field"><label>City</label><input name="city"></div><div class="desk-field"><label>State</label><input name="state"></div><div class="desk-field"><label>Postal code</label><input name="postcode"></div>
				</div></details>
				<div class="desk-actions"><button type="button" class="desk-secondary" id="desk-cancel-manual">Cancel</button><button type="submit">Review registration</button></div>
			</form>`;
		main().querySelector('#desk-cancel-manual').addEventListener('click', () => showDashboard());
		main().querySelector('#desk-add-family').addEventListener('click', addFamilyMember);
		main().querySelector('#desk-manual-option').addEventListener('change', syncManualOption);
		main().querySelector('#desk-manual-form').addEventListener('submit', (event) => reviewManual(event, administrator));
	}

	function syncManualOption() {
		const selected = main().querySelector('#desk-manual-option').value;
		const option = (state.station.options || []).find((candidate) => candidate.option_uuid === selected);
		const family = option?.classification === 'family';
		main().querySelector('#desk-family-controls').hidden = !family;
		main().querySelector('#desk-one-day-field').hidden = option?.validity_type !== 'one_day';
		if (!family) main().querySelector('#desk-family-members').innerHTML = '';
		main().querySelector('#desk-family-limit').textContent = family ? `Up to ${Number(option.max_attendees || 1)} actual arrivals including the primary contact.` : '';
	}

	function addFamilyMember() {
		const container = main().querySelector('#desk-family-members');
		const selected = main().querySelector('#desk-manual-option').value;
		const option = (state.station.options || []).find((candidate) => candidate.option_uuid === selected);
		const maximum = Number(option?.max_attendees || 1);
		if (container.children.length + 1 >= maximum) return;
		const row = document.createElement('div');
		row.className = 'desk-attendee-row';
		row.innerHTML = '<span class="desk-pill">Family</span><div class="desk-field"><label>First name (optional)</label><input data-first></div><div class="desk-field"><label>Last name (optional)</label><input data-last></div><button type="button" class="desk-secondary">Remove</button>';
		row.querySelector('button').addEventListener('click', () => row.remove());
		container.appendChild(row);
	}

	function reviewManual(event, administrator) {
		event.preventDefault();
		const form = event.currentTarget;
		const data = Object.fromEntries(new FormData(form).entries());
		data.additional_attendees = [...form.querySelectorAll('#desk-family-members .desk-attendee-row')].map((row) => ({first_name: row.querySelector('[data-first]').value, last_name: row.querySelector('[data-last]').value}));
		state.pendingPayload = data;
		state.pendingRequest = uuid();
		const option = (state.station.options || []).find((candidate) => candidate.option_uuid === data.option_uuid) || {};
		main().innerHTML = `
			<h1>Review registration</h1>
			<p class="desk-lede">Nothing has been saved yet. Cancel safely if the attendee changes their mind.</p>
			<div id="desk-review-message"></div>
			<section class="desk-card desk-review"><dl><dt>Name</dt><dd>${escapeHtml(data.first_name)} ${escapeHtml(data.last_name)}</dd><dt>Contact</dt><dd>${escapeHtml(data.email)} · ${escapeHtml(data.phone)}</dd><dt>Option</dt><dd>${escapeHtml(option.label)}</dd><dt>Arriving now</dt><dd>${1 + data.additional_attendees.length}</dd></dl></section>
			${administrator ? '<section class="desk-card desk-section"><h2>Finish administrator registration</h2><p>No sale or order will be created.</p><button type="button" data-admin-save>Save and check in</button></section>' : '<section class="desk-card desk-section"><h2>Separate AlfaPOS step</h2><p>Complete any actual sale in AlfaPOS. Then record only the volunteer’s operational statement below. If saving fails, retry this screen—do not collect payment again.</p><div class="desk-payment-actions"><button type="button" data-payment="paid_card">Paid—Card</button><button type="button" data-payment="paid_cash">Paid—Cash</button><button type="button" data-payment="paid_check">Paid—Check</button><button type="button" data-payment="unpaid" class="desk-secondary">Unpaid</button></div></section>'}
			<div class="desk-actions desk-section"><button type="button" class="desk-secondary" id="desk-edit-manual">Back to edit</button><button type="button" class="desk-secondary" id="desk-abandon-manual">Cancel registration</button></div>`;
		main().querySelector('#desk-edit-manual').addEventListener('click', () => showManualForm(administrator));
		main().querySelector('#desk-abandon-manual').addEventListener('click', () => showDashboard());
		main().querySelectorAll('[data-payment]').forEach((button) => button.addEventListener('click', () => saveManual(button.dataset.payment, administrator)));
		main().querySelector('[data-admin-save]')?.addEventListener('click', () => saveManual('complimentary', true));
	}

	async function saveManual(payment, administrator, acknowledge = false) {
		const payload = {...state.pendingPayload, payment_assertion: payment, duplicate_acknowledged: acknowledge};
		const target = administrator ? '/registrations/complimentary' : '/registrations/walk-in';
		const message = main().querySelector('#desk-review-message');
		main().querySelectorAll('button').forEach((button) => { button.disabled = true; });
		try {
			const result = await api(target, {method: 'POST', body: JSON.stringify(payload)}, state.pendingRequest);
			state.pendingPayload = null;
			state.pendingRequest = '';
			const count = result.historical_result?.attendance?.length || 1;
			await showDashboard(`Registration saved and ${count} ${count === 1 ? 'person' : 'people'} checked in.`);
		} catch (error) {
			main().querySelectorAll('button').forEach((button) => { button.disabled = false; });
			if (error.code === 'oras_desk_possible_duplicate') {
				message.innerHTML = `${notice(error.message, 'warning')}<button type="button" id="desk-continue-duplicate">Keep separate and continue</button>`;
				message.querySelector('#desk-continue-duplicate').addEventListener('click', () => saveManual(payment, administrator, true));
			} else {
				message.innerHTML = notice(error.message, 'error');
			}
		}
	}

	loadStation();
	if (state.station?.station_token) {
		renderShell();
		showDashboard();
	} else {
		renderSetup();
	}
})();
