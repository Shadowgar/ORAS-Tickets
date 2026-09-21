<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/fixtures/class-wp-error.php';

function oras_training_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
	fwrite( STDOUT, "PASS: {$message}\n" );
}

$plugin_dir = dirname( __DIR__ ) . '/oras-tickets/';
$schema     = $plugin_dir . 'includes/Registration_Desk/Schema.php';
$base_store = $plugin_dir . 'includes/Registration_Desk/Store.php';
$store      = $plugin_dir . 'includes/Registration_Desk/Training_Store.php';
$context    = $plugin_dir . 'includes/Registration_Desk/Training_Context.php';
$service    = $plugin_dir . 'includes/Registration_Desk/Training_Service.php';
$snapshot   = $plugin_dir . 'includes/Registration_Desk/Training_Snapshot_Service.php';
$rest       = $plugin_dir . 'includes/Registration_Desk/Training_Rest_Controller.php';

oras_training_assert( file_exists( $schema ), 'Registration Desk schema exists' );
oras_training_assert( file_exists( $base_store ), 'Registration Desk base store exists' );
oras_training_assert( file_exists( $store ), 'Dedicated Training Store exists' );
oras_training_assert( file_exists( $context ), 'Server-authorized Training Context exists' );
oras_training_assert( file_exists( $service ), 'Synthetic Training Service exists' );
oras_training_assert( file_exists( $snapshot ), 'Production roster Training Snapshot Service exists' );
oras_training_assert( file_exists( $rest ), 'Dedicated Training REST controller exists' );

require_once $schema;
require_once $base_store;
require_once $store;
require_once $context;
require_once $service;
require_once $snapshot;

$schema_class = '\\ORAS\\Tickets\\Registration_Desk\\Schema';
$store_class  = '\\ORAS\\Tickets\\Registration_Desk\\Training_Store';
$context_class = '\\ORAS\\Tickets\\Registration_Desk\\Training_Context';
$service_class = '\\ORAS\\Tickets\\Registration_Desk\\Training_Service';
$snapshot_class = '\\ORAS\\Tickets\\Registration_Desk\\Training_Snapshot_Service';

oras_training_assert( method_exists( $service_class, 'live_detail' ), 'Training Service exposes the shared live-shaped registration detail' );
$snapshot_registration = $snapshot_class::normalize_registration(
	array(
		'registration_uuid' => '12121212-1212-4212-8212-121212121212',
		'name'              => 'Jamie Morgan',
		'phone'             => '814-555-0103',
		'registration_type' => 'AstroBlast 2026 - Student',
		'source_type'       => 'online',
	),
	array(
		'registration' => array(
			'registration_uuid'   => '12121212-1212-4212-8212-121212121212',
			'option_uuid'         => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
			'source_contact_name' => 'Jamie Morgan',
			'source_email'        => 'jamie@example.com',
			'source_phone'        => '814-555-0103',
			'source_type'         => 'online',
			'classification'      => 'individual',
			'validity_type'       => 'full_event',
			'valid_local_date'    => '',
			'payment_assertion'   => 'paid_card',
		),
		'attendees'    => array(
			array(
				'attendee_uuid'      => '13131313-1313-4313-8313-131313131313',
				'slot_key'           => 'individual-1',
				'display_name'       => 'Jamie Morgan',
				'first_name'         => 'Jamie',
				'last_name'          => 'Morgan',
				'current_attendance' => array( 'state' => 'checked_in' ),
			),
		),
		'admission'    => array(
			'selection_allowed' => true,
			'check_in_allowed'  => true,
			'maximum_attendees' => 1,
		),
	)
);
oras_training_assert( 'Jamie Morgan' === $snapshot_registration['contact_name'] && 'jamie@example.com' === $snapshot_registration['email'], 'Training snapshot preserves the real event roster identity and contact fields' );
oras_training_assert( ! isset( $snapshot_registration['attendees'][0]['current_attendance'] ), 'Training snapshot starts with separate attendance state' );
$snapshot_stats = $service_class::stats(
	array(
		'registrations' => array( $snapshot_registration['registration_uuid'] => $snapshot_registration ),
		'attendance'    => array(),
		'memberships'   => array(),
	),
	'2026-10-06'
);
oras_training_assert( 1 === ( $snapshot_stats['event_total']['direct_website_registrations'] ?? -1 ), 'Training stats classify copied website registrations exactly like live Event Stats' );

oras_training_assert( 3 === $schema_class::VERSION, 'Training table advances the Registration Desk schema version' );
$tables = $schema_class::table_names( 'wp_' );
oras_training_assert( 'wp_oras_registration_desk_training_sessions' === ( $tables['training_sessions'] ?? '' ), 'Training table has a dedicated physical name' );

$sql    = $schema_class::build_schema_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
$joined = implode( "\n", $sql );
oras_training_assert( 6 === count( $sql ), 'Schema defines five live stores and one isolated training store' );
oras_training_assert( false !== strpos( $joined, 'CREATE TABLE wp_oras_registration_desk_training_sessions' ), 'Schema creates the isolated training table' );
foreach (
	array(
		'training_uuid char(36) NOT NULL',
		'station_uuid char(36) NOT NULL',
		'user_id bigint(20) unsigned NOT NULL',
		'wp_session_digest char(64) NOT NULL',
		'event_id bigint(20) unsigned NOT NULL',
		'config_revision bigint(20) unsigned NOT NULL',
		'simulated_local_date date NOT NULL',
		'state_json longtext NOT NULL',
		'record_version bigint(20) unsigned NOT NULL DEFAULT 1',
		'expires_at_utc datetime NOT NULL',
		'UNIQUE KEY station_uuid (station_uuid)',
		'ENGINE=InnoDB',
	) as $fragment
) {
	oras_training_assert( false !== strpos( $joined, $fragment ), "Training schema contains {$fragment}" );
}
oras_training_assert( false === strpos( (string) file_get_contents( $store ), 'oras_event_registrations' ), 'Training Store never references the live registration table' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.

oras_training_assert( class_exists( $store_class ), 'Training Store class loads' );
foreach ( array( 'create', 'find_for_station', 'mutate', 'change_date', 'reset', 'delete_for_station', 'cleanup_expired' ) as $method ) {
	oras_training_assert( method_exists( $store_class, $method ), "Training Store exposes {$method}" );
}

oras_training_assert( class_exists( $context_class ), 'Training Context class loads' );
$station = array(
	'station_uuid'         => '11111111-1111-4111-8111-111111111111',
	'user_id'              => 99,
	'event_id'             => 123,
	'config_revision'      => 7,
	'wp_session'           => str_repeat( 'a', 64 ),
	'mode'                 => 'training',
	'simulated_local_date' => '2026-10-06',
);
$row = array(
	'station_uuid'         => $station['station_uuid'],
	'user_id'              => 99,
	'event_id'             => 123,
	'config_revision'      => 7,
	'wp_session_digest'    => $station['wp_session'],
	'simulated_local_date' => '2026-10-06',
	'expires_at_utc'       => '2026-10-07 12:00:00',
);
$config = array( 'revision' => 7 );
$event  = array(
	'event_id'   => 123,
	'start_date' => '2026-10-06',
	'end_date'   => '2026-10-11',
);
$valid_context = $context_class::validate_binding( $station, $row, $config, $event, strtotime( '2026-09-21 12:00:00 UTC' ) );
oras_training_assert( is_array( $valid_context ) && '2026-10-06' === $valid_context['simulated_local_date'], 'Matching server row authorizes the simulated date' );

$changed_date = $row;
$changed_date['simulated_local_date'] = '2026-10-10';
$changed_station = $station;
$changed_station['simulated_local_date'] = '2026-10-10';
oras_training_assert( is_array( $context_class::validate_binding( $changed_station, $changed_date, $config, $event, strtotime( '2026-09-21 12:00:00 UTC' ) ) ), 'Simulated date is mutable without changing the station identity when the token is reissued' );
oras_training_assert( $context_class::validate_binding( $station, $changed_date, $config, $event, strtotime( '2026-09-21 12:00:00 UTC' ) ) instanceof WP_Error, 'Prior training token cannot authorize the changed simulated date' );

$outside_date = $row;
$outside_date['simulated_local_date'] = '2026-10-12';
$outside_station = $station;
$outside_station['simulated_local_date'] = '2026-10-12';
$outside_result = $context_class::validate_binding( $outside_station, $outside_date, $config, $event, strtotime( '2026-09-21 12:00:00 UTC' ) );
oras_training_assert( $outside_result instanceof WP_Error && 'oras_desk_training_date_invalid' === $outside_result->get_error_code(), 'Date outside the inclusive event range fails closed' );

$stale_config = $config;
$stale_config['revision'] = 8;
$stale_result = $context_class::validate_binding( $station, $row, $stale_config, $event, strtotime( '2026-09-21 12:00:00 UTC' ) );
oras_training_assert( $stale_result instanceof WP_Error && 'oras_desk_training_config_changed' === $stale_result->get_error_code(), 'Configuration revision change requires Training Mode restart' );
oras_training_assert( is_array( $context_class::validate_scope( $station, $row, strtotime( '2026-09-21 12:00:00 UTC' ) ) ), 'Immutable scope remains valid so a manager can end a stale-configuration training session' );

$other_station = $station;
$other_station['station_uuid'] = '22222222-2222-4222-8222-222222222222';
oras_training_assert( $context_class::validate_binding( $other_station, $row, $config, $event ) instanceof WP_Error, 'Training row cannot authorize another station' );

$other_session = $station;
$other_session['wp_session'] = str_repeat( 'b', 64 );
oras_training_assert( $context_class::validate_binding( $other_session, $row, $config, $event ) instanceof WP_Error, 'Training row remains bound to the WordPress session' );

$expired = $row;
$expired['expires_at_utc'] = '2026-09-20 12:00:00';
$expired_result = $context_class::validate_binding( $station, $expired, $config, $event, strtotime( '2026-09-21 12:00:00 UTC' ) );
oras_training_assert( $expired_result instanceof WP_Error && 'oras_desk_training_expired' === $expired_result->get_error_code(), 'Expired Training Mode fails closed' );

$live_result = $context_class::assert_live( $row );
oras_training_assert( $live_result instanceof WP_Error && 'oras_desk_training_live_route_forbidden' === $live_result->get_error_code(), 'Active training row blocks live operational routes' );
oras_training_assert( true === $context_class::assert_live( null ), 'Station without a training row remains live' );

$offerings = array(
	array(
		'option_uuid'          => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
		'ticket_key'           => 'individual',
		'label'                => 'Individual',
		'description'          => 'One event admission.',
		'price'                => '25.00',
		'classification'       => 'individual',
		'validity_type'        => 'full_event',
		'valid_local_date'     => '',
		'max_attendees'        => 1,
		'offering_fingerprint' => str_repeat( '1', 64 ),
		'included_events'      => array(
			array(
				'event_id' => 456,
				'label'    => 'Friday Star Party',
			),
		),
	),
	array(
		'option_uuid'          => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
		'ticket_key'           => 'family',
		'label'                => 'Family',
		'description'          => 'One household.',
		'price'                => '60.00',
		'classification'       => 'family',
		'validity_type'        => 'full_event',
		'valid_local_date'     => '',
		'max_attendees'        => 6,
		'offering_fingerprint' => str_repeat( '2', 64 ),
		'included_events'      => array(),
	),
	array(
		'option_uuid'          => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
		'ticket_key'           => 'student',
		'label'                => 'Student',
		'description'          => 'Student admission.',
		'price'                => '15.00',
		'classification'       => 'individual',
		'validity_type'        => 'one_day',
		'valid_local_date'     => '2026-10-06',
		'max_attendees'        => 1,
		'offering_fingerprint' => str_repeat( '3', 64 ),
		'included_events'      => array(),
	),
);
$seed_a = $service_class::seed_state( '33333333-3333-4333-8333-333333333333', $offerings );
$seed_b = $service_class::seed_state( '33333333-3333-4333-8333-333333333333', $offerings );
oras_training_assert( $seed_a === $seed_b, 'Seeded training data is deterministic for one training session' );
oras_training_assert( 3 === count( $seed_a['registrations'] ?? array() ), 'Seed includes one registration per meaningful canonical option' );

$seed_names       = array();
$seed_emails      = array();
$family_attendees = 0;
$has_student      = false;
$has_included     = false;
foreach ( $seed_a['registrations'] as $registration ) {
	$seed_names[]  = (string) ( $registration['contact_name'] ?? '' );
	$seed_emails[] = (string) ( $registration['email'] ?? '' );
	if ( 'family' === (string) ( $registration['classification'] ?? '' ) ) {
		$family_attendees = count( $registration['attendees'] ?? array() );
	}
	if ( 'Student' === (string) ( $registration['option_label'] ?? '' ) ) {
		$has_student = true;
	}
	if ( ! empty( $registration['included_events'] ) ) {
		$has_included = true;
	}
}
oras_training_assert( count( array_filter( $seed_names, static fn( string $name ): bool => str_starts_with( $name, 'DEMO — ' ) ) ) === count( $seed_names ), 'Every seed name is unmistakably synthetic' );
oras_training_assert( count( array_filter( $seed_emails, static fn( string $email ): bool => str_ends_with( $email, '@example.invalid' ) ) ) === count( $seed_emails ), 'Every seed uses reserved example contact data' );
oras_training_assert( $family_attendees >= 3, 'Family-capable option seeds multiple attendees' );
oras_training_assert( $has_student, 'Student option receives a dedicated student example' );
oras_training_assert( $has_included, 'Configured included access is copied as configuration-only evidence' );

$roster = $service_class::roster( $seed_a, array( 'status' => 'everyone' ), '2026-10-06' );
oras_training_assert( 3 === count( $roster['items'] ?? array() ), 'Training roster reads only the synthetic state' );
$searched = $service_class::roster( $seed_a, array( 'q' => 'jamie' ), '2026-10-06' );
oras_training_assert( 1 === count( $searched['items'] ?? array() ) && 'Student' === $searched['items'][0]['option_label'], 'Training roster searches synthetic name and contact fields' );
$family_only = $service_class::roster( $seed_a, array( 'option_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' ), '2026-10-06' );
oras_training_assert( 1 === count( $family_only['items'] ?? array() ), 'Training roster filters by canonical registration type' );
$not_checked_in = $service_class::roster( $seed_a, array( 'status' => 'not_checked_in' ), '2026-10-06' );
oras_training_assert( 3 === count( $not_checked_in['items'] ?? array() ), 'New training roster reports every seed as not checked in' );
$detail = $service_class::detail( $seed_a, (string) $family_only['items'][0]['registration_uuid'], true );
oras_training_assert( is_array( $detail ) && true === ( $detail['manager_detail']['synthetic'] ?? false ), 'Manager detail identifies synthetic origin explicitly' );
oras_training_assert( strlen( (string) json_encode( $seed_a ) ) < 524288, 'Seeded state remains within the bounded training row' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone fixture has no WordPress JSON helper.

$service_source = (string) file_get_contents( $service ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.
foreach ( array( 'wc_get_orders', 'wc_get_order', 'WP_Query', 'oras_event_registrations' ) as $forbidden ) {
	oras_training_assert( false === strpos( $service_source, $forbidden ), "Training seeding avoids live order/registration lookup: {$forbidden}" );
}

$operation_context = array(
	'training_uuid'           => '33333333-3333-4333-8333-333333333333',
	'simulated_local_date'    => '2026-10-06',
	'event_start_date'        => '2026-10-06',
	'event_end_date'          => '2026-10-11',
	'config_revision'         => 7,
	'current_config_revision' => 7,
	'canonical_offerings'     => $offerings,
	'occurred_at_utc'         => '2026-09-21 14:00:00',
);
$individual = null;
$family     = null;
foreach ( $seed_a['registrations'] as $registration ) {
	if ( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' === $registration['option_uuid'] ) {
		$individual = $registration;
	}
	if ( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' === $registration['option_uuid'] ) {
		$family = $registration;
	}
}
oras_training_assert( is_array( $individual ) && is_array( $family ), 'Operation fixtures resolve seeded individual and family registrations' );

$individual_request = array(
	'request_uuid'      => '44444444-4444-4444-8444-444444444444',
	'registration_uuid' => $individual['registration_uuid'],
	'attendee_uuids'    => array( $individual['attendees'][0]['attendee_uuid'] ),
);
$individual_check_in = $service_class::check_in_state( $seed_a, $individual_request, $operation_context );
oras_training_assert( is_array( $individual_check_in ) && isset( $individual_check_in['state'], $individual_check_in['result'] ), 'Seeded individual can check in on the simulated date' );
$state_after_individual = $individual_check_in['state'];
oras_training_assert( 1 === count( $state_after_individual['attendance']['2026-10-06'] ?? array() ), 'Training attendance is stored under the simulated event date' );

$selected_family = array( $family['attendees'][0]['attendee_uuid'], $family['attendees'][2]['attendee_uuid'] );
$family_check_in = $service_class::check_in_state(
	$state_after_individual,
	array(
		'request_uuid'      => '55555555-5555-4555-8555-555555555555',
		'registration_uuid' => $family['registration_uuid'],
		'attendee_uuids'    => $selected_family,
	),
	$operation_context
);
oras_training_assert( is_array( $family_check_in ) && 2 === count( $family_check_in['result']['attendee_uuids'] ?? array() ), 'Training family check-in honors selected attendees' );
$state_after_family = $family_check_in['state'];
oras_training_assert( 3 === count( $state_after_family['attendance']['2026-10-06'] ?? array() ), 'Selected family attendance is added without duplicating the individual' );

$later_roster = $service_class::roster( $state_after_family, array( 'status' => 'checked_in' ), '2026-10-07' );
oras_training_assert( 0 === count( $later_roster['items'] ?? array() ), 'Prior-day check-ins do not count on a newly selected training date' );
oras_training_assert( 3 === count( $state_after_family['attendance']['2026-10-06'] ?? array() ), 'Prior-day check-ins remain in training history after a date change' );

$replay = $service_class::check_in_state( $state_after_individual, $individual_request, $operation_context );
oras_training_assert( is_array( $replay ) && $individual_check_in['result'] === $replay['result'], 'Identical request replay returns the historical result' );
oras_training_assert( $state_after_individual === $replay['state'], 'Identical request replay does not duplicate attendance' );
$request_conflict = $individual_request;
$request_conflict['attendee_uuids'] = array();
$conflict = $service_class::check_in_state( $state_after_individual, $request_conflict, $operation_context );
oras_training_assert( $conflict instanceof WP_Error && 'oras_desk_training_request_conflict' === $conflict->get_error_code(), 'Request UUID reuse with another payload fails closed' );

$stale_offerings = $offerings;
$stale_offerings[0]['offering_fingerprint'] = str_repeat( '9', 64 );
$stale_operation_context = $operation_context;
$stale_operation_context['canonical_offerings'] = $stale_offerings;
$stale_check_in = $service_class::check_in_state( $seed_a, $individual_request, $stale_operation_context );
oras_training_assert( $stale_check_in instanceof WP_Error && 'oras_desk_training_offering_changed' === $stale_check_in->get_error_code(), 'Training transition fails closed when its canonical offering changed' );

$walk_in_state = $seed_a;
foreach ( array( 'paid_card', 'paid_cash', 'paid_check', 'unpaid' ) as $payment_index => $payment_assertion ) {
	$walk_in = $service_class::walk_in_state(
		$walk_in_state,
		array(
			'request_uuid'         => sprintf( '66666666-6666-4666-8666-%012d', $payment_index + 1 ),
			'option_uuid'          => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
			'offering_fingerprint' => str_repeat( '1', 64 ),
			'contact_name'         => 'Practice Guest ' . ( $payment_index + 1 ),
			'email'                => 'practice' . ( $payment_index + 1 ) . '@example.invalid',
			'phone'                => '555-0199',
			'attendees'            => array( array( 'name' => 'Practice Guest ' . ( $payment_index + 1 ) ) ),
			'payment_assertion'    => $payment_assertion,
		),
		$operation_context
	);
	oras_training_assert( is_array( $walk_in ), "Training walk-in accepts {$payment_assertion} as a simulation-only assertion" );
	$walk_in_state = $walk_in['state'];
}
$walk_in_roster = $service_class::roster( $walk_in_state, array( 'status' => 'walk_in' ), '2026-10-06' );
oras_training_assert( 4 === count( $walk_in_roster['items'] ?? array() ), 'Training walk-ins persist in the isolated roster across refreshes' );

$family_walk_in = $service_class::walk_in_state(
	$walk_in_state,
	array(
		'request_uuid'         => '77777777-7777-4777-8777-777777777777',
		'option_uuid'          => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
		'offering_fingerprint' => str_repeat( '2', 64 ),
		'contact_name'         => 'Practice Family',
		'email'                => 'practice.family@example.invalid',
		'attendees'            => array( array( 'name' => 'Adult One' ), array( 'name' => 'Youth Two' ) ),
		'payment_assertion'    => 'paid_cash',
	),
	$operation_context
);
oras_training_assert( is_array( $family_walk_in ) && 2 === count( $family_walk_in['result']['attendee_uuids'] ?? array() ), 'Training family walk-in preserves its attendee set' );

$wrong_one_day_context = $operation_context;
$wrong_one_day_context['simulated_local_date'] = '2026-10-07';
$wrong_one_day = $service_class::walk_in_state(
	$walk_in_state,
	array(
		'request_uuid'         => '88888888-8888-4888-8888-888888888888',
		'option_uuid'          => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
		'offering_fingerprint' => str_repeat( '3', 64 ),
		'contact_name'         => 'Practice Student',
		'attendees'            => array( array( 'name' => 'Practice Student' ) ),
		'payment_assertion'    => 'unpaid',
	),
	$wrong_one_day_context
);
oras_training_assert( $wrong_one_day instanceof WP_Error && 'oras_desk_training_date_invalid' === $wrong_one_day->get_error_code(), 'One-day training walk-in cannot be recorded on another simulated date' );
oras_training_assert( false === strpos( $service_source, 'Registration_Store' ) && false === strpos( $service_source, 'Attendance_Store' ), 'Training operations have no fallback to live stores' );

$member_matches = $service_class::member_lookup( $seed_a, 'demo.member@example.invalid' );
oras_training_assert( 1 === count( $member_matches ) && true === ( $member_matches[0]['synthetic'] ?? false ), 'Training member lookup returns an unmistakably synthetic fixture' );
$membership_offerings = array(
	array(
		'level_id'     => 7,
		'display_name' => 'Annual Individual Membership',
		'price'        => '35.00',
		'period_label' => 'Every 1 Year',
	),
	array(
		'level_id'     => 8,
		'display_name' => 'Annual Family Membership',
		'price'        => '55.00',
		'period_label' => 'Every 1 Year',
	),
);
$membership_context = $operation_context;
$membership_context['canonical_membership_offerings'] = $membership_offerings;
$cash_membership = $service_class::record_membership_state(
	$seed_a,
	array(
		'request_uuid'   => '99999999-9999-4999-8999-999999999999',
		'level_id'       => 7,
		'contact_name'   => 'Practice Member',
		'email'          => 'practice.member@example.invalid',
		'payment_method' => 'cash',
	),
	$membership_context
);
oras_training_assert( is_array( $cash_membership ) && true === ( $cash_membership['result']['simulated'] ?? false ), 'Training membership records a simulation-only Cash result' );
oras_training_assert( 'Annual Individual Membership' === ( $cash_membership['result']['level_name'] ?? '' ) && '35.00' === ( $cash_membership['result']['reference_price'] ?? '' ), 'Training membership snapshots canonical PMPro display facts' );
$check_membership = $service_class::record_membership_state(
	$cash_membership['state'],
	array(
		'request_uuid'   => 'aaaaaaaa-9999-4999-8999-999999999999',
		'level_id'       => 8,
		'contact_name'   => 'Practice Family Member',
		'email'          => 'practice.family.member@example.invalid',
		'payment_method' => 'check',
	),
	$membership_context
);
oras_training_assert( is_array( $check_membership ) && 2 === count( $check_membership['state']['memberships'] ?? array() ), 'Training membership supports Cash and Check without activating membership' );

$next_day_context = $operation_context;
$next_day_context['simulated_local_date'] = '2026-10-07';
$next_day_context['occurred_at_utc'] = '2026-09-21 15:00:00';
$next_day_check_in = $service_class::check_in_state(
	$family_walk_in['state'],
	array(
		'request_uuid'      => 'bbbbbbbb-9999-4999-8999-999999999999',
		'registration_uuid' => $individual['registration_uuid'],
		'attendee_uuids'    => array( $individual['attendees'][0]['attendee_uuid'] ),
	),
	$next_day_context
);
oras_training_assert( is_array( $next_day_check_in ), 'Training fixture can add attendance on a second simulated date' );
$stats = $service_class::stats( $next_day_check_in['state'], '2026-10-07' );
oras_training_assert( 1 === ( $stats['today']['actual_people'] ?? -1 ), 'Training statistics count only the selected simulated date as today' );
oras_training_assert( 7 === ( $stats['event_total']['attendance_instances'] ?? -1 ), 'Training statistics retain current and historical attendance instances' );
oras_training_assert(
	array(
		'2026-10-06' => 6,
		'2026-10-07' => 1,
	) === ( $stats['event_total']['attendance_by_day'] ?? array() ),
	'Training statistics group attendance by simulated event date'
);
oras_training_assert( 5 === ( $stats['event_total']['walk_in_registrations'] ?? -1 ), 'Training statistics report only synthetic walk-ins' );
oras_training_assert( 2 === ( $stats['event_total']['payment_assertions']['paid_cash'] ?? -1 ), 'Training statistics expose assertion counts rather than revenue' );
oras_training_assert( true === ( $stats['training'] ?? false ), 'Training statistics identify their isolated source' );

$membership_stats = $service_class::stats( $check_membership['state'], '2026-10-06' );
oras_training_assert( 2 === ( $membership_stats['memberships']['total'] ?? -1 ) && 1 === ( $membership_stats['memberships']['cash'] ?? -1 ) && 1 === ( $membership_stats['memberships']['check'] ?? -1 ), 'Training statistics summarize only simulated memberships' );
foreach ( array( 'wp_mail(', 'pmpro_changeMembershipLevel(', 'Membership_Credit_Service', 'Offline_Membership_Store', 'Event_Stats_Service', 'Board_Reports', '$wpdb' ) as $forbidden ) {
	oras_training_assert( false === strpos( $service_source, $forbidden ), "Training membership and stats avoid live side effect: {$forbidden}" );
}

$rest_source = (string) file_get_contents( $rest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.
foreach ( array( '/training/start', '/training/context', '/training/offerings', '/training/roster', '/training/registrations/', '/training/walk-in', '/training/members', '/training/membership-offerings', '/training/memberships', '/training/stats', '/training/date', '/training/reset', '/training/end' ) as $route ) {
	oras_training_assert( false !== strpos( $rest_source, $route ), "Training controller exposes {$route}" );
}
oras_training_assert( false !== strpos( $rest_source, 'permission_start' ) && false !== strpos( $rest_source, 'permission_training_manage' ), 'Training lifecycle uses manager-specific authorization' );
oras_training_assert( false !== strpos( $rest_source, "get_param( 'confirmed' )" ), 'Destructive training lifecycle routes require explicit confirmation' );
oras_training_assert( false !== strpos( $rest_source, 'Training_Store' ) && false !== strpos( $rest_source, 'Training_Service' ), 'Training routes target only the isolated store and service' );
foreach ( array( 'Registration_Store', 'Attendance_Store', 'Membership_Credit_Service', 'Projection_Service' ) as $forbidden ) {
	oras_training_assert( false === strpos( $rest_source, $forbidden ), "Training routes never invoke live writer: {$forbidden}" );
}

echo "Registration Desk training checks passed.\n";
