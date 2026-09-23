(() => {
	'use strict';

	const config = window.ORASRegistrationDesk || {};
	const root = document.getElementById('oras-registration-desk-root');
	const storageKey = 'orasRegistrationDeskStationV2';
	const draftLifetime = 12 * 60 * 60 * 1000;
	const state = {
		station: null,
		operatorLabel: '',
		events: [],
		view: 'home',
		searchQuery: '',
		wizard: null,
		membershipWizard: null,
		pendingRequest: '',
		pendingPayload: null,
		pendingPayment: '',
		failureCount: 0,
		finalizing: false,
		roster: {q: '', status: 'everyone', option_uuid: '', offset: 0, items: [], mode: 'tickets', registration_types: []},
		detailReturn: 'search',
		managerDestination: 'manager',
	};

	const isTraining = () => state.station?.mode === 'training' || state.station?.training === true;

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
		if (state.station?.manager_token) {
			headers['X-ORAS-Desk-Manager'] = state.station.manager_token;
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
			if (String(payload.code || '').startsWith('oras_desk_training_')) {
				// Training errors never fall through to live recovery or clear the signed station.
			} else if (payload.code === 'oras_desk_station_event_ended') {
				window.setTimeout(showEventEnded, 0);
			} else if (String(payload.code || '').startsWith('oras_desk_station_')) {
				clearStation();
			}
			throw new DeskError(payload.message || 'The request could not be completed.', payload.code, payload.data);
		}
		return payload;
	}

	function loadStation() {
		try {
			state.station = JSON.parse(window.localStorage.getItem(storageKey) || 'null');
			const legacy = state.station?.failed_request ? {...state.station.failed_request, kind: 'walk-in', draft_expires_at: Date.now() + draftLifetime, event_id: state.station.event_id} : null;
			const draft = state.station?.draft || legacy;
			if (draft && Number(draft.draft_expires_at || 0) > Date.now() && Number(draft.event_id || 0) === Number(state.station.event_id || 0)) {
				state.pendingRequest = draft.request || '';
				state.pendingPayload = draft.payload || null;
				state.pendingPayment = draft.payment || '';
				state.failureCount = Number(draft.failures || 0);
				if (draft.kind === 'membership') state.membershipWizard = draft.membership_wizard || null;
				else state.wizard = draft.wizard || null;
			} else if (state.station?.draft || state.station?.failed_request) {
				delete state.station.draft;
				delete state.station.failed_request;
				window.localStorage.setItem(storageKey, JSON.stringify(state.station));
			}
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
		state.failureCount = 0;
		state.finalizing = false;
		state.membershipWizard = null;
		if (state.station?.failed_request || state.station?.draft) {
			delete state.station.failed_request;
			delete state.station.draft;
			saveStation(state.station);
		}
	}

	function persistDraft(kind = 'walk-in') {
		if (!state.station) return;
		state.station.draft = {
			kind,
			event_id: Number(state.station.event_id),
			request: state.pendingRequest,
			payload: state.pendingPayload,
			payment: state.pendingPayment,
			failures: state.failureCount,
			wizard: kind === 'walk-in' ? state.wizard : null,
			membership_wizard: kind === 'membership' ? state.membershipWizard : null,
			draft_expires_at: Date.now() + draftLifetime,
		};
		delete state.station.failed_request;
		saveStation(state.station);
	}

	function persistPending() {
		persistDraft('walk-in');
	}

	function hasUnsavedDraft() {
		return Boolean(state.wizard || state.membershipWizard || state.pendingPayload || state.station?.draft);
	}

	function clearStation() {
		state.station = null;
		state.wizard = null;
		state.membershipWizard = null;
		resetPending();
		window.localStorage.removeItem(storageKey);
	}

	async function restoreTrainingContext() {
		if (!isTraining()) return state.station;
		const stationToken = state.station.station_token;
		const managerToken = state.station.manager_token || '';
		const context = await api('/training/context');
		state.station = {...state.station, ...context, station_token: stationToken};
		if (managerToken) state.station.manager_token = managerToken;
		saveStation(state.station);
		return state.station;
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
		return '<span class="desk-logo-mark" aria-hidden="true"><img src="' + escapeHtml(config.markUrl || '') + '" alt=""></span>';
	}

	function notice(message, kind = '') {
		return `<div class="desk-notice ${kind ? `desk-notice-${kind}` : ''}" role="status">${escapeHtml(message)}</div>`;
	}

	function friendlyError(error) {
		const code = String(error?.code || '');
		if (code === 'oras_desk_training_config_changed') return 'Event configuration changed. End and restart Training Mode before continuing.';
		if (code === 'oras_desk_training_stale') return 'Training data changed on this station. Reload the training screen and try again.';
		if (code.startsWith('oras_desk_training_')) return 'We couldn’t save that action. Please try again or ask a manager for help.';
		if (code.startsWith('oras_desk_station_') || ['oras_desk_config_changed', 'oras_desk_active_event_changed'].includes(code)) {
			return 'The event settings changed while you were working. Return to the home screen and set up this station again.';
		}
		if (['oras_desk_registration_inactive', 'oras_desk_not_eligible', 'oras_desk_source_changed', 'oras_desk_source_option_changed', 'oras_desk_source_unit_invalid', 'oras_desk_attendance_reversed'].includes(code)) {
			return 'This registration is no longer valid for check-in. Please ask a manager for help.';
		}
		if (['oras_desk_wrong_date', 'oras_desk_date_changed'].includes(code)) {
			return 'This registration cannot be checked in for today. Please ask a manager for help.';
		}
		if (code === 'oras_desk_station_event_ended') return 'This event has ended. Choose the event you are working today.';
		if (code === 'oras_desk_search_short') {
			return 'Type at least two letters or numbers to search.';
		}
		if (code === 'oras_desk_pin_incorrect') return 'That PIN was not correct. Try again.';
		if (code === 'oras_desk_pin_rate_limited') return 'Too many incorrect tries. Please wait a few minutes.';
		if (code === 'oras_desk_membership_unavailable') return 'Membership activation is unavailable. Ask an administrator to check PMPro.';
		if (code === 'oras_desk_membership_email_failed') return 'Membership was recorded, but the email could not be sent.';
		if (code === 'oras_desk_offering_changed') return 'That registration option changed. Return to ticket selection and review the current name, price, and availability.';
		if (code === 'oras_desk_rsvp_closed') return 'RSVP registration is not open for this event.';
		if (code === 'oras_desk_rsvp_full') return 'This event is full and no RSVP waitlist is available.';
		if (code === 'oras_desk_rsvp_waitlisted') return 'This person is on the RSVP waitlist and has not been admitted.';
		if (code === 'oras_desk_recovery_not_valid') return 'This website registration does not grant access to the selected event.';
		if (code === 'oras_desk_verification_reason_required') return 'Add a short note describing the proof you reviewed.';
		if (code === 'oras_desk_proof_acknowledgement_required') return 'Confirm that you verified proof outside this system.';
		if (code === 'oras_desk_contact_required') {
			return 'Enter the required name and check any email or phone number you provided.';
		}
		if (code === 'oras_desk_attendee_name_invalid') {
			return 'Enter both first and last name, or leave both blank for an unnamed family member.';
		}
		if (code === 'network_error') {
			return 'We could not reach the server. Check the connection and try again.';
		}
		return 'We could not finish that step. Please try again or ask a manager for help.';
	}

	function showConnectionLost(container, retry, paymentHandled = false) {
		resetViewport();
		const paymentWarning = paymentHandled ? '<p><strong>PAYMENT WAS ALREADY HANDLED.<br>DO NOT CHARGE THIS PERSON AGAIN.</strong></p>' : '';
		container.innerHTML = `<section class="desk-centered desk-connection-lost"><span class="desk-large-icon">${icon('help')}</span><h1>CONNECTION LOST</h1><p>We can’t reach the Registration Desk right now.</p><p>Your information is still here.</p><p>Please wait for the connection to return, then try again.</p>${paymentWarning}<div class="desk-actions"><button type="button" class="desk-primary" id="desk-connection-retry">TRY AGAIN</button><button type="button" class="desk-secondary" id="desk-connection-manager">MANAGER HELP</button></div></section>`;
		container.querySelector('#desk-connection-retry').addEventListener('click', retry);
		container.querySelector('#desk-connection-manager').addEventListener('click', () => state.station.manager_token ? showManagerArea() : showManagerHelp());
		focusMain();
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
			.filter((option) => option.visible !== false)
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
		return {online: 'Direct Website Registration', online_included: 'Included with another event', walk_in: 'Walk-In', complimentary: 'Complimentary', speaker: 'Complimentary', rsvp_walk_in: 'RSVP', rsvp_waitlist: 'RSVP', rsvp_website: 'RSVP', manager_verified_manual: 'Manager Verified'}[source] || 'Registration';
	}

	function paymentLabel(registration, admission = {}) {
		if (registration.source_type === 'online') return 'Website registration recorded';
		if (registration.source_type === 'online_included') return 'Included with another event';
		if (registration.source_type === 'manager_verified_manual') return 'Manager Verified outside this system';
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
		return `<div class="desk-screen-actions"><button type="button" class="desk-link-button" id="desk-screen-back">${icon('back')} ${escapeHtml(backLabel)}</button>${includeStartOver ? '<button type="button" class="desk-link-button desk-start-over" id="desk-start-over">HOME / START OVER</button>' : ''}</div>`;
	}

	function bindScreenActions(backHandler = showHome) {
		main().querySelector('#desk-screen-back')?.addEventListener('click', () => backHandler());
		main().querySelector('#desk-start-over')?.addEventListener('click', requestHome);
	}

	function renderSetup(message = '') {
		root.innerHTML = `<div class="desk-setup-backdrop"><main class="desk-setup-card">
			<div class="desk-setup-brand">${brandMark()}<div><strong>ORAS</strong></div></div>
			<p class="desk-eyebrow">Step 1</p><h1>WHO IS WORKING THIS STATION?</h1><p class="desk-lede">Enter your name.</p>
			${message ? notice(message, 'error') : ''}
			<form id="desk-station-form" class="desk-form"><div class="desk-field"><label for="desk-operator">Your name</label><input id="desk-operator" name="operator_label" autocomplete="name" maxlength="100" value="${escapeHtml(state.operatorLabel)}" required autofocus></div><button type="submit" class="desk-primary desk-wide">NEXT ${icon('arrow')}</button></form>
		</main></div>`;
		root.querySelector('#desk-station-form').addEventListener('submit', startStation);
	}

	async function startStation(event) {
		event.preventDefault();
		state.operatorLabel = String(new FormData(event.currentTarget).get('operator_label') || '').trim();
		const button = event.currentTarget.querySelector('button');
		button.disabled = true;
		try {
			const data = await api('/events');
			state.events = Array.isArray(data.items) ? data.items : [];
			renderEventPicker();
		} catch (error) {
			renderSetup(friendlyError(error));
		}
	}

	function renderEventPicker(message = '') {
		const cards = state.events.map((event) => `<button type="button" class="desk-event-card" data-event-id="${Number(event.event_id)}"><strong>${escapeHtml(event.title)}</strong><span>${escapeHtml(event.friendly_date)}</span><small>${event.has_tickets ? 'Tickets' : ''}${event.has_tickets && event.has_rsvp ? ' · ' : ''}${event.has_rsvp ? 'RSVP' : ''}</small></button>`).join('');
		root.innerHTML = `<div class="desk-setup-backdrop"><main class="desk-setup-card desk-event-picker"><button type="button" class="desk-link-button" id="desk-event-back">${icon('back')} Back</button><p class="desk-eyebrow">Step 2</p><h1>WHICH EVENT ARE YOU WORKING?</h1><p class="desk-lede">Tap the event for this station.</p>${message ? notice(message, 'error') : ''}<div class="desk-event-list">${cards || notice('No ticket or RSVP events are available for this year.', 'warning')}</div></main></div>`;
		root.querySelector('#desk-event-back').addEventListener('click', () => renderSetup());
		root.querySelectorAll('[data-event-id]').forEach((button) => button.addEventListener('click', () => selectEvent(Number(button.dataset.eventId), button)));
	}

	async function selectEvent(eventId, button) {
		button.disabled = true;
		try {
			const data = await api('/station', {method: 'POST', body: JSON.stringify({operator_label: state.operatorLabel, event_id: eventId})});
			saveStation(data);
			renderShell();
			showHome();
		} catch (error) {
			renderEventPicker(friendlyError(error));
		}
	}

	function renderShell() {
		const station = state.station;
		const trainingMarker = isTraining() ? `<div class="desk-training-marker" role="status"><strong>TRAINING</strong><span>${escapeHtml(station.friendly_training_date || formatDateValue(station.simulated_local_date))}</span></div>` : '';
		root.innerHTML = `<header class="desk-topbar">
			<button type="button" class="desk-brand-button" id="desk-brand-home" aria-label="Return to Registration Desk home">${brandMark()}</button>
			<div class="desk-event-block"><strong>${escapeHtml(station.event_title)}</strong><small>${escapeHtml(station.event_date || station.friendly_date || formatDateValue(station.local_date))}</small>${trainingMarker}</div>
			<div class="desk-user-block"><span><strong>${escapeHtml(station.operator_label)}</strong></span><button id="desk-change-volunteer" class="desk-header-button" type="button">Change</button></div>
			<button id="desk-change-event" class="desk-header-button" type="button">CHANGE EVENT</button>
			<a class="desk-header-button desk-logout" id="desk-logout" href="${escapeHtml(station.logout_url)}">Log out</a>
			</header><aside id="desk-manager-status" class="desk-manager-status" hidden><strong>MANAGER MODE</strong><button type="button" id="desk-exit-manager-mode">EXIT MANAGER MODE</button></aside><main id="desk-main" class="desk-main" tabindex="-1"></main>`;
		refreshManagerStatus();
		root.querySelector('#desk-brand-home').addEventListener('click', () => requestHome());
		root.querySelector('#desk-change-volunteer').addEventListener('click', () => {
			if (window.confirm('Change the volunteer name for this station?')) {
				clearStation();
				renderSetup();
			}
		});
		root.querySelector('#desk-change-event')?.addEventListener('click', async () => {
			if (isTraining()) {
				if (state.station.manager_token) showTrainingManagerArea();
				else showManagerHelp('training');
				return;
			}
			const warning = hasUnsavedDraft() ? 'Changing events will discard the unfinished information for this event. Change event?' : 'Change the event for this station?';
			if (!window.confirm(warning)) return;
			state.operatorLabel = state.station.operator_label;
			clearStation();
			try {
				const data = await api('/events');
				state.events = Array.isArray(data.items) ? data.items : [];
				renderEventPicker();
			} catch (error) {
				renderSetup(friendlyError(error));
			}
		});
		root.querySelector('#desk-logout').addEventListener('click', (event) => {
			if (!window.confirm('Log out of the shared Registration Desk account?')) event.preventDefault();
			else clearStation();
		});
	}

	function showEventEnded() {
		if (!state.station || !main()) return;
		resetViewport();
		state.finalizing = false;
		state.view = 'event-ended';
		const paymentWarning = state.wizard?.payment_handled || state.membershipWizard?.payment_handled
			? '<p><strong>PAYMENT WAS ALREADY HANDLED.<br>DO NOT CHARGE THIS PERSON AGAIN.</strong></p>'
			: '';
		main().innerHTML = `<section class="desk-centered desk-event-ended"><span class="desk-large-icon">${icon('calendar')}</span><h1>THIS EVENT HAS ENDED</h1><p>Please choose the event you are working today.</p>${hasUnsavedDraft() ? '<p>Your unfinished information is still saved for this event.</p>' : ''}${paymentWarning}<button type="button" class="desk-primary" id="desk-ended-choose-event">CHOOSE EVENT</button></section>`;
		main().querySelector('#desk-ended-choose-event').addEventListener('click', chooseEventAfterEnd);
		focusMain();
	}

	async function chooseEventAfterEnd() {
		if (hasUnsavedDraft()) {
			const paymentWarning = state.wizard?.payment_handled || state.membershipWizard?.payment_handled ? ' Payment may already have been handled; do not charge the person again.' : '';
			if (!window.confirm(`Choosing another event will discard the unfinished information for this ended event.${paymentWarning} Choose another event?`)) return;
		}
		state.operatorLabel = state.station?.operator_label || state.operatorLabel;
		clearStation();
		root.innerHTML = '<div class="desk-setup-backdrop"><main class="desk-setup-card"><h1>LOADING CURRENT EVENTS…</h1></main></div>';
		try {
			const data = await api('/events');
			state.events = Array.isArray(data.items) ? data.items : [];
			renderEventPicker();
		} catch (error) {
			renderSetup(friendlyError(error));
		}
	}

	function requestHome() {
		if (!hasUnsavedDraft()) return showHome();
		resetViewport();
		main().innerHTML = `<section class="desk-centered desk-start-over-prompt"><span class="desk-large-icon">${icon('help')}</span><h1>START OVER?</h1><p>You have information entered that has not been saved.</p>${state.wizard?.payment_handled || state.membershipWizard?.payment_handled ? '<p><strong>PAYMENT WAS ALREADY HANDLED.<br>DO NOT CHARGE THIS PERSON AGAIN.</strong></p>' : ''}<div class="desk-actions"><button type="button" class="desk-primary" id="desk-keep-working">KEEP WORKING</button><button type="button" class="desk-danger" id="desk-discard-draft">DISCARD &amp; RETURN HOME</button></div></section>`;
		main().querySelector('#desk-keep-working').addEventListener('click', restoreDraft);
		main().querySelector('#desk-discard-draft').addEventListener('click', () => { state.wizard = null; resetPending(); showHome(); });
	}

	function refreshManagerStatus() {
		const banner = document.getElementById('desk-manager-status');
		if (!banner) return;
		banner.hidden = !state.station?.manager_token;
		const exit = banner.querySelector('#desk-exit-manager-mode');
		if (exit) exit.onclick = exitManagerMode;
	}

	function exitManagerMode() {
		if (!state.station) return;
		delete state.station.manager_token;
		saveStation(state.station);
		refreshManagerStatus();
		showHome('Manager Mode closed.');
	}

	async function restoreDraft() {
		const draft = state.station?.draft;
		if (!draft) return showHome();
		if (draft.kind === 'membership' && state.membershipWizard) {
			await startMembershipWizard(false);
		} else if (state.wizard) {
			state.view = state.wizard.administrator ? 'complimentary' : 'walk-in';
			main().innerHTML = '<section class="desk-centered"><h1>RESTORING YOUR INFORMATION…</h1></section>';
			try {
				const response = await api('/offerings');
				state.station.options = Array.isArray(response.items) ? response.items : [];
				saveStation(state.station);
				if (state.failureCount > 0 && state.pendingPayload && state.pendingRequest) return showRestoredFailure();
				const step = state.wizard.payment_handled && ['type', 'contact', 'attendees', 'review', 'handoff'].includes(state.wizard.step) ? 'payment' : state.wizard.step;
				showWalkInStep(step || 'type');
			} catch (error) {
				if (error.code === 'network_error') return showConnectionLost(main(), restoreDraft, Boolean(state.wizard.payment_handled));
				main().innerHTML = `${screenActions('Back to Home', false)}${notice(friendlyError(error), 'error')}`;
				bindScreenActions(requestHome);
			}
		} else {
			resetPending();
			return showHome();
		}
		const section = main().querySelector('section');
		section?.insertAdjacentHTML('afterbegin', notice('Your unfinished information was restored.', 'success'));
	}

	function main() {
		return document.getElementById('desk-main');
	}

	function focusMain() {
		main()?.focus({preventScroll: true});
	}

	function resetViewport() {
		window.scrollTo(0, 0);
	}

	async function showHome(message = '') {
		resetViewport();
		state.view = 'home';
		if (!state.station?.draft) {
			state.wizard = null;
			state.membershipWizard = null;
			if (!state.pendingPayload) resetPending();
		}
		main().innerHTML = `<section class="desk-home">
				${message ? notice(message, 'success') : ''}
				${state.station?.draft ? `<div class="desk-recovery-card"><h2>UNFINISHED INFORMATION IS SAVED</h2><p>Continue the same ${state.station.draft.kind === 'membership' ? 'membership' : 'registration'} before starting another one.</p><button type="button" class="desk-primary desk-wide" id="desk-resume-draft">KEEP WORKING</button></div>` : ''}
				<div class="desk-home-heading"><p class="desk-eyebrow">Welcome, ${escapeHtml(state.station.operator_label)}</p><h1>WHAT DO YOU NEED TO DO?</h1></div>
				<div class="desk-task-grid">
					<button type="button" class="desk-task-card desk-task-find" id="desk-home-find"><span class="desk-task-icon">${icon('search')}</span><strong>FIND A REGISTRATION</strong><small>Someone is standing here and you need to find them.</small><span class="desk-task-next">Start ${icon('arrow')}</span></button>
					<button type="button" class="desk-task-card desk-task-walkin" id="desk-home-walkin"><span class="desk-task-icon">${icon('family')}</span><strong>REGISTER A WALK-IN</strong><small>Use this for someone registering here today.</small><span class="desk-task-next">Start ${icon('arrow')}</span></button>
				</div>
				<div class="desk-home-secondary"><button type="button" class="desk-help-button desk-membership-home" id="desk-home-members">${icon('person')}<span><strong>ORAS MEMBERSHIP</strong><small>Look up a member or record a membership paid here.</small></span></button><button type="button" class="desk-help-button" id="desk-home-stats">${icon('calendar')} EVENT STATS</button><button type="button" class="desk-help-button" id="desk-home-help">${icon('manager')} MANAGER HELP</button></div>
			</section>`;
		main().querySelector('#desk-home-find').addEventListener('click', () => showEventRoster(true));
		main().querySelector('#desk-home-walkin').addEventListener('click', () => state.station?.draft ? restoreDraft() : startWalkInWizard(false));
		main().querySelector('#desk-home-members').addEventListener('click', () => state.station?.draft ? restoreDraft() : showMembershipMenu());
		main().querySelector('#desk-home-stats').addEventListener('click', () => showEventStats());
		main().querySelector('#desk-home-help').addEventListener('click', () => state.station.manager_token ? showManagerArea() : showManagerHelp());
		main().querySelector('#desk-resume-draft')?.addEventListener('click', restoreDraft);
		focusMain();
	}

	function renderRecent(items) {
		return items.map((item) => `<div class="desk-recent-item"><span class="desk-mini-check">${icon('check')}</span><div><strong>${escapeHtml(item.display_name || 'Unnamed attendee')}</strong><small>Helped by ${escapeHtml(item.checked_in_operator_label)}</small></div><time datetime="${escapeHtml(item.checked_in_at_utc)}">${escapeHtml(formatLocalTime(item.checked_in_at_utc))}</time></div>`).join('');
	}

	function showManagerHelp(destination = 'manager') {
		resetViewport();
		state.view = 'help';
		state.managerDestination = destination;
		main().innerHTML = `${screenActions('Back to Home', false)}<section class="desk-centered desk-help-screen"><span class="desk-large-icon desk-gold-icon">${icon('manager')}</span><p class="desk-eyebrow">Manager only</p><h1>ENTER MANAGER PIN</h1><form id="desk-manager-pin-form" class="desk-form"><div class="desk-field"><label for="desk-manager-pin">4-digit PIN</label><input id="desk-manager-pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off" required autofocus></div><div id="desk-pin-message"></div><button type="submit" class="desk-primary desk-wide">UNLOCK MANAGER MODE</button></form></section>`;
		bindScreenActions(requestHome);
		main().querySelector('#desk-manager-pin-form').addEventListener('submit', unlockManager);
		focusMain();
	}

	async function unlockManager(event) {
		event.preventDefault();
		const form = event.currentTarget;
		form.querySelector('button').disabled = true;
		try {
			const data = await api('/manager/unlock', {method: 'POST', body: JSON.stringify({pin: new FormData(form).get('pin')})});
			state.station.manager_token = data.manager_token;
			saveStation(state.station);
			refreshManagerStatus();
			if (state.managerDestination === 'recovery') showMissingRegistration();
			else showManagerArea();
		} catch (error) {
			form.querySelector('button').disabled = false;
			form.querySelector('#desk-pin-message').innerHTML = notice(friendlyError(error), 'error');
			form.querySelector('input').select();
		}
	}

	function showMembershipMenu() {
		resetViewport();
		state.view = 'membership-menu';
		main().innerHTML = `${screenActions('Back to Home', false)}<section class="desk-kiosk-panel desk-membership-menu"><p class="desk-eyebrow">Organization membership — not event registration</p><h1>ORAS MEMBERSHIP</h1><p class="desk-lede">What do you need to do?</p><div class="desk-option-grid desk-option-grid-two"><button type="button" class="desk-option-card" id="desk-membership-lookup">${icon('search')}<strong>LOOK UP MEMBER</strong><span>Find a current, expired, or pending member.</span></button><button type="button" class="desk-option-card" id="desk-membership-record">${icon('person')}<strong>RECORD MEMBERSHIP PAYMENT</strong><span>Card, cash, or check already handled in AlfaPOS.</span></button></div></section>`;
		bindScreenActions(showHome);
		main().querySelector('#desk-membership-lookup').addEventListener('click', showMemberLookup);
		main().querySelector('#desk-membership-record').addEventListener('click', () => startMembershipWizard(true));
		focusMain();
	}

	function showMemberLookup() {
		state.view = 'members';
		main().innerHTML = `${screenActions('Back to Membership', false)}<section class="desk-kiosk-panel"><p class="desk-eyebrow">Organization membership — not the event roster</p><h1>LOOK UP MEMBER</h1><p class="desk-lede">Type a member name or email address.</p><form id="desk-member-form" class="desk-search-form"><label class="desk-sr-only" for="desk-member-query">Name or email</label><div class="desk-search-box">${icon('search')}<input id="desk-member-query" name="q" minlength="2" placeholder="Member name or email" required autofocus></div><button type="submit" class="desk-primary">SEARCH</button></form><div id="desk-member-results" class="desk-results"></div></section>`;
		bindScreenActions(showMembershipMenu);
		main().querySelector('#desk-member-form').addEventListener('submit', async (event) => {
			event.preventDefault();
			const target = main().querySelector('#desk-member-results');
			target.innerHTML = '<div class="desk-loading desk-loading-small">Searching…</div>';
			try {
				const path = isTraining() ? `/training/members?q=${encodeURIComponent(new FormData(event.currentTarget).get('q'))}` : `/members?q=${encodeURIComponent(new FormData(event.currentTarget).get('q'))}`;
				const data = await api(path);
				target.innerHTML = data.items.length ? data.items.map((item) => {
					const status = String(item.status || '').toUpperCase();
					const statusLabel = status === 'CURRENT' ? '✓ CURRENT' : status === 'PENDING' || status === 'PENDING ONLINE ACTIVATION' ? '⚠ PENDING ONLINE ACTIVATION' : status === 'EXPIRED' ? '✕ EXPIRED' : status;
					return `<article class="desk-result-card desk-member-card"><div class="desk-result-main"><strong>${escapeHtml(item.name)}</strong><span>${escapeHtml(item.level_name || 'Membership')}</span><small><strong>${escapeHtml(statusLabel)}</strong></small>${item.expiration ? `<small>Expires: ${escapeHtml(formatDateValue(item.expiration))}</small>` : ''}</div></article>`;
				}).join('') : '<div class="desk-no-results"><h2>NOT FOUND</h2><p>No matching membership was found.</p></div>';
			} catch (error) {
				target.innerHTML = notice(friendlyError(error), 'error');
			}
		});
		focusMain();
	}

	async function showEventRoster(reset = false) {
		resetViewport();
		state.view = 'roster';
		if (reset) state.roster = {q: '', status: 'everyone', option_uuid: '', offset: 0, items: [], mode: 'tickets', registration_types: []};
		main().innerHTML = '<div class="desk-loading">Loading event roster…</div>';
		await loadEventRoster(true);
	}

	function rosterQuery() {
		const query = new URLSearchParams({q: state.roster.q, status: state.roster.status, option_uuid: state.roster.option_uuid, offset: String(state.roster.offset), limit: '25'});
		return `${isTraining() ? '/training/roster' : '/roster'}?${query.toString()}`;
	}

	async function loadEventRoster(replace) {
		try {
			const data = await api(rosterQuery());
			state.roster.mode = data.mode || 'tickets';
			if (isTraining()) state.station.record_version = Number(data.record_version || state.station.record_version);
			state.roster.registration_types = Array.isArray(data.registration_types) ? data.registration_types : [];
			state.roster.items = replace ? (data.items || []) : [...state.roster.items, ...(data.items || [])];
			state.roster.offset = Number(data.next_offset || state.roster.items.length);
			renderEventRoster(Boolean(data.has_more));
		} catch (error) {
			if (error.code === 'network_error') return showConnectionLost(main(), () => showEventRoster(false));
			main().innerHTML = `${screenActions('Back to Home', false)}<section class="desk-centered"><h1>FIND REGISTRATION</h1>${notice(friendlyError(error), 'error')}<button type="button" id="desk-roster-retry">TRY AGAIN</button></section>`;
			bindScreenActions(showHome);
			main().querySelector('#desk-roster-retry').addEventListener('click', () => showEventRoster(false));
		}
	}

	function rosterStatusChoices() {
		return state.roster.mode === 'rsvp' ? [['everyone', 'ALL RSVPs'], ['admitted', 'CONFIRMED'], ['waitlist', 'WAITLISTED'], ['checked_in', 'HERE TODAY']] : [['everyone', 'EVERYONE'], ['not_checked_in', 'NOT CHECKED IN'], ['checked_in', 'HERE TODAY'], ['walk_ins', 'WALK-INS']];
	}

	function selectedRosterTypeLabel() {
		return state.roster.registration_types.find((type) => type.option_uuid === state.roster.option_uuid)?.label || 'ALL TYPES';
	}

	function renderEventRoster(hasMore) {
		const types = state.roster.registration_types;
		const statusLabel = rosterStatusChoices().find(([value]) => value === state.roster.status)?.[1] || 'EVERYONE';
		const filtersActive = state.roster.status !== 'everyone' || Boolean(state.roster.option_uuid);
		const statusChoices = rosterStatusChoices().map(([value, label]) => `<label class="desk-filter-choice"><input type="radio" name="status" value="${escapeHtml(value)}" ${state.roster.status === value ? 'checked' : ''}><span>${escapeHtml(label)}</span></label>`).join('');
		const typeChoices = `<label class="desk-filter-choice"><input type="radio" name="option_uuid" value="" ${state.roster.option_uuid ? '' : 'checked'}><span>ALL TYPES</span></label>${types.map((type) => `<label class="desk-filter-choice"><input type="radio" name="option_uuid" value="${escapeHtml(type.option_uuid)}" ${state.roster.option_uuid === type.option_uuid ? 'checked' : ''}><span>${escapeHtml(type.label)}</span></label>`).join('')}`;
		const emptyState = state.roster.q ? `<div class="desk-no-results"><h2>WE COULDN’T FIND THEIR REGISTRATION.</h2><p>Check the spelling or try a different email or phone number.</p><div class="desk-actions"><button type="button" class="desk-secondary desk-touch-centered" id="desk-search-again">SEARCH AGAIN</button><button type="button" class="desk-touch-centered" id="desk-search-recovery">THEY SAY THEY ALREADY REGISTERED</button><button type="button" class="desk-touch-centered" id="desk-search-walkin">REGISTER AS WALK-IN</button></div></div>` : '<div class="desk-no-results"><h2>NO PEOPLE MATCH THESE FILTERS</h2><p>Change or clear filters to see more people.</p></div>';
		main().innerHTML = `${screenActions('Back to Home', false)}<section class="desk-kiosk-panel desk-roster"><div class="desk-roster-heading"><p class="desk-eyebrow">Selected event</p><h1>FIND REGISTRATION</h1><p>Search or browse everyone registered for ${escapeHtml(state.station.event_title)}.</p></div><form id="desk-roster-search" class="desk-search-form"><label class="desk-sr-only" for="desk-roster-query">SEARCH THIS EVENT</label><div class="desk-search-box">${icon('search')}<input id="desk-roster-query" name="q" autocomplete="off" placeholder="Name, email, or phone" value="${escapeHtml(state.roster.q)}"></div><button type="submit">SEARCH THIS EVENT</button></form>${state.roster.q ? '<button type="button" class="desk-clear-search" id="desk-clear-search">CLEAR SEARCH</button>' : ''}<div class="desk-filter-summary ${filtersActive ? 'is-active' : ''}"><span>Filters: <strong>${escapeHtml(statusLabel)} · ${escapeHtml(selectedRosterTypeLabel())}</strong></span><button type="button" class="desk-secondary" id="desk-change-filters" aria-haspopup="dialog">CHANGE FILTERS</button></div><div class="desk-roster-results">${state.roster.items.length ? state.roster.items.map(renderRosterRow).join('') : emptyState}</div>${hasMore ? '<button type="button" class="desk-primary desk-wide desk-show-more" id="desk-roster-more">SHOW MORE PEOPLE</button>' : ''}<dialog class="desk-filter-dialog" id="desk-filter-dialog" aria-labelledby="desk-filter-title"><form id="desk-filter-form"><h2 id="desk-filter-title">FILTER REGISTRATIONS</h2><fieldset><legend>Status</legend><div class="desk-filter-options">${statusChoices}</div></fieldset><fieldset><legend>Registration type</legend><div class="desk-filter-options">${typeChoices}</div></fieldset><div class="desk-filter-actions"><button type="submit" class="desk-primary">APPLY FILTERS</button><button type="button" class="desk-secondary" id="desk-reset-filters">CLEAR FILTERS</button><button type="button" class="desk-secondary" id="desk-cancel-filters">CANCEL</button></div></form></dialog></section>`;
		bindScreenActions(showHome);
		main().querySelector('#desk-roster-search').addEventListener('submit', (event) => { event.preventDefault(); state.roster.q = String(new FormData(event.currentTarget).get('q') || '').trim(); refreshRoster(); });
		main().querySelector('#desk-clear-search')?.addEventListener('click', () => { state.roster.q = ''; refreshRoster(); });
		main().querySelector('#desk-roster-more')?.addEventListener('click', () => loadEventRoster(false));
		main().querySelector('#desk-search-again')?.addEventListener('click', () => { main().querySelector('#desk-roster-query').focus(); main().querySelector('#desk-roster-query').select(); });
		main().querySelector('#desk-search-recovery')?.addEventListener('click', showRecoveryProofPrompt);
		main().querySelector('#desk-search-walkin')?.addEventListener('click', () => startWalkInWizard(false));
		main().querySelectorAll('[data-roster-registration]').forEach((button) => button.addEventListener('click', () => showRegistration(button.dataset.rosterRegistration, 'roster')));
		main().querySelectorAll('[data-roster-rsvp]').forEach((button) => button.addEventListener('click', () => showPublicRsvp(state.roster.items.find((item) => String(item.rsvp_user_id) === button.dataset.rosterRsvp))));
		const filterDialog = main().querySelector('#desk-filter-dialog');
		main().querySelector('#desk-change-filters').addEventListener('click', () => filterDialog.showModal());
		main().querySelector('#desk-cancel-filters').addEventListener('click', () => filterDialog.close());
		main().querySelector('#desk-filter-form').addEventListener('submit', (event) => {
			event.preventDefault();
			const filters = new FormData(event.currentTarget);
			state.roster.status = String(filters.get('status') || 'everyone');
			state.roster.option_uuid = String(filters.get('option_uuid') || '');
			filterDialog.close();
			refreshRoster();
		});
		main().querySelector('#desk-reset-filters').addEventListener('click', () => {
			state.roster.status = 'everyone';
			state.roster.option_uuid = '';
			filterDialog.close();
			refreshRoster();
		});
		focusMain();
	}

	function renderRosterRow(item) {
		const status = item.checked_in_today ? '✓ HERE TODAY' : item.rsvp_status === 'waitlist' ? '⚠ WAITLISTED — NOT ADMITTED' : item.rsvp_status ? '✓ CONFIRMED — NOT HERE TODAY' : '✕ NOT CHECKED IN TODAY';
		const action = item.detail_kind === 'public_rsvp' ? `data-roster-rsvp="${Number(item.rsvp_user_id)}"` : `data-roster-registration="${escapeHtml(item.registration_uuid)}"`;
		return `<button type="button" class="desk-roster-row ${item.checked_in_today ? 'is-checked-in' : ''}" ${action}><span><strong>${escapeHtml(item.name || 'Unnamed registration')}</strong><small>${escapeHtml(item.phone || 'Phone not recorded')}</small></span><span><strong>${escapeHtml(item.registration_type)}</strong>${item.attendees?.length ? `<small>${escapeHtml(item.attendees.join(' · '))}</small>` : ''}</span><span class="desk-roster-state">${escapeHtml(status)}</span>${icon('arrow')}</button>`;
	}

	function refreshRoster() {
		state.roster.offset = 0;
		state.roster.items = [];
		main().innerHTML = '<div class="desk-loading">Updating event roster…</div>';
		loadEventRoster(true);
	}

	function showPublicRsvp(item) {
		if (!item) return showEventRoster(false);
		main().innerHTML = `${screenActions('Back to Find Registration', false)}<section class="desk-detail-heading"><p class="desk-eyebrow">Registration details</p><h1>${escapeHtml(item.name)}</h1><div class="desk-detail-summary"><span>${escapeHtml(item.registration_type)}</span><span>${escapeHtml(sourceLabel(item.source_type))}</span><span>Phone: ${escapeHtml(item.phone || 'Not recorded')}</span><span>${item.rsvp_status === 'waitlist' ? '⚠ WAITLISTED — NOT ADMITTED' : '✓ CONFIRMED'}</span></div></section><div id="desk-detail-message"></div><section class="desk-kiosk-panel desk-attendance-panel">${item.rsvp_status === 'waitlist' ? notice('⚠ This person is on the waitlist and cannot be checked in.', 'warning') : '<h2>WHO IS HERE TODAY?</h2><p>Confirm that this admitted website RSVP is here now.</p><button type="button" class="desk-primary desk-wide" id="desk-rsvp-checkin">CHECK IN THIS PERSON</button>'}</section>`;
		bindScreenActions(() => renderEventRoster(false));
		main().querySelector('#desk-rsvp-checkin')?.addEventListener('click', async (event) => {
			event.currentTarget.disabled = true;
			try {
				const result = await api(`/roster/rsvp/${Number(item.rsvp_user_id)}/check-in`, {method: 'POST', body: JSON.stringify({attendance_local_date: state.station.local_date})}, uuid());
				const attendance = Array.isArray(result.current_attendance) ? result.current_attendance[0] : result.current_attendance;
				showSuccess({kind: 'checkin', name: item.name, count: 1, type: item.registration_type, when: attendance?.checked_in_at_utc || ''});
			} catch (error) {
				if (['oras_desk_rsvp_waitlisted', 'oras_desk_rsvp_not_admitted', 'oras_desk_wrong_date', 'oras_desk_date_changed'].includes(error.code)) {
					await showEventRoster(false);
				} else {
					event.currentTarget.disabled = false;
					main().querySelector('#desk-detail-message').innerHTML = notice(friendlyError(error), 'error');
				}
			}
		});
		focusMain();
	}

	async function showEventStats(view = 'today') {
		state.view = 'stats';
		main().innerHTML = '<div class="desk-loading">Loading event stats…</div>';
		try {
			const stats = isTraining() ? await api('/training/stats') : await api('/stats');
			const data = view === 'today' ? stats.today : stats.event_total;
			const cards = view === 'today' ? [
				['Actual people checked in today', data.actual_people], ['Direct website attendees', data.website_people], ['Included with another event attendees', data.included_event_people], ['Walk-in attendees', data.walk_in_people], ['Complimentary attendees', data.complimentary_people], ['RSVP attendees', data.rsvp_people], ['Manager Verified attendees', data.manager_verified_people], ['New walk-in registrations', data.new_walk_in_registrations],
			] : [
				['Active registrations', data.active_registrations], ['People registered', data.people_registered], ['Unique actual attendees', data.unique_attendees], ['Total check-ins', data.attendance_instances], ['Direct website registrations', data.direct_website_registrations], ['Included through another event', data.included_event_registrations], ['Walk-in registrations', data.walk_in_registrations], ['Complimentary registrations', data.complimentary_registrations], ['RSVP registrations', data.rsvp_registrations], ['Manager Verified registrations', data.manager_verified_registrations], ['Family registrations', data.family_registrations], ['Actual family attendees', data.family_attendees_attended], ['Registered but never attended', data.no_show_registrations],
			];
			main().innerHTML = `${screenActions('Back to Home', false)}<section class="desk-kiosk-panel desk-stats"><h1>EVENT STATS</h1><div class="desk-segmented"><button type="button" data-stats-view="today" class="${view === 'today' ? 'is-selected' : ''}">TODAY</button><button type="button" data-stats-view="total" class="${view === 'total' ? 'is-selected' : ''}">EVENT TOTAL</button></div><p>Registrations are passes. People are actual attendees.</p><div class="desk-stats-grid">${cards.map(([label, value]) => `<div class="desk-stat-card"><strong>${Number(value || 0)}</strong><span>${escapeHtml(label)}</span></div>`).join('')}</div>${renderStatsBreakdown(view === 'today' ? data.pass_types : data.attendance_by_day, view === 'today' ? 'Today by pass type' : 'Attendance by day')}</section>`;
			bindScreenActions(requestHome);
			main().querySelectorAll('[data-stats-view]').forEach((button) => button.addEventListener('click', () => showEventStats(button.dataset.statsView)));
			focusMain();
		} catch (error) {
			main().innerHTML = `${screenActions('Back to Home', false)}${notice(friendlyError(error), 'error')}`;
			bindScreenActions(requestHome);
		}
	}

	function renderStatsBreakdown(values, title) {
		const rows = Object.entries(values || {});
		return rows.length ? `<section class="desk-stats-breakdown"><h2>${escapeHtml(title)}</h2>${rows.map(([label, value]) => `<div><span>${escapeHtml(label.replaceAll('_', ' '))}</span><strong>${Number(value)}</strong></div>`).join('')}</section>` : '';
	}

	function showRecoveryProofPrompt() {
		state.view = 'recovery-proof';
		main().innerHTML = `${screenActions('Back to Search', false)}<section class="desk-centered desk-recovery-proof"><span class="desk-large-icon desk-gold-icon">${icon('help')}</span><p class="desk-eyebrow">Registration recovery</p><h1>DO THEY HAVE PROOF OF REGISTRATION OR PAYMENT?</h1><p>Ask for an order number, confirmation email, receipt, or other clear proof. A manager can search the website order records without changing payment or creating an order.</p><div class="desk-actions"><button type="button" class="desk-primary desk-touch-centered" id="desk-recovery-manager">GET MANAGER HELP</button><button type="button" class="desk-secondary desk-touch-centered" id="desk-recovery-back">GO BACK</button></div></section>`;
		bindScreenActions(() => showEventRoster(false));
		main().querySelector('#desk-recovery-manager').addEventListener('click', () => state.station.manager_token ? showMissingRegistration() : showManagerHelp('recovery'));
		main().querySelector('#desk-recovery-back').addEventListener('click', () => showEventRoster(false));
		focusMain();
	}

	function recoveryResultCard(item) {
		const accessClass = item.event_access === 'valid' ? 'desk-notice-success' : item.event_access === 'review' ? 'desk-notice-warning' : 'desk-notice-error';
		const action = item.event_access === 'valid' ? (item.projected ? `<button type="button" class="desk-secondary desk-touch-centered" data-recovery-open="${escapeHtml(item.registration_uuid)}">OPEN REGISTRATION</button>` : `<button type="button" class="desk-primary desk-touch-centered" data-recovery-sync="${Number(item.order_id)}" data-recovery-item="${Number(item.order_item_id)}">SYNC THIS REGISTRATION</button>`) : '';
		return `<article class="desk-result-card desk-recovery-result"><div class="desk-result-main"><strong>${escapeHtml(item.contact_name || 'Website registration')}</strong><span>${escapeHtml(item.registration_type || 'Registration')}</span><small>${escapeHtml(item.email || 'Email not recorded')} · ${escapeHtml(item.phone || 'Phone not recorded')}</small><small>Website order ${escapeHtml(item.order_number)} · ${escapeHtml(item.order_status_label)}</small>${item.cross_event ? '<small>Includes access to this event from another event ticket.</small>' : ''}<span class="desk-recovery-access ${accessClass}">${escapeHtml(item.access_label)}</span></div>${action}</article>`;
	}

	function showMissingRegistration() {
		resetViewport();
		if (!state.station.manager_token) return showManagerHelp('recovery');
		state.view = 'recovery';
		state.managerDestination = 'manager';
		main().innerHTML = `${screenActions('Back to Manager', false)}<section class="desk-kiosk-panel desk-recovery-search"><div class="desk-manager-banner"><strong>MANAGER MODE</strong></div><p class="desk-eyebrow">Paid-but-not-found recovery</p><h1>FIND MISSING REGISTRATION</h1><p>Search canonical website orders by name, email, phone, order number, or reference. Results are limited to this event and explicit access from another event.</p><form id="desk-recovery-search-form" class="desk-search-form"><label class="desk-sr-only" for="desk-recovery-query">Name, email, phone, order number, or reference</label><div class="desk-search-box">${icon('search')}<input id="desk-recovery-query" name="q" minlength="2" value="${escapeHtml(state.searchQuery)}" placeholder="Name, email, phone, order number, or reference" required autofocus></div><button type="submit" class="desk-primary desk-touch-centered">SEARCH</button></form><div id="desk-recovery-message"></div><div id="desk-recovery-results" class="desk-results"></div></section>`;
		bindScreenActions(showManagerArea);
		main().querySelector('#desk-recovery-search-form').addEventListener('submit', searchMissingRegistration);
		focusMain();
	}

	async function searchMissingRegistration(event) {
		event.preventDefault();
		const query = String(new FormData(event.currentTarget).get('q') || '').trim();
		state.searchQuery = query;
		const results = main().querySelector('#desk-recovery-results');
		const message = main().querySelector('#desk-recovery-message');
		results.innerHTML = '<div class="desk-loading desk-loading-small">Searching website registrations…</div>';
		message.innerHTML = '';
		try {
			const data = await api(`/manager/recovery?q=${encodeURIComponent(query)}`);
			results.innerHTML = data.items?.length ? data.items.map(recoveryResultCard).join('') : `<div class="desk-no-results"><h2>NO WEBSITE REGISTRATION WAS FOUND</h2><p>Try another order number, email, phone, or name. If the person has clear proof that cannot be found in the website records, use the audited manual option.</p><button type="button" class="desk-primary desk-touch-centered" id="desk-recovery-manual">RECORD VERIFIED MANUAL REGISTRATION</button></div>`;
			results.querySelector('#desk-recovery-manual')?.addEventListener('click', showManagerVerifiedForm);
			results.querySelectorAll('[data-recovery-open]').forEach((button) => button.addEventListener('click', () => showRegistration(button.dataset.recoveryOpen, 'recovery')));
			results.querySelectorAll('[data-recovery-sync]').forEach((button) => button.addEventListener('click', () => syncMissingRegistration(button)));
		} catch (error) {
			results.innerHTML = notice(friendlyError(error), 'error');
		}
	}

	async function syncMissingRegistration(button) {
		button.disabled = true;
		try {
			const data = await api('/manager/recovery/sync', {method: 'POST', body: JSON.stringify({order_id: Number(button.dataset.recoverySync), order_item_id: Number(button.dataset.recoveryItem)})});
			resetViewport();
			main().innerHTML = `${screenActions('Back to Manager', false)}<section class="desk-success-screen"><span class="desk-success-check">${icon('check')}</span><p class="desk-eyebrow">Manager recovery</p><h1>REGISTRATION FOUND</h1><p>${escapeHtml(data.message || 'Registration found and added to the roster.')}</p><p>No payment, order, email, membership, or website account was changed.</p><div class="desk-success-actions"><button type="button" class="desk-primary desk-touch-centered" id="desk-recovery-open">OPEN REGISTRATION</button><button type="button" class="desk-secondary desk-touch-centered" id="desk-recovery-done">RETURN TO MANAGER TOOLS</button></div></section>`;
			bindScreenActions(showManagerArea);
			main().querySelector('#desk-recovery-open').addEventListener('click', () => showRegistration(data.registration_uuid, 'recovery'));
			main().querySelector('#desk-recovery-done').addEventListener('click', () => showManagerArea('Website registration synchronized.'));
		} catch (error) {
			button.disabled = false;
			main().querySelector('#desk-recovery-message').innerHTML = notice(friendlyError(error), 'error');
		}
	}

	async function showManagerVerifiedForm() {
		resetViewport();
		main().innerHTML = '<div class="desk-loading">Loading current registration choices…</div>';
		try {
			const data = await api('/offerings');
			const options = (data.items || []).filter((option) => option.kind !== 'rsvp' && option.available_for_new);
			main().innerHTML = `${screenActions('Back to Missing Registration', false)}<section class="desk-kiosk-panel desk-manager-verified"><div class="desk-manager-banner"><strong>MANAGER MODE</strong></div><p class="desk-eyebrow">Audited exception</p><h1>RECORD VERIFIED MANUAL REGISTRATION</h1><p>Use this only after canonical website search found no source and you reviewed clear proof outside this system. This records access only; it does not check anyone in.</p>${options.length ? `<form id="desk-manager-verified-form" class="desk-form desk-large-form"><div class="desk-fields"><div class="desk-field"><label>First name</label><input name="first_name" required></div><div class="desk-field"><label>Last name</label><input name="last_name" required></div><div class="desk-field"><label>Email (optional)</label><input name="email" type="email"></div><div class="desk-field"><label>Phone (optional)</label><input name="phone" type="tel"></div><div class="desk-field desk-field-wide"><label>Current event registration option</label><select name="option_uuid" required><option value="">Choose an option</option>${options.map((option) => `<option value="${escapeHtml(option.option_uuid)}" data-fingerprint="${escapeHtml(option.offering_fingerprint)}" data-classification="${escapeHtml(option.classification)}" data-maximum="${Number(option.max_attendees || 1)}">${escapeHtml(option.label)} — $${Number(option.price || 0).toFixed(2)}</option>`).join('')}</select></div><div class="desk-field desk-field-wide" id="desk-manager-family" hidden><label>Additional family members (optional)</label><div id="desk-manager-family-members" class="desk-family-list"></div><button type="button" class="desk-secondary desk-wide" id="desk-manager-family-add">+ ADD FAMILY MEMBER</button><p class="desk-help" id="desk-manager-family-help"></p></div><div class="desk-field desk-field-wide"><label>Reason and proof reviewed</label><textarea name="reason" rows="3" minlength="5" required></textarea></div></div><label class="desk-proof-ack"><input type="checkbox" name="proof_acknowledged" value="1" required><span>I verified proof of registration/payment outside this system.</span></label><div id="desk-manager-verified-message"></div><button type="submit" class="desk-primary desk-wide desk-touch-centered">SAVE MANAGER VERIFIED REGISTRATION</button></form>` : notice('No current ticket offering is available for a verified manual registration.', 'warning')}</section>`;
			bindScreenActions(showMissingRegistration);
			const form = main().querySelector('#desk-manager-verified-form');
			form?.addEventListener('submit', saveManagerVerified);
			form?.querySelector('select[name="option_uuid"]').addEventListener('change', () => renderManagerVerifiedFamily(form));
			form?.querySelector('#desk-manager-family-add').addEventListener('click', () => {
				const selected = form.querySelector('select[name="option_uuid"] option:checked');
				addWizardFamilyRow(form.querySelector('#desk-manager-family-members'), Number(selected?.dataset.maximum || 1));
			});
		} catch (error) {
			main().innerHTML = `${screenActions('Back to Missing Registration', false)}${notice(friendlyError(error), 'error')}`;
			bindScreenActions(showMissingRegistration);
		}
	}

	function renderManagerVerifiedFamily(form) {
		const selected = form.querySelector('select[name="option_uuid"] option:checked');
		const section = form.querySelector('#desk-manager-family');
		const container = form.querySelector('#desk-manager-family-members');
		const maximum = Math.max(1, Number(selected?.dataset.maximum || 1));
		container.innerHTML = '';
		section.hidden = selected?.dataset.classification !== 'family';
		form.querySelector('#desk-manager-family-help').textContent = `The primary contact is included. This registration allows up to ${maximum} people total.`;
	}

	function managerVerifiedAttendees(form) {
		return [...form.querySelectorAll('#desk-manager-family-members .desk-family-row')].map((row) => ({
			first_name: row.querySelector('[data-first]').value,
			last_name: row.querySelector('[data-last]').value,
		}));
	}

	async function saveManagerVerified(event) {
		event.preventDefault();
		const form = event.currentTarget;
		const payload = Object.fromEntries(new FormData(form).entries());
		const selected = form.querySelector('select[name="option_uuid"] option:checked');
		payload.offering_fingerprint = selected?.dataset.fingerprint || '';
		payload.proof_acknowledged = Boolean(payload.proof_acknowledged);
		payload.additional_attendees = managerVerifiedAttendees(form);
		const message = form.querySelector('#desk-manager-verified-message');
		form.querySelector('button[type="submit"]').disabled = true;
		try {
			const data = await api('/registrations/manager-verified', {method: 'POST', body: JSON.stringify(payload)}, uuid());
			const result = data.historical_result || {};
			const registration = result.registration || {};
			resetViewport();
			main().innerHTML = `${screenActions('Back to Manager', false)}<section class="desk-success-screen"><span class="desk-success-check">${icon('check')}</span><p class="desk-eyebrow">Manager Verified</p><h1>REGISTRATION RECORDED</h1><p>Manager verified registration manually. No one was checked in.</p><div class="desk-success-actions"><button type="button" class="desk-primary desk-touch-centered" id="desk-manager-verified-open">OPEN REGISTRATION</button><button type="button" class="desk-secondary desk-touch-centered" id="desk-manager-verified-done">RETURN TO MANAGER TOOLS</button></div></section>`;
			bindScreenActions(showManagerArea);
			main().querySelector('#desk-manager-verified-open').addEventListener('click', () => showRegistration(registration.registration_uuid, 'recovery'));
			main().querySelector('#desk-manager-verified-done').addEventListener('click', () => showManagerArea('Manager Verified registration recorded.'));
		} catch (error) {
			form.querySelector('button[type="submit"]').disabled = false;
			if (error.code === 'oras_desk_verified_duplicate') {
				const candidates = error.data?.candidates || [];
				message.innerHTML = `<div class="desk-confirm-card"><h2>MATCHING REGISTRATION FOUND</h2><p>Open or synchronize the existing source. A second registration was not created.</p>${candidates.map((candidate) => `<p><strong>${escapeHtml(candidate.contact_name || 'Existing registration')}</strong> · ${escapeHtml(sourceLabel(candidate.source_type))}</p>`).join('')}</div>`;
			} else message.innerHTML = notice(friendlyError(error), 'error');
		}
	}

	async function showRegistration(registrationUuid, returnTo = 'roster') {
		state.view = 'registration';
		state.detailReturn = returnTo;
		main().innerHTML = '<div class="desk-loading">Opening registration…</div>';
		try {
			const detailPath = isTraining() ? `/training/registrations/${encodeURIComponent(registrationUuid)}` : `/registrations/${encodeURIComponent(registrationUuid)}`;
			const data = await api(detailPath);
			const registration = data.registration;
			const admission = data.admission || {state: 'manager_review_required', status_label: 'MANAGER HELP NEEDED', message: 'This registration needs manager help before check-in.', selection_allowed: false, check_in_allowed: false, manager_help: true};
			state.station.local_date = data.local_date || state.station.local_date;
			if (isTraining()) state.station.record_version = Number(data.record_version || state.station.record_version);
			saveStation(state.station);
			const option = optionFor(registration.option_uuid);
			const maximum = Number(admission.maximum_attendees || option.max_attendees || 1);
			const existing = data.attendees || [];
			const canAddAttendee = admission.selection_allowed === true && registration.classification === 'family' && existing.length < maximum;
			const everyoneCheckedIn = admission.state === 'already_checked_in';
			const back = state.detailReturn === 'recovery' ? showMissingRegistration : () => renderEventRoster(false);
			const backLabel = state.detailReturn === 'recovery' ? 'Back to Missing Registration' : 'Back to Find Registration';
			const status = admission.state === 'eligible' ? '✓ REGISTRATION VALID' : everyoneCheckedIn ? '✓ CHECKED IN TODAY' : `⚠ ${admission.status_label || 'MANAGER HELP NEEDED'}`;
			const attendancePanel = admission.check_in_allowed === true
				? `<section class="desk-kiosk-panel desk-attendance-panel"><h2>WHO IS HERE TODAY?</h2><p>Select only the people who are here now.${registration.classification === 'family' ? ` This registration allows up to ${maximum} people.` : ''}</p><form id="desk-checkin-form" class="desk-form" data-maximum="${maximum}" data-classification="${escapeHtml(registration.classification)}"><div id="desk-arrival-rows" class="desk-attendee-list">${existing.map((attendee) => renderExistingAttendee(attendee)).join('')}</div><div class="desk-detail-actions">${canAddAttendee ? '<button type="button" class="desk-secondary" id="desk-add-arrival">+ ADD FAMILY MEMBER</button>' : ''}<button type="submit" class="desk-primary" disabled>CHECK IN SELECTED PEOPLE ${icon('arrow')}</button></div></form></section>`
				: everyoneCheckedIn
					? '<section class="desk-kiosk-panel desk-attendance-panel"><div class="desk-all-checked"><strong>✓ CHECKED IN TODAY</strong><p>Everyone on this registration is already checked in today.</p><button type="button" class="desk-primary" id="desk-detail-done">DONE</button></div></section>'
					: `<section class="desk-kiosk-panel desk-attendance-panel desk-admission-blocked"><span class="desk-large-icon desk-gold-icon">${icon('manager')}</span><h2>⚠ MANAGER HELP NEEDED</h2><p>${escapeHtml(admission.message || 'We found this registration, but it cannot be checked in right now.')}</p><div class="desk-actions"><button type="button" class="desk-primary" id="desk-detail-manager">GET MANAGER HELP</button><button type="button" class="desk-secondary" id="desk-detail-back">BACK TO FIND REGISTRATION</button></div></section>`;
			main().innerHTML = `${screenActions(backLabel)}<section class="desk-detail-heading"><p class="desk-eyebrow">Registration details</p><h1>${escapeHtml(registration.contact_name || 'Registration')}</h1><div class="desk-admission-status ${admission.state === 'eligible' || everyoneCheckedIn ? 'is-positive' : 'is-blocked'}">${escapeHtml(status)}</div><div class="desk-detail-summary"><span>${escapeHtml(registration.registration_type || registrationType(option, registration))}</span><span>${escapeHtml(sourceLabel(registration.source_type))}</span><span>Email: ${escapeHtml(registration.contact_email || 'Not recorded')}</span><span>Phone: ${escapeHtml(registration.contact_phone || 'Not recorded')}</span>${admission.check_in_allowed === true ? `<span>${escapeHtml(paymentLabel(registration, admission))}</span>` : ''}</div></section>
				<div id="desk-detail-message"></div>${attendancePanel}
				${state.station.manager_token && data.manager_detail ? renderManagerRosterDetail(data.manager_detail) : ''}${state.station.manager_token && data.editable_registration ? `<section class="desk-manager-inline"><details><summary>Manager correction tools</summary>${renderCorrectionForm(data.editable_registration)}</details></section>` : ''}`;
			bindScreenActions(back);
			const form = main().querySelector('#desk-checkin-form');
			main().querySelector('#desk-detail-done')?.addEventListener('click', back);
			main().querySelector('#desk-detail-manager')?.addEventListener('click', () => state.station.manager_token ? showMissingRegistration() : showManagerHelp('recovery'));
			main().querySelector('#desk-detail-back')?.addEventListener('click', back);
			if (!form) { focusMain(); return; }
			const updateSubmitState = () => { form.querySelector('[type="submit"]').disabled = admission.check_in_allowed !== true || !collectArrivals(form).length; };
			const add = (name = {}) => { addArrivalRow(main().querySelector('#desk-arrival-rows'), registration.classification, maximum, name); updateSubmitState(); };
			main().querySelector('#desk-add-arrival')?.addEventListener('click', () => add());
			if (!existing.length && registration.classification === 'individual') add(parseName(registration.contact_name));
			form.addEventListener('change', updateSubmitState);
			form.addEventListener('click', () => window.setTimeout(updateSubmitState, 0));
			form.addEventListener('submit', (event) => submitCheckIn(event, registration, option));
			updateSubmitState();
			main().querySelectorAll('[data-reverse-attendee]').forEach((button) => button.addEventListener('click', () => reverseAttendance(registration, button)));
			main().querySelector('#desk-correction-form')?.addEventListener('submit', (event) => saveCorrection(event, registration));
			focusMain();
		} catch (error) {
			main().innerHTML = `${screenActions('Back to Find Registration')}<section class="desk-centered"><h1>Registration</h1>${notice(friendlyError(error), 'error')}</section>`;
			bindScreenActions(state.detailReturn === 'recovery' ? showMissingRegistration : () => renderEventRoster(false));
		}
	}

	function showTrainingFailure(error, retry) {
		resetViewport();
		main().innerHTML = `<section class="desk-centered desk-finalization-recovery"><div class="desk-recovery-card"><span class="desk-large-icon">${icon('help')}</span><h1>TRAINING ACTION COULD NOT BE SAVED</h1><p>${escapeHtml(friendlyError(error))}</p><p><strong>No live event data was changed.</strong></p><p><strong>TRAINING ONLY — NO PAYMENT WAS TAKEN.</strong></p><div class="desk-actions"><button type="button" id="desk-training-retry">TRY AGAIN</button><button type="button" class="desk-secondary" id="desk-training-manager">MANAGER HELP</button></div></div></section>`;
		main().querySelector('#desk-training-retry').addEventListener('click', retry);
		main().querySelector('#desk-training-manager').addEventListener('click', () => state.station.manager_token ? showManagerArea() : showManagerHelp());
		focusMain();
	}

	function renderManagerRosterDetail(detail) {
		const address = Object.values(detail.mailing_address || {}).filter(Boolean).join(', ');
		const diagnostic = detail.admission_diagnostics || {};
		return `<section class="desk-manager-inline desk-manager-roster-detail"><div class="desk-manager-banner"><strong>MANAGER DETAIL</strong></div><dl><dt>Full name</dt><dd>${escapeHtml(detail.full_name || 'Not recorded')}</dd><dt>Full phone</dt><dd>${escapeHtml(detail.phone || 'Not recorded')}</dd><dt>Email</dt><dd>${escapeHtml(detail.email || 'Not recorded')}</dd><dt>Mailing address</dt><dd>${escapeHtml(address || 'Not recorded')}</dd><dt>Source</dt><dd>${escapeHtml(sourceLabel(detail.source_type))}</dd><dt>Registration type</dt><dd>${escapeHtml(detail.registration_type)}</dd><dt>Payment assertion</dt><dd>${escapeHtml(detail.payment_assertion || 'Not applicable')}</dd><dt>Created</dt><dd>${escapeHtml(detail.created_at_utc)}</dd><dt>Source reference</dt><dd>${detail.source_order_id ? `Order ${Number(detail.source_order_id)} · Item ${Number(detail.source_order_item_id)}` : 'Desk record'}</dd></dl><details open><summary>Current admission diagnostic</summary><dl><dt>Access origin</dt><dd>${escapeHtml(diagnostic.access_origin || 'Direct registration')}</dd><dt>Source event</dt><dd>${escapeHtml(diagnostic.source_event || 'Not applicable')}</dd><dt>Source ticket</dt><dd>${escapeHtml(diagnostic.source_ticket || 'Not applicable')}</dd><dt>Grants access to</dt><dd>${escapeHtml(diagnostic.grants_access_to || 'Not available')}</dd><dt>Operational registration</dt><dd>${escapeHtml(diagnostic.operational_registration || 'Not available')}</dd><dt>Canonical source status</dt><dd>${escapeHtml(diagnostic.canonical_source_status || 'Not applicable')}</dd><dt>Current event entitlement</dt><dd>${escapeHtml(diagnostic.event_entitlement || 'Not available')}</dd><dt>Current ticket mapping</dt><dd>${escapeHtml(diagnostic.ticket_mapping || 'Not available')}</dd><dt>Selected event</dt><dd>${escapeHtml(diagnostic.selected_event || 'Not available')}</dd><dt>Date validity</dt><dd>${escapeHtml(diagnostic.date_validity || 'Not available')}</dd><dt>Cancellation or refund</dt><dd>${escapeHtml(diagnostic.source_lifecycle || 'Not applicable')}</dd><dt>Quantity and unit</dt><dd>${escapeHtml(diagnostic.source_quantity || 'Not applicable')}</dd><dt>Historical mapping</dt><dd>${escapeHtml(diagnostic.historical_mapping || 'No ambiguity detected')}</dd></dl></details><details><summary>Corrections and audit history</summary><div class="desk-audit-history">${(detail.audit_history || []).map((entry) => `<p><strong>${escapeHtml(entry.operation)}</strong> · ${escapeHtml(entry.operator_label || 'System')} · ${escapeHtml(entry.created_at_utc)}</p>`).join('') || '<p>No audit entries recorded.</p>'}</div></details></section>`;
	}

	function renderExistingAttendee(attendee) {
		const attendance = attendee.current_attendance;
		const checkedIn = attendance?.state === 'checked_in';
		const selectable = attendee.admission?.selection_allowed === true;
		const blocked = !checkedIn && !selectable;
		const helper = checkedIn ? `✓ CHECKED IN TODAY${formatLocalTime(attendance.checked_in_at_utc) ? ` at ${escapeHtml(formatLocalTime(attendance.checked_in_at_utc))}` : ''}` : blocked ? '⚠ MANAGER HELP NEEDED' : 'Tap to select this person';
		return `<div class="desk-attendee-card ${checkedIn ? 'is-checked-in' : ''} ${blocked ? 'is-blocked' : ''}"><label><input type="checkbox" name="selected" value="${escapeHtml(attendee.slot_key)}" ${selectable ? '' : 'disabled'}><span class="desk-select-box">${checkedIn ? icon('check') : blocked ? icon('help') : ''}</span><span><strong>${escapeHtml(attendee.display_name || 'Unnamed attendee')}</strong><small>${helper}</small></span></label><input type="hidden" data-first value="${escapeHtml(attendee.first_name)}"><input type="hidden" data-last value="${escapeHtml(attendee.last_name)}">${state.station.manager_token && checkedIn ? `<button type="button" class="desk-danger desk-manager-action" data-reverse-attendee="${escapeHtml(attendee.attendee_uuid)}" data-version="${Number(attendance.record_version)}">Reverse check-in</button>` : ''}</div>`;
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
		if (isTraining()) state.pendingPayload.expected_record_version = Number(state.station.record_version);
		const message = main().querySelector('#desk-detail-message');
		form.querySelectorAll('button').forEach((button) => { button.disabled = true; });
		try {
			const checkInPath = isTraining() ? `/training/registrations/${registration.registration_uuid}/check-in` : `/registrations/${registration.registration_uuid}/check-in`;
			const result = await api(checkInPath, {method: 'POST', body: JSON.stringify(state.pendingPayload)}, state.pendingRequest);
			const operation = result.result || result;
			if (isTraining()) {
				state.station.record_version = Number(result.record_version || state.station.record_version);
				saveStation(state.station);
			}
			const count = operation.historical_result?.attendance?.length || operation.attendee_uuids?.length || arrivals.length;
			const attendance = Array.isArray(operation.current_attendance) ? operation.current_attendance[0] : operation.current_attendance;
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
			} else if (['oras_desk_registration_inactive', 'oras_desk_not_eligible', 'oras_desk_source_changed', 'oras_desk_source_option_changed', 'oras_desk_source_unit_invalid', 'oras_desk_source_review', 'oras_desk_attendance_reversed', 'oras_desk_wrong_date', 'oras_desk_date_changed', 'oras_desk_rsvp_waitlisted', 'oras_desk_rsvp_not_admitted', 'oras_desk_event_dates_unavailable'].includes(error.code)) {
				resetPending();
				await showRegistration(registration.registration_uuid, state.detailReturn);
			} else {
				message.innerHTML = notice(friendlyError(error), 'error');
			}
		}
	}

	async function startWalkInWizard(administrator) {
		state.view = administrator ? 'complimentary' : 'walk-in';
		resetPending();
		main().innerHTML = '<section class="desk-centered"><h1>LOADING CURRENT REGISTRATION OPTIONS…</h1></section>';
		try {
			const response = isTraining() ? await api('/training/offerings') : await api('/offerings');
			state.station.options = Array.isArray(response.items) ? response.items : [];
			saveStation(state.station);
		} catch (error) {
			main().innerHTML = `<section class="desk-centered">${notice(friendlyError(error), 'error')}<button type="button" id="desk-offerings-retry">TRY AGAIN</button><button type="button" class="desk-secondary" id="desk-offerings-home">RETURN HOME</button></section>`;
			main().querySelector('#desk-offerings-retry').addEventListener('click', () => startWalkInWizard(administrator));
			main().querySelector('#desk-offerings-home').addEventListener('click', () => showHome());
			return;
		}
		state.pendingRequest = uuid();
		state.wizard = {administrator, step: 'type', data: {first_name: '', last_name: '', email: '', phone: '', address_1: '', address_2: '', city: '', state: '', postcode: '', option_uuid: '', offering_fingerprint: '', valid_local_date: '', source_type: 'complimentary'}, additional_attendees: [], payment: '', payment_handled: false};
		persistDraft('walk-in');
		showWalkInStep('type');
	}

	function wizardSteps() {
		const option = optionFor(state.wizard?.data.option_uuid);
		const steps = ['type', 'contact'];
		if (option.classification === 'family') steps.push('attendees');
		steps.push('review');
		if (option.kind === 'rsvp') return steps;
		if (state.wizard?.administrator) steps.push('kind');
		else steps.push('handoff', 'payment');
		return steps;
	}

	function wizardProgress(step) {
		const steps = wizardSteps();
		const current = Math.max(0, steps.indexOf(step));
		return `<div class="desk-progress" aria-label="Step ${current + 1} of ${steps.length}"><strong>STEP ${current + 1} OF ${steps.length}</strong><div class="desk-progress-track">${steps.map((item, index) => `<span class="${index <= current ? 'is-complete' : ''}"></span>`).join('')}</div></div>`;
	}

	function showWalkInStep(step) {
		resetViewport();
		if (!state.wizard) return startWalkInWizard(false);
		state.wizard.step = step;
		persistDraft('walk-in');
		if (step === 'type') return showWizardType();
		if (step === 'contact') return showWizardContact();
		if (step === 'attendees') return showWizardAttendees();
		if (step === 'review') return showWizardReview();
		if (step === 'handoff') return showWizardHandoff();
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
		wizardFrame(`<div class="desk-wizard-heading"><p class="desk-eyebrow">${state.wizard.administrator ? 'Manager registration' : 'Walk-in registration'}</p><h1>WHAT TYPE OF REGISTRATION?</h1><p>These choices come directly from the selected event.</p></div><div class="desk-option-grid">${options.map((option) => `<button type="button" class="desk-option-card" data-option="${escapeHtml(option.option_uuid)}" ${option.selectable ? '' : 'disabled'}><span class="desk-option-icon">${icon(option.kind === 'rsvp' || option.validity_type === 'one_day' ? 'calendar' : option.classification === 'family' ? 'family' : 'person')}</span><strong>${escapeHtml(option.label)}</strong><span>${escapeHtml(option.kind === 'rsvp' ? 'Event RSVP' : registrationType(option))}</span>${option.description ? `<small>${escapeHtml(option.description)}</small>` : ''}<small>${option.kind === 'rsvp' ? escapeHtml(option.availability_label) : `${escapeHtml(option.attendance_mode === 'virtual' ? 'Virtual' : 'On-site')} · $${Number(option.price || 0).toFixed(2)} · ${escapeHtml(option.availability_label)}`}</small></button>`).join('')}</div>${options.length ? '' : notice('No current walk-in registration choices are available. Please ask a manager for help.', 'warning')}`, showHome);
		main().querySelectorAll('[data-option]').forEach((button) => button.addEventListener('click', () => {
			const option = optionFor(button.dataset.option);
			state.wizard.data.option_uuid = option.option_uuid;
			state.wizard.data.offering_fingerprint = option.offering_fingerprint || '';
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
		main().querySelector('#desk-contact-form').addEventListener('input', (event) => {
			Object.assign(state.wizard.data, Object.fromEntries(new FormData(event.currentTarget).entries()));
			persistDraft('walk-in');
		});
	}

	function showWizardAttendees() {
		const option = optionFor(state.wizard.data.option_uuid);
		const maximum = Number(option.max_attendees || 1);
		wizardFrame(`<div class="desk-wizard-heading"><h1>WHO ELSE IS ATTENDING?</h1><p>Names are optional. Add the people who are here if you know them.</p></div><form id="desk-family-form" class="desk-form"><div id="desk-family-members" class="desk-family-list"></div><button type="button" class="desk-secondary desk-wide" id="desk-add-family">+ ADD ANOTHER PERSON</button><p class="desk-help">The primary contact is already included. This registration allows up to ${maximum} people total.</p><button type="submit" class="desk-primary desk-wide">CONTINUE ${icon('arrow')}</button></form>`, () => showWalkInStep('contact'));
		const container = main().querySelector('#desk-family-members');
		state.wizard.additional_attendees.forEach((attendee) => addWizardFamilyRow(container, maximum, attendee));
		main().querySelector('#desk-add-family').addEventListener('click', () => { addWizardFamilyRow(container, maximum); persistDraft('walk-in'); });
		container.addEventListener('input', () => {
			state.wizard.additional_attendees = [...container.querySelectorAll('.desk-family-row')].map((row) => ({first_name: row.querySelector('[data-first]').value, last_name: row.querySelector('[data-last]').value}));
			persistDraft('walk-in');
		});
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
		row.querySelector('button').addEventListener('click', () => {
			row.remove();
			state.wizard.additional_attendees = [...container.querySelectorAll('.desk-family-row')].map((item) => ({first_name: item.querySelector('[data-first]').value, last_name: item.querySelector('[data-last]').value}));
			persistDraft('walk-in');
		});
		container.appendChild(row);
	}

	function showWizardReview() {
		const data = state.wizard.data;
		const option = optionFor(data.option_uuid);
		const familyCount = 1 + state.wizard.additional_attendees.length;
		wizardFrame(`<div class="desk-wizard-heading"><h1>PLEASE CHECK THIS INFORMATION</h1><p>Nothing has been saved yet.</p></div><section class="desk-review-card"><dl><dt>Name</dt><dd>${escapeHtml(data.first_name)} ${escapeHtml(data.last_name)}</dd><dt>Email</dt><dd>${escapeHtml(data.email)}</dd><dt>Phone</dt><dd>${escapeHtml(data.phone)}</dd><dt>Registration</dt><dd>${escapeHtml(option.label)}<small>${escapeHtml(option.kind === 'rsvp' ? option.availability_label : registrationType(option))}</small></dd>${option.validity_type === 'one_day' ? `<dt>Day</dt><dd>${escapeHtml(formatDateValue(data.valid_local_date))}</dd>` : ''}<dt>${option.kind === 'rsvp' && option.availability === 'waitlist' ? 'Outcome' : 'Checking in'}</dt><dd>${option.kind === 'rsvp' && option.availability === 'waitlist' ? 'Add to waitlist — no check-in' : `${familyCount} ${familyCount === 1 ? 'person' : 'people'} today`}</dd></dl></section><div id="desk-payment-message"></div><div class="desk-actions desk-review-actions"><button type="button" class="desk-secondary" id="desk-review-back">GO BACK AND FIX</button><button type="button" class="desk-primary" id="desk-review-correct">INFORMATION IS CORRECT ${icon('arrow')}</button></div>`, () => showWalkInStep(option.classification === 'family' ? 'attendees' : 'contact'));
		main().querySelector('#desk-review-back').addEventListener('click', () => showWalkInStep('contact'));
		main().querySelector('#desk-review-correct').addEventListener('click', () => option.kind === 'rsvp' ? saveManual('rsvp', false) : showWalkInStep(state.wizard.administrator ? 'kind' : 'handoff'));
	}

	function showWizardHandoff() {
		const content = `<div class="desk-wizard-heading"><p class="desk-eyebrow">Use AlfaPOS now</p><h1>PAYMENT IS HANDLED IN ALFAPOS</h1><p><strong>Before taking payment, ask whether they are buying anything else today.</strong></p><p>Add the registration and any other items — such as pizza, T-shirts, merchandise, or door-prize tickets — in AlfaPOS and take ONE payment.</p><p>Return here when the sale is finished.</p></div><button type="button" class="desk-primary desk-wide" id="desk-handoff-done">THE ALFAPOS SALE IS FINISHED ${icon('arrow')}</button>`;
		wizardFrame(content, () => showWalkInStep('review'));
		main().querySelector('#desk-handoff-done').addEventListener('click', () => {
			state.wizard.payment_handled = true;
			persistDraft('walk-in');
			showWalkInStep('payment');
		});
	}

	function showWizardPayment() {
		const paymentCopy = `<p>The sale stays in AlfaPOS.</p>${state.wizard.payment_handled ? '<p><strong>PAYMENT WAS ALREADY HANDLED.<br>DO NOT CHARGE THIS PERSON AGAIN.</strong></p>' : ''}`;
		wizardFrame(`<div class="desk-wizard-heading"><p class="desk-eyebrow">Operational statement only</p><h1>HOW WAS THE REGISTRATION PAID?</h1>${paymentCopy}</div><div class="desk-payment-grid"><button type="button" data-payment="paid_card">${icon('card')}<strong>CARD</strong></button><button type="button" data-payment="paid_cash">${icon('cash')}<strong>CASH</strong></button><button type="button" data-payment="paid_check">${icon('check')}<strong>CHECK</strong></button><button type="button" data-payment="unpaid" class="desk-unpaid-choice">${icon('help')}<strong>UNPAID</strong></button></div><div id="desk-payment-message"></div><button type="button" class="desk-primary desk-wide" id="desk-complete-registration" ${state.wizard.payment ? '' : 'disabled'}>COMPLETE REGISTRATION &amp; CHECK IN TODAY ${icon('arrow')}</button>`, () => showWalkInStep('handoff'));
		main().querySelectorAll('[data-payment]').forEach((button) => button.addEventListener('click', () => selectWizardPayment(button.dataset.payment)));
		main().querySelector('#desk-complete-registration').addEventListener('click', () => {
			if (state.wizard.payment === 'unpaid') return showUnpaidWarning();
			saveManual(state.wizard.payment, false);
		});
	}

	function selectWizardPayment(payment) {
		state.wizard.payment = payment;
		persistDraft('walk-in');
		main().querySelectorAll('[data-payment]').forEach((button) => button.classList.toggle('is-selected', button.dataset.payment === payment));
		main().querySelector('#desk-complete-registration').disabled = false;
		main().querySelector('#desk-payment-message').innerHTML = payment === 'unpaid' ? notice('This registration will be checked in without a recorded payment.', 'warning') : notice(`${payment.replace('paid_', '').toUpperCase()} selected. Complete the registration when ready.`, 'success');
	}

	function showUnpaidWarning() {
		const message = main().querySelector('#desk-payment-message');
		message.innerHTML = '<div class="desk-confirm-card"><h2>CONTINUE WITHOUT A RECORDED PAYMENT?</h2><p>This registration will be saved and checked in as unpaid.</p><div class="desk-actions"><button type="button" class="desk-warning-button" id="desk-continue-unpaid">CONTINUE UNPAID</button><button type="button" class="desk-secondary" id="desk-cancel-unpaid">GO BACK</button></div></div>';
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
		if (state.finalizing) return;
		state.finalizing = true;
		if (!state.pendingPayload) state.pendingPayload = buildManualPayload();
		if (!state.pendingRequest) state.pendingRequest = uuid();
		state.pendingPayment = payment;
		state.pendingPayload.duplicate_acknowledged = acknowledge;
		persistPending();
		const payload = {...state.pendingPayload, payment_assertion: payment, duplicate_acknowledged: acknowledge};
		const target = administrator ? '/registrations/complimentary' : '/registrations/walk-in';
		const message = main().querySelector('#desk-payment-message') || main().querySelector('#desk-kind-message');
		const pendingOption = optionFor(state.pendingPayload.option_uuid);
		main().querySelectorAll('button').forEach((button) => { button.disabled = true; });
		try {
			if (isTraining()) {
				const attendees = [{name: `${state.pendingPayload.first_name} ${state.pendingPayload.last_name}`.trim()}, ...(state.pendingPayload.additional_attendees || []).map((attendee) => ({name: `${attendee.first_name || ''} ${attendee.last_name || ''}`.trim()}))];
				const trainingPayload = {
					option_uuid: state.pendingPayload.option_uuid,
					offering_fingerprint: state.pendingPayload.offering_fingerprint,
					contact_name: `${state.pendingPayload.first_name} ${state.pendingPayload.last_name}`.trim(),
					email: state.pendingPayload.email,
					phone: state.pendingPayload.phone,
					attendees,
					payment_assertion: payment,
					expected_record_version: Number(state.station.record_version),
				};
				const response = await api('/training/walk-in', {method: 'POST', body: JSON.stringify(trainingPayload)}, state.pendingRequest);
				state.station.record_version = Number(response.record_version);
				saveStation(state.station);
				const result = response.result || {};
				const name = trainingPayload.contact_name;
				resetPending();
				showSuccess({kind: 'walk-in', name, count: result.attendee_uuids?.length || attendees.length, type: pendingOption.label || registrationType(pendingOption), payment});
				return;
			}
			const result = await api(target, {method: 'POST', body: JSON.stringify(payload)}, state.pendingRequest);
			const count = result.historical_result?.attendance?.length || 1;
			const attendance = result.historical_result?.attendance?.[0] || result.current_attendance?.[0];
			const name = `${state.pendingPayload.first_name} ${state.pendingPayload.last_name}`.trim();
			const option = pendingOption;
			const outcome = result.historical_result?.result || '';
			resetPending();
			if (outcome === 'rsvp_waitlisted') showWaitlistSuccess(name);
			else showSuccess({kind: option.kind === 'rsvp' ? 'rsvp' : administrator ? 'manager' : 'walk-in', name, count, type: option.kind === 'rsvp' ? 'Event RSVP' : registrationType(option), when: attendance?.checked_in_at_utc || '', payment});
		} catch (error) {
			state.finalizing = false;
			if (error.code === 'oras_desk_possible_duplicate') {
				main().querySelectorAll('button').forEach((button) => { button.disabled = false; });
				const candidates = Array.isArray(error.data?.candidates) ? error.data.candidates : [];
				message.innerHTML = `<div class="desk-confirm-card desk-duplicate-card"><h2>POSSIBLE MATCH FOUND</h2><p>Another registration uses the same email or phone. Nothing will be merged.</p>${candidates.map((candidate) => `<div class="desk-duplicate-name"><strong>${escapeHtml(candidate.contact_name || 'Existing registration')}</strong><span>${escapeHtml(sourceLabel(candidate.source_type))}</span></div>`).join('')}<p><strong>If payment was handled in AlfaPOS, do not collect it again.</strong></p><div class="desk-actions"><button type="button" id="desk-continue-duplicate">KEEP SEPARATE AND RETRY</button><button type="button" class="desk-secondary" id="desk-duplicate-manager">ASK A MANAGER</button></div></div>`;
				message.querySelector('#desk-continue-duplicate').addEventListener('click', () => saveManual(payment, administrator, true));
				message.querySelector('#desk-duplicate-manager').addEventListener('click', () => { message.innerHTML = notice('Please ask a manager to review the possible match. Keep this screen open.', 'warning'); });
			} else if (pendingOption.kind === 'rsvp') {
				main().querySelectorAll('button').forEach((button) => { button.disabled = false; });
				showRsvpRefusal(message, error);
			} else if (!administrator) {
				state.failureCount += 1;
				persistPending();
				showPaymentRecovery(error, payment, acknowledge);
			} else {
				main().querySelectorAll('button').forEach((button) => { button.disabled = false; });
				message.innerHTML = `${notice(friendlyError(error), 'error')}<button type="button" id="desk-save-retry">RETRY</button>`;
				message.querySelector('#desk-save-retry').addEventListener('click', () => saveManual(payment, administrator, acknowledge));
			}
		}
	}

	function showRsvpRefusal(container, error) {
		resetPending();
		const full = error.code === 'oras_desk_rsvp_full';
		container.innerHTML = `<div class="desk-confirm-card desk-rsvp-refusal"><span class="desk-large-icon">${icon('help')}</span><h2>${full ? 'EVENT IS FULL' : 'RSVP NOT AVAILABLE'}</h2><p>${escapeHtml(friendlyError(error))}</p><p><strong>This person was not registered or checked in.</strong></p><div class="desk-actions"><button type="button" class="desk-secondary" id="desk-rsvp-review">REVIEW CURRENT OPTIONS</button><button type="button" class="desk-secondary" id="desk-rsvp-home">RETURN HOME</button></div></div>`;
		container.querySelector('#desk-rsvp-review').addEventListener('click', () => startWalkInWizard(false));
		container.querySelector('#desk-rsvp-home').addEventListener('click', () => showHome());
	}

	function showWaitlistSuccess(name) {
		resetViewport();
		state.view = 'success';
		state.wizard = null;
		main().innerHTML = `<section class="desk-success-screen desk-waitlist-screen"><span class="desk-large-icon">${icon('calendar')}</span><p class="desk-eyebrow">RSVP waitlist</p><h1>ADDED TO WAITLIST</h1><p class="desk-success-name">${escapeHtml(name)}</p><p><strong>⚠ This person does not have a confirmed spot yet.</strong></p><p>The event is currently full. Their accountless RSVP was saved to the waitlist and they were not checked in.</p><div class="desk-success-actions"><button type="button" class="desk-primary" id="desk-success-home">DONE — RETURN HOME</button><button type="button" class="desk-secondary" id="desk-success-another">REGISTER ANOTHER</button></div></section>`;
		main().querySelector('#desk-success-home').addEventListener('click', () => showHome());
		main().querySelector('#desk-success-another').addEventListener('click', () => startWalkInWizard(false));
		focusMain();
	}

	function showPaymentRecovery(error, payment, acknowledge) {
		state.view = 'recovery';
		if (error.code === 'network_error') {
			return showConnectionLost(main(), () => saveManual(payment, false, acknowledge), payment !== 'unpaid');
		}
		const repeated = state.failureCount >= 2;
		main().innerHTML = `<section class="desk-centered desk-finalization-recovery"><div class="desk-recovery-card"><span class="desk-large-icon">${icon('help')}</span><h2>${repeated ? 'WE STILL COULDN’T SAVE THIS REGISTRATION.' : 'WE COULDN’T SAVE THIS REGISTRATION YET.'}</h2><p>Your information ${repeated ? 'is safe' : 'has not been lost'}.</p>${payment !== 'unpaid' ? '<p><strong>PAYMENT WAS ALREADY HANDLED.<br>DO NOT CHARGE THIS PERSON AGAIN.</strong></p>' : ''}${repeated ? '<p>Please ask a manager for help.</p>' : ''}<div class="desk-actions"><button type="button" id="desk-payment-retry">TRY AGAIN</button><button type="button" class="desk-secondary" id="desk-payment-manager">MANAGER HELP</button>${repeated ? '<button type="button" class="desk-danger" id="desk-payment-abandon">RETURN HOME ONLY AFTER CONFIRMATION</button>' : ''}</div></div></section>`;
		main().querySelector('#desk-payment-retry').addEventListener('click', () => saveManual(payment, false, acknowledge));
		main().querySelector('#desk-payment-manager').addEventListener('click', () => showManagerHelp());
		main().querySelector('#desk-payment-abandon')?.addEventListener('click', () => {
			if (window.confirm('Return home and discard this unsaved registration? Payment may already have been handled.')) {
				state.wizard = null;
				resetPending();
				showHome();
			}
		});
		focusMain();
	}

	function showRestoredFailure() {
		showPaymentRecovery(new DeskError(''), state.pendingPayment, Boolean(state.pendingPayload?.duplicate_acknowledged));
	}

	function showSuccess(details) {
		resetViewport();
		state.view = 'success';
		const isWalkIn = details.kind === 'walk-in' || details.kind === 'manager';
		const isRsvp = details.kind === 'rsvp';
		const time = formatLocalTime(details.when);
		const payment = ({paid_card: 'Paid by card recorded', paid_cash: 'Paid by cash recorded', paid_check: 'Paid by check recorded', unpaid: 'Unpaid recorded', complimentary: 'Manager registration'}[details.payment] || '');
		state.wizard = null;
		main().innerHTML = `<section class="desk-success-screen"><span class="desk-success-check">${icon('check')}</span><p class="desk-eyebrow">All set</p><h1>${isRsvp ? 'RSVP CONFIRMED' : isWalkIn ? 'REGISTRATION COMPLETE' : 'CHECK-IN COMPLETE'}</h1><p class="desk-success-name">${escapeHtml(details.name)}</p><div class="desk-success-statements">${isWalkIn ? '<p>✓ Registered</p>' : isRsvp ? '<p>✓ RSVP recorded</p>' : ''}<p>✓ Checked in today</p></div><p>${isWalkIn ? 'The registration was saved and ' : ''}${Number(details.count || 1)} ${Number(details.count || 1) === 1 ? 'person was' : 'people were'} checked in for today.</p><div class="desk-success-summary"><span><strong>Registration</strong>${escapeHtml(details.type || '')}</span><span><strong>Event</strong>${escapeHtml(state.station.event_title)}</span><span><strong>Checked in</strong>${escapeHtml(state.station.friendly_date || formatDateValue(state.station.local_date))}${time ? ` at ${escapeHtml(time)}` : ''}</span>${payment ? `<span><strong>Statement</strong>${escapeHtml(payment)}</span>` : ''}<span><strong>Volunteer</strong>${escapeHtml(state.station.operator_label)}</span></div><div class="desk-success-actions"><button type="button" class="desk-primary" id="desk-success-home">DONE — RETURN HOME</button><button type="button" class="desk-secondary" id="desk-success-another">${isWalkIn || isRsvp ? 'REGISTER ANOTHER' : 'FIND ANOTHER REGISTRATION'}</button></div></section>`;
		main().querySelector('#desk-success-home').addEventListener('click', () => showHome());
		main().querySelector('#desk-success-another').addEventListener('click', () => isWalkIn || isRsvp ? startWalkInWizard(false) : showEventRoster(true));
		focusMain();
	}

	async function showManagerArea(messageText = '') {
		resetViewport();
		state.view = 'manager';
		if (!state.station.manager_token) return showManagerHelp();
		if (isTraining()) return showTrainingManagerArea(messageText);
		main().innerHTML = '<div class="desk-loading">Opening manager tools…</div>';
		try {
			const data = await api('/dashboard');
			const summary = data.summary || {};
			main().innerHTML = `${screenActions('Back to Home', false)}<section class="desk-manager-area"><div class="desk-wizard-heading"><h1>MANAGER TOOLS</h1><p>Corrections and recovery actions are audited.</p></div>${messageText ? notice(messageText, 'success') : ''}<div id="desk-sync-message"></div>${state.pendingPayload ? '<div class="desk-recovery-card"><h2>UNSAVED REGISTRATION NEEDS HELP</h2><p>The original request and payment warning are still available.</p><button type="button" id="desk-resume-failed">RESUME SAME REQUEST</button></div>' : ''}<div class="desk-manager-grid"><button type="button" class="desk-manager-card desk-training-start" id="desk-start-training">${icon('calendar')}<strong>START TRAINING MODE</strong><span>Practice safely with isolated synthetic event data.</span></button><button type="button" class="desk-manager-card" id="desk-manager-recovery">${icon('search')}<strong>FIND MISSING REGISTRATION</strong><span>Search and synchronize a paid website registration.</span></button><button type="button" class="desk-manager-card" id="desk-manager-comp">${icon('person')}<strong>COMPLIMENTARY / SPEAKER</strong><span>Create an approved nonfinancial registration.</span></button><button type="button" class="desk-manager-card" id="desk-manager-pending">${icon('calendar')}<strong>PENDING MEMBERSHIP ACTIVATIONS</strong><span>Correct, resend, copy, or cancel unused credits.</span></button><button type="button" class="desk-manager-card" id="desk-sync-registrations">${icon('search')}<strong>SYNC WEBSITE REGISTRATIONS</strong><span>Refresh website registration search.</span></button></div><section class="desk-manager-summary"><div><strong>${Number(summary.checked_in_today || 0)}</strong><span>Checked in today</span></div><div><strong>${Number(summary.active_registrations || 0)}</strong><span>Active registrations</span></div><div><strong>${Number(summary.reversed_today || 0)}</strong><span>Reversals today</span></div></section>${data.recent?.length ? `<section class="desk-recent"><h2>Recent operational activity</h2><div class="desk-recent-list">${renderRecent(data.recent.slice(0, 8))}</div></section>` : ''}</section>`;
			bindScreenActions(requestHome);
			main().querySelector('#desk-start-training').addEventListener('click', showTrainingSetup);
			main().querySelector('#desk-manager-comp').addEventListener('click', () => startWalkInWizard(true));
			main().querySelector('#desk-manager-recovery').addEventListener('click', showMissingRegistration);
			main().querySelector('#desk-manager-pending').addEventListener('click', () => showPendingMemberships());
			main().querySelector('#desk-resume-failed')?.addEventListener('click', () => {
				if (!state.wizard) return;
				showRestoredFailure();
			});
			main().querySelector('#desk-sync-registrations').addEventListener('click', syncWebsiteRegistrations);
			focusMain();
		} catch (error) {
			main().innerHTML = `${screenActions('Back to Home', false)}${notice(friendlyError(error), 'error')}`;
			bindScreenActions(requestHome);
		}
	}

	async function showTrainingSetup() {
		main().innerHTML = '<div class="desk-loading">Loading events for Training Mode…</div>';
		try {
			const data = await api('/events');
			state.events = Array.isArray(data.items) ? data.items : [];
			main().innerHTML = `${screenActions('Back to Manager Tools', false)}<section class="desk-kiosk-panel"><p class="desk-eyebrow">Manager only</p><h1>START TRAINING MODE</h1><p>Choose the event volunteers will practice with.</p><div class="desk-event-list">${state.events.map((item) => `<button type="button" class="desk-event-card" data-training-event="${Number(item.event_id)}"><strong>${escapeHtml(item.title)}</strong><span>${escapeHtml(item.friendly_date)}</span></button>`).join('')}</div></section>`;
			bindScreenActions(showManagerArea);
			main().querySelectorAll('[data-training-event]').forEach((button) => button.addEventListener('click', () => showTrainingDateChoice(state.events.find((item) => Number(item.event_id) === Number(button.dataset.trainingEvent)))));
		} catch (error) {
			main().innerHTML = `${screenActions('Back to Manager Tools', false)}${notice(friendlyError(error), 'error')}`;
			bindScreenActions(showManagerArea);
		}
	}

	function showTrainingDateChoice(event) {
		if (!event) return showTrainingSetup();
		main().innerHTML = `${screenActions('Back to Event Choice', false)}<section class="desk-kiosk-panel"><p class="desk-eyebrow">Manager only</p><h1>CHOOSE TRAINING DATE</h1><p>${escapeHtml(event.title)} · ${escapeHtml(event.friendly_date)}</p><form id="desk-training-date-choice" class="desk-form"><div class="desk-field"><label>Simulated event date</label><input name="simulated_local_date" type="date" min="${escapeHtml(event.start_date)}" max="${escapeHtml(event.end_date)}" value="${escapeHtml(event.start_date)}" required></div><button type="submit" class="desk-primary desk-wide">REVIEW TRAINING MODE</button></form></section>`;
		bindScreenActions(showTrainingSetup);
		main().querySelector('#desk-training-date-choice').addEventListener('submit', (submitEvent) => {
			submitEvent.preventDefault();
			showTrainingStartConfirmation(event, String(new FormData(submitEvent.currentTarget).get('simulated_local_date')));
		});
	}

	function showTrainingStartConfirmation(event, date) {
		main().innerHTML = `${screenActions('Back to Training Date', false)}<section class="desk-centered desk-confirm-card"><h1>START TRAINING MODE?</h1><dl><dt>Event</dt><dd>${escapeHtml(event.title)}</dd><dt>Training date</dt><dd>${escapeHtml(formatDateValue(date))}</dd></dl><p>No live registrations, attendance, payments, memberships, or reports will be changed.</p><div class="desk-actions"><button type="button" class="desk-primary" id="desk-confirm-start-training">START TRAINING</button><button type="button" class="desk-secondary" id="desk-cancel-start-training">CANCEL</button></div></section>`;
		bindScreenActions(() => showTrainingDateChoice(event));
		main().querySelector('#desk-confirm-start-training').addEventListener('click', () => startTrainingMode(event, date));
		main().querySelector('#desk-cancel-start-training').addEventListener('click', showManagerArea);
	}

	async function startTrainingMode(event, date) {
		main().innerHTML = '<div class="desk-loading">Starting isolated Training Mode…</div>';
		const liveStation = state.station;
		try {
			const data = await api('/training/start', {method: 'POST', body: JSON.stringify({event_id: Number(event.event_id), simulated_local_date: date, confirmed: true})});
			const station = {...liveStation, ...data};
			delete station.manager_token;
			saveStation(station);
			resetPending();
			renderShell();
			showHome();
		} catch (error) {
			showTrainingFailure(error, () => startTrainingMode(event, date));
		}
	}

	function showTrainingManagerArea(messageText = '') {
		resetViewport();
		main().innerHTML = `${screenActions('Back to Home', false)}<section class="desk-manager-area desk-training-manager"><div class="desk-wizard-heading"><p class="desk-eyebrow">TRAINING</p><h1>TRAINING MANAGER TOOLS</h1><p>These actions affect only this station’s isolated training dataset.</p></div>${messageText ? notice(messageText, 'success') : ''}<div class="desk-manager-grid"><button type="button" class="desk-manager-card" id="desk-change-training-date">${icon('calendar')}<strong>CHANGE TRAINING DATE</strong><span>Preserve registrations and attendance history; start a fresh simulated day.</span></button><button type="button" class="desk-manager-card" id="desk-reset-training">${icon('person')}<strong>RESET TRAINING DATA</strong><span>Clear training activity and refresh the complete current event roster.</span></button><button type="button" class="desk-manager-card desk-danger" id="desk-end-training">${icon('back')}<strong>END TRAINING MODE</strong><span>Close this training session and return to the live desk setup.</span></button></div></section>`;
		bindScreenActions(showHome);
		main().querySelector('#desk-change-training-date').addEventListener('click', showChangeTrainingDate);
		main().querySelector('#desk-reset-training').addEventListener('click', confirmResetTraining);
		main().querySelector('#desk-end-training').addEventListener('click', confirmEndTraining);
		focusMain();
	}

	function showChangeTrainingDate() {
		main().innerHTML = `${screenActions('Back to Training Manager Tools', false)}<section class="desk-kiosk-panel"><h1>CHANGE TRAINING DATE</h1><p>Existing training registrations and prior-date attendance history will be preserved.</p><form id="desk-change-training-date-form" class="desk-form"><div class="desk-field"><label>New simulated event date</label><input name="simulated_local_date" type="date" min="${escapeHtml(state.station.event_start_date)}" max="${escapeHtml(state.station.event_end_date)}" value="${escapeHtml(state.station.simulated_local_date)}" required></div><button type="submit" class="desk-primary desk-wide">CHANGE TRAINING DATE</button></form></section>`;
		bindScreenActions(showTrainingManagerArea);
		main().querySelector('#desk-change-training-date-form').addEventListener('submit', async (event) => {
			event.preventDefault();
			const date = String(new FormData(event.currentTarget).get('simulated_local_date'));
			try {
				const managerToken = state.station.manager_token;
				const data = await api('/training/date', {method: 'POST', body: JSON.stringify({simulated_local_date: date, expected_record_version: Number(state.station.record_version), confirmed: true})});
				saveStation({...state.station, ...data, manager_token: managerToken});
				renderShell();
				showHome('Training date changed. Prior-day check-ins remain in training history.');
			} catch (error) {
				showTrainingFailure(error, showChangeTrainingDate);
			}
		});
	}

	function confirmResetTraining() {
		main().innerHTML = `${screenActions('Back to Training Manager Tools', false)}<section class="desk-centered desk-confirm-card"><h1>RESET TRAINING DATA?</h1><p>This deletes only practice registrations and check-ins for this training station. Live event data will not be changed.</p><div class="desk-actions"><button type="button" class="desk-danger" id="desk-confirm-reset-training">RESET TRAINING</button><button type="button" class="desk-secondary" id="desk-cancel-reset-training">CANCEL</button></div></section>`;
		bindScreenActions(showTrainingManagerArea);
		main().querySelector('#desk-confirm-reset-training').addEventListener('click', resetTrainingData);
		main().querySelector('#desk-cancel-reset-training').addEventListener('click', showTrainingManagerArea);
	}

	async function resetTrainingData() {
		try {
			const data = await api('/training/reset', {method: 'POST', body: JSON.stringify({expected_record_version: Number(state.station.record_version), confirmed: true})});
			const managerToken = state.station.manager_token;
			saveStation({...state.station, ...data, manager_token: managerToken});
			resetPending();
			showTrainingManagerArea('Training data reset and the current event roster was refreshed.');
		} catch (error) {
			showTrainingFailure(error, resetTrainingData);
		}
	}

	function confirmEndTraining() {
		main().innerHTML = `${screenActions('Back to Training Manager Tools', false)}<section class="desk-centered desk-confirm-card"><h1>END TRAINING MODE?</h1><p>You are returning to the LIVE Registration Desk.</p><div class="desk-actions"><button type="button" class="desk-danger" id="desk-confirm-end-training">END TRAINING</button><button type="button" class="desk-secondary" id="desk-cancel-end-training">CANCEL</button></div></section>`;
		bindScreenActions(showTrainingManagerArea);
		main().querySelector('#desk-confirm-end-training').addEventListener('click', endTrainingMode);
		main().querySelector('#desk-cancel-end-training').addEventListener('click', showTrainingManagerArea);
	}

	async function endTrainingMode() {
		const operator = state.station.operator_label;
		try {
			await api('/training/end', {method: 'POST', body: JSON.stringify({confirmed: true})});
			clearStation();
			state.operatorLabel = operator;
			const data = await api('/events');
			state.events = Array.isArray(data.items) ? data.items : [];
			renderEventPicker('Training Mode ended. Choose the live event for this station.');
		} catch (error) {
			showTrainingFailure(error, endTrainingMode);
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

	async function startMembershipWizard(reset = true) {
		resetViewport();
		state.view = 'membership';
		if (reset) {
			resetPending();
			state.membershipWizard = {step: 'details', request_uuid: uuid(), data: {first_name: '', last_name: '', email: '', phone: '', level_id: ''}, payment_method: '', payment_handled: false};
		}
		main().innerHTML = '<section class="desk-centered"><h1>LOADING CURRENT MEMBERSHIP OPTIONS…</h1></section>';
		try {
			const response = isTraining() ? await api('/training/membership-offerings') : await api('/membership-offerings');
			state.station.membership_levels = Array.isArray(response.items) ? response.items : [];
			saveStation(state.station);
			showMembershipStep(state.membershipWizard?.step || 'details');
		} catch (error) {
			if (error.code === 'network_error') return showConnectionLost(main(), () => startMembershipWizard(false), Boolean(state.membershipWizard?.payment_handled));
			main().innerHTML = `${screenActions('Back to Membership', false)}${notice(friendlyError(error), 'error')}`;
			bindScreenActions(showMembershipMenu);
		}
	}

	function selectedMembershipLevel() {
		return (state.station.membership_levels || []).find((level) => Number(level.level_id) === Number(state.membershipWizard?.data.level_id)) || {};
	}

	function membershipProgress(step) {
		const steps = ['details', 'payment', 'handoff', 'review'];
		const current = Math.max(0, steps.indexOf(step));
		return `<div class="desk-progress" aria-label="Step ${current + 1} of ${steps.length}"><strong>STEP ${current + 1} OF ${steps.length}</strong><div class="desk-progress-track">${steps.map((item, index) => `<span class="${index <= current ? 'is-complete' : ''}"></span>`).join('')}</div></div>`;
	}

	function showMembershipStep(step) {
		if (!state.membershipWizard) return startMembershipWizard(true);
		state.membershipWizard.step = step;
		persistDraft('membership');
		if (step === 'details') return showMembershipDetails();
		if (step === 'payment') return showMembershipPayment();
		if (step === 'handoff') return showMembershipHandoff();
		return showMembershipReview();
	}

	function membershipFrame(content, backHandler) {
		main().innerHTML = `${screenActions('Back')}<section class="desk-wizard">${membershipProgress(state.membershipWizard.step)}${content}</section>`;
		bindScreenActions(backHandler);
		focusMain();
	}

	function showMembershipDetails() {
		const data = state.membershipWizard.data;
		const levels = state.station.membership_levels || [];
		membershipFrame(`<div class="desk-wizard-heading"><p class="desk-eyebrow">Membership paid in AlfaPOS</p><h1>RECORD MEMBERSHIP PAYMENT</h1><p>Enter the member's information, then choose a current membership level.</p></div>${levels.length ? `<form id="desk-membership-details" class="desk-form desk-large-form"><div class="desk-fields"><div class="desk-field"><label>First name</label><input name="first_name" autocomplete="given-name" value="${escapeHtml(data.first_name)}" required></div><div class="desk-field"><label>Last name</label><input name="last_name" autocomplete="family-name" value="${escapeHtml(data.last_name)}" required></div><div class="desk-field"><label>Email</label><input name="email" type="email" autocomplete="email" value="${escapeHtml(data.email)}" required></div><div class="desk-field"><label>Phone</label><input name="phone" type="tel" autocomplete="tel" value="${escapeHtml(data.phone)}"></div></div><fieldset class="desk-membership-levels"><legend>CHOOSE MEMBERSHIP</legend>${levels.map((level) => `<label class="desk-membership-level ${Number(data.level_id) === Number(level.level_id) ? 'is-selected' : ''}"><input type="radio" name="level_id" value="${Number(level.level_id)}" ${Number(data.level_id) === Number(level.level_id) ? 'checked' : ''} required><span><strong>${escapeHtml(level.display_name)}</strong><small>$${Number(level.price || 0).toFixed(2)} · ${escapeHtml(level.period_label || 'Membership')}</small></span></label>`).join('')}</fieldset><button type="submit" class="desk-primary desk-wide">CONTINUE ${icon('arrow')}</button></form>` : notice('No membership levels are enabled for event sales. Please ask a manager for help.', 'warning')}`, requestHome);
		const form = main().querySelector('#desk-membership-details');
		form?.addEventListener('input', () => {
			Object.assign(state.membershipWizard.data, Object.fromEntries(new FormData(form).entries()));
			persistDraft('membership');
			form.querySelectorAll('.desk-membership-level').forEach((choice) => choice.classList.toggle('is-selected', choice.querySelector('input').checked));
		});
		form?.addEventListener('submit', (event) => {
			event.preventDefault();
			Object.assign(state.membershipWizard.data, Object.fromEntries(new FormData(form).entries()));
			showMembershipStep('payment');
		});
	}

	function showMembershipPayment() {
		membershipFrame(`<div class="desk-wizard-heading"><p class="desk-eyebrow">Membership payment</p><h1>HOW DID THEY PAY?</h1><p>Choose the payment method completed in AlfaPOS.</p></div><div class="desk-membership-payment-grid"><button type="button" data-membership-payment="card" class="desk-membership-payment-choice ${state.membershipWizard.payment_method === 'card' ? 'is-selected' : ''}">${icon('card')}<strong>${state.membershipWizard.payment_method === 'card' ? '✓ ' : ''}CARD</strong></button><button type="button" data-membership-payment="cash" class="desk-membership-payment-choice ${state.membershipWizard.payment_method === 'cash' ? 'is-selected' : ''}">${icon('cash')}<strong>${state.membershipWizard.payment_method === 'cash' ? '✓ ' : ''}CASH</strong></button><button type="button" data-membership-payment="check" class="desk-membership-payment-choice ${state.membershipWizard.payment_method === 'check' ? 'is-selected' : ''}">${icon('check')}<strong>${state.membershipWizard.payment_method === 'check' ? '✓ ' : ''}CHECK</strong></button></div><button type="button" class="desk-primary desk-wide" id="desk-membership-payment-next" ${state.membershipWizard.payment_method ? '' : 'disabled'}>CONTINUE ${icon('arrow')}</button>`, () => showMembershipStep('details'));
		main().querySelectorAll('[data-membership-payment]').forEach((button) => button.addEventListener('click', () => {
			state.membershipWizard.payment_method = button.dataset.membershipPayment;
			persistDraft('membership');
			showMembershipStep('payment');
		}));
		main().querySelector('#desk-membership-payment-next').addEventListener('click', () => showMembershipStep('handoff'));
	}

	function showMembershipHandoff() {
		const content = `<div class="desk-wizard-heading"><p class="desk-eyebrow">Use AlfaPOS now</p><h1>RECORD THE PAYMENT IN ALFAPOS</h1><p>${state.membershipWizard.payment_method === 'card' ? 'Confirm the card transaction is complete in AlfaPOS.' : 'Put the cash or check in the event payment bag.'}</p><p>Complete this membership payment in AlfaPOS using the same method. The desk does not charge the card.</p><p>Return here when AlfaPOS is finished.</p></div><button type="button" class="desk-primary desk-wide" id="desk-membership-handoff-done">PAYMENT RECORDED IN ALFAPOS ${icon('arrow')}</button>`;
		membershipFrame(content, () => showMembershipStep('payment'));
		main().querySelector('#desk-membership-handoff-done').addEventListener('click', () => {
			state.membershipWizard.payment_handled = true;
			showMembershipStep('review');
		});
	}

	function showMembershipReview() {
		const data = state.membershipWizard.data;
		const level = selectedMembershipLevel();
		membershipFrame(`<div class="desk-wizard-heading"><h1>REVIEW MEMBERSHIP</h1><p><strong>PAYMENT WAS ALREADY HANDLED.<br>DO NOT CHARGE THIS PERSON AGAIN.</strong></p></div><section class="desk-review-card"><dl><dt>Name</dt><dd>${escapeHtml(data.first_name)} ${escapeHtml(data.last_name)}</dd><dt>Email</dt><dd>${escapeHtml(data.email)}</dd><dt>Phone</dt><dd>${escapeHtml(data.phone || 'Not provided')}</dd><dt>Membership</dt><dd>${escapeHtml(level.display_name || 'Membership')}<small>$${Number(level.price || 0).toFixed(2)} · ${escapeHtml(level.period_label || '')}</small></dd><dt>Payment</dt><dd>${escapeHtml(state.membershipWizard.payment_method.toUpperCase())} — recorded in AlfaPOS</dd></dl></section><div id="desk-membership-message"></div><button type="button" class="desk-primary desk-wide" id="desk-membership-submit">RECORD MEMBERSHIP &amp; SEND EMAIL</button>`, () => showMembershipStep('handoff'));
		main().querySelector('#desk-membership-submit').addEventListener('click', recordMembership);
	}

	async function recordMembership() {
		const button = main().querySelector('#desk-membership-submit');
		if (button) button.disabled = true;
		const payload = {...state.membershipWizard.data, payment_method: state.membershipWizard.payment_method};
		persistDraft('membership');
		try {
			if (isTraining()) {
				const trainingPayload = {
					level_id: Number(payload.level_id),
					contact_name: `${payload.first_name} ${payload.last_name}`.trim(),
					email: payload.email,
					payment_method: payload.payment_method,
					expected_record_version: Number(state.station.record_version),
				};
				const response = await api('/training/memberships', {method: 'POST', body: JSON.stringify(trainingPayload)}, state.membershipWizard.request_uuid);
				state.station.record_version = Number(response.record_version);
				saveStation(state.station);
				resetPending();
				showMembershipComplete({...response.result, first_name: payload.first_name, last_name: payload.last_name});
				return;
			}
			const result = await api('/memberships', {method: 'POST', body: JSON.stringify(payload)}, state.membershipWizard.request_uuid);
			resetPending();
			showMembershipComplete(result);
		} catch (error) {
			const activation = error.data?.activation;
			if (activation) {
				resetPending();
				return showMembershipComplete(activation, true);
			}
			if (error.code === 'network_error') return showConnectionLost(main(), () => { showMembershipStep('review'); recordMembership(); }, true);
			if (button) button.disabled = false;
			main().querySelector('#desk-membership-message').innerHTML = notice(friendlyError(error), 'error');
		}
	}

	function showMembershipComplete(record, emailFailed = false) {
		resetViewport();
		state.view = 'success';
		main().innerHTML = `<section class="desk-success-screen"><span class="desk-success-check">${icon('check')}</span><h1>MEMBERSHIP RECORDED</h1><p class="desk-success-name">${escapeHtml(record.first_name)} ${escapeHtml(record.last_name)}</p><div class="desk-success-statements"><p>✓ Membership payment recorded</p><p>${emailFailed ? '⚠ Activation email needs manager help' : '✓ Activation email sent'}</p></div><div class="desk-success-summary"><span><strong>Membership</strong>${escapeHtml(record.level_name)}</span><span><strong>Status</strong>PENDING ONLINE ACTIVATION</span></div>${emailFailed ? notice('The membership is safely recorded, but the email could not be sent. Ask a manager to recover the existing activation.', 'warning') : ''}<div class="desk-success-actions"><button type="button" class="desk-primary" id="desk-membership-done">DONE — RETURN HOME</button>${emailFailed ? '<button type="button" class="desk-secondary" id="desk-membership-manager">MANAGER HELP</button>' : ''}</div></section>`;
		main().querySelector('#desk-membership-done').addEventListener('click', () => showHome());
		main().querySelector('#desk-membership-manager')?.addEventListener('click', () => state.station.manager_token ? showPendingMemberships() : showManagerHelp());
		focusMain();
	}

	async function showPendingMemberships(messageText = '') {
		main().innerHTML = '<div class="desk-loading">Loading membership activations…</div>';
		try {
			const data = await api('/memberships');
			main().innerHTML = `${screenActions('Back to Manager', false)}<section class="desk-kiosk-panel"><h1>PENDING MEMBERSHIP ACTIVATIONS</h1>${messageText ? notice(messageText, 'success') : ''}<div id="desk-pending-membership-message"></div><div class="desk-results">${data.items.length ? data.items.map((item) => `<article class="desk-result-card"><div class="desk-result-main"><strong>${escapeHtml(item.first_name)} ${escapeHtml(item.last_name)}</strong><span>${escapeHtml(item.level_name)} · ${escapeHtml(item.status)}</span><small>${escapeHtml(item.email)} · ${escapeHtml(item.phone || 'Phone not recorded')} · Email ${escapeHtml(item.email_status)}</small><code>${escapeHtml(item.credit_code)}</code></div>${item.status === 'pending' ? `<div class="desk-actions"><button type="button" class="desk-secondary" data-edit-membership="${escapeHtml(item.activation_uuid)}">EDIT CONTACT</button><button type="button" data-resend-membership="${escapeHtml(item.activation_uuid)}">RESEND EMAIL</button><button type="button" class="desk-secondary" data-copy-membership="${escapeHtml(item.credit_code)}">COPY EXISTING CODE</button><button type="button" class="desk-danger" data-cancel-membership="${escapeHtml(item.activation_uuid)}">CANCEL UNUSED CODE</button></div>` : ''}</article>`).join('') : '<p>No membership activations were recorded at this event.</p>'}</div></section>`;
			bindScreenActions(showManagerArea);
			main().querySelectorAll('[data-edit-membership]').forEach((button) => button.addEventListener('click', () => showMembershipCorrection(data.items.find((item) => item.activation_uuid === button.dataset.editMembership))));
			main().querySelectorAll('[data-resend-membership]').forEach((button) => button.addEventListener('click', async () => {
				await api(`/memberships/${button.dataset.resendMembership}/resend`, {method: 'POST', body: '{}'});
				showPendingMemberships('The existing activation email was sent again.');
			}));
			main().querySelectorAll('[data-copy-membership]').forEach((button) => button.addEventListener('click', async () => {
				await navigator.clipboard.writeText(button.dataset.copyMembership);
				main().querySelector('#desk-pending-membership-message').innerHTML = notice('The existing code was copied. No new code was created.', 'success');
			}));
			main().querySelectorAll('[data-cancel-membership]').forEach((button) => button.addEventListener('click', async () => {
				if (!window.confirm('Cancel this unused membership credit?')) return;
				await api(`/memberships/${button.dataset.cancelMembership}/cancel`, {method: 'POST', body: JSON.stringify({reason: 'Cancelled by Registration Desk manager'})});
				showPendingMemberships();
			}));
			focusMain();
		} catch (error) {
			main().innerHTML = `${screenActions('Back to Manager', false)}${notice(friendlyError(error), 'error')}`;
			bindScreenActions(showManagerArea);
		}
	}

	function showMembershipCorrection(record) {
		if (!record || record.status !== 'pending') return showPendingMemberships();
		main().innerHTML = `${screenActions('Back to Pending Activations', false)}<section class="desk-kiosk-panel"><h1>CORRECT MEMBERSHIP CONTACT</h1><p>Update contact information before the credit is used. This keeps the existing activation and code.</p><form id="desk-membership-correction" class="desk-form desk-large-form"><div class="desk-fields"><div class="desk-field"><label>First name</label><input name="first_name" value="${escapeHtml(record.first_name)}" required></div><div class="desk-field"><label>Last name</label><input name="last_name" value="${escapeHtml(record.last_name)}" required></div><div class="desk-field"><label>Email</label><input name="email" type="email" value="${escapeHtml(record.email)}" required></div><div class="desk-field"><label>Phone</label><input name="phone" type="tel" value="${escapeHtml(record.phone || '')}"></div></div><div id="desk-membership-correction-message"></div><button type="submit" class="desk-primary desk-wide">SAVE CONTACT CORRECTION</button></form></section>`;
		bindScreenActions(showPendingMemberships);
		main().querySelector('#desk-membership-correction').addEventListener('submit', async (event) => {
			event.preventDefault();
			const form = event.currentTarget;
			form.querySelector('button').disabled = true;
			try {
				await api(`/memberships/${record.activation_uuid}/correct`, {method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(form).entries()))});
				showPendingMemberships('Contact information corrected. Resend the existing activation email if needed.');
			} catch (error) {
				form.querySelector('button').disabled = false;
				form.querySelector('#desk-membership-correction-message').innerHTML = notice(friendlyError(error), 'error');
			}
		});
	}

	function renderCorrectionForm(editor) {
		const options = availableOptions(true);
		return `<form id="desk-correction-form" class="desk-form desk-manager-form"><p>Correct this desk-created registration. Attendance changes use the separate Reverse check-in action.</p><input type="hidden" name="expected_record_version" value="${Number(editor.expected_record_version)}"><div class="desk-fields"><div class="desk-field"><label>First name</label><input name="first_name" value="${escapeHtml(editor.first_name)}" required></div><div class="desk-field"><label>Last name</label><input name="last_name" value="${escapeHtml(editor.last_name)}" required></div><div class="desk-field"><label>Email</label><input name="email" type="email" value="${escapeHtml(editor.email)}" required></div><div class="desk-field"><label>Phone</label><input name="phone" type="tel" value="${escapeHtml(editor.phone)}" required></div><div class="desk-field desk-field-wide"><label>Option</label><select name="option_uuid" required>${options.map((option) => `<option value="${escapeHtml(option.option_uuid)}" ${option.option_uuid === editor.option_uuid ? 'selected' : ''}>${escapeHtml(option.label)}</option>`).join('')}</select></div><div class="desk-field"><label>One-day date</label><input name="valid_local_date" type="date" value="${escapeHtml(editor.valid_local_date)}"></div><div class="desk-field"><label>Recorded statement</label><select name="payment_assertion"><option value="paid_card" ${editor.payment_assertion === 'paid_card' ? 'selected' : ''}>Paid by card</option><option value="paid_cash" ${editor.payment_assertion === 'paid_cash' ? 'selected' : ''}>Paid by cash</option><option value="paid_check" ${editor.payment_assertion === 'paid_check' ? 'selected' : ''}>Paid by check</option><option value="unpaid" ${editor.payment_assertion === 'unpaid' ? 'selected' : ''}>Unpaid</option><option value="complimentary" ${editor.payment_assertion === 'complimentary' ? 'selected' : ''}>Complimentary / speaker</option><option value="manager_verified" ${editor.payment_assertion === 'manager_verified' ? 'selected' : ''}>Manager Verified</option></select></div></div><input type="hidden" name="address_1" value="${escapeHtml(editor.address_1)}"><input type="hidden" name="address_2" value="${escapeHtml(editor.address_2)}"><input type="hidden" name="city" value="${escapeHtml(editor.city)}"><input type="hidden" name="state" value="${escapeHtml(editor.state)}"><input type="hidden" name="postcode" value="${escapeHtml(editor.postcode)}"><button type="submit">SAVE CORRECTION</button></form>`;
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

	async function boot() {
		loadStation();
		if (!state.station?.station_token) return renderSetup();
		if (isTraining()) {
			try {
				await restoreTrainingContext();
			} catch (error) {
				renderShell();
				main().innerHTML = `<section class="desk-centered"><h1>TRAINING MODE NEEDS MANAGER HELP</h1>${notice(friendlyError(error), 'error')}<p>No live event data was changed.</p><div class="desk-actions"><button type="button" id="desk-training-reload">TRY AGAIN</button><button type="button" class="desk-secondary" id="desk-training-stale-manager">MANAGER HELP</button></div></section>`;
				main().querySelector('#desk-training-reload').addEventListener('click', () => window.location.reload());
				main().querySelector('#desk-training-stale-manager').addEventListener('click', () => state.station.manager_token ? showTrainingManagerArea() : showManagerHelp());
				return;
			}
		}
		renderShell();
		if (state.station.draft && (state.wizard || state.membershipWizard)) restoreDraft();
		else showHome();
	}

	boot();
})();
