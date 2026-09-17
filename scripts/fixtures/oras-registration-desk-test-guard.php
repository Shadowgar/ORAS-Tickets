<?php
/**
 * Fail-closed transport guard for the disposable Registration Desk runtime.
 *
 * @package ORAS\Tickets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ORAS_REGISTRATION_DESK_TEST_GUARD_ACTIVE', true );

/**
 * Record that an authenticated dispatcher reached a protected test handler.
 *
 * @param string $transport Dispatcher under test.
 */
function oras_registration_desk_test_dispatch_probe( string $transport ): void {
	$rows   = get_option( 'oras_registration_desk_test_dispatch_probes', array() );
	$rows   = is_array( $rows ) ? $rows : array();
	$rows[] = array(
		'transport' => sanitize_key( $transport ),
		'user_id'   => get_current_user_id(),
	);
	update_option( 'oras_registration_desk_test_dispatch_probes', array_slice( $rows, -20 ), false );
	wp_send_json_success( array( 'probe' => sanitize_key( $transport ) ) );
}

add_action(
	'wp_ajax_oras_registration_desk_probe',
	static function (): void {
		oras_registration_desk_test_dispatch_probe( 'admin_ajax' );
	}
);

add_action(
	'wc_ajax_oras_registration_desk_probe',
	static function (): void {
		oras_registration_desk_test_dispatch_probe( 'wc_ajax' );
	}
);

/**
 * Append a bounded, secret-free transport observation.
 *
 * @param string              $kind Transport kind.
 * @param array<string,mixed> $record Sanitized observation.
 */
function oras_registration_desk_test_log_transport( string $kind, array $record ): void {
	$key  = 'oras_registration_desk_test_' . $kind . '_log';
	$rows = get_option( $key, array() );
	$rows = is_array( $rows ) ? $rows : array();
	$rows[] = $record;
	update_option( $key, array_slice( $rows, -100 ), false );
}

add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if (
			defined( 'ORAS_REGISTRATION_DESK_ALLOW_PLUGIN_DOWNLOADS' )
			&& ORAS_REGISTRATION_DESK_ALLOW_PLUGIN_DOWNLOADS
			&& in_array( $host, array( 'api.wordpress.org', 'downloads.wordpress.org' ), true )
		) {
			return $preempt;
		}

		oras_registration_desk_test_log_transport(
			'http',
			array(
				'host'   => $host,
				'method' => strtoupper( sanitize_key( (string) ( $args['method'] ?? 'GET' ) ) ),
			)
		);

		return new WP_Error( 'oras_registration_desk_external_http_blocked', 'External HTTP is disabled in the Registration Desk test runtime.' );
	},
	PHP_INT_MIN,
	3
);

add_filter(
	'pre_wp_mail',
	static function ( $preempted, array $attributes ) {
		unset( $preempted );
		$recipients = $attributes['to'] ?? array();
		if ( ! is_array( $recipients ) ) {
			$recipients = array_filter( array_map( 'trim', explode( ',', (string) $recipients ) ) );
		}
		oras_registration_desk_test_log_transport(
			'mail',
			array(
				'recipient_count' => count( $recipients ),
				'subject_hash'    => hash( 'sha256', (string) ( $attributes['subject'] ?? '' ) ),
			)
		);

		return true;
	},
	PHP_INT_MIN,
	2
);
