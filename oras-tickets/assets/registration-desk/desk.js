(() => {
	'use strict';

	const config = window.ORASRegistrationDesk || {};
	const root = document.getElementById('oras-registration-desk-root');
	const storageKey = 'orasRegistrationDeskStationV1';
	const state = {
		station: null,
		view: 'home',
		searchQuery: '',
		wizard: null,
		pendingRequest: '',
		pendingPayload: null,
		pendingPayment: '',
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

	function apiUrl(path) {
		const [endpoint, query = ''] = String(path).split('?', 2);
		const url = new URL(`${config.restUrl}${endpoint}`, window.location.href);
		new URLSearchParams(query).forEach((value, key) => url.searchParams.set(key, value));
		return url.toString();
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
			response = await fetch(apiUrl(path), {
				credentials: 'same-origin',
				...options,
				headers,
			});
		} catch (error) {
			throw new DeskError('The server could not be reached.', 'network_error');
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

	function resetPending() {
		state.pendingRequest = '';
		state.pendingPayload = null;
		state.pendingPayment = '';
	}

	function clearStation() {
		state.station = null;
		state.wizard = null;
		resetPending();
		window.localStorage.removeItem(storageKey);
	}

	function icon(name) {
		const paths = {
			search: '<circle cx="11" cy="11" r="6"></circle><path d="m16 16 5 5"></path>',
			person: '<circle cx="12" cy="8" r="4"></circle><path d="M4 21c.8-5 3.5-7 8-7s7.2 2 8 7"></path>',
			family: '<circle cx="8" cy="9" r="3"></circle><circle cx="17" cy="8" r="3"></circle><path d="M2 21c.5-4 2.5-6 6-6 2 0 3.6.7 4.6 2M13 21c.4-4 2-7 5-7 2.8 0 4.5 2.2 5 7"></path>',
			calendar: '<rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M8 3v4M16 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"></path>',
			manager: '<circle cx="12" cy="12" r="3"></circle><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"></path>',
			check: '<path d="m5 12 4 4L19 6"></path>',
			arrow: '<path d="m9 18 6-6-6-6"></path>',
			back: '<path d="m15 18-6-6 6-6"></path>',
			card: '<rect x="2" y="5" width="20" height="14" rx="2"></rect><path d="M2 10h20"></path>',
			cash: '<rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="3"></circle><path d="M6 9h.01M18 15h.01"></path>',
			help: '<circle cx="12" cy="12" r="10"></circle><path d="M9.4 9a3 3 0 1 1 4.5 2.6c-1.2.7-1.9 1.3-1.9 2.4M12 18h.01"></path>',
		};
		return `<svg class="desk-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${paths[name] || paths.help}</svg>`;
	}

	function brandMark() {
		return `<span class="desk-logo-mark" aria-hidden="true"><svg viewBox="0 0 64 64" focusable="false"><circle cx="32" cy="32" r="29"></circle><path d="M15 43 41 24M36 23l10 4-5 9M25 36l8 8M19 47h28"></path><circle cx="20" cy="17" r="1.5"></circle><circle cx="39" cy="13" r="1.5"></circle><circle cx="49" cy="19" r="1"></circle></svg></span>`;
	}

	function notice(message, kind = '') {
		return `<div class="desk-notice ${kind ? `desk-notice-${kind}` : ''}" role="status">${escapeHtml(message)}</div>`;
	}

	function friendlyError(error) {
		const code = String(error?.code || '');
		if (code.startsWith('oras_desk_station_') || ['oras_desk_config_changed', 'oras_desk_active_event_changed'].includes(code)) {
			return 'The event settings changed while you were working. Return to the home screen and set up this station again.';
		}
		if (['oras_desk_registration_inactive', 'oras_desk_not_eligible', 'oras_desk_source_changed', 'oras_desk_source_option_changed', 'oras_desk_source_unit_invalid', 'oras_desk_attendance_reversed'].includes(code)) {
			return 'This registration is no longer valid for check-in. Please ask a manager for help.';
		}
		if (['oras_desk_wrong_date', 'oras_desk_date_changed'].includes(code)) {
			return 'This registration cannot be checked in for today. Please ask a manager for help.';
		}
		if (code === 'oras_desk_search_short') {
			return 'Type at least two letters or numbers to search.';
		}
		if (code === 'oras_desk_contact_required') {
			return 'Enter a first name, last name, valid email address, and phone number.';
		}
		if (code === 'oras_desk_attendee_name_invalid') {
			return 'Enter both first and last name, or leave both blank for an unnamed family member.';
		}
		if (code === 'network_error') {
			return 'We could not reach the server. Check the connection and try again.';
		}
		return 'We could not finish that step. Please try again or ask a manager for help.';
	}

	function formatDateValue(value) {
		if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ''))) return String(value || '');
		const [year, month, day] = value.split('-').map(Number);
		return new Intl.DateTimeFormat('en-US', {weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC'}).format(new Date(Date.UTC(year, month - 1, day, 12)));
	}

	function formatLocalTime(value) {
		if (!value) return '';
		const normalized = String(value).includes('T') ? String(value) : `${String(value).replace(' ', 'T')}Z`;
		const date = new Date(normalized);
		if (Number.isNaN(date.getTime())) return '';
		return new Intl.DateTimeFormat('en-US', {hour: 'numeric', minute: '2-digit', timeZone: config.timeZone || 'UTC'}).format(date);
	}

	function optionFor(uuidValue) {
		return (state.station?.options || []).find((option) => option.option_uuid === uuidValue) || {};
	}

	function availableOptions(administrator = false) {
		return (state.station?.options || [])
			.filter((option) => administrator || option.available_for_new)
			.filter((option) => ['individual', 'family'].includes(option.classification) && ['full_event', 'one_day'].includes(option.validity_type))
			.filter((option) => option.validity_type !== 'one_day' || option.valid_local_date === state.station.local_date);
	}

	function registrationType(option, registration = {}) {
		const classification = option.classification || registration.classification || 'Registration';
		const validity = option.validity_type || registration.validity_type || '';
		const type = classification === 'family' ? 'Family' : 'Individual';
		return `${type} — ${validity === 'one_day' ? 'One Day' : 'Full Event'}`;
	}

	function sourceLabel(source) {
		return {online: 'Website registration', walk_in: 'Walk-in registration', complimentary: 'Complimentary registration', speaker: 'Speaker registration'}[source] || 'Registration';
	}

	function paymentLabel(registration, admission = {}) {
		if (registration.source_type === 'online') return 'Website registration recorded';
		return {
			paid_card: 'Walk-in marked paid by card',
			paid_cash: 'Walk-in marked paid by cash',
			paid_check: 'Walk-in marked paid by check',
			unpaid: 'Walk-in marked unpaid',
			complimentary: registration.source_type === 'speaker' ? 'Speaker registration' : 'Complimentary registration',
		}[registration.payment_assertion] || (admission.requires_explicit_unpaid ? 'Website payment is not confirmed' : 'Registration recorded');
	}

	function parseName(name) {
		const pieces = String(name || '').trim().split(/\s+/);
		return {first_name: pieces.shift() || '', last_name: pieces.join(' ')};
	}

	function screenActions(backLabel = 'Back', includeStartOver = true) {
		return `<div class="desk-screen-actions"><button type="button" class="desk-link-button" id="desk-screen-back">${icon('back')} ${escapeHtml(backLabel)}</button>${includeStartOver ? '<button type="button" class="desk-link-button" id="desk-start-over">Cancel / Start Over</button>' : ''}</div>`;
	}

	function bindScreenActions(backHandler = showHome) {
		main().querySelector('#desk-screen-back')?.addEventListener('click', () => backHandler());
		main().querySelector('#desk-start-over')?.addEventListener('click', () => {
			if (window.confirm('Cancel this task and return to the home screen?')) {
				state.wizard = null;
				resetPending();
				showHome();
			}
		});
	}

	function renderSetup(message = '') {
		root.innerHTML = `<div class="desk-setup-backdrop"><main class="desk-setup-card">
			<div class="desk-setup-brand">${brandMark()}<div><strong>ORAS</strong><span>Oil Region Astronomical Society</span></div></div>
			<p class="desk-eyebrow">Registration Desk</p><h1>Who is volunteering at this station?</h1><p class="desk-lede">Enter your name so each check-in has a clear helper.</p>
			${message ? notice(message, 'error') : ''}
			<form id="desk-station-form" class="desk-form"><div class="desk-field"><label for="desk-operator">Volunteer name</label><input id="desk-operator" name="operator_label" autocomplete="name" maxlength="100" required autofocus></div><button type="submit" class="desk-primary desk-wide">START REGISTRATION DESK ${icon('arrow')}</button></form>
		</main></div>`;
		root.querySelector('#desk-station-form').addEventListener('submit', startStation);
	}

	async function startStation(event) {
		event.preventDefault();
		const button = event.currentTarget.querySelector('button');
		button.disabled = true;
		try {
			const data = await api('/station', {method: 'POST', body: JSON.stringify({operator_label: new FormData(event.currentTarget).get('operator_label')})});
			saveStation(data);
			renderShell();
			await showHome();
		} catch (error) {
			renderSetup(friendlyError(error));
		}
	}

	function renderShell() {
		const station = state.station;
		root.innerHTML = `<header class="desk-topbar">
			<button type="button" class="desk-brand-button" id="desk-brand-home" aria-label="Return to Registration Desk home">${brandMark()}<span class="desk-brand-copy"><strong>ORAS</strong><small>Oil Region Astronomical Society</small></span></button>
			<div class="desk-title-block"><strong>Registration Desk</strong><small>Simple. Friendly. Foolproof.</small></div>
			<div class="desk-event-block"><strong>${escapeHtml(station.event_title)}</strong><small>${escapeHtml(station.friendly_date || formatDateValue(station.local_date))}</small></div>
			<div class="desk-user-block"><span>Volunteer: <strong>${escapeHtml(station.operator_label)}</strong></span><button id="desk-change-volunteer" class="desk-header-button" type="button">Change</button></div>
			<a class="desk-header-button desk-logout" id="desk-logout" href="${escapeHtml(station.logout_url)}">Log out</a>
		</header><main id="desk-main" class="desk-main" tabindex="-1"></main>`;
		root.querySelector('#desk-brand-home').addEventListener('click', () => showHome());
		root.querySelector('#desk-change-volunteer').addEventListener('click', () => {
			if (window.confirm('Change the volunteer name for this station?')) {
				clearStation();
				renderSetup();
			}
		});
		root.querySelector('#desk-logout').addEventListener('click', (event) => {
			if (!window.confirm('Log out of the shared Registration Desk account?')) event.preventDefault();
		});
	}

	function main() {
		return document.getElementById('desk-main');
	}

	function focusMain() {
		main()?.focus({preventScroll: true});
	}

	async function showHome(message = '') {
		state.view = 'home';
		state.wizard = null;
		resetPending();
		main().innerHTML = '<div class="desk-loading">Opening the desk…</div>';
		try {
			const data = await api('/dashboard');
			if (data.local_date) {
				state.station.local_date = data.local_date;
				state.station.friendly_date = data.friendly_date || formatDateValue(data.local_date);
				saveStation(state.station);
				const headerDate = root.querySelector('.desk-event-block small');
				if (headerDate) headerDate.textContent = state.station.friendly_date;
			}
			const summary = data.summary || {};
			const recent = (data.recent || []).slice(0, 3);
			main().innerHTML = `<section class="desk-home">
				${message ? notice(message, 'success') : ''}
				<div class="desk-home-heading"><p class="desk-eyebrow">Welcome, ${escapeHtml(state.station.operator_label)}</p><h1>WHAT DO YOU NEED TO DO?</h1></div>
				<div class="desk-task-grid">
					<button type="button" class="desk-task-card desk-task-find" id="desk-home-find"><span class="desk-task-icon">${icon('search')}</span><strong>FIND SOMEONE ALREADY REGISTERED</strong><small>Use this for people who already signed up.</small><span class="desk-task-next">Start ${icon('arrow')}</span></button>
					<button type="button" class="desk-task-card desk-task-walkin" id="desk-home-walkin"><span class="desk-task-icon">${icon('family')}</span><strong>REGISTER A WALK-IN</strong><small>Use this for someone registering here today.</small><span class="desk-task-next">Start ${icon('arrow')}</span></button>
				</div>
				<div class="desk-home-secondary"><div class="desk-today-count">${icon('check')} <strong>${Number(summary.checked_in_today || 0)}</strong> checked in today</div><button type="button" class="desk-help-button" id="desk-home-help">${icon('manager')} ${state.station.can_manage ? 'MANAGER TOOLS' : 'NEED MANAGER HELP?'}</button></div>
				${recent.length ? `<section class="desk-recent"><h2>Recent check-ins</h2><div class="desk-recent-list">${renderRecent(recent)}</div></section>` : ''}
			</section>`;
			main().querySelector('#desk-home-find').addEventListener('click', () => showSearch());
			main().querySelector('#desk-home-walkin').addEventListener('click', () => startWalkInWizard(false));
			main().querySelector('#desk-home-help').addEventListener('click', () => state.station.can_manage ? showManagerArea() : showManagerHelp());
			focusMain();
		} catch (error) {
			main().innerHTML = `<section class="desk-centered"><h1>Registration Desk</h1>${notice(friendlyError(error), 'error')}<button type="button" id="desk-home-retry">Try Again</button></section>`;
			main().querySelector('#desk-home-retry').addEventListener('click', () => showHome());
		}
	}

	function renderRecent(items) {
		return items.map((item) => `<div class="desk-recent-item"><span class="desk-mini-check">${icon('check')}</span><div><strong>${escapeHtml(item.display_name || 'Unnamed attendee')}</strong><small>Helped by ${escapeHtml(item.checked_in_operator_label)}</small></div><time datetime="${escapeHtml(item.checked_in_at_utc)}">${escapeHtml(formatLocalTime(item.checked_in_at_utc))}</time></div>`).join('');
	}

	function showManagerHelp() {
		state.view = 'help';
		main().innerHTML = `${screenActions('Back to Home', false)}<section class="desk-centered desk-help-screen"><span class="desk-large-icon desk-gold-icon">${icon('manager')}</span><p class="desk-eyebrow">Need help?</p><h1>PLEASE ASK A MANAGER</h1><p>A manager can help with unusual registrations, corrections, or attendance changes.</p><button type="button" class="desk-primary" id="desk-help-done">DONE — RETURN HOME</button></section>`;
		bindScreenActions(showHome);
		main().querySelector('#desk-help-done').addEventListener('click', () => showHome());
		focusMain();
	}

	function showSearch() {
		state.view = 'search';
		main().innerHTML = `${screenActions('Back to Home')}<section class="desk-kiosk-panel desk-search-panel">
			<p class="desk-eyebrow">Find someone who already signed up</p><h1>FIND A REGISTRATION</h1><p class="desk-lede">Type the person’s name, email, or phone number.</p>
			<form id="desk-search-form" class="desk-search-form"><label class="desk-sr-only" for="desk-search">Name, email, or phone number</label><div class="desk-search-box">${icon('search')}<input id="desk-search" name="q" minlength="2" autocomplete="off" placeholder="Name, email, or phone number" value="${escapeHtml(state.searchQuery)}" required autofocus></div><button type="submit" class="desk-primary">SEARCH</button></form>
			<p class="desk-example">Examples: John Smith · john@example.com · 814-555-1234</p><div id="desk-search-message"></div><div id="desk-search-results" class="desk-results"></div>
		</section>`;
		bindScreenActions(showHome);
		main().querySelector('#desk-search-form').addEventListener('submit', searchRegistrations);
		focusMain();
	}

	async function searchRegistrations(event) {
		event.preventDefault();
		const query = String(new FormData(event.currentTarget).get('q') || '').trim();
		state.searchQuery = query;
		const results = main().querySelector('#desk-search-results');
		const message = main().querySelector('#desk-search-message');
		results.innerHTML = '<div class="desk-loading desk-loading-small">Searching…</div>';
		message.innerHTML = '';
		try {
			const data = await api(`/registrations?q=${encodeURIComponent(query)}`);
			if (!data.coverage_complete) message.innerHTML = notice('We may not have all website registrations loaded yet. Please ask a manager for help.', 'warning');
			if (!data.items.length) {
				results.innerHTML = `<div class="desk-no-results"><span class="desk-large-icon">${icon('search')}</span><h2>We couldn’t find a registration.</h2><p>Check the spelling or try a different email or phone number.</p><div class="desk-actions"><button type="button" class="desk-secondary" id="desk-search-again">SEARCH AGAIN</button><button type="button" id="desk-search-walkin">REGISTER AS WALK-IN</button></div></div>`;
				results.querySelector('#desk-search-again').addEventListener('click', () => { main().querySelector('#desk-search').focus(); main().querySelector('#desk-search').select(); });
				results.querySelector('#desk-search-walkin').addEventListener('click', () => startWalkInWizard(false));
				return;
			}
			results.innerHTML = `<h2 class="desk-results-title">Search results</h2>${data.items.map((item) => renderSearchResult(item)).join('')}`;
			results.querySelectorAll('[data-registration]').forEach((button) => button.addEventListener('click', () => showRegistration(button.dataset.registration)));
		} catch (error) {
			results.innerHTML = `${notice(friendlyError(error), 'error')}<button type="button" class="desk-secondary" id="desk-search-retry">TRY AGAIN</button>`;
			results.querySelector('#desk-search-retry').addEventListener('click', () => event.currentTarget.requestSubmit());
		}
	}

	function renderSearchResult(item) {
		const option = optionFor(item.option_uuid);
		const date = item.validity_type === 'one_day' && item.valid_local_date ? formatDateValue(item.valid_local_date) : 'Full event';
		return `<article class="desk-result-card"><div class="desk-result-main"><strong>${escapeHtml(item.contact_name || 'Registration')}</strong><span>${escapeHtml(registrationType(option, item))}</span><small>${escapeHtml(date)} · ${escapeHtml(sourceLabel(item.source_type))}</small><small>${escapeHtml(item.contact_email)} · ${escapeHtml(item.contact_phone)}</small></div><button type="button" data-registration="${escapeHtml(item.registration_uuid)}">OPEN REGISTRATION ${icon('arrow')}</button></article>`;
	}

	async function showRegistration(registrationUuid) {
		state.view = 'registration';
		main().innerHTML = '<div class="desk-loading">Opening registration…</div>';
		try {
			const data = await api(`/registrations/${encodeURIComponent(registrationUuid)}`);
			const registration = data.registration;
			state.station.local_date = data.local_date || state.station.local_date;
			saveStation(state.station);
			const option = optionFor(registration.option_uuid);
			const maximum = Number(option.max_attendees || 1);
			const existing = data.attendees || [];
			const allowed = data.admission?.allowed !== false;
			const canAddAttendee = existing.length < maximum;
			main().innerHTML = `${screenActions('Back to Search')}<section class="desk-detail-heading"><p class="desk-eyebrow">Registration details</p><h1>${escapeHtml(registration.contact_name || 'Registration')}</h1><div class="desk-detail-summary"><span>${escapeHtml(registrationType(option, registration))}</span><span>${escapeHtml(registration.validity_type === 'one_day' ? formatDateValue(registration.valid_local_date) : state.station.event_title)}</span><span>${escapeHtml(paymentLabel(registration, data.admission))}</span></div></section>
				<div id="desk-detail-message">${allowed ? '' : notice('This registration cannot be checked in. Please ask a manager for help.', 'error')}</div>
				<section class="desk-kiosk-panel desk-attendance-panel"><h2>WHO IS HERE TODAY?</h2><p>Select only the people who are here now.${registration.classification === 'family' ? ` This registration allows up to ${maximum} people.` : ''}</p><form id="desk-checkin-form" class="desk-form" data-maximum="${maximum}" data-classification="${escapeHtml(registration.classification)}"><div id="desk-arrival-rows" class="desk-attendee-list">${existing.map((attendee) => renderExistingAttendee(attendee)).join('')}</div><div class="desk-detail-actions">${canAddAttendee ? `<button type="button" class="desk-secondary" id="desk-add-arrival">${registration.classification === 'family' ? 'ADD ANOTHER PERSON' : 'ENTER ATTENDEE NAME'}</button>` : ''}<button type="submit" class="desk-primary" disabled>CHECK IN SELECTED PEOPLE ${icon('arrow')}</button></div></form></section>
				${state.station.can_manage && data.editable_registration ? `<section class="desk-manager-inline"><details><summary>Manager correction tools</summary>${renderCorrectionForm(data.editable_registration)}</details></section>` : ''}`;
			bindScreenActions(showSearch);
			const form = main().querySelector('#desk-checkin-form');
			const updateSubmitState = () => { form.querySelector('[type="submit"]').disabled = !allowed || !collectArrivals(form).length; };
			const add = (name = {}) => { addArrivalRow(main().querySelector('#desk-arrival-rows'), registration.classification, maximum, name); updateSubmitState(); };
			main().querySelector('#desk-add-arrival')?.addEventListener('click', () => add());
			if (!existing.length) add(parseName(registration.contact_name));
			form.addEventListener('change', updateSubmitState);
			form.addEventListener('click', () => window.setTimeout(updateSubmitState, 0));
			form.addEventListener('submit', (event) => submitCheckIn(event, registration, option));
			updateSubmitState();
			main().querySelectorAll('[data-reverse-attendee]').forEach((button) => button.addEventListener('click', () => reverseAttendance(registration, button)));
			main().querySelector('#desk-correction-form')?.addEventListener('submit', (event) => saveCorrection(event, registration));
			focusMain();
		} catch (error) {
			main().innerHTML = `${screenActions('Back to Search')}<section class="desk-centered"><h1>Registration</h1>${notice(friendlyError(error), 'error')}</section>`;
			bindScreenActions(showSearch);
		}
	}

	function renderExistingAttendee(attendee) {
		const attendance = attendee.current_attendance;
		const checkedIn = attendance?.state === 'checked_in';
		return `<div class="desk-attendee-card ${checkedIn ? 'is-checked-in' : ''}"><label><input type="checkbox" name="selected" value="${escapeHtml(attendee.slot_key)}" ${checkedIn ? 'disabled' : ''}><span class="desk-select-box">${checkedIn ? icon('check') : ''}</span><span><strong>${escapeHtml(attendee.display_name || 'Unnamed attendee')}</strong><small>${checkedIn ? `Checked in today${formatLocalTime(attendance.checked_in_at_utc) ? ` at ${escapeHtml(formatLocalTime(attendance.checked_in_at_utc))}` : ''}` : 'Tap to select this person'}</small></span></label><input type="hidden" data-first value="${escapeHtml(attendee.first_name)}"><input type="hidden" data-last value="${escapeHtml(attendee.last_name)}">${state.station.can_manage && checkedIn ? `<button type="button" class="desk-danger desk-manager-action" data-reverse-attendee="${escapeHtml(attendee.attendee_uuid)}" data-version="${Number(attendance.record_version)}">Reverse check-in</button>` : ''}</div>`;
	}

	function addArrivalRow(container, classification, maximum, name = {}) {
		if (container.children.length >= maximum) return;
		const prefix = classification === 'individual' ? 'individual' : 'family';
		const used = new Set([...container.children].map((child) => child.dataset.slot || child.querySelector('[name="selected"]')?.value));
		let number = 1;
		while (used.has(`${prefix}-${number}`) && number <= maximum) number++;
		if (number > maximum) return;
		const row = document.createElement('div');
		row.className = 'desk-new-attendee';
		row.dataset.slot = `${prefix}-${number}`;
		row.innerHTML = `<div class="desk-new-attendee-heading"><strong>${classification === 'family' ? `Attendee ${number}` : 'Attendee name'}</strong>${classification === 'family' ? '<small>Leave both names blank for an unnamed attendee.</small>' : ''}</div><div class="desk-field"><label>First name${classification === 'family' ? ' — optional' : ''}</label><input data-first value="${escapeHtml(name.first_name || '')}"></div><div class="desk-field"><label>Last name${classification === 'family' ? ' — optional' : ''}</label><input data-last value="${escapeHtml(name.last_name || '')}"></div><button type="button" class="desk-remove-button" aria-label="Remove attendee">Remove</button>`;
		row.querySelector('button').addEventListener('click', () => row.remove());
		container.appendChild(row);
	}

	async function submitCheckIn(event, registration, option) {
		event.preventDefault();
		resetPending();
		await performCheckIn(event.currentTarget, registration, false, option);
	}

	function collectArrivals(form) {
		const arrivals = [];
		form.querySelectorAll('.desk-attendee-card, .desk-new-attendee').forEach((row) => {
			const checkbox = row.querySelector('[name="selected"]');
			if (checkbox && (!checkbox.checked || checkbox.disabled)) return;
			arrivals.push({slot_key: checkbox?.value || row.dataset.slot, first_name: row.querySelector('[data-first]')?.value || '', last_name: row.querySelector('[data-last]')?.value || ''});
		});
		return arrivals;
	}

	async function performCheckIn(form, registration, explicitUnpaid, option = optionFor(registration.option_uuid)) {
		const arrivals = state.pendingPayload?.arrivals || collectArrivals(form);
		if (!arrivals.length) {
			main().querySelector('#desk-detail-message').innerHTML = notice('Select or add at least one person who is here today.', 'error');
			return;
		}
		if (!state.pendingRequest) state.pendingRequest = uuid();
		state.pendingPayload = {arrivals, attendance_local_date: state.station.local_date, explicit_unpaid: explicitUnpaid};
		const message = main().querySelector('#desk-detail-message');
		form.querySelectorAll('button').forEach((button) => { button.disabled = true; });
		try {
			const result = await api(`/registrations/${registration.registration_uuid}/check-in`, {method: 'POST', body: JSON.stringify(state.pendingPayload)}, state.pendingRequest);
			const count = result.historical_result?.attendance?.length || arrivals.length;
			const attendance = Array.isArray(result.current_attendance) ? result.current_attendance[0] : result.current_attendance;
			resetPending();
			showSuccess({kind: 'checkin', name: registration.contact_name || 'Registration', count, type: registrationType(option, registration), when: attendance?.checked_in_at_utc || ''});
		} catch (error) {
			form.querySelectorAll('button').forEach((button) => { button.disabled = false; });
			if (error.code === 'oras_desk_unpaid_confirmation_required') {
				message.innerHTML = `<div class="desk-confirm-card"><h3>CHECK IN AS UNPAID?</h3><p>Payment is not confirmed for this website registration.</p><div class="desk-actions"><button type="button" class="desk-warning-button" id="desk-explicit-unpaid">CONTINUE UNPAID</button><button type="button" class="desk-secondary" id="desk-unpaid-back">GO BACK</button></div></div>`;
				message.querySelector('#desk-explicit-unpaid').addEventListener('click', () => performCheckIn(form, registration, true));
				message.querySelector('#desk-unpaid-back').addEventListener('click', () => { message.innerHTML = ''; resetPending(); });
			} else if (error.code === 'network_error') {
				message.innerHTML = `${notice('We could not confirm whether the check-in was saved. Tap Retry; the same check-in will not be counted twice.', 'warning')}<button type="button" id="desk-checkin-retry">RETRY</button>`;
				message.querySelector('#desk-checkin-retry').addEventListener('click', () => performCheckIn(form, registration, explicitUnpaid, option));
			} else {
				message.innerHTML = notice(friendlyError(error), 'error');
			}
		}
	}

	function startWalkInWizard(administrator) {
		state.view = administrator ? 'complimentary' : 'walk-in';
		resetPending();
		state.wizard = {administrator, step: 'type', data: {first_name: '', last_name: '', email: '', phone: '', address_1: '', address_2: '', city: '', state: '', postcode: '', option_uuid: '', valid_local_date: '', source_type: 'complimentary'}, additional_attendees: [], payment: ''};
		showWalkInStep('type');
	}

	function wizardSteps() {
		const option = optionFor(state.wizard?.data.option_uuid);
		const steps = ['type', 'contact'];
		if (option.classification === 'family') steps.push('attendees');
		steps.push('review', state.wizard?.administrator ? 'kind' : 'payment');
		return steps;
	}

	function wizardProgress(step) {
		const steps = wizardSteps();
		const current = Math.max(0, steps.indexOf(step));
		return `<div class="desk-progress" aria-label="Step ${current + 1} of ${steps.length}"><strong>STEP ${current + 1} OF ${steps.length}</strong><div class="desk-progress-track">${steps.map((item, index) => `<span class="${index <= current ? 'is-complete' : ''}"></span>`).join('')}</div></div>`;
	}

	function showWalkInStep(step) {
		if (!state.wizard) return startWalkInWizard(false);
		state.wizard.step = step;
		resetPending();
		if (step === 'type') return showWizardType();
		if (step === 'contact') return showWizardContact();
		if (step === 'attendees') return showWizardAttendees();
		if (step === 'review') return showWizardReview();
		if (step === 'payment') return showWizardPayment();
		if (step === 'kind') return showWizardKind();
	}

	function wizardFrame(content, backHandler = showHome) {
		main().innerHTML = `${screenActions('Back')}<section class="desk-wizard">${wizardProgress(state.wizard.step)}${content}</section>`;
		bindScreenActions(backHandler);
		focusMain();
	}

	function showWizardType() {
		const options = availableOptions(state.wizard.administrator);
		wizardFrame(`<div class="desk-wizard-heading"><p class="desk-eyebrow">${state.wizard.administrator ? 'Manager registration' : 'Walk-in registration'}</p><h1>WHAT TYPE OF REGISTRATION?</h1><p>Tap the option that applies.</p></div><div class="desk-option-grid">${options.map((option) => `<button type="button" class="desk-option-card" data-option="${escapeHtml(option.option_uuid)}"><span class="desk-option-icon">${icon(option.validity_type === 'one_day' ? 'calendar' : option.classification === 'family' ? 'family' : 'person')}</span><strong>${escapeHtml(option.label)}</strong><span>${escapeHtml(registrationType(option))}</span>${option.validity_type === 'one_day' ? `<small>${escapeHtml(formatDateValue(option.valid_local_date))}</small>` : '<small>All event days</small>'}</button>`).join('')}</div>${options.length ? '' : notice('No walk-in registration types are available. Please ask a manager for help.', 'warning')}`, showHome);
		main().querySelectorAll('[data-option]').forEach((button) => button.addEventListener('click', () => {
			const option = optionFor(button.dataset.option);
			state.wizard.data.option_uuid = option.option_uuid;
			state.wizard.data.valid_local_date = option.validity_type === 'one_day' ? option.valid_local_date : '';
			showWalkInStep('contact');
		}));
	}

	function showWizardContact() {
		const data = state.wizard.data;
		wizardFrame(`<div class="desk-wizard-heading"><h1>WHO IS REGISTERING?</h1><p>Enter the primary contact information.</p></div><form id="desk-contact-form" class="desk-form desk-large-form"><div class="desk-fields"><div class="desk-field"><label>First name</label><input name="first_name" autocomplete="given-name" value="${escapeHtml(data.first_name)}" required></div><div class="desk-field"><label>Last name</label><input name="last_name" autocomplete="family-name" value="${escapeHtml(data.last_name)}" required></div><div class="desk-field"><label>Email</label><input name="email" type="email" autocomplete="email" value="${escapeHtml(data.email)}" required></div><div class="desk-field"><label>Phone</label><input name="phone" type="tel" autocomplete="tel" inputmode="tel" value="${escapeHtml(data.phone)}" required></div></div><details class="desk-optional"><summary>ADD MAILING ADDRESS — OPTIONAL</summary><div class="desk-fields"><div class="desk-field desk-field-wide"><label>Street address</label><input name="address_1" autocomplete="address-line1" value="${escapeHtml(data.address_1)}"></div><div class="desk-field desk-field-wide"><label>Address line 2</label><input name="address_2" autocomplete="address-line2" value="${escapeHtml(data.address_2)}"></div><div class="desk-field"><label>City</label><input name="city" autocomplete="address-level2" value="${escapeHtml(data.city)}"></div><div class="desk-field"><label>State</label><input name="state" autocomplete="address-level1" value="${escapeHtml(data.state)}"></div><div class="desk-field"><label>Postal code</label><input name="postcode" autocomplete="postal-code" value="${escapeHtml(data.postcode)}"></div></div></details><button type="submit" class="desk-primary desk-wide">CONTINUE ${icon('arrow')}</button></form>`, () => showWalkInStep('type'));
		main().querySelector('#desk-contact-form').addEventListener('submit', (event) => {
			event.preventDefault();
			Object.assign(state.wizard.data, Object.fromEntries(new FormData(event.currentTarget).entries()));
			const option = optionFor(state.wizard.data.option_uuid);
			showWalkInStep(option.classification === 'family' ? 'attendees' : 'review');
		});
	}

	function showWizardAttendees() {
		const option = optionFor(state.wizard.data.option_uuid);
		const maximum = Number(option.max_attendees || 1);
		wizardFrame(`<div class="desk-wizard-heading"><h1>WHO ELSE IS ATTENDING?</h1><p>Names are optional. Add the people who are here if you know them.</p></div><form id="desk-family-form" class="desk-form"><div id="desk-family-members" class="desk-family-list"></div><button type="button" class="desk-secondary desk-wide" id="desk-add-family">+ ADD ANOTHER PERSON</button><p class="desk-help">The primary contact is already included. This registration allows up to ${maximum} people total.</p><button type="submit" class="desk-primary desk-wide">CONTINUE ${icon('arrow')}</button></form>`, () => showWalkInStep('contact'));
		const container = main().querySelector('#desk-family-members');
		state.wizard.additional_attendees.forEach((attendee) => addWizardFamilyRow(container, maximum, attendee));
		main().querySelector('#desk-add-family').addEventListener('click', () => addWizardFamilyRow(container, maximum));
		main().querySelector('#desk-family-form').addEventListener('submit', (event) => {
			event.preventDefault();
			state.wizard.additional_attendees = [...container.querySelectorAll('.desk-family-row')].map((row) => ({first_name: row.querySelector('[data-first]').value, last_name: row.querySelector('[data-last]').value}));
			showWalkInStep('review');
		});
	}

	function addWizardFamilyRow(container, maximum, attendee = {}) {
		if (container.children.length + 1 >= maximum) return;
		const row = document.createElement('div');
		row.className = 'desk-family-row';
		row.innerHTML = `<span class="desk-family-number">${container.children.length + 2}</span><div class="desk-field"><label>First name — optional</label><input data-first value="${escapeHtml(attendee.first_name || '')}"></div><div class="desk-field"><label>Last name — optional</label><input data-last value="${escapeHtml(attendee.last_name || '')}"></div><button type="button" class="desk-remove-button">Remove</button>`;
		row.querySelector('button').addEventListener('click', () => row.remove());
		container.appendChild(row);
	}

	function showWizardReview() {
		const data = state.wizard.data;
		const option = optionFor(data.option_uuid);
		const familyCount = 1 + state.wizard.additional_attendees.length;
		wizardFrame(`<div class="desk-wizard-heading"><h1>PLEASE CHECK THIS INFORMATION</h1><p>Nothing has been saved yet.</p></div><section class="desk-review-card"><dl><dt>Name</dt><dd>${escapeHtml(data.first_name)} ${escapeHtml(data.last_name)}</dd><dt>Email</dt><dd>${escapeHtml(data.email)}</dd><dt>Phone</dt><dd>${escapeHtml(data.phone)}</dd><dt>Registration</dt><dd>${escapeHtml(option.label)}<small>${escapeHtml(registrationType(option))}</small></dd>${option.validity_type === 'one_day' ? `<dt>Day</dt><dd>${escapeHtml(formatDateValue(data.valid_local_date))}</dd>` : ''}<dt>Checking in</dt><dd>${familyCount} ${familyCount === 1 ? 'person' : 'people'} today</dd></dl></section><div class="desk-actions desk-review-actions"><button type="button" class="desk-secondary" id="desk-review-back">GO BACK AND FIX</button><button type="button" class="desk-primary" id="desk-review-correct">INFORMATION IS CORRECT ${icon('arrow')}</button></div>`, () => showWalkInStep(option.classification === 'family' ? 'attendees' : 'contact'));
		main().querySelector('#desk-review-back').addEventListener('click', () => showWalkInStep('contact'));
		main().querySelector('#desk-review-correct').addEventListener('click', () => showWalkInStep(state.wizard.administrator ? 'kind' : 'payment'));
	}

	function showWizardPayment() {
		wizardFrame(`<div class="desk-wizard-heading"><p class="desk-eyebrow">Separate payment step</p><h1>PAYMENT IS HANDLED IN ALFAPOS</h1><p>After the payment is handled, tell us how it was paid.</p></div><div class="desk-payment-grid"><button type="button" data-payment="paid_card">${icon('card')}<strong>CARD</strong></button><button type="button" data-payment="paid_cash">${icon('cash')}<strong>CASH</strong></button><button type="button" data-payment="paid_check">${icon('check')}<strong>CHECK</strong></button><button type="button" data-payment="unpaid" class="desk-unpaid-choice">${icon('help')}<strong>UNPAID</strong></button></div><div id="desk-payment-message"></div><button type="button" class="desk-primary desk-wide" id="desk-complete-registration" ${state.wizard.payment ? '' : 'disabled'}>COMPLETE REGISTRATION &amp; CHECK IN TODAY ${icon('arrow')}</button>`, () => showWalkInStep('review'));
		main().querySelectorAll('[data-payment]').forEach((button) => button.addEventListener('click', () => selectWizardPayment(button.dataset.payment)));
		main().querySelector('#desk-complete-registration').addEventListener('click', () => {
			if (state.wizard.payment === 'unpaid') return showUnpaidWarning();
			saveManual(state.wizard.payment, false);
		});
	}

	function selectWizardPayment(payment) {
		state.wizard.payment = payment;
		main().querySelectorAll('[data-payment]').forEach((button) => button.classList.toggle('is-selected', button.dataset.payment === payment));
		main().querySelector('#desk-complete-registration').disabled = false;
		main().querySelector('#desk-payment-message').innerHTML = payment === 'unpaid' ? notice('This registration will be checked in without a recorded payment.', 'warning') : notice(`${payment.replace('paid_', '').toUpperCase()} selected. Complete the registration when ready.`, 'success');
	}

	function showUnpaidWarning() {
		const message = main().querySelector('#desk-payment-message');
		message.innerHTML = `<div class="desk-confirm-card"><h2>CONTINUE WITHOUT A RECORDED PAYMENT?</h2><p>This registration will be saved and checked in as unpaid.</p><div class="desk-actions"><button type="button" class="desk-warning-button" id="desk-continue-unpaid">CONTINUE UNPAID</button><button type="button" class="desk-secondary" id="desk-cancel-unpaid">GO BACK</button></div></div>`;
		message.querySelector('#desk-continue-unpaid').addEventListener('click', () => saveManual('unpaid', false));
		message.querySelector('#desk-cancel-unpaid').addEventListener('click', () => { message.innerHTML = ''; });
	}

	function showWizardKind() {
		wizardFrame(`<div class="desk-wizard-heading"><h1>MANAGER REGISTRATION TYPE</h1><p>No sale or order will be created.</p></div><div class="desk-option-grid desk-option-grid-two"><button type="button" data-kind="complimentary">${icon('person')}<strong>COMPLIMENTARY</strong><span>Guest or approved no-charge admission</span></button><button type="button" data-kind="speaker">${icon('manager')}<strong>SPEAKER</strong><span>Event speaker admission</span></button></div><div id="desk-kind-message"></div>`, () => showWalkInStep('review'));
		main().querySelectorAll('[data-kind]').forEach((button) => button.addEventListener('click', () => {
			state.wizard.data.source_type = button.dataset.kind;
			saveManual('complimentary', true);
		}));
	}

	function buildManualPayload() {
		return {...state.wizard.data, additional_attendees: state.wizard.additional_attendees};
	}

	async function saveManual(payment, administrator, acknowledge = false) {
		if (!state.pendingPayload) state.pendingPayload = buildManualPayload();
		if (!state.pendingRequest) state.pendingRequest = uuid();
		state.pendingPayment = payment;
		const payload = {...state.pendingPayload, payment_assertion: payment, duplicate_acknowledged: acknowledge};
		const target = administrator ? '/registrations/complimentary' : '/registrations/walk-in';
		const message = main().querySelector('#desk-payment-message') || main().querySelector('#desk-kind-message');
		main().querySelectorAll('button').forEach((button) => { button.disabled = true; });
		try {
			const result = await api(target, {method: 'POST', body: JSON.stringify(payload)}, state.pendingRequest);
			const count = result.historical_result?.attendance?.length || 1;
			const attendance = result.historical_result?.attendance?.[0] || result.current_attendance?.[0];
			const name = `${state.pendingPayload.first_name} ${state.pendingPayload.last_name}`.trim();
			const option = optionFor(state.pendingPayload.option_uuid);
			resetPending();
			showSuccess({kind: administrator ? 'manager' : 'walk-in', name, count, type: registrationType(option), when: attendance?.checked_in_at_utc || '', payment});
		} catch (error) {
			main().querySelectorAll('button').forEach((button) => { button.disabled = false; });
			if (error.code === 'oras_desk_possible_duplicate') {
				const candidates = Array.isArray(error.data?.candidates) ? error.data.candidates : [];
				message.innerHTML = `<div class="desk-confirm-card desk-duplicate-card"><h2>POSSIBLE MATCH FOUND</h2><p>Another registration uses the same email or phone. Nothing will be merged.</p>${candidates.map((candidate) => `<div class="desk-duplicate-name"><strong>${escapeHtml(candidate.contact_name || 'Existing registration')}</strong><span>${escapeHtml(sourceLabel(candidate.source_type))}</span></div>`).join('')}<p><strong>If payment was handled in AlfaPOS, do not collect it again.</strong></p><div class="desk-actions"><button type="button" id="desk-continue-duplicate">KEEP SEPARATE AND RETRY</button><button type="button" class="desk-secondary" id="desk-duplicate-manager">ASK A MANAGER</button></div></div>`;
				message.querySelector('#desk-continue-duplicate').addEventListener('click', () => saveManual(payment, administrator, true));
				message.querySelector('#desk-duplicate-manager').addEventListener('click', () => { message.innerHTML = notice('Please ask a manager to review the possible match. Keep this screen open.', 'warning'); });
			} else if (!administrator && payment !== 'unpaid') {
				showPaymentRecovery(message, error, payment, acknowledge);
			} else {
				message.innerHTML = `${notice(friendlyError(error), 'error')}<button type="button" id="desk-save-retry">RETRY</button>`;
				message.querySelector('#desk-save-retry').addEventListener('click', () => saveManual(payment, administrator, acknowledge));
			}
		}
	}

	function showPaymentRecovery(container, error, payment, acknowledge) {
		container.innerHTML = `<div class="desk-recovery-card"><span class="desk-large-icon">${icon('help')}</span><h2>PAYMENT WAS ALREADY HANDLED.</h2><p>Do NOT collect payment again.</p><p>${escapeHtml(friendlyError(error))}</p><div class="desk-actions"><button type="button" id="desk-payment-retry">RETRY SAVING</button><button type="button" class="desk-secondary" id="desk-payment-manager">ASK A MANAGER</button></div></div>`;
		container.querySelector('#desk-payment-retry').addEventListener('click', () => saveManual(payment, false, acknowledge));
		container.querySelector('#desk-payment-manager').addEventListener('click', () => { container.innerHTML = notice('Please ask a manager for help. Do not collect payment again. Keep this screen open so Retry remains available.', 'warning'); });
	}

	function showSuccess(details) {
		state.view = 'success';
		const isWalkIn = details.kind === 'walk-in' || details.kind === 'manager';
		const time = formatLocalTime(details.when);
		const payment = {paid_card: 'Paid by card recorded', paid_cash: 'Paid by cash recorded', paid_check: 'Paid by check recorded', unpaid: 'Unpaid recorded', complimentary: 'Manager registration'}[details.payment] || '';
		state.wizard = null;
		main().innerHTML = `<section class="desk-success-screen"><span class="desk-success-check">${icon('check')}</span><p class="desk-eyebrow">All set</p><h1>${isWalkIn ? 'REGISTRATION COMPLETE' : 'CHECK-IN COMPLETE'}</h1><p class="desk-success-name">${escapeHtml(details.name)}</p><p>${isWalkIn ? 'The registration was saved and ' : ''}${Number(details.count || 1)} ${Number(details.count || 1) === 1 ? 'person was' : 'people were'} checked in for today.</p><div class="desk-success-summary"><span><strong>Registration</strong>${escapeHtml(details.type || '')}</span><span><strong>Event</strong>${escapeHtml(state.station.event_title)}</span><span><strong>Checked in</strong>${escapeHtml(state.station.friendly_date || formatDateValue(state.station.local_date))}${time ? ` at ${escapeHtml(time)}` : ''}</span>${payment ? `<span><strong>Statement</strong>${escapeHtml(payment)}</span>` : ''}<span><strong>Volunteer</strong>${escapeHtml(state.station.operator_label)}</span></div><div class="desk-success-actions"><button type="button" class="desk-primary" id="desk-success-home">DONE — RETURN HOME</button><button type="button" class="desk-secondary" id="desk-success-another">${isWalkIn ? 'REGISTER ANOTHER' : 'FIND ANOTHER REGISTRATION'}</button></div></section>`;
		main().querySelector('#desk-success-home').addEventListener('click', () => showHome());
		main().querySelector('#desk-success-another').addEventListener('click', () => isWalkIn ? startWalkInWizard(false) : showSearch());
		focusMain();
	}

	async function showManagerArea(messageText = '') {
		state.view = 'manager';
		if (!state.station.can_manage) return showManagerHelp();
		main().innerHTML = '<div class="desk-loading">Opening manager tools…</div>';
		try {
			const data = await api('/dashboard');
			const summary = data.summary || {};
			main().innerHTML = `${screenActions('Back to Home', false)}<section class="desk-manager-area"><div class="desk-wizard-heading"><p class="desk-eyebrow">Manager only</p><h1>MANAGER TOOLS</h1><p>Advanced actions are kept separate from the volunteer workflow.</p></div>${messageText ? notice(messageText, 'success') : ''}<div id="desk-sync-message"></div><div class="desk-manager-grid"><button type="button" class="desk-manager-card" id="desk-manager-comp">${icon('person')}<strong>COMPLIMENTARY / SPEAKER</strong><span>Create an approved nonfinancial registration.</span></button><button type="button" class="desk-manager-card" id="desk-sync-registrations">${icon('search')}<strong>SYNC WEBSITE REGISTRATIONS</strong><span>Refresh the read-only website registration search.</span></button><a class="desk-manager-card" href="${escapeHtml(config.settingsUrl || '#')}">${icon('manager')}<strong>EVENT SETTINGS</strong><span>Open Registration Desk configuration.</span></a></div><section class="desk-manager-summary"><div><strong>${Number(summary.checked_in_today || 0)}</strong><span>Checked in today</span></div><div><strong>${Number(summary.active_registrations || 0)}</strong><span>Active registrations</span></div><div><strong>${Number(summary.reversed_today || 0)}</strong><span>Reversals today</span></div></section>${data.recent?.length ? `<section class="desk-recent"><h2>Recent check-ins</h2><div class="desk-recent-list">${renderRecent(data.recent.slice(0, 8))}</div></section>` : ''}</section>`;
			bindScreenActions(showHome);
			main().querySelector('#desk-manager-comp').addEventListener('click', () => startWalkInWizard(true));
			main().querySelector('#desk-sync-registrations').addEventListener('click', syncWebsiteRegistrations);
			focusMain();
		} catch (error) {
			main().innerHTML = `${screenActions('Back to Home', false)}${notice(friendlyError(error), 'error')}`;
			bindScreenActions(showHome);
		}
	}

	async function syncWebsiteRegistrations(event) {
		const button = event.currentTarget;
		const message = main().querySelector('#desk-sync-message');
		let continuation = '';
		let scanned = 0;
		let matching = 0;
		let pages = 0;
		button.disabled = true;
		try {
			do {
				message.innerHTML = notice(`Syncing website registrations… page ${pages + 1}.`);
				const data = await api('/project', {method: 'POST', body: JSON.stringify({continuation, limit: 50})});
				pages += 1;
				scanned += Number(data.scanned_orders || 0);
				matching += Number(data.matching_items || 0);
				continuation = String(data.continuation || '');
				if (data.has_more && !continuation) throw new DeskError('', 'recovery_continuation_missing');
			} while (continuation);
			await showManagerArea(`Website registration sync complete: ${scanned} orders checked and ${matching} matching items refreshed.`);
		} catch (error) {
			message.innerHTML = `${notice(friendlyError(error), 'error')}<button type="button" id="desk-sync-retry">RETRY</button>`;
			message.querySelector('#desk-sync-retry').addEventListener('click', () => syncWebsiteRegistrations({currentTarget: button}));
			button.disabled = false;
		}
	}

	function renderCorrectionForm(editor) {
		const options = availableOptions(true);
		return `<form id="desk-correction-form" class="desk-form desk-manager-form"><p>Correct this desk-created registration. Attendance changes use the separate Reverse check-in action.</p><input type="hidden" name="expected_record_version" value="${Number(editor.expected_record_version)}"><div class="desk-fields"><div class="desk-field"><label>First name</label><input name="first_name" value="${escapeHtml(editor.first_name)}" required></div><div class="desk-field"><label>Last name</label><input name="last_name" value="${escapeHtml(editor.last_name)}" required></div><div class="desk-field"><label>Email</label><input name="email" type="email" value="${escapeHtml(editor.email)}" required></div><div class="desk-field"><label>Phone</label><input name="phone" type="tel" value="${escapeHtml(editor.phone)}" required></div><div class="desk-field desk-field-wide"><label>Option</label><select name="option_uuid" required>${options.map((option) => `<option value="${escapeHtml(option.option_uuid)}" ${option.option_uuid === editor.option_uuid ? 'selected' : ''}>${escapeHtml(option.label)}</option>`).join('')}</select></div><div class="desk-field"><label>One-day date</label><input name="valid_local_date" type="date" value="${escapeHtml(editor.valid_local_date)}"></div><div class="desk-field"><label>Recorded statement</label><select name="payment_assertion"><option value="paid_card" ${editor.payment_assertion === 'paid_card' ? 'selected' : ''}>Paid by card</option><option value="paid_cash" ${editor.payment_assertion === 'paid_cash' ? 'selected' : ''}>Paid by cash</option><option value="paid_check" ${editor.payment_assertion === 'paid_check' ? 'selected' : ''}>Paid by check</option><option value="unpaid" ${editor.payment_assertion === 'unpaid' ? 'selected' : ''}>Unpaid</option><option value="complimentary" ${editor.payment_assertion === 'complimentary' ? 'selected' : ''}>Complimentary / speaker</option></select></div></div><input type="hidden" name="address_1" value="${escapeHtml(editor.address_1)}"><input type="hidden" name="address_2" value="${escapeHtml(editor.address_2)}"><input type="hidden" name="city" value="${escapeHtml(editor.city)}"><input type="hidden" name="state" value="${escapeHtml(editor.state)}"><input type="hidden" name="postcode" value="${escapeHtml(editor.postcode)}"><button type="submit">SAVE CORRECTION</button></form>`;
	}

	async function reverseAttendance(registration, button) {
		const reason = window.prompt('Why are you reversing this check-in?');
		if (!reason?.trim() || !window.confirm('Reverse this person’s check-in for today?')) return;
		button.disabled = true;
		try {
			await api(`/registrations/${registration.registration_uuid}/attendees/${button.dataset.reverseAttendee}/reverse`, {method: 'POST', body: JSON.stringify({attendance_local_date: state.station.local_date, expected_record_version: Number(button.dataset.version), reason: reason.trim()})}, uuid());
			await showRegistration(registration.registration_uuid);
			main().querySelector('#desk-detail-message').innerHTML = notice('The check-in was reversed and recorded.', 'success');
		} catch (error) {
			button.disabled = false;
			main().querySelector('#desk-detail-message').innerHTML = notice(friendlyError(error), 'error');
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
			main().querySelector('#desk-detail-message').innerHTML = notice('The registration details were corrected and recorded.', 'success');
		} catch (error) {
			form.querySelector('button').disabled = false;
			main().querySelector('#desk-detail-message').innerHTML = notice(friendlyError(error), 'error');
		}
	}

	loadStation();
	if (state.station?.station_token) {
		renderShell();
		showHome();
	} else {
		renderSetup();
	}
})();
