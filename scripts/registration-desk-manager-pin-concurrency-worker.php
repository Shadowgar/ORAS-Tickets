<?php
/** Independent guarded manager PIN attempt worker. */

use ORAS\Tickets\Registration_Desk\Manager_Access;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
$expected = defined( 'ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED' ) ? (string) ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED : '';
$worker = defined( 'ORAS_REGISTRATION_DESK_WORKER_INDEX' ) ? (int) ORAS_REGISTRATION_DESK_WORKER_INDEX : 0;
if ( '' === $expected || ! hash_equals( $expected, (string) get_option( 'oras_registration_desk_disposable_fixture_id', '' ) ) || 'tests-wordpress' !== DB_NAME || 'tests-mysql' !== DB_HOST || ! defined( 'ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE' ) || ! ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE || $worker < 1 || $worker > 5 ) {
	WP_CLI::error( 'Manager PIN concurrency worker refused an unverified runtime.' );
}
$context = get_option( 'oras_registration_desk_integration_context', array() );
if ( ! is_array( $context ) || empty( $context['desk_id'] ) ) {
	WP_CLI::error( 'Manager PIN concurrency context is missing.' );
}
update_option( 'oras_registration_desk_manager_race_ready_' . $worker, 'yes', false );
global $wpdb;
$deadline = microtime( true ) + 45;
while ( microtime( true ) < $deadline ) {
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'oras_registration_desk_manager_race_release' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core options table.
	if ( 'yes' === $value ) {
		break;
	}
	usleep( 50000 );
}
if ( 'yes' !== $value ) {
	WP_CLI::error( 'Manager PIN concurrency barrier timed out.' );
}
$result = Manager_Access::unlock(
	'0000',
	array(
		'user_id'      => (int) $context['desk_id'],
		'station_uuid' => wp_generate_uuid4(),
	),
	'different-station-' . $worker
);
if ( ! is_wp_error( $result ) || 'oras_desk_pin_incorrect' !== $result->get_error_code() ) {
	WP_CLI::error( 'Manager PIN worker did not consume exactly one failed attempt.' );
}
WP_CLI::log( 'PASS: independent failed PIN attempt ' . $worker );
