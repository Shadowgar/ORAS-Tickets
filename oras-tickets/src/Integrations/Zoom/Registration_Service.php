<?php

namespace ORAS\Tickets\Integrations\Zoom;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Registration_Service {

	public const EVENT_CONFIG_META = '_oras_zoom_integration_v1';

	private Api_Interface $api;
	private Registration_Repository $repository;

	public function __construct( ?Api_Interface $api = null, ?Registration_Repository $repository = null ) {
		$this->api        = $api ?? new Api_Client();
		$this->repository = $repository ?? new Registration_Store();
	}

	public static function is_managed_event( int $event_id ): bool {
		$config = get_post_meta( $event_id, self::EVENT_CONFIG_META, true );
		return is_array( $config ) && ! empty( $config['enabled'] );
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function register_attendee(
		int $event_id,
		string $source_type,
		string $source_ref,
		string $email,
		string $first_name,
		string $last_name,
		int $user_id = 0
	) {
		if ( ! Settings::is_enabled() || ! Settings::has_credentials() ) {
			return new \WP_Error(
				'oras_zoom_registration_disabled',
				__( 'The ORAS Zoom integration is not fully configured.', 'oras-tickets' )
			);
		}

		if ( ! self::is_managed_event( $event_id ) ) {
			return new \WP_Error(
				'oras_zoom_registration_not_managed',
				__( 'Managed Zoom registration is not enabled for this event.', 'oras-tickets' )
			);
		}

		$source_type = sanitize_key( $source_type );
		$source_ref  = sanitize_text_field( $source_ref );
		$email       = sanitize_email( $email );
		$meeting_id  = Meeting_Service::resolve_meeting_id( $event_id );
		if ( ! in_array( $source_type, array( 'ticket', 'rsvp' ), true )
			|| '' === $source_ref
			|| '' === $email
			|| '' === $meeting_id
		) {
			return new \WP_Error(
				'oras_zoom_invalid_registration',
				__( 'Zoom registration requires a valid event, source, meeting, and attendee email.', 'oras-tickets' )
			);
		}

		$existing = $this->repository->find_by_event_email( $event_id, $meeting_id, $email );
		if ( ! empty( $existing['id'] )
			&& 'active' === (string) ( $existing['status'] ?? '' )
			&& '' !== (string) ( $existing['join_url'] ?? '' )
		) {
			$this->repository->activate_source( absint( $existing['id'] ), $source_type, $source_ref );
			return $existing;
		}

		$response = $this->api->add_meeting_registrant(
			$meeting_id,
			array(
				'email'      => $email,
				'first_name' => sanitize_text_field( $first_name ),
				'last_name'  => sanitize_text_field( $last_name ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$registrant_id = sanitize_text_field( (string) ( $response['registrant_id'] ?? '' ) );
		$join_url      = esc_url_raw( (string) ( $response['join_url'] ?? '' ) );
		if ( '' === $registrant_id || '' === $join_url ) {
			return new \WP_Error(
				'oras_zoom_registration_missing_join_url',
				__( 'Zoom created the registration without a usable join URL. Confirm that automatic meeting registration approval is enabled.', 'oras-tickets' )
			);
		}

		$saved = $this->repository->save_registration(
			array(
				'event_id'      => $event_id,
				'user_id'       => $user_id,
				'meeting_id'    => $meeting_id,
				'email'         => $email,
				'first_name'    => $first_name,
				'last_name'     => $last_name,
				'registrant_id' => $registrant_id,
				'join_url'      => $join_url,
				'status'        => 'active',
				'last_error'    => '',
			)
		);
		if ( empty( $saved['id'] ) ) {
			return new \WP_Error(
				'oras_zoom_registration_store_failed',
				__( 'ORAS could not store the Zoom registration.', 'oras-tickets' )
			);
		}

		$this->repository->activate_source( absint( $saved['id'] ), $source_type, $source_ref );
		return $saved;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_active_registration( int $event_id, string $email ): array {
		$meeting_id = Meeting_Service::resolve_meeting_id( $event_id );
		if ( '' === $meeting_id ) {
			return array();
		}

		$registration = $this->repository->find_by_event_email(
			$event_id,
			$meeting_id,
			sanitize_email( $email )
		);

		return 'active' === (string) ( $registration['status'] ?? '' )
			? $registration
			: array();
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function cancel_entitlement(
		int $event_id,
		string $source_type,
		string $source_ref,
		string $email
	) {
		if ( ! self::is_managed_event( $event_id ) ) {
			return array(
				'cancelled' => false,
				'reason'    => 'not_managed',
			);
		}

		$email      = sanitize_email( $email );
		$meeting_id = Meeting_Service::resolve_meeting_id( $event_id );
		$existing   = $this->repository->find_by_event_email( $event_id, $meeting_id, $email );
		if ( empty( $existing['id'] ) ) {
			return array(
				'cancelled' => false,
				'reason'    => 'not_registered',
			);
		}

		$registration_id = absint( $existing['id'] );
		$this->repository->deactivate_source(
			$registration_id,
			sanitize_key( $source_type ),
			sanitize_text_field( $source_ref )
		);
		if ( $this->repository->has_active_sources( $registration_id ) ) {
			return array(
				'cancelled' => false,
				'reason'    => 'other_entitlement',
			);
		}

		$result = $this->api->update_registrant_status(
			$meeting_id,
			(string) ( $existing['registrant_id'] ?? '' ),
			$email,
			'cancel'
		);
		if ( is_wp_error( $result ) ) {
			$this->repository->update_status( $registration_id, 'sync_error', $result->get_error_message() );
			return $result;
		}

		$this->repository->update_status( $registration_id, 'cancelled' );
		return array(
			'cancelled' => true,
			'reason'    => 'final_entitlement',
		);
	}
}
