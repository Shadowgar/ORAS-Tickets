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

oras_training_assert( file_exists( $schema ), 'Registration Desk schema exists' );
oras_training_assert( file_exists( $base_store ), 'Registration Desk base store exists' );
oras_training_assert( file_exists( $store ), 'Dedicated Training Store exists' );
oras_training_assert( file_exists( $context ), 'Server-authorized Training Context exists' );
oras_training_assert( file_exists( $service ), 'Synthetic Training Service exists' );

require_once $schema;
require_once $base_store;
require_once $store;
require_once $context;
require_once $service;

$schema_class = '\\ORAS\\Tickets\\Registration_Desk\\Schema';
$store_class  = '\\ORAS\\Tickets\\Registration_Desk\\Training_Store';
$context_class = '\\ORAS\\Tickets\\Registration_Desk\\Training_Context';
$service_class = '\\ORAS\\Tickets\\Registration_Desk\\Training_Service';

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
foreach ( array( 'create', 'find_for_station', 'mutate', 'reset', 'delete_for_station', 'cleanup_expired' ) as $method ) {
	oras_training_assert( method_exists( $store_class, $method ), "Training Store exposes {$method}" );
}

oras_training_assert( class_exists( $context_class ), 'Training Context class loads' );
$station = array(
	'station_uuid'    => '11111111-1111-4111-8111-111111111111',
	'user_id'         => 99,
	'event_id'        => 123,
	'config_revision' => 7,
	'wp_session'      => str_repeat( 'a', 64 ),
);
$row = array(
	'station_uuid'        => $station['station_uuid'],
	'user_id'             => 99,
	'event_id'            => 123,
	'config_revision'     => 7,
	'wp_session_digest'   => $station['wp_session'],
	'simulated_local_date' => '2026-10-06',
	'expires_at_utc'      => '2026-10-07 12:00:00',
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
oras_training_assert( is_array( $context_class::validate_binding( $station, $changed_date, $config, $event, strtotime( '2026-09-21 12:00:00 UTC' ) ) ), 'Simulated date is mutable without changing the station identity' );

$outside_date = $row;
$outside_date['simulated_local_date'] = '2026-10-12';
$outside_result = $context_class::validate_binding( $station, $outside_date, $config, $event, strtotime( '2026-09-21 12:00:00 UTC' ) );
oras_training_assert( $outside_result instanceof WP_Error && 'oras_desk_training_date_invalid' === $outside_result->get_error_code(), 'Date outside the inclusive event range fails closed' );

$stale_config = $config;
$stale_config['revision'] = 8;
$stale_result = $context_class::validate_binding( $station, $row, $stale_config, $event, strtotime( '2026-09-21 12:00:00 UTC' ) );
oras_training_assert( $stale_result instanceof WP_Error && 'oras_desk_training_config_changed' === $stale_result->get_error_code(), 'Configuration revision change requires Training Mode restart' );

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
		'option_uuid'         => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
		'ticket_key'          => 'individual',
		'label'               => 'Individual',
		'description'         => 'One event admission.',
		'price'               => '25.00',
		'classification'      => 'individual',
		'validity_type'       => 'full_event',
		'valid_local_date'    => '',
		'max_attendees'       => 1,
		'offering_fingerprint' => str_repeat( '1', 64 ),
		'included_events'     => array( array( 'event_id' => 456, 'label' => 'Friday Star Party' ) ),
	),
	array(
		'option_uuid'         => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
		'ticket_key'          => 'family',
		'label'               => 'Family',
		'description'         => 'One household.',
		'price'               => '60.00',
		'classification'      => 'family',
		'validity_type'       => 'full_event',
		'valid_local_date'    => '',
		'max_attendees'       => 6,
		'offering_fingerprint' => str_repeat( '2', 64 ),
		'included_events'     => array(),
	),
	array(
		'option_uuid'         => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
		'ticket_key'          => 'student',
		'label'               => 'Student',
		'description'         => 'Student admission.',
		'price'               => '15.00',
		'classification'      => 'individual',
		'validity_type'       => 'one_day',
		'valid_local_date'    => '2026-10-06',
		'max_attendees'       => 1,
		'offering_fingerprint' => str_repeat( '3', 64 ),
		'included_events'     => array(),
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
oras_training_assert( strlen( (string) json_encode( $seed_a ) ) < 524288, 'Seeded state remains within the bounded training row' );

$service_source = (string) file_get_contents( $service ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.
foreach ( array( 'wc_get_orders', 'wc_get_order', 'WP_Query', 'oras_event_registrations' ) as $forbidden ) {
	oras_training_assert( false === strpos( $service_source, $forbidden ), "Training seeding avoids live order/registration lookup: {$forbidden}" );
}

echo "Registration Desk training checks passed.\n";
