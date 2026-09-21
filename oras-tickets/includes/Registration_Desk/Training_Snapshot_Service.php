<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds a read-only event-roster snapshot for one isolated training session. */
final class Training_Snapshot_Service {
	private const PAGE_SIZE = 50;
	private const MAX_REGISTRATIONS = 5000;

	private Event_Roster_Service $roster;
	private Service $service;

	public function __construct( ?Event_Roster_Service $roster = null, ?Service $service = null ) {
		$this->roster  = $roster ?? new Event_Roster_Service();
		$this->service = $service ?? new Service();
	}

	/**
	 * @param array<int,array<string,mixed>> $offerings
	 * @return array<string,mixed>|\WP_Error
	 */
	public function capture( int $event_id, string $training_uuid, array $offerings, string $simulated_local_date ) {
		$registrations = array();
		$offset        = 0;
		do {
			$page = $this->roster->get(
				$event_id,
				array(
					'status' => 'everyone',
					'offset' => $offset,
					'limit'  => self::PAGE_SIZE,
				)
			);
			foreach ( $page['items'] as $item ) {
				if ( 'public_rsvp' === (string) ( $item['detail_kind'] ?? '' ) ) {
					$normalized = self::normalize_public_rsvp( $training_uuid, $item );
				} else {
					$detail_date = 'one_day' === (string) ( $item['validity_type'] ?? '' ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $item['valid_local_date'] ?? '' ) )
						? (string) $item['valid_local_date']
						: $simulated_local_date;
					$detail = $this->service->detail( $event_id, (string) ( $item['registration_uuid'] ?? '' ), $detail_date, true );
					if ( $detail instanceof \WP_Error ) {
						return new \WP_Error( 'oras_desk_training_snapshot_failed', 'The current event roster could not be copied into Training Mode.', array( 'status' => 409 ) );
					}
					$normalized = self::normalize_registration( $item, $detail );
				}
				$registrations[ $normalized['registration_uuid'] ] = $normalized;
			}
			$offset += count( $page['items'] );
			if ( ! empty( $page['has_more'] ) && $offset >= self::MAX_REGISTRATIONS ) {
				return new \WP_Error( 'oras_desk_training_snapshot_too_large', 'The complete event roster is too large to copy safely into Training Mode.', array( 'status' => 409 ) );
			}
		} while ( ! empty( $page['has_more'] ) );

		return array(
			'schema_version'    => 2,
			'seed_version'      => 2,
			'snapshot_event_id' => $event_id,
			'snapshot_at_utc'   => gmdate( 'Y-m-d H:i:s' ),
			'registrations'     => $registrations,
			'attendance'        => array(),
			'requests'          => array(),
			'memberships'       => array(),
			'member_directory'  => array(),
			'history'           => array(),
			'offerings'         => $offerings,
		);
	}

	/**
	 * @param array<string,mixed> $roster_item
	 * @param array<string,mixed> $detail
	 * @return array<string,mixed>
	 */
	public static function normalize_registration( array $roster_item, array $detail ): array {
		$registration = is_array( $detail['registration'] ?? null ) ? $detail['registration'] : array();
		$attendees    = array();
		foreach ( is_array( $detail['attendees'] ?? null ) ? $detail['attendees'] : array() as $attendee ) {
			if ( ! is_array( $attendee ) ) {
				continue;
			}
			unset( $attendee['current_attendance'] );
			$attendee['attendee_uuid'] = (string) ( $attendee['attendee_uuid'] ?? '' );
			$attendee['name']          = (string) ( $attendee['display_name'] ?? '' );
			$attendees[]               = $attendee;
		}
		$detail['attendees']  = $attendees;
		$detail['local_date'] = '';
		$detail['registration'] = array(
			'registration_uuid' => (string) ( $registration['registration_uuid'] ?? $roster_item['registration_uuid'] ?? '' ),
			'event_id'          => (int) ( $registration['event_id'] ?? 0 ),
			'option_uuid'       => (string) ( $registration['option_uuid'] ?? '' ),
			'source_type'       => (string) ( $registration['source_type'] ?? $roster_item['source_type'] ?? 'online' ),
			'classification'    => (string) ( $registration['classification'] ?? $roster_item['classification'] ?? 'individual' ),
			'status'            => (string) ( $registration['status'] ?? 'active' ),
			'website_status'    => (string) ( $registration['source_status'] ?? '' ),
			'contact_name'      => (string) ( $registration['source_contact_name'] ?? $roster_item['name'] ?? '' ),
			'contact_email'     => self::mask_email( (string) ( $registration['source_email'] ?? '' ) ),
			'contact_phone'     => self::mask_phone( (string) ( $registration['source_phone'] ?? $roster_item['phone'] ?? '' ) ),
			'validity_type'     => (string) ( $registration['validity_type'] ?? $roster_item['validity_type'] ?? 'full_event' ),
			'valid_local_date'  => (string) ( $registration['valid_local_date'] ?? $roster_item['valid_local_date'] ?? '' ),
			'payment_assertion' => (string) ( $registration['payment_assertion'] ?? '' ),
			'registration_type' => (string) ( $roster_item['registration_type'] ?? 'Event registration' ),
			'record_version'    => (int) ( $registration['record_version'] ?? 1 ),
		);

		return array(
			'registration_uuid' => (string) ( $registration['registration_uuid'] ?? $roster_item['registration_uuid'] ?? '' ),
			'option_uuid'       => (string) ( $registration['option_uuid'] ?? '' ),
			'option_label'      => (string) ( $roster_item['registration_type'] ?? 'Event registration' ),
			'classification'    => (string) ( $registration['classification'] ?? $roster_item['classification'] ?? 'individual' ),
			'validity_type'     => (string) ( $registration['validity_type'] ?? $roster_item['validity_type'] ?? 'full_event' ),
			'valid_local_date'  => (string) ( $registration['valid_local_date'] ?? $roster_item['valid_local_date'] ?? '' ),
			'contact_name'      => (string) ( $registration['source_contact_name'] ?? $roster_item['name'] ?? '' ),
			'email'             => (string) ( $registration['source_email'] ?? '' ),
			'phone'             => (string) ( $registration['source_phone'] ?? $roster_item['phone'] ?? '' ),
			'source_type'       => (string) ( $registration['source_type'] ?? $roster_item['source_type'] ?? 'online' ),
			'payment_assertion' => (string) ( $registration['payment_assertion'] ?? '' ),
			'attendees'         => $attendees,
			'roster_item'       => $roster_item,
			'live_detail'       => $detail,
			'snapshot'          => true,
		);
	}

	/** @param array<string,mixed> $item @return array<string,mixed> */
	private static function normalize_public_rsvp( string $training_uuid, array $item ): array {
		$uuid = self::deterministic_uuid( $training_uuid, 'rsvp|' . (string) ( $item['rsvp_user_id'] ?? $item['row_id'] ?? '' ) );
		$attendee_uuid = self::deterministic_uuid( $training_uuid, 'rsvp-attendee|' . $uuid );
		$waitlisted = 'waitlist' === (string) ( $item['rsvp_status'] ?? '' );
		$attendee = array(
			'attendee_uuid' => $attendee_uuid,
			'slot_key'      => 'individual-1',
			'display_name'  => (string) ( $item['name'] ?? '' ),
			'name'          => (string) ( $item['name'] ?? '' ),
			'first_name'    => '',
			'last_name'     => '',
			'admission'     => array(
				'state'             => $waitlisted ? 'rsvp_waitlisted' : 'eligible',
				'selection_allowed' => ! $waitlisted,
				'status_label'      => $waitlisted ? 'WAITLISTED — NOT ADMITTED' : 'READY TO CHECK IN',
			),
		);
		$registration = array(
			'registration_uuid'   => $uuid,
			'option_uuid'         => '',
			'source_contact_name' => (string) ( $item['name'] ?? '' ),
			'source_email'        => '',
			'source_phone'        => (string) ( $item['phone'] ?? '' ),
			'source_type'         => $waitlisted ? 'rsvp_waitlist' : 'rsvp_website',
			'classification'      => 'individual',
			'validity_type'       => 'full_event',
			'valid_local_date'    => '',
			'payment_assertion'   => '',
		);
		$item['registration_uuid'] = $uuid;
		$item['detail_kind']       = 'registration';

		return array(
			'registration_uuid' => $uuid,
			'option_uuid'       => '',
			'option_label'      => (string) ( $item['registration_type'] ?? 'Event RSVP' ),
			'classification'    => 'individual',
			'validity_type'     => 'full_event',
			'valid_local_date'  => '',
			'contact_name'      => (string) ( $item['name'] ?? '' ),
			'email'             => '',
			'phone'             => (string) ( $item['phone'] ?? '' ),
			'source_type'       => (string) $registration['source_type'],
			'payment_assertion' => '',
			'attendees'         => array( $attendee ),
			'roster_item'       => $item,
			'live_detail'       => array(
				'registration' => $registration,
				'attendees'    => array( $attendee ),
				'local_date'   => '',
				'admission'    => array(
					'state'             => $waitlisted ? 'rsvp_waitlisted' : 'eligible',
					'status_label'      => $waitlisted ? 'WAITLISTED — NOT ADMITTED' : 'REGISTRATION VALID',
					'message'           => $waitlisted ? 'This person is on the waitlist and cannot be checked in.' : '',
					'selection_allowed' => ! $waitlisted,
					'check_in_allowed'  => ! $waitlisted,
					'allowed'           => ! $waitlisted,
					'maximum_attendees' => 1,
				),
				'coverage'     => array(
					'complete'    => true,
					'limitations' => array(),
				),
			),
			'snapshot'          => true,
		);
	}

	private static function deterministic_uuid( string $namespace_uuid, string $key ): string {
		$hex = substr( hash( 'sha256', strtolower( $namespace_uuid ) . '|' . $key ), 0, 32 );
		$hex[12] = '4';
		$hex[16] = dechex( ( hexdec( $hex[16] ) & 0x3 ) | 0x8 );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	private static function mask_email( string $email ): string {
		$parts = explode( '@', $email, 2 );

		return 2 === count( $parts ) ? substr( $parts[0], 0, 1 ) . '***@' . $parts[1] : '';
	}

	private static function mask_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';

		return strlen( $digits ) >= 4 ? '***-***-' . substr( $digits, -4 ) : '';
	}
}
