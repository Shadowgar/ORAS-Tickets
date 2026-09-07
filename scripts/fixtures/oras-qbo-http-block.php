<?php
/**
 * Fail-closed HTTP boundary for the repository's disposable QuickBooks tests.
 *
 * A test may provide a preempted WordPress HTTP response. Any unmocked request
 * to an Intuit-owned host is rejected before a network transport is selected.
 *
 * @package OrasTickets
 */

defined( 'ABSPATH' ) || exit;

define( 'ORAS_QBO_HTTP_BLOCK_ACTIVE', true );

add_filter(
	'pre_http_request',
	static function ( $preempt, array $parsed_args, string $url ) {
		unset( $parsed_args );

		if ( false !== $preempt ) {
			return $preempt;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'intuit.com' === $host || str_ends_with( $host, '.intuit.com' ) ) {
			return new WP_Error(
				'oras_qbo_disposable_http_blocked',
				'Unmocked Intuit HTTP is blocked in the disposable QuickBooks test environment.'
			);
		}

		return $preempt;
	},
	PHP_INT_MAX,
	3
);
