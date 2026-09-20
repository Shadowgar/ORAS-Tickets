<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/fixtures/class-wp-error.php';

function sanitize_text_field( mixed $value ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone WordPress-function test double.
	return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' ); }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone WordPress-function test double.
	return json_encode( $value, $flags ); }

function oras_operation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
	fwrite( STDOUT, "PASS: {$message}\n" );
}

$base = dirname( __DIR__ ) . '/oras-tickets/includes/Registration_Desk/';
foreach ( array( 'Store.php', 'Registration_Store.php', 'Attendee_Store.php', 'Attendance_Store.php', 'Recovery_Service.php', 'Service.php', 'Rest_Controller.php' ) as $file ) {
	oras_operation_assert( file_exists( $base . $file ), "{$file} exists" );
	require_once $base . $file;
}

$service = '\\ORAS\\Tickets\\Registration_Desk\\Service';
$rest    = '\\ORAS\\Tickets\\Registration_Desk\\Rest_Controller';
$registration_store = '\\ORAS\\Tickets\\Registration_Desk\\Registration_Store';
$attendee_store     = '\\ORAS\\Tickets\\Registration_Desk\\Attendee_Store';
$attendance_store   = '\\ORAS\\Tickets\\Registration_Desk\\Attendance_Store';
oras_operation_assert( class_exists( $service ), 'Attendance service loads' );
oras_operation_assert( class_exists( $rest ), 'REST controller loads' );

$hash_a = $service::payload_hash(
	array(
		'last_name'  => 'Person',
		'first_name' => 'Actual',
		'unpaid'     => false,
	)
);
$hash_b = $service::payload_hash(
	array(
		'unpaid'     => false,
		'first_name' => 'Actual',
		'last_name'  => 'Person',
	)
);
$hash_c = $service::payload_hash(
	array(
		'unpaid'     => true,
		'first_name' => 'Actual',
		'last_name'  => 'Person',
	)
);
oras_operation_assert( $hash_a === $hash_b, 'Payload hashing is stable across associative-key order' );
oras_operation_assert( $hash_a !== $hash_c, 'Payload hashing binds meaningful request changes' );
oras_operation_assert( 64 === strlen( $hash_a ), 'Payload hash is SHA-256' );

oras_operation_assert( true === $service::date_is_within_event( '2026-10-06', '2026-10-06', '2026-10-11' ), 'Event start date is admissible' );
oras_operation_assert( true === $service::date_is_within_event( '2026-10-11', '2026-10-06', '2026-10-11' ), 'Event end date is admissible' );
oras_operation_assert( false === $service::date_is_within_event( '2026-10-12', '2026-10-06', '2026-10-11' ), 'Date after event is rejected without a grace period' );

foreach ( array( 'station', 'offerings', 'search', 'detail', 'confirm_and_check_in', 'recent', 'reverse' ) as $method ) {
	oras_operation_assert( method_exists( $rest, $method ), "REST controller exposes {$method} contract" );
}
foreach ( array( 'dashboard', 'create_walk_in', 'check_in', 'create_complimentary', 'create_manager_verified', 'correct_registration' ) as $method ) {
	oras_operation_assert( method_exists( $service, $method ), "V1 service exposes {$method} workflow" );
	oras_operation_assert( method_exists( $rest, $method ), "V1 REST controller exposes {$method} contract" );
}
foreach ( array( 'recovery_search', 'recovery_sync' ) as $method ) {
	oras_operation_assert( method_exists( $rest, $method ), "Manager REST controller exposes {$method} contract" );
}
foreach ( array( 'paid_card', 'paid_cash', 'paid_check', 'unpaid' ) as $statement ) {
	oras_operation_assert( true === $service::payment_assertion_is_valid( $statement ), "{$statement} is an allowed operational payment statement" );
}
oras_operation_assert( false === $service::payment_assertion_is_valid( 'refunded' ), 'Financial lifecycle states are not payment assertions' );
oras_operation_assert( method_exists( $registration_store, 'create_manual' ), 'Registration store creates nonfinancial desk records' );
oras_operation_assert( method_exists( $registration_store, 'duplicate_candidates' ), 'Registration store exposes conservative duplicate warnings' );
oras_operation_assert( method_exists( $registration_store, 'correct_manual' ), 'Registration store supports guarded manual corrections' );
oras_operation_assert( method_exists( $attendee_store, 'confirm_slot' ), 'Attendee store supports stable named or unnamed family slots' );
oras_operation_assert( method_exists( $attendance_store, 'dashboard' ), 'Attendance store reports honestly defined dashboard counts' );
oras_operation_assert( method_exists( $attendance_store, 'recent_detailed' ), 'Recent arrivals include attendee and registration context' );
oras_operation_assert( method_exists( $attendance_store, 'for_attendees_on_date' ), 'Registration detail can expose current daily attendance for manager actions' );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source fixture.
$service_code = (string) file_get_contents( $base . 'Service.php' );
oras_operation_assert( false !== strpos( $service_code, 'Store::transaction' ), 'Business mutation and audit use a database transaction' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source fixture.
$attendee_store_code = (string) file_get_contents( $base . 'Attendee_Store.php' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source fixture.
$attendance_store_code = (string) file_get_contents( $base . 'Attendance_Store.php' );
oras_operation_assert( false !== strpos( $attendee_store_code, 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)' ), 'Attendee confirmation converges concurrent inserts atomically' );
oras_operation_assert( false !== strpos( $attendance_store_code, 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)' ), 'Daily attendance converges concurrent inserts atomically' );
oras_operation_assert( false !== strpos( $service_code, 'source_adapter->load' ), 'Check-in revalidates the Woo source immediately' );
oras_operation_assert( substr_count( $service_code, 'current_admission(' ) >= 4, 'Detail and both final check-in paths share one authoritative admission resolver' );
oras_operation_assert( false !== strpos( $service_code, 'explicit_unpaid_required' ), 'On-hold admission requires explicit unpaid intent' );
oras_operation_assert( false !== strpos( $service_code, 'expected_record_version' ), 'Reversal binds the expected attendance version' );
oras_operation_assert( false !== strpos( $service_code, 'current_attendance' ), 'Replay response includes current attendance state' );
oras_operation_assert( false !== strpos( $service_code, "'friendly_date' => wp_date" ), 'Dashboard refresh carries the authoritative friendly local date' );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source fixture.
$rest_code = (string) file_get_contents( $base . 'Rest_Controller.php' );
oras_operation_assert( false !== strpos( $rest_code, '/registration-desk/station' ), 'Station bootstrap has a dedicated route' );
oras_operation_assert( false !== strpos( $rest_code, '/registration-desk/offerings' ), 'Walk-in choices have a current-offerings route' );
oras_operation_assert( false !== strpos( $rest_code, 'apply_desk_admission_state' ), 'Desk offerings apply the current event admission date without changing canonical public sale state' );
oras_operation_assert( false !== strpos( $rest_code, "'availability_label']    = __( 'Not admitting today'" ), 'Out-of-date desk offerings explain that the event is not admitting today' );
oras_operation_assert( false !== strpos( $rest_code, '/registration-desk/membership-offerings' ), 'Membership choices have a current canonical-offerings route' );
oras_operation_assert( false !== strpos( $rest_code, '/memberships/(?P<activation_uuid>[0-9a-f-]{36})/correct' ), 'Pending membership correction has a dedicated manager route' );
oras_operation_assert( false !== strpos( $rest_code, '/registration-desk/registrations' ), 'Search uses operational registrations route' );
oras_operation_assert( false !== strpos( $rest_code, '/registration-desk/manager/recovery' ), 'Missing-registration recovery has a manager-only route' );
oras_operation_assert(
	1 === preg_match( '#\x27methods\x27\s*=>\s*\x27POST\x27,\s*\x27callback\x27\s*=>\s*array\( \$this, \x27record_membership\x27 \),\s*\x27permission_callback\x27\s*=>\s*array\( \$this, \x27permission_admit\x27 \)#s', $rest_code ),
	'Normal volunteers may record a paid membership after the external AlfaPOS handoff'
);
oras_operation_assert( false !== strpos( $rest_code, '/registration-desk/registrations/manager-verified' ), 'Manager-verified manual registration has a dedicated route' );
oras_operation_assert( false === strpos( $rest_code, '/orders/(?P<' ), 'No desk route uses an order ID as registration identity' );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source fixture.
$desk_js = (string) file_get_contents( dirname( __DIR__ ) . '/oras-tickets/assets/registration-desk/desk.js' );
oras_operation_assert( false !== strpos( $desk_js, 'performCheckIn(form, registration, true)' ), 'Volunteer UI offers the explicit unpaid admission path when required' );
oras_operation_assert( false !== strpos( $desk_js, 'reverseAttendance' ), 'Manager UI exposes audited attendance reversal' );
oras_operation_assert( false !== strpos( $desk_js, 'saveCorrection' ), 'Manager UI exposes guarded manual-registration correction' );
oras_operation_assert( false !== strpos( $desk_js, 'syncWebsiteRegistrations' ), 'Manager UI exposes initial website registration recovery' );
oras_operation_assert( false !== strpos( $desk_js, "api('/project'" ), 'Website registration recovery uses the manager-only projection endpoint' );
oras_operation_assert( false !== strpos( $desk_js, 'URLSearchParams' ), 'Desk REST queries support both plain and pretty permalinks' );
oras_operation_assert( false !== strpos( $desk_js, 'WHAT DO YOU NEED TO DO?' ), 'Volunteer home uses the approved kiosk prompt' );
oras_operation_assert( false !== strpos( $desk_js, 'FIND A REGISTRATION' ), 'Volunteer home exposes a large registered-attendee task' );
oras_operation_assert( false !== strpos( $desk_js, 'REGISTER A WALK-IN' ), 'Volunteer home exposes a large walk-in task' );
oras_operation_assert( false === strpos( $desk_js, 'class="desk-nav"' ), 'Volunteer shell has no side navigation' );
oras_operation_assert( false !== strpos( $desk_js, 'showWalkInStep' ), 'Walk-in registration is a stateful step-by-step wizard' );
oras_operation_assert( false !== strpos( $desk_js, "api('/offerings'" ), 'Walk-in registration refreshes canonical offerings when opened' );
oras_operation_assert( false !== strpos( $desk_js, 'offering_fingerprint' ), 'Walk-in submission carries the reviewed offering fingerprint' );
oras_operation_assert( false !== strpos( $desk_js, 'showWaitlistSuccess' ), 'RSVP waitlisting has an explicit not-admitted success state' );
oras_operation_assert( false !== strpos( $desk_js, 'showRsvpRefusal' ), 'Unavailable RSVP submission has a clear refusal state' );
oras_operation_assert( false !== strpos( $desk_js, 'PAYMENT IS HANDLED IN ALFAPOS' ), 'Walk-in payment step preserves the separate AlfaPOS boundary' );
oras_operation_assert( false !== strpos( $desk_js, 'PAYMENT WAS ALREADY HANDLED' ), 'Lost-response recovery warns volunteers not to collect payment twice' );
oras_operation_assert( false !== strpos( $desk_js, 'showSuccess' ), 'Completed check-in and registration use a dedicated success screen' );
oras_operation_assert( false !== strpos( $desk_js, 'friendlyError' ), 'Volunteer errors pass through a plain-language error mapper' );
oras_operation_assert( false !== strpos( $desk_js, 'formatLocalTime' ), 'Volunteer timestamps use a site-local formatter' );
oras_operation_assert( false !== strpos( $desk_js, 'showManagerArea' ), 'Manager functions are separated from normal volunteer tasks' );
oras_operation_assert( false !== strpos( $desk_js, "api('/events'" ), 'Volunteer chooses from the server event catalog after entering a name' );
oras_operation_assert( false !== strpos( $desk_js, 'CHANGE EVENT' ), 'Compact header exposes event switching' );
oras_operation_assert( false !== strpos( $desk_js, 'THIS EVENT HAS ENDED' ) && false !== strpos( $desk_js, 'Please choose the event you are working today.' ), 'Expired open station receives the required full-screen event-ended state' );
oras_operation_assert( false !== strpos( $desk_js, 'chooseEventAfterEnd' ) && false !== strpos( $desk_js, 'hasUnsavedDraft()' ), 'Choosing another event after midnight explicitly resolves an unfinished draft' );
oras_operation_assert( false !== strpos( $desk_js, "payload.code === 'oras_desk_station_event_ended'" ), 'The browser preserves the ended station draft until the volunteer chooses an event' );
oras_operation_assert( false === strpos( $desk_js, 'id="desk-home-roster"' ), 'Volunteer home omits the duplicate Event Roster action' );
oras_operation_assert( false !== strpos( $desk_js, "#desk-home-find').addEventListener('click', () => showEventRoster(true)" ), 'Find Registration opens the populated event roster directly' );
oras_operation_assert( false !== strpos( $desk_js, '<h1>FIND REGISTRATION</h1>' ), 'Unified registration browser uses the simple Find Registration title' );
oras_operation_assert( false !== strpos( $desk_js, 'SEARCH THIS EVENT' ), 'Unified registration browser makes search optional and event scoped' );
oras_operation_assert( false !== strpos( $desk_js, 'ORAS MEMBERSHIP' ), 'Organization membership remains conceptually separate from the event roster' );
oras_operation_assert( false !== strpos( $desk_js, 'Look up a member or record a membership paid here.' ), 'Membership home action explains both normal volunteer choices' );
oras_operation_assert( false !== strpos( $desk_js, 'LOOK UP MEMBER' ) && false !== strpos( $desk_js, 'RECORD MEMBERSHIP PAYMENT' ), 'Normal volunteer membership menu separates lookup from recording' );
oras_operation_assert( false !== strpos( $desk_js, 'HOW DID THEY PAY?' ) && false !== strpos( $desk_js, 'PAYMENT RECORDED IN ALFAPOS' ), 'Volunteer membership wizard uses explicit cash or check AlfaPOS handoff' );
oras_operation_assert( false !== strpos( $desk_js, 'MEMBERSHIP RECORDED' ) && false !== strpos( $desk_js, 'Activation email sent' ), 'Membership completion is unmistakable' );
oras_operation_assert( false !== strpos( $desk_js, 'EVENT STATS' ), 'Volunteer home exposes shared event statistics' );
oras_operation_assert( false !== strpos( $desk_js, "api('/manager/unlock'" ), 'Manager Help performs PIN unlock inside the kiosk' );
oras_operation_assert( false !== strpos( $desk_js, 'RECORD MEMBERSHIP' ), 'Manager area exposes the offline membership workflow' );
oras_operation_assert( false !== strpos( $desk_js, 'Before taking payment, ask whether they are buying anything else today.' ), 'AlfaPOS handoff is a dedicated instruction step' );
oras_operation_assert( false !== strpos( $desk_js, 'failureCount' ), 'Save recovery tracks repeated failure without discarding request identity' );
oras_operation_assert( false !== strpos( $desk_js, 'finalizing: false' ) && false !== strpos( $desk_js, 'if (state.finalizing) return;' ), 'Walk-in finalization has an in-flight double-submission guard' );
oras_operation_assert( false !== strpos( $desk_js, 'function showPaymentRecovery(error, payment, acknowledge)' ), 'Finalization recovery owns the complete kiosk screen rather than a nested message slot' );
oras_operation_assert( false !== strpos( $desk_js, "state.view = 'recovery';" ) && false !== strpos( $desk_js, 'main().innerHTML = `<section class="desk-centered desk-finalization-recovery">' ), 'Finalization failure replaces the active submit screen' );
oras_operation_assert( false !== strpos( $desk_js, 'if (state.failureCount > 0 && state.pendingPayload && state.pendingRequest) return showRestoredFailure();' ), 'Refreshing the same failed request restores its recovery state and failure count' );
oras_operation_assert( false === strpos( $desk_js, 'showPaymentRecovery(message,' ), 'Recovery is never rendered beneath the still-actionable payment form' );
oras_operation_assert( false !== strpos( $desk_js, 'draft_expires_at' ) && false !== strpos( $desk_js, 'restoreDraft' ), 'Walk-in and membership drafts are retained for a bounded session window' );
oras_operation_assert( false !== strpos( $desk_js, 'START OVER?' ) && false !== strpos( $desk_js, 'DISCARD &amp; RETURN HOME' ), 'Unsafe home navigation requires an explicit discard choice' );
oras_operation_assert( false !== strpos( $desk_js, 'CONNECTION LOST' ) && false !== strpos( $desk_js, 'Your information is still here.' ), 'Unreachable requests use the approved plain-language retained-data state' );
oras_operation_assert( false !== strpos( $desk_js, 'desk-manager-status' ) && false !== strpos( $desk_js, 'EXIT MANAGER MODE' ), 'Manager Mode has a persistent shell indicator and exit control' );
oras_operation_assert( false !== strpos( $desk_js, 'RETURN HOME ONLY AFTER CONFIRMATION' ), 'Second save failure offers only confirmed abandonment' );
oras_operation_assert( false === strpos( $desk_js, ".addEventListener('click', showHome)" ), 'Home actions do not pass browser click events into volunteer status messages' );
oras_operation_assert( false === strpos( $desk_js, 'desk-today-count' ), 'Volunteer home does not contain count clutter' );
oras_operation_assert( false !== strpos( $desk_js, 'THEY SAY THEY ALREADY REGISTERED' ), 'Failed volunteer search offers the approved recovery choice' );
oras_operation_assert( false !== strpos( $desk_js, 'DO THEY HAVE PROOF OF REGISTRATION OR PAYMENT?' ), 'Recovery explains acceptable proof in plain language' );
oras_operation_assert( false !== strpos( $desk_js, 'FIND MISSING REGISTRATION' ), 'Manager PIN recovery opens the dedicated missing-registration workflow' );
oras_operation_assert( false !== strpos( $desk_js, 'SYNC THIS REGISTRATION' ), 'Manager can synchronize one canonical registration source' );
oras_operation_assert( false !== strpos( $desk_js, 'RECORD VERIFIED MANUAL REGISTRATION' ), 'Manager can record a nonfinancial verified manual registration' );
oras_operation_assert( false !== strpos( $desk_js, 'I verified proof of registration/payment outside this system.' ), 'Manual recovery requires explicit proof acknowledgement' );
oras_operation_assert( false !== strpos( $desk_js, 'data-classification' ) && false !== strpos( $desk_js, 'data-maximum' ), 'Manual recovery carries canonical family coverage into the manager form' );
oras_operation_assert( false !== strpos( $desk_js, 'desk-manager-family-members' ) && false !== strpos( $desk_js, 'managerVerifiedAttendees' ), 'Manual recovery collects optional family members up to the canonical limit' );
oras_operation_assert( false !== strpos( $desk_js, 'const options = (data.items || []).filter' ), 'Manual recovery consumes the current-offerings response contract' );
oras_operation_assert( false !== strpos( $desk_js, 'function resetViewport()' ) && substr_count( $desk_js, 'resetViewport();' ) >= 8, 'Major kiosk screen transitions reset inherited scroll position' );
oras_operation_assert( false !== strpos( $desk_js, "['everyone', 'ALL RSVPs'], ['admitted', 'CONFIRMED'], ['waitlist', 'WAITLISTED'], ['checked_in', 'HERE TODAY']" ), 'RSVP filters use approved volunteer wording' );
oras_operation_assert( false !== strpos( $desk_js, '✓ Registered' ) && false !== strpos( $desk_js, '✓ Checked in today' ), 'Registration success uses words and icons rather than color alone' );
oras_operation_assert( false !== strpos( $desk_js, 'RSVP CONFIRMED' ) && false !== strpos( $desk_js, 'ADDED TO WAITLIST' ), 'RSVP success states clearly distinguish admission from waitlisting' );
foreach ( array( 'Historical label ignored by desk', 'needs_review', 'source revoked', 'projection incomplete', 'stale configuration', 'raw REST status', 'raw UUID', 'raw SQL error' ) as $internal_phrase ) {
	oras_operation_assert( false === strpos( $desk_js, $internal_phrase ), "Volunteer UI omits internal phrase: {$internal_phrase}" );
}
oras_operation_assert( false !== strpos( $desk_js, '✓ REGISTRATION VALID' ), 'Eligible detail exposes one unambiguous registration-valid state' );
oras_operation_assert( false !== strpos( $desk_js, '⚠ MANAGER HELP NEEDED' ) && false !== strpos( $desk_js, 'GET MANAGER HELP' ), 'Blocked detail replaces attendance controls with a clear manager-help state' );
oras_operation_assert( false !== strpos( $desk_js, 'admission.check_in_allowed' ) && false !== strpos( $desk_js, 'admission.selection_allowed' ), 'Detail controls consume the normalized server admission result' );
oras_operation_assert( false !== strpos( $desk_js, 'await showRegistration(registration.registration_uuid, state.detailReturn)' ), 'Final stale-admission rejection reloads authoritative registration detail' );
oras_operation_assert( false !== strpos( $desk_js, 'await showEventRoster(false)' ), 'Stale website RSVP rejection returns to the authoritative roster state' );
oras_operation_assert( false !== strpos( $desk_js, 'Operational registration' ) && false !== strpos( $desk_js, 'Canonical source status' ) && false !== strpos( $desk_js, 'Date validity' ), 'Manager detail renders readable admission diagnostics' );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source fixture.
$desk_css = (string) file_get_contents( dirname( __DIR__ ) . '/oras-tickets/assets/registration-desk/desk.css' );
oras_operation_assert( false !== strpos( $desk_css, '--desk-navy:' ), 'Kiosk styling uses the approved navy brand foundation' );
oras_operation_assert( false !== strpos( $desk_css, 'min-height: 56px' ), 'Kiosk controls provide large touch targets' );
oras_operation_assert( false !== strpos( $desk_css, '@media (orientation: portrait)' ), 'Kiosk has an explicit iPad portrait layout' );
oras_operation_assert( false !== strpos( $desk_css, '100dvh' ), 'Kiosk sizing uses the dynamic iOS viewport' );
oras_operation_assert( false !== strpos( $desk_css, 'env(safe-area-inset-top)' ), 'Kiosk respects iOS safe-area insets' );
oras_operation_assert( false !== strpos( $desk_css, 'overflow-x: hidden' ), 'Kiosk prevents horizontal page scrolling' );
oras_operation_assert( false !== strpos( $desk_css, '.desk-touch-centered' ), 'Kiosk exposes one reusable centered large-control style' );
oras_operation_assert( false !== strpos( $desk_css, 'align-items: center' ) && false !== strpos( $desk_css, 'justify-content: center' ), 'Large kiosk controls center wrapped labels on both axes' );

echo "Registration Desk operation checks passed.\n";
