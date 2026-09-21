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

oras_training_assert( file_exists( $schema ), 'Registration Desk schema exists' );
oras_training_assert( file_exists( $base_store ), 'Registration Desk base store exists' );
oras_training_assert( file_exists( $store ), 'Dedicated Training Store exists' );
oras_training_assert( file_exists( $context ), 'Server-authorized Training Context exists' );

require_once $schema;
require_once $base_store;
require_once $store;
require_once $context;

$schema_class = '\\ORAS\\Tickets\\Registration_Desk\\Schema';
$store_class  = '\\ORAS\\Tickets\\Registration_Desk\\Training_Store';
$context_class = '\\ORAS\\Tickets\\Registration_Desk\\Training_Context';

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

echo "Registration Desk training checks passed.\n";
