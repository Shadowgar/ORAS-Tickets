<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Domain\Event_Offering_Resolver;
use ORAS\Tickets\Support\DbLock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Service {
	private Registration_Store $registrations;
	private Attendee_Store $attendees;
	private Attendance_Store $attendance;
	private Audit_Store $audits;
	private Source_Adapter $source_adapter;

	public function __construct(
		?Registration_Store $registrations = null,
		?Attendee_Store $attendees = null,
		?Attendance_Store $attendance = null,
		?Audit_Store $audits = null,
		?Source_Adapter $source_adapter = null
	) {
		$this->registrations = $registrations ?? new Registration_Store();
		$this->attendees     = $attendees ?? new Attendee_Store();
		$this->attendance    = $attendance ?? new Attendance_Store();
		$this->audits        = $audits ?? new Audit_Store();
		$this->source_adapter = $source_adapter ?? new Source_Adapter();
	}

	/** @param array<string,mixed> $payload */
	public static function payload_hash( array $payload ): string {
		$normalized = self::canonicalize( $payload );
		$json       = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return hash( 'sha256', is_string( $json ) ? $json : '{}' );
	}

	public static function date_is_within_event( string $date, string $start, string $end ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && $date >= $start && $date <= $end;
	}

	public static function payment_assertion_is_valid( string $statement ): bool {
		return in_array( sanitize_key( $statement ), array( 'paid_card', 'paid_cash', 'paid_check', 'unpaid' ), true );
	}

	/** @return array<string,mixed> */
	public function dashboard( int $event_id ): array {
		$today = wp_date( 'Y-m-d', null, wp_timezone() );

		return array(
			'local_date'    => $today,
			'friendly_date' => wp_date( 'l, F j, Y', null, wp_timezone() ),
			'summary'       => $this->attendance->dashboard( $event_id, $today ),
			'recent'        => $this->attendance->recent_detailed( $event_id, 12 ),
		);
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	public function create_walk_in( array $payload, array $context ) {
		if ( ! Event_Offering_Resolver::has_canonical_tickets( (int) $context['event_id'] ) ) {
			$rsvp = get_post_meta( (int) $context['event_id'], '_oras_rsvp_v1', true );
			if ( is_array( $rsvp ) && ! empty( $rsvp['enabled'] ) ) {
				return $this->create_rsvp_walk_in( $payload, $context );
			}
		}

		return $this->create_manual_registration( 'walk_in', $payload, $context, false );
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	public function create_complimentary( array $payload, array $context ) {
		$kind = sanitize_key( (string) ( $payload['source_type'] ?? 'complimentary' ) );
		if ( ! in_array( $kind, array( 'complimentary', 'speaker' ), true ) ) {
			return new \WP_Error( 'oras_desk_registration_kind_invalid', 'Choose complimentary or speaker registration.', array( 'status' => 400 ) );
		}
		$payload['payment_assertion'] = 'complimentary';

		return $this->create_manual_registration( $kind, $payload, $context, true );
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	public function create_manager_verified( array $payload, array $context ) {
		$binding = $this->binding( 'create_manager_verified_manual', $payload, $context );
		if ( $binding instanceof \WP_Error ) {
			return $binding;
		}
		$replay = $this->replay_or_conflict( $binding );
		if ( null !== $replay ) {
			return $replay;
		}
		$event_id = (int) $context['event_id'];
		$config   = Config::get_event_config( $event_id );
		$option   = Event_Offering_Resolver::find_desk_offering( $event_id, $config, sanitize_text_field( (string) ( $payload['option_uuid'] ?? '' ) ) );
		if ( null === $option || empty( $option['available_for_new'] ) ) {
			return new \WP_Error( 'oras_desk_option_invalid', 'Choose an available registration option.', array( 'status' => 400 ) );
		}
		if ( ! hash_equals( (string) $option['offering_fingerprint'], sanitize_text_field( (string) ( $payload['offering_fingerprint'] ?? '' ) ) ) ) {
			return new \WP_Error( 'oras_desk_offering_changed', 'That registration option changed. Review the current details before continuing.', array( 'status' => 409 ) );
		}
		$validated = $this->validate_manager_verified_payload( $payload, $option );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		$duplicates = $this->registrations->duplicate_candidates( $event_id, (string) $validated['email'], (string) $validated['phone'] );
		if ( ! empty( $duplicates ) ) {
			return $this->manager_verified_duplicate_error(
				array_map(
					static fn( array $row ): array => array(
						'registration_uuid' => (string) $row['registration_uuid'],
						'contact_name'      => (string) $row['source_contact_name'],
						'source_type'       => (string) $row['source_type'],
					),
					$duplicates
				)
			);
		}
		$canonical = ( new Recovery_Service( $this->source_adapter, null, $this->registrations ) )->contact_matches( $event_id, (string) $validated['email'], (string) $validated['phone'], $config );
		if ( $canonical instanceof \WP_Error ) {
			return $canonical;
		}
		if ( ! empty( $canonical ) ) {
			return $this->manager_verified_duplicate_error( $canonical );
		}

		$result = Store::transaction(
			function () use ( $validated, $event_id, $context, $binding ) {
				$record                    = $validated;
				$record['source_type']     = 'manager_verified_manual';
				$record['event_id']        = $event_id;
				$record['config_revision'] = (int) $context['config_revision'];
				$registration = $this->registrations->create_manual( $record );
				if ( $registration instanceof \WP_Error ) {
					return $registration;
				}
				$attendees = array();
				foreach ( $validated['arrivals'] as $index => $arrival ) {
					$prefix   = 'individual' === $validated['classification'] ? 'individual' : 'family';
					$attendee = $this->attendees->confirm_slot( (int) $registration['id'], $prefix . '-' . ( $index + 1 ), (string) $arrival['first_name'], (string) $arrival['last_name'] );
					if ( $attendee instanceof \WP_Error ) {
						return $attendee;
					}
					$attendees[] = $attendee;
				}
				$response = array(
					'result'       => 'manager_verified_recorded',
					'message'      => 'Manager verified registration manually.',
					'registration' => $registration,
					'attendees'    => $attendees,
					'attendance'   => array(),
				);
				$result_json  = wp_json_encode( $response );
				$changes_json = wp_json_encode(
					array(
						'source' => 'manager_verified_manual',
						'reason' => (string) $validated['evidence']['manager_verification']['reason'],
					)
				);
				$audit = $this->audits->append(
					array_merge(
						$binding,
						array(
							'registration_uuid' => (string) $registration['registration_uuid'],
							'attendee_uuid'     => (string) ( $attendees[0]['attendee_uuid'] ?? '' ),
							'attendance_id'     => null,
							'result_status'     => 'success',
							'result_code'       => 'manager_verified_recorded',
							'result_json'       => is_string( $result_json ) ? $result_json : '{}',
							'changes_json'      => is_string( $changes_json ) ? $changes_json : '{}',
						)
					)
				);
				if ( $audit instanceof \WP_Error ) {
					return $audit;
				}

				return array(
					'replayed'           => false,
					'historical_result'  => $response,
					'current_attendance' => null,
				);
			}
		);
		if ( $result instanceof \WP_Error && 'oras_desk_request_exists' === $result->get_error_code() ) {
			$replay = $this->replay_or_conflict( $binding );
			return null !== $replay ? $replay : $result;
		}

		return $result;
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	public function check_in_public_rsvp( int $user_id, array $payload, array $context ) {
		$event_id = (int) $context['event_id'];
		if ( $user_id <= 0 || ! class_exists( \ORAS\Tickets\Frontend\Event_RSVP::class ) || 'yes' !== \ORAS\Tickets\Frontend\Event_RSVP::get_user_status( $event_id, $user_id ) ) {
			return new \WP_Error( 'oras_desk_rsvp_not_admitted', 'This website RSVP is not currently admitted.', array( 'status' => 409 ) );
		}
		$stored = get_user_meta( $user_id, '_oras_rsvp_event_' . $event_id . '_contact', true );
		$stored = is_array( $stored ) ? $stored : array();
		$user   = get_userdata( $user_id );
		$first  = sanitize_text_field( (string) ( $stored['first_name'] ?? get_user_meta( $user_id, 'first_name', true ) ) );
		$last   = sanitize_text_field( (string) ( $stored['last_name'] ?? get_user_meta( $user_id, 'last_name', true ) ) );
		if ( '' === trim( $first . $last ) && $user instanceof \WP_User ) {
			$parts = preg_split( '/\s+/', trim( (string) $user->display_name ), 2 );
			$first = sanitize_text_field( (string) ( $parts[0] ?? '' ) );
			$last  = sanitize_text_field( (string) ( $parts[1] ?? '' ) );
		}
		$contact = array(
			'first_name' => $first,
			'last_name'  => $last,
			'email'      => sanitize_email( (string) ( $stored['email'] ?? ( $user instanceof \WP_User ? $user->user_email : '' ) ) ),
			'phone'      => sanitize_text_field( (string) ( $stored['phone'] ?? get_user_meta( $user_id, 'billing_phone', true ) ) ),
		);
		$registration = $this->registrations->ensure_rsvp_website( $event_id, $user_id, $contact, (int) $context['config_revision'] );
		if ( $registration instanceof \WP_Error ) {
			return $registration;
		}
		$payload['explicit_unpaid'] = false;
		$payload['arrivals'] = array(
			array(
				'slot_key'   => 'individual-1',
				'first_name' => $first,
				'last_name'  => $last,
			),
		);

		return $this->check_in( (string) $registration['registration_uuid'], $payload, $context );
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	public function check_in( string $registration_uuid, array $payload, array $context ) {
		$binding = $this->binding( 'check_in_attendees', $payload, $context );
		if ( $binding instanceof \WP_Error ) {
			return $binding;
		}
		$replay = $this->replay_or_conflict( $binding );
		if ( null !== $replay ) {
			return $replay;
		}
		$registration = $this->registrations->find_by_uuid( $registration_uuid );
		if ( ! $registration || (int) $registration['event_id'] !== (int) $context['event_id'] ) {
			return new \WP_Error( 'oras_desk_registration_missing', 'Registration was not found for the active event.', array( 'status' => 404 ) );
		}
		$config = Config::get_event_config( (int) $context['event_id'] );
		if ( empty( $config['enabled'] ) || (int) $config['revision'] !== (int) $context['config_revision'] ) {
			return new \WP_Error( 'oras_desk_config_changed', 'Registration Desk settings changed. Set up this station again.', array( 'status' => 409 ) );
		}
		$local_date = sanitize_text_field( (string) ( $payload['attendance_local_date'] ?? '' ) );
		$today      = wp_date( 'Y-m-d', null, wp_timezone() );
		if ( $local_date !== $today ) {
			return new \WP_Error( 'oras_desk_date_changed', 'The site-local date changed. Review the check-in before trying again.', array( 'status' => 409 ) );
		}
		$admission = $this->current_admission( $registration, $config, $today );
		if ( empty( $admission['check_in_allowed'] ) ) {
			return $this->admission_error( $admission );
		}
		if ( ! empty( $admission['requires_explicit_unpaid'] ) && empty( $payload['explicit_unpaid'] ) ) {
			return new \WP_Error( 'oras_desk_unpaid_confirmation_required', 'Payment is not confirmed. Choose the explicit unpaid admission action to continue.', array( 'status' => 409 ) );
		}
		$option        = is_array( $admission['_option'] ?? null ) ? $admission['_option'] : array();
		$payment_label = (string) ( $admission['_source_label'] ?? $admission['payment_label'] ?? '' );
		$arrivals = is_array( $payload['arrivals'] ?? null ) ? $payload['arrivals'] : array();
		$maximum  = max( 1, min( 20, (int) ( $option['max_attendees'] ?? 1 ) ) );
		if ( empty( $arrivals ) || count( $arrivals ) > $maximum || ( 'individual' === (string) $registration['classification'] && 1 !== count( $arrivals ) ) ) {
			return new \WP_Error( 'oras_desk_arrivals_invalid', 'Choose the actual arriving attendees within this registration’s configured limit.', array( 'status' => 400 ) );
		}

		$result = Store::transaction(
			function () use ( $registration, $arrivals, $local_date, $context, $binding, $payment_label, $maximum ) {
				$attendee_rows  = array();
				$attendance_rows = array();
				$used_slots     = array();
				foreach ( array_values( $arrivals ) as $index => $arrival ) {
					if ( ! is_array( $arrival ) ) {
						return new \WP_Error( 'oras_desk_arrivals_invalid', 'Each arriving attendee must be a structured entry.', array( 'status' => 400 ) );
					}
					$first = sanitize_text_field( (string) ( $arrival['first_name'] ?? '' ) );
					$last  = sanitize_text_field( (string) ( $arrival['last_name'] ?? '' ) );
					if ( ( '' === $first ) !== ( '' === $last ) || ( 'individual' === (string) $registration['classification'] && '' === $first ) ) {
						return new \WP_Error( 'oras_desk_attendee_name_invalid', 'Provide both first and last name, or leave both blank for an unnamed family slot.', array( 'status' => 400 ) );
					}
					$prefix = 'individual' === (string) $registration['classification'] ? 'individual' : 'family';
					$slot   = sanitize_key( (string) ( $arrival['slot_key'] ?? '' ) );
					if ( '' === $slot ) {
						$slot = $prefix . '-' . ( $index + 1 );
					}
					$slot_number = (int) substr( $slot, strrpos( $slot, '-' ) + 1 );
					if ( ! str_starts_with( $slot, $prefix . '-' ) || $slot_number < 1 || $slot_number > $maximum || isset( $used_slots[ $slot ] ) ) {
						return new \WP_Error( 'oras_desk_attendee_slot_invalid', 'An arriving attendee slot is invalid or repeated.', array( 'status' => 400 ) );
					}
					$used_slots[ $slot ] = true;
					$attendee = $this->attendees->confirm_slot( (int) $registration['id'], $slot, $first, $last );
					if ( $attendee instanceof \WP_Error ) {
						return $attendee;
					}
					$attendance = $this->attendance->find_daily( (int) $context['event_id'], (int) $attendee['id'], $local_date );
					if ( $attendance && 'reversed' === (string) $attendance['state'] ) {
						return new \WP_Error( 'oras_desk_attendance_reversed', 'An attendee’s attendance was reversed and cannot be restored by retry.', array( 'status' => 409 ) );
					}
					if ( ! $attendance ) {
						$attendance = $this->attendance->check_in( (int) $context['event_id'], (int) $attendee['id'], $local_date, (int) $context['actor_user_id'], (string) $context['station_uuid'], (string) $context['operator_label'] );
					}
					if ( $attendance instanceof \WP_Error || 'reversed' === (string) ( $attendance['state'] ?? '' ) ) {
						return $attendance instanceof \WP_Error ? $attendance : new \WP_Error( 'oras_desk_attendance_reversed', 'An attendee’s attendance was reversed and cannot be restored by retry.', array( 'status' => 409 ) );
					}
					unset( $attendance['_was_created'] );
					$attendee_rows[]   = $attendee;
					$attendance_rows[] = $attendance;
				}
				$response = array(
					'result'            => 'checked_in',
					'registration_uuid' => (string) $registration['registration_uuid'],
					'attendees'         => $attendee_rows,
					'attendance'        => $attendance_rows,
					'payment_label'     => $payment_label,
				);
				$audit = $this->audits->append( $this->audit_record( $binding, $registration, $attendee_rows[0], $attendance_rows[0], 'checked_in', $response, array( 'arrival_count' => count( $attendance_rows ) ) ) );
				if ( $audit instanceof \WP_Error ) {
					return $audit;
				}

				return array(
					'replayed'           => false,
					'historical_result'  => $response,
					'current_attendance' => $attendance_rows,
				);
			}
		);
		if ( $result instanceof \WP_Error && 'oras_desk_request_exists' === $result->get_error_code() ) {
			$replay = $this->replay_or_conflict( $binding );
			return null !== $replay ? $replay : $result;
		}

		return $result;
	}

	/** @return array<int,array<string,mixed>> */
	public function search( int $event_id, string $query, int $limit = 25 ): array {
		return $this->registrations->search( $event_id, $query, $limit );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function detail( int $event_id, string $registration_uuid ) {
		$registration = $this->registrations->find_by_uuid( $registration_uuid );
		if ( ! $registration || $event_id !== (int) $registration['event_id'] ) {
			return new \WP_Error( 'oras_desk_registration_missing', 'Registration was not found for the active event.', array( 'status' => 404 ) );
		}

		$today       = wp_date( 'Y-m-d', null, wp_timezone() );
		$attendees   = $this->attendees->for_registration( (int) $registration['id'] );
		$attendance  = $this->attendance->for_attendees_on_date( $event_id, array_column( $attendees, 'id' ), $today );
		foreach ( $attendees as &$attendee ) {
			$attendee['current_attendance'] = $attendance[ (int) $attendee['id'] ] ?? null;
		}
		unset( $attendee );
		$config    = Config::get_event_config( $event_id );
		$admission = $this->current_admission( $registration, $config, $today );
		$maximum   = max( 1, min( 20, (int) ( $admission['maximum_attendees'] ?? 1 ) ) );
		$selectable_attendees = 0;
		$checked_attendees    = 0;
		$blocked_attendees    = 0;
		foreach ( $attendees as &$attendee ) {
			$current = (string) ( $attendee['current_attendance']['state'] ?? '' );
			if ( 'checked_in' === $current ) {
				$attendee['admission'] = array(
					'state'             => 'already_checked_in',
					'selection_allowed' => false,
					'status_label'      => 'CHECKED IN TODAY',
				);
				++$checked_attendees;
			} elseif ( 'reversed' === $current ) {
				$attendee['admission'] = array(
					'state'             => 'manager_review_required',
					'selection_allowed' => false,
					'status_label'      => 'MANAGER HELP NEEDED',
				);
				++$blocked_attendees;
			} elseif ( ! empty( $admission['selection_allowed'] ) ) {
				$attendee['admission'] = array(
					'state'             => 'eligible',
					'selection_allowed' => true,
					'status_label'      => 'READY TO CHECK IN',
				);
				++$selectable_attendees;
			} else {
				$attendee['admission'] = array(
					'state'             => (string) $admission['state'],
					'selection_allowed' => false,
					'status_label'      => (string) $admission['status_label'],
				);
				++$blocked_attendees;
			}
		}
		unset( $attendee );
		if ( ! empty( $admission['selection_allowed'] ) ) {
			$can_add = count( $attendees ) < $maximum;
			$admission['selection_allowed'] = $selectable_attendees > 0 || $can_add;
			$admission['check_in_allowed']  = $admission['selection_allowed'];
			$admission['allowed']           = $admission['check_in_allowed'];
			if ( ! $admission['check_in_allowed'] ) {
				if ( $checked_attendees > 0 && 0 === $blocked_attendees ) {
					$admission['state']        = 'already_checked_in';
					$admission['status_label'] = 'CHECKED IN TODAY';
					$admission['message']      = 'Everyone on this registration is already checked in today.';
					$admission['manager_help'] = false;
				} else {
					$admission = $this->blocked_admission(
						'manager_review_required',
						'MANAGER HELP NEEDED',
						'This registration needs manager help before anyone can be checked in.',
						'oras_desk_attendance_reversed',
						$admission['_diagnostics'] ?? array()
					);
				}
			}
		}

		return array(
			'registration' => $registration,
			'attendees'    => $attendees,
			'local_date'   => $today,
			'admission'    => $admission,
			'coverage'     => array(
				'complete'    => in_array( $registration['classification'], array( 'individual', 'family' ), true ) && in_array( $registration['validity_type'], array( 'full_event', 'one_day' ), true ),
				'limitations' => 'unclassified' === $registration['classification'] || 'unclassified' === $registration['validity_type'] ? array( 'Registration classification requires administrator review.' ) : array(),
			),
		);
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	public function confirm_and_check_in( string $registration_uuid, array $payload, array $context ) {
		$binding = $this->binding( 'confirm_and_check_in', $payload, $context );
		if ( $binding instanceof \WP_Error ) {
			return $binding;
		}
		$replay = $this->replay_or_conflict( $binding );
		if ( null !== $replay ) {
			return $replay;
		}
		$registration = $this->registrations->find_by_uuid( $registration_uuid );
		if ( ! $registration || (int) $context['event_id'] !== (int) $registration['event_id'] ) {
			return new \WP_Error( 'oras_desk_registration_missing', 'Registration was not found for the active event.', array( 'status' => 404 ) );
		}
		$config = Config::get_event_config( (int) $context['event_id'] );
		if ( empty( $config['enabled'] ) || (int) $config['revision'] !== (int) $context['config_revision'] ) {
			return new \WP_Error( 'oras_desk_config_changed', 'Registration Desk settings changed. Set up this station again.', array( 'status' => 409 ) );
		}
		$local_date = sanitize_text_field( (string) ( $payload['attendance_local_date'] ?? '' ) );
		$today      = wp_date( 'Y-m-d', null, wp_timezone() );
		if ( $local_date !== $today ) {
			return new \WP_Error( 'oras_desk_date_changed', 'The site-local date changed. Review the check-in before trying again.', array( 'status' => 409 ) );
		}
		if ( 'online' !== $registration['source_type'] ) {
			return new \WP_Error( 'oras_desk_source_review', 'This registration does not have a supported website source.', array( 'status' => 409 ) );
		}
		$admission = $this->current_admission( $registration, $config, $today );
		if ( empty( $admission['check_in_allowed'] ) ) {
			return $this->admission_error( $admission );
		}
		if ( 'individual' !== (string) $registration['classification'] || 'full_event' !== (string) $registration['validity_type'] ) {
			return new \WP_Error( 'oras_desk_source_review', 'Website registration classification requires review.', array( 'status' => 409 ) );
		}
		$explicit_unpaid = ! empty( $payload['explicit_unpaid'] );
		if ( ! empty( $admission['requires_explicit_unpaid'] ) && ! $explicit_unpaid ) {
			return new \WP_Error( 'oras_desk_unpaid_confirmation_required', 'Payment is not confirmed. Choose the explicit unpaid admission action to continue.', array( 'status' => 409 ) );
		}
		$payment_label = (string) ( $admission['_source_label'] ?? $admission['payment_label'] ?? '' );
		$first_name = sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) );
		if ( '' === $first_name || '' === $last_name ) {
			return new \WP_Error( 'oras_desk_attendee_name_required', 'Confirm the arriving person’s first and last name.', array( 'status' => 400 ) );
		}

		$result = Store::transaction(
			function () use ( $registration, $first_name, $last_name, $local_date, $context, $binding, $explicit_unpaid, $payment_label ) {
				$attendee = $this->attendees->confirm_individual( (int) $registration['id'], $first_name, $last_name );
				if ( $attendee instanceof \WP_Error ) {
					return $attendee;
				}
				$attendance = $this->attendance->find_daily( (int) $context['event_id'], (int) $attendee['id'], $local_date );
				$code       = 'checked_in';
				if ( $attendance && 'reversed' === $attendance['state'] ) {
					return new \WP_Error( 'oras_desk_attendance_reversed', 'Today’s attendance was reversed by an administrator and cannot be restored by retry.', array( 'status' => 409 ) );
				}
				if ( ! $attendance ) {
					$attendance = $this->attendance->check_in( (int) $context['event_id'], (int) $attendee['id'], $local_date, (int) $context['actor_user_id'], (string) $context['station_uuid'], (string) $context['operator_label'] );
					if ( $attendance instanceof \WP_Error ) {
						return $attendance;
					}
					if ( 'reversed' === $attendance['state'] ) {
						return new \WP_Error( 'oras_desk_attendance_reversed', 'Today’s attendance was reversed by an administrator and cannot be restored by retry.', array( 'status' => 409 ) );
					}
					$code = ! empty( $attendance['_was_created'] ) ? 'checked_in' : 'already_checked_in';
					unset( $attendance['_was_created'] );
				} else {
					$code = 'already_checked_in';
				}
				$response = array(
					'result'            => $code,
					'registration_uuid' => (string) $registration['registration_uuid'],
					'attendee_uuid'     => (string) $attendee['attendee_uuid'],
					'attendance'        => $attendance,
					'payment_label'     => $payment_label,
					'explicit_unpaid'   => $explicit_unpaid,
				);
				$audit = $this->audits->append( $this->audit_record( $binding, $registration, $attendee, $attendance, $code, $response, array( 'explicit_unpaid' => $explicit_unpaid ) ) );
				if ( $audit instanceof \WP_Error ) {
					return $audit;
				}

				return array(
					'replayed'           => false,
					'historical_result'  => $response,
					'current_attendance' => $attendance,
				);
			}
		);
		if ( $result instanceof \WP_Error && 'oras_desk_request_exists' === $result->get_error_code() ) {
			$replay = $this->replay_or_conflict( $binding );
			return null !== $replay ? $replay : $result;
		}

		return $result;
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	public function reverse( string $registration_uuid, string $attendee_uuid, array $payload, array $context ) {
		$binding = $this->binding( 'reverse_attendance', $payload, $context );
		if ( $binding instanceof \WP_Error ) {
			return $binding;
		}
		$replay = $this->replay_or_conflict( $binding );
		if ( null !== $replay ) {
			return $replay;
		}
		$registration = $this->registrations->find_by_uuid( $registration_uuid );
		$attendee     = $this->attendees->find_by_uuid( $attendee_uuid );
		if ( ! $registration || ! $attendee || (int) $registration['event_id'] !== (int) $context['event_id'] || (int) $attendee['registration_id'] !== (int) $registration['id'] ) {
			return new \WP_Error( 'oras_desk_attendance_missing', 'Attendance was not found for this active-event registration.', array( 'status' => 404 ) );
		}
		$local_date = sanitize_text_field( (string) ( $payload['attendance_local_date'] ?? '' ) );
		$attendance = $this->attendance->find_daily( (int) $context['event_id'], (int) $attendee['id'], $local_date );
		$reason     = sanitize_text_field( (string) ( $payload['reason'] ?? '' ) );
		$expected_record_version = (int) ( $payload['expected_record_version'] ?? 0 );
		if ( ! $attendance || '' === $reason || $expected_record_version <= 0 ) {
			return new \WP_Error( 'oras_desk_reversal_invalid', 'Reversal requires current attendance, a reason, and its expected version.', array( 'status' => 400 ) );
		}

		$result = Store::transaction(
			function () use ( $registration, $attendee, $attendance, $expected_record_version, $context, $reason, $binding ) {
				$reversed = $this->attendance->reverse( (int) $attendance['id'], $expected_record_version, (int) $context['actor_user_id'], $reason );
				if ( $reversed instanceof \WP_Error ) {
					return $reversed;
				}
				$response = array(
					'result'            => 'reversed',
					'registration_uuid' => $registration['registration_uuid'],
					'attendee_uuid'     => $attendee['attendee_uuid'],
					'attendance'        => $reversed,
				);
				$audit    = $this->audits->append(
					$this->audit_record(
						$binding,
						$registration,
						$attendee,
						$reversed,
						'reversed',
						$response,
						array(
							'reason'        => $reason,
							'prior_version' => $expected_record_version,
						)
					)
				);
				if ( $audit instanceof \WP_Error ) {
					return $audit;
				}

				return array(
					'replayed'           => false,
					'historical_result'  => $response,
					'current_attendance' => $reversed,
				);
			}
		);
		if ( $result instanceof \WP_Error && 'oras_desk_request_exists' === $result->get_error_code() ) {
			$replay = $this->replay_or_conflict( $binding );
			return null !== $replay ? $replay : $result;
		}

		return $result;
	}

	/** @return array<int,array<string,mixed>> */
	public function recent( int $event_id, int $limit = 25 ): array {
		return $this->attendance->recent_detailed( $event_id, $limit );
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	public function correct_registration( string $registration_uuid, array $payload, array $context ) {
		$binding = $this->binding( 'correct_registration', $payload, $context );
		if ( $binding instanceof \WP_Error ) {
			return $binding;
		}
		$replay = $this->replay_or_conflict( $binding );
		if ( null !== $replay ) {
			return $replay;
		}
		$current = $this->registrations->find_by_uuid( $registration_uuid );
		if ( ! $current || (int) $current['event_id'] !== (int) $context['event_id'] ) {
			return new \WP_Error( 'oras_desk_registration_missing', 'Registration was not found for the active event.', array( 'status' => 404 ) );
		}
		$config = Config::get_event_config( (int) $context['event_id'] );
		$option = Event_Offering_Resolver::find_desk_offering( (int) $context['event_id'], $config, sanitize_text_field( (string) ( $payload['option_uuid'] ?? '' ) ) );
		if ( null === $option ) {
			return new \WP_Error( 'oras_desk_option_invalid', 'Choose a configured registration option.', array( 'status' => 400 ) );
		}
		$complimentary = in_array( (string) $current['source_type'], array( 'complimentary', 'speaker' ), true );
		$validated      = $this->validate_manual_payload( $payload, $option, (int) $context['event_id'], $complimentary );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		$result = Store::transaction(
			function () use ( $registration_uuid, $payload, $validated, $current, $binding ) {
				$corrected = $this->registrations->correct_manual(
					$registration_uuid,
					absint( $payload['expected_record_version'] ?? 0 ),
					$validated
				);
				if ( $corrected instanceof \WP_Error ) {
					return $corrected;
				}
				$response = array(
					'result'       => 'corrected',
					'registration' => $corrected,
				);
				$result_json  = wp_json_encode( $response );
				$changes_json = wp_json_encode(
					array(
						'before' => $current,
						'after'  => $corrected,
					)
				);
				$audit = $this->audits->append(
					array_merge(
						$binding,
						array(
							'registration_uuid' => $registration_uuid,
							'attendee_uuid'     => null,
							'attendance_id'     => null,
							'result_status'     => 'success',
							'result_code'       => 'corrected',
							'result_json'       => is_string( $result_json ) ? $result_json : '{}',
							'changes_json'      => is_string( $changes_json ) ? $changes_json : '{}',
						)
					)
				);
				if ( $audit instanceof \WP_Error ) {
					return $audit;
				}

				return array(
					'replayed'           => false,
					'historical_result'  => $response,
					'current_attendance' => null,
				);
			}
		);
		if ( $result instanceof \WP_Error && 'oras_desk_request_exists' === $result->get_error_code() ) {
			$replay = $this->replay_or_conflict( $binding );
			return null !== $replay ? $replay : $result;
		}

		return $result;
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	private function create_rsvp_walk_in( array $payload, array $context ) {
		$binding = $this->binding( 'create_rsvp_walk_in', $payload, $context );
		if ( $binding instanceof \WP_Error ) {
			return $binding;
		}
		$replay = $this->replay_or_conflict( $binding );
		if ( null !== $replay ) {
			return $replay;
		}
		$event_id = (int) $context['event_id'];
		$option   = array(
			'option_uuid'     => Event_Offering_Resolver::option_uuid( $event_id, 'rsvp' ),
			'label'           => 'RSVP — On-site',
			'description'     => 'Accountless event RSVP recorded at the Registration Desk.',
			'price'           => '0.00',
			'attendance_mode' => 'onsite',
			'classification'  => 'individual',
			'validity_type'   => 'full_event',
			'max_attendees'   => 1,
		);
		$validated = $this->validate_manual_payload( $payload, $option, $event_id, true );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		$validated['payment_assertion'] = 'rsvp';
		$duplicates = $this->registrations->duplicate_candidates( $event_id, (string) $validated['email'], (string) $validated['phone'] );
		if ( ! empty( $duplicates ) && empty( $payload['duplicate_acknowledged'] ) ) {
			return new \WP_Error(
				'oras_desk_possible_duplicate',
				'An RSVP with the same email or phone may already exist. Review it before continuing; records will not be merged.',
				array(
					'status'     => 409,
					'candidates' => array_map(
						static fn( array $row ): array => array(
							'registration_uuid' => (string) $row['registration_uuid'],
							'contact_name'      => (string) $row['source_contact_name'],
							'source_type'       => (string) $row['source_type'],
						),
						$duplicates
					),
				)
			);
		}

		$result = DbLock::forEvent(
			$event_id,
			function () use ( $event_id, $validated, $context, $binding, $duplicates ) {
				$state = RSVP_Capacity::state( $event_id );
				if ( empty( $state['enabled'] ) || 'open' !== (string) $state['window_state'] ) {
					return new \WP_Error( 'oras_desk_rsvp_closed', 'RSVP registration is not open for this event.', array( 'status' => 409 ) );
				}
				if ( 'refuse' === (string) $state['decision'] ) {
					return new \WP_Error( 'oras_desk_rsvp_full', 'This event is full and no RSVP waitlist is available.', array( 'status' => 409 ) );
				}
				$waitlisted = 'waitlist' === (string) $state['decision'];

				return Store::transaction(
					function () use ( $event_id, $validated, $context, $binding, $duplicates, $state, $waitlisted ) {
						$record                        = $validated;
						$record['source_type']         = $waitlisted ? 'rsvp_waitlist' : 'rsvp_walk_in';
						$record['event_id']            = $event_id;
						$record['config_revision']     = (int) $context['config_revision'];
						$record['evidence']['rsvp']    = $state;
						$registration = $this->registrations->create_manual( $record );
						if ( $registration instanceof \WP_Error ) {
							return $registration;
						}
						$arrival  = $validated['arrivals'][0];
						$attendee = $this->attendees->confirm_slot( (int) $registration['id'], 'individual-1', (string) $arrival['first_name'], (string) $arrival['last_name'] );
						if ( $attendee instanceof \WP_Error ) {
							return $attendee;
						}
						$attendance = null;
						if ( ! $waitlisted ) {
							$attendance = $this->attendance->check_in( $event_id, (int) $attendee['id'], (string) $validated['attendance_local_date'], (int) $context['actor_user_id'], (string) $context['station_uuid'], (string) $context['operator_label'] );
							if ( $attendance instanceof \WP_Error ) {
								return $attendance;
							}
							unset( $attendance['_was_created'] );
						}
						$response = array(
							'result'              => $waitlisted ? 'rsvp_waitlisted' : 'rsvp_registered_and_checked_in',
							'admitted'            => ! $waitlisted,
							'message'             => $waitlisted ? 'The person was added to the RSVP waitlist and was not admitted.' : 'The RSVP was recorded and the person was checked in.',
							'registration'        => $registration,
							'attendees'           => array( $attendee ),
							'attendance'          => null === $attendance ? array() : array( $attendance ),
							'possible_duplicates' => count( $duplicates ),
						);
						$result_json  = wp_json_encode( $response );
						$changes_json = wp_json_encode( array( 'rsvp_decision' => (string) $state['decision'] ) );
						$audit = $this->audits->append(
							array_merge(
								$binding,
								array(
									'registration_uuid' => (string) $registration['registration_uuid'],
									'attendee_uuid'     => (string) $attendee['attendee_uuid'],
									'attendance_id'     => is_array( $attendance ) ? (int) $attendance['id'] : null,
									'result_status'     => 'success',
									'result_code'       => $waitlisted ? 'rsvp_waitlisted' : 'rsvp_registered_and_checked_in',
									'result_json'       => is_string( $result_json ) ? $result_json : '{}',
									'changes_json'      => is_string( $changes_json ) ? $changes_json : '{}',
								)
							)
						);
						if ( $audit instanceof \WP_Error ) {
							return $audit;
						}

						return array(
							'replayed'           => false,
							'historical_result'  => $response,
							'current_attendance' => $attendance,
						);
					}
				);
			}
		);

		return $result;
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	private function create_manual_registration( string $source_type, array $payload, array $context, bool $administrator_override ) {
		$binding = $this->binding( 'create_' . $source_type, $payload, $context );
		if ( $binding instanceof \WP_Error ) {
			return $binding;
		}
		$replay = $this->replay_or_conflict( $binding );
		if ( null !== $replay ) {
			return $replay;
		}
		$config = Config::get_event_config( (int) $context['event_id'] );
		$option = Event_Offering_Resolver::find_desk_offering( (int) $context['event_id'], $config, sanitize_text_field( (string) ( $payload['option_uuid'] ?? '' ) ) );
		if ( null === $option || empty( $option['available_for_new'] ) ) {
			return new \WP_Error( 'oras_desk_option_invalid', 'Choose an available registration option.', array( 'status' => 400 ) );
		}
		if ( ! hash_equals( (string) $option['offering_fingerprint'], sanitize_text_field( (string) ( $payload['offering_fingerprint'] ?? '' ) ) ) ) {
			return new \WP_Error( 'oras_desk_offering_changed', 'That registration option changed. Return to ticket selection and review the current details.', array( 'status' => 409 ) );
		}
		$validated = $this->validate_manual_payload( $payload, $option, (int) $context['event_id'], $administrator_override );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		$duplicates = $this->registrations->duplicate_candidates( (int) $context['event_id'], (string) $validated['email'], (string) $validated['phone'] );
		if ( ! empty( $duplicates ) && empty( $payload['duplicate_acknowledged'] ) ) {
			return new \WP_Error(
				'oras_desk_possible_duplicate',
				'A registration with the same email or phone may already exist. Review it before continuing; records will not be merged.',
				array(
					'status'     => 409,
					'candidates' => array_map(
						static fn( array $row ): array => array(
							'registration_uuid' => (string) $row['registration_uuid'],
							'contact_name'      => (string) $row['source_contact_name'],
							'source_type'       => (string) $row['source_type'],
						),
						$duplicates
					),
				)
			);
		}

		$result = Store::transaction(
			function () use ( $source_type, $validated, $context, $binding, $duplicates ) {
				$validated['source_type']     = $source_type;
				$validated['event_id']        = (int) $context['event_id'];
				$validated['config_revision'] = (int) $context['config_revision'];
				$registration = $this->registrations->create_manual( $validated );
				if ( $registration instanceof \WP_Error ) {
					return $registration;
				}
				$attendee_rows   = array();
				$attendance_rows = array();
				foreach ( $validated['arrivals'] as $index => $arrival ) {
					$prefix   = 'individual' === $validated['classification'] ? 'individual' : 'family';
					$attendee = $this->attendees->confirm_slot( (int) $registration['id'], $prefix . '-' . ( $index + 1 ), (string) $arrival['first_name'], (string) $arrival['last_name'] );
					if ( $attendee instanceof \WP_Error ) {
						return $attendee;
					}
					$attendance = $this->attendance->check_in( (int) $context['event_id'], (int) $attendee['id'], (string) $validated['attendance_local_date'], (int) $context['actor_user_id'], (string) $context['station_uuid'], (string) $context['operator_label'] );
					if ( $attendance instanceof \WP_Error ) {
						return $attendance;
					}
					unset( $attendance['_was_created'] );
					$attendee_rows[]   = $attendee;
					$attendance_rows[] = $attendance;
				}
				$response = array(
					'result'              => 'registered_and_checked_in',
					'registration'        => $registration,
					'attendees'           => $attendee_rows,
					'attendance'          => $attendance_rows,
					'possible_duplicates' => count( $duplicates ),
				);
				$audit = $this->audits->append(
					$this->audit_record(
						$binding,
						$registration,
						$attendee_rows[0],
						$attendance_rows[0],
						'registered_and_checked_in',
						$response,
						array(
							'arrival_count'     => count( $attendance_rows ),
							'payment_assertion' => $validated['payment_assertion'],
						)
					)
				);
				if ( $audit instanceof \WP_Error ) {
					return $audit;
				}

				return array(
					'replayed'           => false,
					'historical_result'  => $response,
					'current_attendance' => $attendance_rows,
				);
			}
		);
		if ( $result instanceof \WP_Error && 'oras_desk_request_exists' === $result->get_error_code() ) {
			$replay = $this->replay_or_conflict( $binding );
			return null !== $replay ? $replay : $result;
		}

		return $result;
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $option @return array<string,mixed>|\WP_Error */
	private function validate_manual_payload( array $payload, array $option, int $event_id, bool $administrator_override ) {
		$first_name = sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) );
		$email      = strtolower( sanitize_email( (string) ( $payload['email'] ?? '' ) ) );
		$phone      = sanitize_text_field( (string) ( $payload['phone'] ?? '' ) );
		if ( '' === $first_name || '' === $last_name || '' === $email || ! is_email( $email ) || strlen( preg_replace( '/\D+/', '', $phone ) ?? '' ) < 7 ) {
			return new \WP_Error( 'oras_desk_contact_required', 'First name, last name, valid email, and phone are required.', array( 'status' => 400 ) );
		}
		$payment = sanitize_key( (string) ( $payload['payment_assertion'] ?? '' ) );
		if ( ! $administrator_override && ! self::payment_assertion_is_valid( $payment ) ) {
			return new \WP_Error( 'oras_desk_payment_statement_required', 'Record Paid—Card, Paid—Cash, Paid—Check, or Unpaid after the separate AlfaPOS step.', array( 'status' => 400 ) );
		}
		if ( $administrator_override ) {
			$payment = 'complimentary';
		}
		$classification = (string) ( $option['classification'] ?? 'unclassified' );
		$validity       = (string) ( $option['validity_type'] ?? 'unclassified' );
		if ( ! in_array( $classification, array( 'individual', 'family' ), true ) || ! in_array( $validity, array( 'full_event', 'one_day' ), true ) ) {
			return new \WP_Error( 'oras_desk_option_review', 'This option is not configured for V1 desk registration.', array( 'status' => 409 ) );
		}
		$attendance_date = wp_date( 'Y-m-d', null, wp_timezone() );
		$valid_date      = 'one_day' === $validity ? sanitize_text_field( (string) ( $payload['valid_local_date'] ?? '' ) ) : '';
		$range           = $this->event_range( $event_id );
		if ( $range instanceof \WP_Error ) {
			return $range;
		}
		if ( ! self::date_is_within_event( $attendance_date, $range['start'], $range['end'] ) ) {
			return new \WP_Error( 'oras_desk_wrong_date', 'The active event is not admitting attendees today.', array( 'status' => 409 ) );
		}
		if ( 'one_day' === $validity && ( ! self::date_is_within_event( $valid_date, $range['start'], $range['end'] ) || $valid_date !== $attendance_date ) ) {
			return new \WP_Error( 'oras_desk_wrong_date', 'A one-day walk-in requires today’s valid event date.', array( 'status' => 409 ) );
		}
		$arrivals = array(
			array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
			),
		);
		if ( 'family' === $classification ) {
			foreach ( is_array( $payload['additional_attendees'] ?? null ) ? $payload['additional_attendees'] : array() as $arrival ) {
				if ( ! is_array( $arrival ) ) {
					continue;
				}
				$additional_first = sanitize_text_field( (string) ( $arrival['first_name'] ?? '' ) );
				$additional_last  = sanitize_text_field( (string) ( $arrival['last_name'] ?? '' ) );
				if ( ( '' === $additional_first ) !== ( '' === $additional_last ) ) {
					return new \WP_Error( 'oras_desk_attendee_name_invalid', 'Provide both names for a family member, or leave both blank for an unnamed slot.', array( 'status' => 400 ) );
				}
				$arrivals[] = array(
					'first_name' => $additional_first,
					'last_name'  => $additional_last,
				);
			}
		}
		if ( count( $arrivals ) > max( 1, min( 20, (int) ( $option['max_attendees'] ?? 1 ) ) ) ) {
			return new \WP_Error( 'oras_desk_arrivals_invalid', 'Actual arrivals exceed this option’s configured attendee limit.', array( 'status' => 400 ) );
		}
		$address = array(
			'address_1' => sanitize_text_field( (string) ( $payload['address_1'] ?? '' ) ),
			'address_2' => sanitize_text_field( (string) ( $payload['address_2'] ?? '' ) ),
			'city'      => sanitize_text_field( (string) ( $payload['city'] ?? '' ) ),
			'state'     => sanitize_text_field( (string) ( $payload['state'] ?? '' ) ),
			'postcode'  => sanitize_text_field( (string) ( $payload['postcode'] ?? '' ) ),
		);

		return array(
			'first_name'            => $first_name,
			'last_name'             => $last_name,
			'email'                 => $email,
			'phone'                 => $phone,
			'option_uuid'           => (string) $option['option_uuid'],
			'classification'        => $classification,
			'validity_type'         => $validity,
			'valid_local_date'      => $valid_date,
			'payment_assertion'     => $payment,
			'attendance_local_date' => $attendance_date,
			'arrivals'              => $arrivals,
			'evidence'              => array(
				'mailing_address' => $address,
				'offering'        => array(
					'option_uuid'     => (string) $option['option_uuid'],
					'ticket_key'      => (string) ( $option['ticket_key'] ?? '' ),
					'product_id'      => absint( $option['product_id'] ?? 0 ),
					'label'           => sanitize_text_field( (string) ( $option['label'] ?? '' ) ),
					'description'     => sanitize_text_field( (string) ( $option['description'] ?? '' ) ),
					'price'           => sanitize_text_field( (string) ( $option['price'] ?? '' ) ),
					'phase_key'       => sanitize_key( (string) ( $option['phase_key'] ?? '' ) ),
					'phase_label'     => sanitize_text_field( (string) ( $option['phase_label'] ?? '' ) ),
					'attendance_mode' => sanitize_key( (string) ( $option['attendance_mode'] ?? '' ) ),
				),
			),
		);
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $option @return array<string,mixed>|\WP_Error */
	private function validate_manager_verified_payload( array $payload, array $option ) {
		$first_name = sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) );
		$email      = strtolower( sanitize_email( (string) ( $payload['email'] ?? '' ) ) );
		$phone      = sanitize_text_field( (string) ( $payload['phone'] ?? '' ) );
		$reason     = sanitize_textarea_field( (string) ( $payload['reason'] ?? '' ) );
		if ( '' === $first_name || '' === $last_name ) {
			return new \WP_Error( 'oras_desk_contact_required', 'First and last name are required.', array( 'status' => 400 ) );
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			return new \WP_Error( 'oras_desk_contact_required', 'Enter a valid email address or leave it blank.', array( 'status' => 400 ) );
		}
		if ( '' !== $phone && strlen( preg_replace( '/\D+/', '', $phone ) ?? '' ) < 7 ) {
			return new \WP_Error( 'oras_desk_contact_required', 'Enter a valid phone number or leave it blank.', array( 'status' => 400 ) );
		}
		if ( strlen( $reason ) < 5 ) {
			return new \WP_Error( 'oras_desk_verification_reason_required', 'Record a short note explaining the proof you reviewed.', array( 'status' => 400 ) );
		}
		if ( empty( $payload['proof_acknowledged'] ) ) {
			return new \WP_Error( 'oras_desk_proof_acknowledgement_required', 'Confirm that you verified proof outside this system.', array( 'status' => 400 ) );
		}
		$classification = (string) ( $option['classification'] ?? 'unclassified' );
		$validity       = (string) ( $option['validity_type'] ?? 'unclassified' );
		if ( ! in_array( $classification, array( 'individual', 'family' ), true ) || ! in_array( $validity, array( 'full_event', 'one_day' ), true ) ) {
			return new \WP_Error( 'oras_desk_option_review', 'This registration option needs manager review.', array( 'status' => 409 ) );
		}
		$arrivals = array(
			array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
			),
		);
		if ( 'family' === $classification ) {
			foreach ( is_array( $payload['additional_attendees'] ?? null ) ? $payload['additional_attendees'] : array() as $arrival ) {
				if ( ! is_array( $arrival ) ) {
					continue;
				}
				$additional_first = sanitize_text_field( (string) ( $arrival['first_name'] ?? '' ) );
				$additional_last  = sanitize_text_field( (string) ( $arrival['last_name'] ?? '' ) );
				if ( ( '' === $additional_first ) !== ( '' === $additional_last ) ) {
					return new \WP_Error( 'oras_desk_attendee_name_invalid', 'Provide both names for a family member, or leave both blank.', array( 'status' => 400 ) );
				}
				$arrivals[] = array(
					'first_name' => $additional_first,
					'last_name'  => $additional_last,
				);
			}
		}
		if ( count( $arrivals ) > max( 1, min( 20, (int) ( $option['max_attendees'] ?? 1 ) ) ) ) {
			return new \WP_Error( 'oras_desk_arrivals_invalid', 'The attendee list exceeds this registration type’s family limit.', array( 'status' => 400 ) );
		}

		return array(
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'email'             => $email,
			'phone'             => $phone,
			'option_uuid'       => (string) $option['option_uuid'],
			'classification'    => $classification,
			'validity_type'     => $validity,
			'valid_local_date'  => 'one_day' === $validity ? sanitize_text_field( (string) ( $option['valid_local_date'] ?? '' ) ) : '',
			'payment_assertion' => 'manager_verified',
			'arrivals'          => $arrivals,
			'evidence'          => array(
				'manager_verification' => array(
					'reason'             => $reason,
					'proof_acknowledged' => true,
				),
				'offering'             => array(
					'option_uuid'     => (string) $option['option_uuid'],
					'ticket_key'      => (string) ( $option['ticket_key'] ?? '' ),
					'product_id'      => absint( $option['product_id'] ?? 0 ),
					'label'           => sanitize_text_field( (string) ( $option['label'] ?? '' ) ),
					'description'     => sanitize_text_field( (string) ( $option['description'] ?? '' ) ),
					'price'           => sanitize_text_field( (string) ( $option['price'] ?? '' ) ),
					'phase_key'       => sanitize_key( (string) ( $option['phase_key'] ?? '' ) ),
					'phase_label'     => sanitize_text_field( (string) ( $option['phase_label'] ?? '' ) ),
					'attendance_mode' => sanitize_key( (string) ( $option['attendance_mode'] ?? '' ) ),
				),
			),
		);
	}

	/** @param array<int,array<string,mixed>> $candidates */
	private function manager_verified_duplicate_error( array $candidates ): \WP_Error {
		return new \WP_Error(
			'oras_desk_verified_duplicate',
			'A matching registration already exists. Open or synchronize that record instead of creating another one.',
			array(
				'status'     => 409,
				'candidates' => $candidates,
			)
		);
	}

	/** @param array<string,mixed> $registration @param array<string,mixed> $config @return array<string,mixed> */
	private function current_admission( array $registration, array $config, string $local_date ): array {
		$source_type = (string) $registration['source_type'];
		$event_title = sanitize_text_field( (string) get_the_title( (int) $registration['event_id'] ) );
		$diagnostics = array(
			'operational_registration' => match ( (string) $registration['status'] ) {
				'active'  => 'Active',
				'revoked' => 'Revoked',
				default   => 'Manager review required',
			},
			'canonical_source_status'  => 'Not applicable',
			'event_entitlement'        => 'Not yet confirmed',
			'ticket_mapping'           => 'Not yet confirmed',
			'selected_event'           => sprintf( '%s (event %d)', '' !== $event_title ? $event_title : 'Selected event', (int) $registration['event_id'] ),
			'date_validity'            => 'Not yet confirmed',
			'source_lifecycle'         => 'Not applicable',
			'source_quantity'          => 'Not applicable',
			'historical_mapping'       => 'No ambiguity detected',
		);
		if ( 'active' !== (string) $registration['status'] ) {
			$state = 'revoked' === (string) $registration['status'] ? 'revoked' : 'manager_review_required';
			return $this->blocked_admission(
				$state,
				'revoked' === $state ? 'REGISTRATION NOT VALID' : 'MANAGER HELP NEEDED',
				'revoked' === $state ? 'This registration was cancelled, refunded, or revoked.' : 'This registration needs manager review before check-in.',
				'oras_desk_registration_inactive',
				$diagnostics
			);
		}
		if ( 'rsvp_waitlist' === $source_type ) {
			$diagnostics['canonical_source_status'] = 'Waitlisted';
			$diagnostics['event_entitlement']       = 'No confirmed RSVP spot';
			return $this->blocked_admission(
				'waitlisted',
				'WAITLISTED — NOT ADMITTED',
				'This person is currently waitlisted and does not have a confirmed spot.',
				'oras_desk_rsvp_waitlisted',
				$diagnostics,
				false
			);
		}

		$option = Event_Offering_Resolver::find_access_option( (int) $registration['event_id'], $config, (string) $registration['option_uuid'] ) ?? Config::option( $config, (string) $registration['option_uuid'] );
		if ( null === $option && in_array( $source_type, array( 'rsvp_walk_in', 'rsvp_website' ), true ) ) {
			$option = array(
				'existing_access_valid' => true,
				'max_attendees'         => 1,
			);
		}
		if ( null === $option || empty( $option['existing_access_valid'] ) ) {
			$diagnostics['ticket_mapping']     = 'No current access mapping';
			$diagnostics['event_entitlement']  = 'Not confirmed for the selected event';
			$diagnostics['historical_mapping'] = 'The stored option no longer has one safe current mapping';
			return $this->blocked_admission(
				'manager_review_required',
				'MANAGER HELP NEEDED',
				'This registration type needs manager review.',
				'oras_desk_registration_inactive',
				$diagnostics
			);
		}
		$diagnostics['ticket_mapping'] = 'Current option mapping confirmed';

		$range = $this->event_range( (int) $registration['event_id'] );
		if ( $range instanceof \WP_Error ) {
			$diagnostics['date_validity'] = 'Event dates are unavailable';
			return $this->blocked_admission(
				'manager_review_required',
				'MANAGER HELP NEEDED',
				'The event dates need manager review before check-in.',
				'oras_desk_event_dates_unavailable',
				$diagnostics
			);
		}
		if ( ! self::date_is_within_event( $local_date, $range['start'], $range['end'] ) || ( 'one_day' === (string) $registration['validity_type'] && $local_date !== (string) $registration['valid_local_date'] ) ) {
			$diagnostics['date_validity'] = sprintf( 'Not valid on %s', $local_date );
			return $this->blocked_admission(
				'wrong_day',
				'VALID FOR ANOTHER DAY',
				'This registration is valid for a different day.',
				'oras_desk_wrong_date',
				$diagnostics,
				false
			);
		}
		$diagnostics['date_validity'] = sprintf( 'Valid on %s', $local_date );

		if ( 'rsvp_website' === $source_type ) {
			if ( ! preg_match( '/^rsvp-user:(\d+)$/', (string) $registration['source_key'], $matches ) || ! class_exists( \ORAS\Tickets\Frontend\Event_RSVP::class ) ) {
				$diagnostics['canonical_source_status'] = 'Website RSVP could not be resolved';
				return $this->blocked_admission( 'manager_review_required', 'MANAGER HELP NEEDED', 'This website RSVP needs manager review.', 'oras_desk_source_review', $diagnostics );
			}
			$rsvp_status = (string) \ORAS\Tickets\Frontend\Event_RSVP::get_user_status( (int) $registration['event_id'], (int) $matches[1] );
			$diagnostics['canonical_source_status'] = 'waitlist' === $rsvp_status ? 'Waitlisted' : ( 'yes' === $rsvp_status ? 'Confirmed' : 'Not confirmed' );
			if ( 'waitlist' === $rsvp_status ) {
				$diagnostics['event_entitlement'] = 'No confirmed RSVP spot';
				return $this->blocked_admission( 'waitlisted', 'WAITLISTED — NOT ADMITTED', 'This person is currently waitlisted and does not have a confirmed spot.', 'oras_desk_rsvp_waitlisted', $diagnostics, false );
			}
			if ( 'yes' !== $rsvp_status ) {
				$diagnostics['event_entitlement'] = 'No current confirmed RSVP';
				return $this->blocked_admission( 'not_valid', 'REGISTRATION NOT VALID', 'This RSVP is no longer confirmed for the event.', 'oras_desk_rsvp_not_admitted', $diagnostics );
			}
			$diagnostics['event_entitlement'] = 'Confirmed for the selected event';
		}

		$payment_label = '';
		$requires_unpaid = false;
		if ( 'online' === $source_type ) {
			if ( empty( $registration['source_order_id'] ) || empty( $registration['source_order_item_id'] ) ) {
				$diagnostics['canonical_source_status'] = 'Website source unavailable';
				return $this->blocked_admission( 'manager_review_required', 'MANAGER HELP NEEDED', 'This website registration needs manager review.', 'oras_desk_source_review', $diagnostics );
			}
			$evidence = $this->source_adapter->load( (int) $registration['source_order_id'], (int) $registration['source_order_item_id'] );
			if ( $evidence instanceof \WP_Error ) {
				$diagnostics['canonical_source_status'] = 'Website source unavailable';
				return $this->blocked_admission( 'manager_review_required', 'MANAGER HELP NEEDED', 'This website registration needs manager review.', 'oras_desk_source_review', $diagnostics );
			}
			$status = sanitize_key( (string) ( $evidence['order_status'] ?? '' ) );
			$diagnostics['canonical_source_status'] = '' !== $status ? ucwords( str_replace( '-', ' ', $status ) ) : 'Unknown';
			$diagnostics['source_lifecycle']        = (int) ( $evidence['refunded_quantity'] ?? 0 ) > 0 ? 'Refund recorded' : 'No refund recorded';
			$source_unit = (int) ( $registration['source_unit_number'] ?? 0 );
			$quantity    = (int) ( $evidence['quantity'] ?? 0 );
			$diagnostics['source_quantity'] = sprintf( 'Unit %d of %d', $source_unit, $quantity );
			if ( (int) ( $evidence['order_id'] ?? 0 ) !== (int) $registration['source_order_id'] || (int) ( $evidence['order_item_id'] ?? 0 ) !== (int) $registration['source_order_item_id'] ) {
				$diagnostics['historical_mapping'] = 'Stored and current source identities differ';
				return $this->blocked_admission( 'manager_review_required', 'MANAGER HELP NEEDED', 'This website registration needs manager review.', 'oras_desk_source_changed', $diagnostics );
			}
			if ( $source_unit <= 0 || $source_unit > $quantity ) {
				$diagnostics['historical_mapping'] = 'The stored source unit is no longer present';
				return $this->blocked_admission( 'manager_review_required', 'MANAGER HELP NEEDED', 'This website registration needs manager review.', 'oras_desk_source_unit_invalid', $diagnostics );
			}
			$resolution = Source_Resolver::resolve( $evidence, (int) $registration['event_id'], $config );
			if ( 'supported' !== (string) $resolution['resolution'] ) {
				$diagnostics['ticket_mapping']     = 'Current mapping needs review';
				$diagnostics['historical_mapping'] = '' !== (string) $resolution['reason'] ? (string) $resolution['reason'] : 'Current source metadata is ambiguous';
				return $this->blocked_admission( 'manager_review_required', 'MANAGER HELP NEEDED', 'This registration type needs manager review.', 'oras_desk_source_review', $diagnostics );
			}
			$diagnostics['event_entitlement'] = 'Confirmed for the selected event';
			if ( ! hash_equals( (string) $registration['option_uuid'], (string) $resolution['option_uuid'] ) || (string) $registration['classification'] !== (string) $resolution['classification'] || (string) $registration['validity_type'] !== (string) $resolution['validity_type'] ) {
				$diagnostics['ticket_mapping']     = 'Stored option differs from the current mapping';
				$diagnostics['historical_mapping'] = 'The canonical option or coverage metadata changed';
				return $this->blocked_admission( 'manager_review_required', 'MANAGER HELP NEEDED', 'This registration type needs manager review.', 'oras_desk_source_option_changed', $diagnostics );
			}
			$payment_label = (string) $resolution['payment_label'];
			if ( 'revoked' === (string) $resolution['eligibility'] ) {
				return $this->blocked_admission( 'revoked', 'REGISTRATION NOT VALID', 'This registration was cancelled or refunded.', 'oras_desk_not_eligible', $diagnostics );
			}
			if ( 'review_required' === (string) $resolution['eligibility'] ) {
				return $this->blocked_admission( 'manager_review_required', 'MANAGER HELP NEEDED', 'This registration type needs manager review.', 'oras_desk_not_eligible', $diagnostics );
			}
			if ( ! in_array( (string) $resolution['eligibility'], array( 'eligible', 'explicit_unpaid_required' ), true ) ) {
				return $this->blocked_admission( 'not_valid', 'REGISTRATION NOT VALID', 'This website registration is not currently valid for check-in.', 'oras_desk_not_eligible', $diagnostics );
			}
			$requires_unpaid = 'explicit_unpaid_required' === (string) $resolution['eligibility'];
		} else {
			$payment = $this->registration_payment_label( $registration, $config, false );
			if ( $payment instanceof \WP_Error ) {
				return $this->blocked_admission( 'manager_review_required', 'MANAGER HELP NEEDED', 'This registration needs manager review before check-in.', $payment->get_error_code(), $diagnostics );
			}
			$payment_label = $payment;
			if ( ! str_starts_with( $source_type, 'rsvp_' ) ) {
				$diagnostics['event_entitlement'] = 'Current option grants access to the selected event';
			}
		}

		return array(
			'state'                    => 'eligible',
			'status_label'             => 'REGISTRATION VALID',
			'message'                  => 'This registration can be checked in now.',
			'selection_allowed'        => true,
			'check_in_allowed'         => true,
			'allowed'                  => true,
			'manager_help'             => false,
			'requires_explicit_unpaid' => $requires_unpaid,
			'payment_label'            => $requires_unpaid ? 'Payment not confirmed' : ( 'online' === $source_type ? 'Website registration confirmed' : $payment_label ),
			'maximum_attendees'        => max( 1, min( 20, (int) ( $option['max_attendees'] ?? 1 ) ) ),
			'_source_label'            => $payment_label,
			'_error_code'              => '',
			'_option'                  => $option,
			'_diagnostics'             => $diagnostics,
		);
	}

	/** @param array<string,string> $diagnostics @return array<string,mixed> */
	private function blocked_admission( string $state, string $status_label, string $message, string $error_code, array $diagnostics, bool $manager_help = true ): array {
		return array(
			'state'                    => $state,
			'status_label'             => $status_label,
			'message'                  => $message,
			'selection_allowed'        => false,
			'check_in_allowed'         => false,
			'allowed'                  => false,
			'manager_help'             => $manager_help,
			'requires_explicit_unpaid' => false,
			'payment_label'            => '',
			'maximum_attendees'        => 0,
			'_source_label'            => '',
			'_error_code'              => $error_code,
			'_option'                  => array(),
			'_diagnostics'             => $diagnostics,
		);
	}

	/** @param array<string,mixed> $admission */
	private function admission_error( array $admission ): \WP_Error {
		return new \WP_Error(
			(string) ( $admission['_error_code'] ?? 'oras_desk_not_eligible' ),
			(string) ( $admission['message'] ?? 'This registration cannot be checked in right now.' ),
			array( 'status' => 409 )
		);
	}

	/** @param array<string,mixed> $registration @param array<string,mixed> $config @return string|\WP_Error */
	private function registration_payment_label( array $registration, array $config, bool $explicit_unpaid ) {
		if ( 'online' !== (string) $registration['source_type'] ) {
			return match ( (string) $registration['payment_assertion'] ) {
				'paid_card'    => 'Paid—Card (volunteer statement)',
				'paid_cash'    => 'Paid—Cash (volunteer statement)',
				'paid_check'   => 'Paid—Check (volunteer statement)',
				'unpaid'       => 'Unpaid admission',
				'complimentary' => 'Complimentary / speaker registration',
				'rsvp'          => 'Accountless desk RSVP',
				'manager_verified' => 'Manager verified registration manually',
				default        => new \WP_Error( 'oras_desk_payment_statement_missing', 'Desk registration payment statement is missing.', array( 'status' => 409 ) ),
			};
		}
		if ( empty( $registration['source_order_id'] ) || empty( $registration['source_order_item_id'] ) ) {
			return new \WP_Error( 'oras_desk_source_review', 'Website registration source is unavailable.', array( 'status' => 409 ) );
		}
		$evidence = $this->source_adapter->load( (int) $registration['source_order_id'], (int) $registration['source_order_item_id'] );
		if ( $evidence instanceof \WP_Error ) {
			return new \WP_Error( 'oras_desk_source_review', 'Website registration source is unavailable. Retry or request review.', array( 'status' => 409 ) );
		}
		$source_unit = (int) ( $registration['source_unit_number'] ?? 0 );
		if ( $source_unit <= 0 || $source_unit > (int) ( $evidence['quantity'] ?? 0 ) ) {
			return new \WP_Error( 'oras_desk_source_unit_invalid', 'This registration unit is no longer present in the website order.', array( 'status' => 409 ) );
		}
		$resolution = Source_Resolver::resolve( $evidence, (int) $registration['event_id'], $config );
		if ( 'supported' !== (string) $resolution['resolution'] || ! hash_equals( (string) $registration['option_uuid'], (string) $resolution['option_uuid'] ) ) {
			return new \WP_Error( 'oras_desk_source_review', 'Website registration mapping changed. Request administrator review.', array( 'status' => 409 ) );
		}
		if ( 'explicit_unpaid_required' === (string) $resolution['eligibility'] && ! $explicit_unpaid ) {
			return new \WP_Error( 'oras_desk_unpaid_confirmation_required', 'Payment is not confirmed. Choose the explicit unpaid admission action to continue.', array( 'status' => 409 ) );
		}
		if ( ! in_array( $resolution['eligibility'], array( 'eligible', 'explicit_unpaid_required' ), true ) ) {
			return new \WP_Error( 'oras_desk_not_eligible', (string) $resolution['payment_label'], array( 'status' => 409 ) );
		}

		return (string) $resolution['payment_label'];
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	private function binding( string $operation, array $payload, array $context ) {
		$request_uuid = strtolower( trim( (string) ( $context['request_uuid'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request_uuid ) ) {
			return new \WP_Error( 'oras_desk_request_invalid', 'A valid request identifier is required.', array( 'status' => 400 ) );
		}

		return array(
			'request_uuid'    => $request_uuid,
			'operation'       => $operation,
			'event_id'        => (int) $context['event_id'],
			'actor_user_id'   => (int) $context['actor_user_id'],
			'station_uuid'    => (string) $context['station_uuid'],
			'operator_label'  => (string) $context['operator_label'],
			'payload_hash'    => self::payload_hash( $payload ),
			'config_revision' => (int) $context['config_revision'],
		);
	}

	/** @param array<string,mixed> $binding @return array<string,mixed>|\WP_Error|null */
	private function replay_or_conflict( array $binding ) {
		$audit = $this->audits->find_request( (string) $binding['request_uuid'] );
		if ( ! $audit ) {
			return null;
		}
		foreach ( array( 'operation', 'event_id', 'actor_user_id', 'station_uuid', 'payload_hash', 'config_revision' ) as $key ) {
			if ( (string) $audit[ $key ] !== (string) $binding[ $key ] ) {
				return new \WP_Error( 'oras_desk_request_conflict', 'Request identifier was already used with different operation data.', array( 'status' => 409 ) );
			}
		}
		$historical = json_decode( (string) $audit['result_json'], true );
		$current    = ! empty( $audit['attendance_id'] ) ? $this->attendance->find_by_id( (int) $audit['attendance_id'] ) : null;

		return array(
			'replayed'           => true,
			'historical_result'  => is_array( $historical ) ? $historical : array(),
			'current_attendance' => $current,
		);
	}

	/** @param array<string,mixed> $binding @param array<string,mixed> $registration @param array<string,mixed> $attendee @param array<string,mixed> $attendance @param array<string,mixed> $result @param array<string,mixed> $changes @return array<string,mixed> */
	private function audit_record( array $binding, array $registration, array $attendee, array $attendance, string $code, array $result, array $changes ): array {
		$result_json  = wp_json_encode( $result );
		$changes_json = wp_json_encode( $changes );

		return array_merge(
			$binding,
			array(
				'registration_uuid' => (string) $registration['registration_uuid'],
				'attendee_uuid'     => (string) $attendee['attendee_uuid'],
				'attendance_id'     => (int) $attendance['id'],
				'result_status'     => 'success',
				'result_code'       => $code,
				'result_json'       => is_string( $result_json ) ? $result_json : '{}',
				'changes_json'      => is_string( $changes_json ) ? $changes_json : '{}',
			)
		);
	}

	/** @return array{start:string,end:string}|\WP_Error */
	private function event_range( int $event_id ) {
		$start = function_exists( 'tribe_get_start_date' ) ? (string) tribe_get_start_date( $event_id, false, 'Y-m-d' ) : substr( (string) get_post_meta( $event_id, '_EventStartDate', true ), 0, 10 );
		$end   = function_exists( 'tribe_get_end_date' ) ? (string) tribe_get_end_date( $event_id, false, 'Y-m-d' ) : substr( (string) get_post_meta( $event_id, '_EventEndDate', true ), 0, 10 );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
			return new \WP_Error( 'oras_desk_event_dates_unavailable', 'Event dates are unavailable. Request administrator review.', array( 'status' => 409 ) );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return is_string( $value ) ? trim( $value ) : $value;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}

		return $value;
	}
}
