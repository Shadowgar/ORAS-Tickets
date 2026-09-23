const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('oras-tickets/assets/registration-desk/desk.js', 'utf8');
assert(source.includes('\tboot();\n})();'));
const requests = [];
const button = () => ({
	disabled: false,
	listeners: {},
	fields: {},
	addEventListener(type, listener) { this.listeners[type] = listener; },
	click() { return this.listeners.click?.(); },
	submit() { return this.listeners.submit?.({preventDefault() {}, currentTarget: this}); },
	focus() {}, select() {}, showModal() {}, close() {},
});
const main = {
	html: '',
	controls: new Map(),
	set innerHTML(value) { this.html = value; this.controls = new Map(); },
	get innerHTML() { return this.html; },
	querySelector(selector) {
		if (!selector.startsWith('#') || !this.html.includes(`id="${selector.slice(1)}"`)) return null;
		if (!this.controls.has(selector)) this.controls.set(selector, button());
		const control = this.controls.get(selector);
		if (selector === '#desk-roster-more') this.moreButton = control;
		return control;
	},
	querySelectorAll() { return []; },
	focus() {},
	moreButton: null,
};
const station = {station_token: 'test', event_id: 1, event_title: 'Fixture Event', local_date: '2026-10-06', options: [
	{option_uuid: 'individual', classification: 'individual', validity_type: 'full_event', label: 'Individual'},
	{option_uuid: 'family', classification: 'family', validity_type: 'full_event', label: 'Family'},
]};
const context = {
	window: {
		ORASRegistrationDesk: {restUrl: 'https://example.test/wp-json/oras-tickets/v1/registration-desk', nonce: 'test'},
		location: {href: 'https://example.test/desk'},
		localStorage: {setItem() {}, getItem() { return null; }},
		crypto: {randomUUID() { return '11111111-1111-4111-8111-111111111111'; }},
		scrollTo() {},
	},
	document: {getElementById(id) { return id === 'desk-main' ? main : button(); }},
	fetch(url) { return new Promise((resolve) => requests.push({url, resolve})); },
	URL,
	URLSearchParams,
	FormData: class { constructor(form) { this.fields = form.fields; } get(name) { return this.fields[name] ?? null; } },
	Date,
	Intl,
	console,
};
vm.createContext(context);
vm.runInContext(source.replace('\tboot();\n})();', '\tglobalThis.__deskTest = {state, showEventRoster, showRegistration, loadEventRoster, refreshRoster, recordMembership, renderCorrectionForm};\n})();'), context);
const desk = context.__deskTest;
desk.state.station = station;
const reply = async (request, data) => {
	request.resolve({ok: true, json: async () => data});
	await new Promise((resolve) => setImmediate(resolve));
};
const fail = async (request) => {
	request.resolve({ok: false, json: async () => ({code: 'fixture_error', message: 'Temporary roster failure'})});
	await new Promise((resolve) => setImmediate(resolve));
};
const rosterPage = (names, nextOffset, hasMore) => ({items: names.map((name) => ({name})), next_offset: nextOffset, has_more: hasMore});
const loadFirstPage = async () => {
	const load = desk.showEventRoster(true);
	await reply(requests.at(-1), rosterPage(['First person'], 25, true));
	await load;
};
const openRegistrationAndReturn = async () => {
	const detail = desk.showRegistration('11111111-1111-4111-8111-111111111111');
	await reply(requests.at(-1), {
		registration: {registration_uuid: '11111111-1111-4111-8111-111111111111', option_uuid: 'individual', classification: 'individual', contact_name: 'First person', source_type: 'walk_in'},
		attendees: [], admission: {state: 'already_checked_in', check_in_allowed: false},
	});
	await detail;
	assert.equal(desk.state.view, 'registration', 'opening detail sets the registration view');
	main.querySelector('#desk-screen-back').click();
	assert(main.innerHTML.includes('FIND REGISTRATION'), 'Back renders the roster');
	assert.equal(desk.state.view, 'roster', 'Back restores the roster view');
};

(async () => {
	desk.state.view = 'roster';
	const old = desk.loadEventRoster(true);
	assert.equal(requests.length, 1);
	desk.state.roster.q = 'new';
	desk.refreshRoster();
	assert.equal(requests.length, 2);
	await reply(requests[1], {items: [{name: 'New result'}], next_offset: 1, has_more: true});
	await reply(requests[0], {items: [{name: 'Old result'}], next_offset: 1, has_more: true});
	await old;
	assert.equal(desk.state.roster.items.length, 1);
	assert.equal(desk.state.roster.items[0].name, 'New result');
	const firstPage = desk.loadEventRoster(false);
	const duplicateTap = desk.loadEventRoster(false);
	assert.equal(requests.length, 3, 'double tap issues one page request');
	assert.equal(main.moreButton.disabled, true, 'Show More is disabled while loading');
	await reply(requests[2], {items: [{name: 'Next result'}], next_offset: 2, has_more: false});
	await Promise.all([firstPage, duplicateTap]);
	assert.equal(desk.state.roster.items.map((item) => item.name).join('|'), 'New result|Next result');

	await loadFirstPage();
	const failedPage = desk.loadEventRoster(false);
	assert.equal(new URL(requests.at(-1).url).searchParams.get('offset'), '25');
	await fail(requests.at(-1));
	await failedPage;
	assert(main.innerHTML.includes('TRY AGAIN'), 'failed pagination offers Retry');
	const retry = main.querySelector('#desk-roster-retry').click();
	assert.equal(new URL(requests.at(-1).url).searchParams.get('offset'), '25', 'Retry requests the failed page');
	await reply(requests.at(-1), rosterPage(['Second person'], 50, true));
	await retry;
	assert.equal(desk.state.roster.items.map((item) => item.name).join('|'), 'First person|Second person', 'Retry preserves page one and appends page two');
	const thirdPage = desk.loadEventRoster(false);
	assert.equal(new URL(requests.at(-1).url).searchParams.get('offset'), '50', 'pagination advances after Retry');
	await reply(requests.at(-1), rosterPage(['Third person'], 75, false));
	await thirdPage;
	assert.equal(desk.state.roster.items.map((item) => item.name).join('|'), 'First person|Second person|Third person');

	await loadFirstPage();
	await openRegistrationAndReturn();
	const afterBackPage = desk.loadEventRoster(false);
	await reply(requests.at(-1), rosterPage(['Second person'], 50, true));
	await afterBackPage;
	assert.equal(desk.state.roster.items.map((item) => item.name).join('|'), 'First person|Second person');
	assert.equal(main.querySelector('#desk-roster-more').disabled, false, 'Show More remains usable after Back');

	await loadFirstPage();
	await openRegistrationAndReturn();
	const searchForm = main.querySelector('#desk-roster-search');
	searchForm.fields = {q: 'matching'};
	searchForm.submit();
	await reply(requests.at(-1), rosterPage(['Matching person'], 1, false));
	assert.equal(desk.state.roster.items[0].name, 'Matching person', 'search works after Back');
	assert(!main.innerHTML.includes('Updating event roster'), 'search does not remain loading');
	main.querySelector('#desk-clear-search').click();
	await reply(requests.at(-1), rosterPage(['First person'], 25, true));
	assert.equal(desk.state.roster.q, '');
	assert.equal(desk.state.roster.items[0].name, 'First person', 'Clear Search restores the roster');

	await openRegistrationAndReturn();
	const filterForm = main.querySelector('#desk-filter-form');
	filterForm.fields = {status: 'checked_in', option_uuid: ''};
	filterForm.submit();
	await reply(requests.at(-1), rosterPage(['Here today'], 1, false));
	assert.equal(desk.state.roster.items[0].name, 'Here today', 'filters work after Back');
	assert(!main.innerHTML.includes('Updating event roster'), 'filters do not remain loading');
	main.querySelector('#desk-reset-filters').click();
	await reply(requests.at(-1), rosterPage(['First person'], 25, true));
	assert.equal(desk.state.roster.status, 'everyone');
	assert.equal(desk.state.roster.items[0].name, 'First person', 'Clear Filters restores the roster');

	const staleFilter = desk.refreshRoster();
	desk.state.roster.status = 'walk_ins';
	desk.refreshRoster();
	await reply(requests.at(-1), rosterPage(['Walk-in result'], 1, false));
	await reply(requests.at(-2), rosterPage(['Obsolete filter result'], 1, false));
	await staleFilter;
	assert.equal(desk.state.roster.items[0].name, 'Walk-in result', 'old filter response cannot replace newer results');

	const firstPageFailure = desk.showEventRoster(true);
	await fail(requests.at(-1));
	await firstPageFailure;
	const firstPageRetry = main.querySelector('#desk-roster-retry').click();
	assert.equal(new URL(requests.at(-1).url).searchParams.get('offset'), '0');
	await reply(requests.at(-1), rosterPage(['First person'], 25, true));
	await firstPageRetry;
	assert.equal(desk.state.roster.items[0].name, 'First person', 'first-page Retry remains correct');

	for (const [label, emailSent] of [['new success', true], ['new failure', false], ['replay success', true], ['replay failure', false]]) {
		desk.state.membershipWizard = {request_uuid: '11111111-1111-4111-8111-111111111111', data: {first_name: 'Fixture', last_name: 'Member', email: 'member@example.test', level_id: 1}, payment_method: 'card'};
		const complete = desk.recordMembership();
		const request = requests.at(-1);
		await reply(request, {first_name: 'Fixture', last_name: 'Member', level_name: 'Membership', email_sent: emailSent, email_status: emailSent ? 'sent' : 'failed'});
		await complete;
		assert.equal(main.innerHTML.includes('Activation email sent'), emailSent, label);
		assert.equal(main.innerHTML.includes('Activation email needs manager help'), !emailSent, label);
	}
	const correction = desk.renderCorrectionForm({has_attendees: true, classification: 'individual', option_uuid: 'individual', expected_record_version: 1});
	assert(correction.includes('Registration type cannot be changed'));
	assert(!correction.includes('value="family"'));
	console.log('PASS: roster navigation, search, filters, stale responses, double pagination, pagination retry, correction choices, and four membership email states');
})().catch((error) => { console.error(error); process.exitCode = 1; });
