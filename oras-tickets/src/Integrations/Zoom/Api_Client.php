<?php

namespace ORAS\Tickets\Integrations\Zoom;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Api_Client implements Api_Interface {

	private const API_BASE = 'https://api.zoom.us/v2';

	private OAuth_Client $oauth;

	public function __construct( ?OAuth_Client $oauth = null ) {
		$this->oauth = $oauth ?? new OAuth_Client();
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_meeting( string $meeting_id ) {
		return $this->request( 'GET', $this->meeting_path( $meeting_id ) );
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_meeting_invitation( string $meeting_id ) {
		return $this->request( 'GET', $this->meeting_path( $meeting_id ) . '/invitation' );
	}

	/**
	 * @param array<string,mixed> $registrant
	 * @return array<string,mixed>|\WP_Error
	 */
	public function add_meeting_registrant( string $meeting_id, array $registrant ) {
		return $this->request( 'POST', $this->meeting_path( $meeting_id ) . '/registrants', $registrant );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function update_registrant_status( string $meeting_id, string $registrant_id, string $email, string $action ) {
		if ( ! in_array( $action, array( 'approve', 'cancel', 'deny' ), true ) ) {
			return new \WP_Error(
				'oras_zoom_invalid_registrant_action',
				__( 'Invalid Zoom registrant action.', 'oras-tickets' )
			);
		}

		$result = $this->request(
			'PUT',
			$this->meeting_path( $meeting_id ) . '/registrants/status',
			array(
				'action'      => $action,
				'registrants' => array(
					array(
						'id'    => sanitize_text_field( $registrant_id ),
						'email' => sanitize_email( $email ),
					),
				),
			)
		);

		return is_wp_error( $result ) ? $result : true;
	}

	private function meeting_path( string $meeting_id ): string {
		$meeting_id = preg_replace( '/\D+/', '', $meeting_id );
		return '/meetings/' . rawurlencode( is_string( $meeting_id ) ? $meeting_id : '' );
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null, bool $allow_auth_retry = true ) {
		$token = $this->oauth->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status && $allow_auth_retry ) {
			$this->oauth->clear_cached_token();
			return $this->request( $method, $path, $body, false );
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = '' === trim( $raw ) ? array() : json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] )
				? sanitize_text_field( (string) $data['message'] )
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'Zoom API request failed with HTTP status %d.', 'oras-tickets' ),
					$status
				);
			$error = new \WP_Error( 'oras_zoom_api_error', $message );
			$error->add_data(
				array(
					'status'    => $status,
					'endpoint'  => $path,
					'retriable' => 429 === $status || $status >= 500,
				)
			);
			return $error;
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'oras_zoom_invalid_response',
				__( 'Zoom returned an invalid response.', 'oras-tickets' )
			);
		}

		return $data;
	}
}
