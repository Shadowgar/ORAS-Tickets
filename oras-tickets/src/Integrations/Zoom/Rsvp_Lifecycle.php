<?php

namespace ORAS\Tickets\Integrations\Zoom;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rsvp_Lifecycle {

	private Registration_Service $registrations;
	private Meeting_Service $meetings;

	public function __construct(
		?Registration_Service $registrations = null,
		?Meeting_Service $meetings = null
	) {
		$this->registrations = $registrations ?? new Registration_Service();
		$this->meetings      = $meetings ?? new Meeting_Service();
	}

	public function register(): void {
		add_action(
			'oras_tickets_rsvp_approval_status_changed',
			array( $this, 'handle_approval_status_changed' ),
			10,
			5
		);
		add_action(
			'oras_tickets_rsvp_cancelled',
			array( $this, 'handle_rsvp_cancelled' ),
			10,
			4
		);
		add_filter(
			'oras_tickets_virtual_rsvp_access_details',
			array( $this, 'filter_access_details' ),
			10,
			5
		);
	}

	/**
	 * @param array<string,mixed> $contact
	 */
	public function handle_approval_status_changed(
		int $event_id,
		int $user_id,
		string $approval_status,
		array $contact,
		string $attendance_mode
	): void {
		if ( 'virtual' !== $attendance_mode || ! Registration_Service::is_managed_event( $event_id ) ) {
			return;
		}

		$email = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		if ( 'approved' === $approval_status ) {
			$this->registrations->register_attendee(
				$event_id,
				'rsvp',
				'user-' . $user_id,
				$email,
				(string) ( $contact['first_name'] ?? '' ),
				(string) ( $contact['last_name'] ?? '' ),
				$user_id
			);
			return;
		}

		$this->registrations->cancel_entitlement(
			$event_id,
			'rsvp',
			'user-' . $user_id,
			$email
		);
	}

	public function handle_rsvp_cancelled(
		int $event_id,
		int $user_id,
		string $email,
		string $attendance_mode
	): void {
		if ( 'virtual' !== $attendance_mode ) {
			return;
		}

		$this->registrations->cancel_entitlement(
			$event_id,
			'rsvp',
			'user-' . $user_id,
			sanitize_email( $email )
		);
	}

	/**
	 * @param array<string,mixed> $access
	 * @return array<string,mixed>
	 */
	public function filter_access_details(
		array $access,
		int $event_id,
		int $user_id,
		string $approval_status,
		string $email
	): array {
		unset( $user_id );
		if ( 'approved' !== $approval_status || ! Registration_Service::is_managed_event( $event_id ) ) {
			return $access;
		}

		$registration = $this->registrations->get_active_registration( $event_id, $email );
		if ( empty( $registration['join_url'] ) ) {
			return $access;
		}

		$invitation = $this->meetings->get_invitation_for_event( $event_id );
		if ( ! is_wp_error( $invitation ) ) {
			$access = array_merge( $access, $invitation );
		}
		$access['join_url'] = esc_url_raw( (string) $registration['join_url'] );
		$access['managed']  = true;

		return $access;
	}
}
