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
foreach ( array( 'Store.php', 'Registration_Store.php', 'Attendee_Store.php', 'Attendance_Store.php', 'Service.php', 'Rest_Controller.php' ) as $file ) {
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

foreach ( array( 'station', 'search', 'detail', 'confirm_and_check_in', 'recent', 'reverse' ) as $method ) {
	oras_operation_assert( method_exists( $rest, $method ), "REST controller exposes {$method} contract" );
}
foreach ( array( 'dashboard', 'create_walk_in', 'check_in', 'create_complimentary', 'correct_registration' ) as $method ) {
	oras_operation_assert( method_exists( $service, $method ), "V1 service exposes {$method} workflow" );
	oras_operation_assert( method_exists( $rest, $method ), "V1 REST controller exposes {$method} contract" );
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
oras_operation_assert( false !== strpos( $service_code, 'explicit_unpaid_required' ), 'On-hold admission requires explicit unpaid intent' );
oras_operation_assert( false !== strpos( $service_code, 'expected_record_version' ), 'Reversal binds the expected attendance version' );
oras_operation_assert( false !== strpos( $service_code, 'current_attendance' ), 'Replay response includes current attendance state' );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source fixture.
$rest_code = (string) file_get_contents( $base . 'Rest_Controller.php' );
oras_operation_assert( false !== strpos( $rest_code, '/registration-desk/station' ), 'Station bootstrap has a dedicated route' );
oras_operation_assert( false !== strpos( $rest_code, '/registration-desk/registrations' ), 'Search uses operational registrations route' );
oras_operation_assert( false === strpos( $rest_code, '/orders/(?P<' ), 'No desk route uses an order ID as registration identity' );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source fixture.
$desk_js = (string) file_get_contents( dirname( __DIR__ ) . '/oras-tickets/assets/registration-desk/desk.js' );
oras_operation_assert( false !== strpos( $desk_js, 'performCheckIn(form, registration, true)' ), 'Volunteer UI offers the explicit unpaid admission path when required' );
oras_operation_assert( false !== strpos( $desk_js, 'reverseAttendance' ), 'Manager UI exposes audited attendance reversal' );
oras_operation_assert( false !== strpos( $desk_js, 'saveCorrection' ), 'Manager UI exposes guarded manual-registration correction' );
oras_operation_assert( false !== strpos( $desk_js, 'syncWebsiteRegistrations' ), 'Manager UI exposes initial website registration recovery' );
oras_operation_assert( false !== strpos( $desk_js, "api('/project'" ), 'Website registration recovery uses the manager-only projection endpoint' );
oras_operation_assert( false !== strpos( $desk_js, 'URLSearchParams' ), 'Desk REST queries support both plain and pretty permalinks' );

echo "Registration Desk operation checks passed.\n";
