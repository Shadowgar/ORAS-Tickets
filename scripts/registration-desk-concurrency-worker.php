<?php
/**
 * One independent Registration Desk database/process concurrency worker.
 *
 * @package ORAS\Tickets
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$expected = defined( 'ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED' ) ? ORAS_REGISTRATION_DESK_DISPOSABLE_MARKER_EXPECTED : '';
$worker   = defined( 'ORAS_REGISTRATION_DESK_WORKER_INDEX' ) ? (int) ORAS_REGISTRATION_DESK_WORKER_INDEX : 0;
if (
	'' === $expected
	|| ! hash_equals( (string) $expected, (string) get_option( 'oras_registration_desk_disposable_fixture_id', '' ) )
	|| 'tests-wordpress' !== DB_NAME
	|| 'tests-mysql' !== DB_HOST
	|| ! in_array( $worker, array( 1, 2 ), true )
) {
	WP_CLI::error( 'Concurrency worker refused an unverified runtime.' );
}

$context = get_option( 'oras_registration_desk_integration_context', array() );
if ( ! is_array( $context ) || empty( $context['concurrency'] ) ) {
	WP_CLI::error( 'Concurrency fixture context is missing.' );
}

wp_set_current_user( (int) $context['desk_id'] );
rest_get_server();
$token_key   = 1 === $worker ? 'token_one' : 'token_two';
$request_key = 1 === $worker ? 'request_one' : 'request_two';
$request     = new WP_REST_Request(
	'POST',
	'/oras-tickets/v1/registration-desk/registrations/' . $context['concurrency']['registration_uuid'] . '/confirm-and-check-in'
);
$request->set_header( 'X-ORAS-Desk-Station', (string) $context['concurrency'][ $token_key ] );
$request->set_header( 'X-ORAS-Desk-Request', (string) $context['concurrency'][ $request_key ] );
$request->set_body_params(
	array(
		'first_name'            => 'Concurrent',
		'last_name'             => 'Arrival',
		'attendance_local_date' => (string) $context['today'],
		'explicit_unpaid'       => false,
	)
);
$response = rest_do_request( $request );
$data     = $response->get_data();
if ( 200 !== $response->get_status() || ! is_array( $data ) ) {
	$code = is_array( $data ) ? sanitize_key( (string) ( $data['code'] ?? 'unknown' ) ) : 'invalid_response';
	WP_CLI::error( 'Concurrent check-in failed with safe code: ' . $code );
}

$result = sanitize_key( (string) ( $data['historical_result']['result'] ?? '' ) );
if ( ! in_array( $result, array( 'checked_in', 'already_checked_in' ), true ) ) {
	WP_CLI::error( 'Concurrent check-in returned an unexpected result.' );
}

WP_CLI::log(
	wp_json_encode(
		array(
			'worker'   => $worker,
			'result'   => $result,
			'replayed' => ! empty( $data['replayed'] ),
		)
	)
);
