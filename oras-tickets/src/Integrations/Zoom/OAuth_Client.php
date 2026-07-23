<?php

namespace ORAS\Tickets\Integrations\Zoom;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OAuth_Client {

	public const TOKEN_TRANSIENT = 'oras_tickets_zoom_access_token';

	private const TOKEN_ENDPOINT = 'https://zoom.us/oauth/token';

	/**
	 * @return string|\WP_Error
	 */
	public function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$settings = Settings::get();
		$account_id = trim( (string) $settings['account_id'] );
		$client_id = trim( (string) $settings['client_id'] );
		$client_secret = trim( (string) $settings['client_secret'] );

		if ( '' === $account_id || '' === $client_id || '' === $client_secret ) {
			return new \WP_Error(
				'oras_zoom_missing_credentials',
				__( 'Zoom Server-to-Server OAuth credentials are incomplete.', 'oras-tickets' )
			);
		}

		$url = add_query_arg(
			array(
				'grant_type' => 'account_credentials',
				'account_id' => $account_id,
			),
			self::TOKEN_ENDPOINT
		);
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth HTTP Basic credentials.
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error = new \WP_Error(
				'oras_zoom_auth_transport_error',
				$response->get_error_message()
			);
			$error->add_data( array( 'retriable' => true ) );
			return $error;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$message = is_array( $data ) && ! empty( $data['reason'] )
				? sanitize_text_field( (string) $data['reason'] )
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'Zoom authentication failed with HTTP status %d.', 'oras-tickets' ),
					$status
				);

			$error = new \WP_Error(
				'oras_zoom_auth_failed',
				$message,
				array(
					'status'    => $status,
					'retriable' => 429 === $status || $status >= 500,
				)
			);
			return $error;
		}

		$token      = sanitize_text_field( (string) $data['access_token'] );
		$expires_in = max( 300, absint( $data['expires_in'] ?? HOUR_IN_SECONDS ) );
		set_transient( self::TOKEN_TRANSIENT, $token, max( 60, $expires_in - 300 ) );

		return $token;
	}

	public function clear_cached_token(): void {
		delete_transient( self::TOKEN_TRANSIENT );
	}
}
