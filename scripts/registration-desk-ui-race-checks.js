const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('oras-tickets/assets/registration-desk/desk.js', 'utf8');
assert(source.includes('\tboot();\n})();'));
const requests = [];
const button = () => ({disabled: false, addEventListener() {}, focus() {}, select() {}, showModal() {}, close() {}});
const main = {
	innerHTML: '',
	querySelector(selector) {
		if (selector === '#desk-roster-more') return this.moreButton;
		return button();
	},
	querySelectorAll() { return []; },
	focus() {},
	moreButton: button(),
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
	Date,
	Intl,
	console,
};
vm.createContext(context);
vm.runInContext(source.replace('\tboot();\n})();', '\tglobalThis.__deskTest = {state, loadEventRoster, refreshRoster, recordMembership, renderCorrectionForm};\n})();'), context);
const desk = context.__deskTest;
desk.state.station = station;
const reply = async (request, data) => {
	request.resolve({ok: true, json: async () => data});
	await Promise.resolve();
	await Promise.resolve();
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
	console.log('PASS: stale roster responses, double pagination, correction choices, and four membership email states');
})().catch((error) => { console.error(error); process.exitCode = 1; });
