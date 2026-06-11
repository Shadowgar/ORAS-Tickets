<?php

namespace ORAS\Tickets;

use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Frontend\Event_RSVP;
use ORAS\Tickets\Reporting\Board_Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Communication_Recipients {

	public const SEGMENT_ALL_ATTENDEES = 'all_attendees';
	public const SEGMENT_TICKET_PURCHASERS = 'ticket_purchasers';
	public const SEGMENT_RSVP_ATTENDEES = 'rsvp_attendees';
	public const SEGMENT_APPROVED_VIRTUAL = 'approved_virtual_attendees';
	public const SEGMENT_PENDING_VIRTUAL = 'pending_virtual_attendees';
	public const SEGMENT_ONSITE = 'onsite_attendees';
	public const SEGMENT_EVENT_CANCELLATION = 'event_cancellation';
	public const SEGMENT_EVENT_UPDATE = 'event_update';

	private Board_Report_Service $reports;

	public function __construct( ?Board_Report_Service $reports = null ) {
		$this->reports = null !== $reports ? $reports : new Board_Report_Service();
	}

	/**
	 * @return array<string,string>
	 */
	public static function get_segments(): array {
		return array(
			self::SEGMENT_ALL_ATTENDEES       => __( 'All attendees', 'oras-tickets' ),
			self::SEGMENT_TICKET_PURCHASERS  => __( 'Ticket purchasers', 'oras-tickets' ),
			self::SEGMENT_RSVP_ATTENDEES     => __( 'RSVP attendees', 'oras-tickets' ),
			self::SEGMENT_APPROVED_VIRTUAL   => __( 'Approved virtual attendees', 'oras-tickets' ),
			self::SEGMENT_PENDING_VIRTUAL    => __( 'Pending virtual attendees', 'oras-tickets' ),
			self::SEGMENT_ONSITE             => __( 'On-site attendees', 'oras-tickets' ),
			self::SEGMENT_EVENT_CANCELLATION => __( 'Event cancellation recipients', 'oras-tickets' ),
			self::SEGMENT_EVENT_UPDATE       => __( 'Event update recipients', 'oras-tickets' ),
		);
	}

	public static function normalize_segment( string $segment ): string {
		$segment = sanitize_key( $segment );

		return isset( self::get_segments()[ $segment ] ) ? $segment : self::SEGMENT_ALL_ATTENDEES;
	}

	public static function get_segment_label( string $segment ): string {
		$segments = self::get_segments();
		$segment = self::normalize_segment( $segment );

		return $segments[ $segment ];
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function resolve( int $event_id, string $segment ): array {
		if ( $event_id <= 0 ) {
			return array();
		}

		$segment = self::normalize_segment( $segment );

		switch ( $segment ) {
			case self::SEGMENT_TICKET_PURCHASERS:
				$rows = $this->reports->get_event_ticket_buyers( $event_id, array( 'status' => 'all', 'search' => '' ) );
				break;

			case self::SEGMENT_RSVP_ATTENDEES:
				$rows = $this->reports->get_rsvp_attendees( $event_id, $this->rsvp_filters() );
				break;

			case self::SEGMENT_APPROVED_VIRTUAL:
				$rows = $this->reports->get_rsvp_attendees(
					$event_id,
					$this->rsvp_filters( Ticket::ATTENDANCE_MODE_VIRTUAL, Event_RSVP::APPROVAL_STATUS_APPROVED )
				);
				break;

			case self::SEGMENT_PENDING_VIRTUAL:
				$rows = $this->reports->get_rsvp_attendees(
					$event_id,
					$this->rsvp_filters( Ticket::ATTENDANCE_MODE_VIRTUAL, Event_RSVP::APPROVAL_STATUS_PENDING )
				);
				break;

			case self::SEGMENT_ONSITE:
				$rows = $this->reports->get_unified_attendees(
					$event_id,
					array(
						'attendee_source' => 'all',
						'ticket_status'   => 'all',
						'rsvp_status'     => 'all',
						'attendance_type' => Ticket::ATTENDANCE_MODE_ONSITE,
						'approval_status' => 'all',
						'search'          => '',
					)
				);
				break;

			case self::SEGMENT_EVENT_CANCELLATION:
			case self::SEGMENT_EVENT_UPDATE:
			case self::SEGMENT_ALL_ATTENDEES:
			default:
				$rows = $this->reports->get_unified_attendees(
					$event_id,
					array(
						'attendee_source' => 'all',
						'ticket_status'   => 'all',
						'rsvp_status'     => 'all',
						'attendance_type' => 'all',
						'approval_status' => 'all',
						'search'          => '',
					)
				);
				break;
		}

		return $this->normalize_and_dedupe( $rows, $segment );
	}

	/**
	 * @return array<string,string>
	 */
	private function rsvp_filters( string $attendance_type = 'all', string $approval_status = 'all' ): array {
		return array(
			'status'          => 'all',
			'attendance_type' => $attendance_type,
			'approval_status' => $approval_status,
			'search'          => '',
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_and_dedupe( array $rows, string $segment ): array {
		$recipients = array();

		foreach ( $rows as $row ) {
			$email = isset( $row['email'] ) && is_scalar( $row['email'] ) ? sanitize_email( (string) $row['email'] ) : '';
			$email_key = strtolower( trim( $email ) );
			if ( '' === $email_key || ! is_email( $email_key ) ) {
				continue;
			}

			if ( isset( $recipients[ $email_key ] ) ) {
				$recipients[ $email_key ] = $this->merge_recipient_context( $recipients[ $email_key ], $row );
				continue;
			}

			$recipients[ $email_key ] = array(
				'name'            => $this->row_string( $row, 'name' ),
				'email'           => $email_key,
				'source'          => $this->row_string( $row, 'source' ),
				'item_label'      => $this->row_string( $row, 'item_label' ),
				'order_status'    => $this->row_string( $row, 'order_status' ),
				'attendance_mode' => $this->row_string( $row, 'attendance_type' ),
				'attendance_label' => $this->row_string( $row, 'attendance_label' ),
				'approval_status' => $this->row_string( $row, 'approval_status' ),
				'approval_label'  => $this->row_string( $row, 'approval_label' ),
				'user_id'         => absint( $row['user_id'] ?? 0 ),
				'order_id'        => absint( $row['order_id'] ?? 0 ),
				'segment'         => $segment,
			);
		}

		return array_values( $recipients );
	}

	/**
	 * @param array<string,mixed> $recipient
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function merge_recipient_context( array $recipient, array $row ): array {
		foreach ( array( 'source', 'item_label' ) as $field ) {
			$value = $this->row_string( $row, $field );
			if ( '' !== $value && false === stripos( (string) ( $recipient[ $field ] ?? '' ), $value ) ) {
				$recipient[ $field ] = '' === (string) ( $recipient[ $field ] ?? '' )
					? $value
					: (string) $recipient[ $field ] . ' + ' . $value;
			}
		}

		foreach ( array( 'name', 'order_status', 'attendance_type', 'attendance_label', 'approval_status', 'approval_label' ) as $field ) {
			$target_field = 'attendance_type' === $field ? 'attendance_mode' : $field;
			if ( empty( $recipient[ $target_field ] ) ) {
				$value = $this->row_string( $row, $field );
				if ( '' !== $value ) {
					$recipient[ $target_field ] = $value;
				}
			}
		}

		if ( empty( $recipient['user_id'] ) ) {
			$recipient['user_id'] = absint( $row['user_id'] ?? 0 );
		}
		if ( empty( $recipient['order_id'] ) ) {
			$recipient['order_id'] = absint( $row['order_id'] ?? 0 );
		}

		return $recipient;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function row_string( array $row, string $key ): string {
		return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? sanitize_text_field( (string) $row[ $key ] ) : '';
	}
}
