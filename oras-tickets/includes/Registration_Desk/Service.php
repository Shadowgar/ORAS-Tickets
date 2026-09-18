<?php

namespace ORAS\Tickets\Registration_Desk;

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
			'summary' => $this->attendance->dashboard( $event_id, $today ),
			'recent'  => $this->attendance->recent_detailed( $event_id, 12 ),
		);
	}

	/** @param array<string,mixed> $payload @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	public function create_walk_in( array $payload, array $context ) {
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
		if ( 'active' !== (string) $registration['status'] ) {
			return new \WP_Error( 'oras_desk_registration_inactive', 'This registration is not active. Request administrator review.', array( 'status' => 409 ) );
		}
		$config = Config::get_event_config( (int) $context['event_id'] );
		if ( empty( $config['enabled'] ) || (int) $config['revision'] !== (int) $context['config_revision'] ) {
			return new \WP_Error( 'oras_desk_config_changed', 'Registration Desk settings changed. Set up this station again.', array( 'status' => 409 ) );
		}
		$option = Config::option( $config, (string) $registration['option_uuid'] );
		if ( null === $option || empty( $option['existing_access_valid'] ) ) {
			return new \WP_Error( 'oras_desk_registration_inactive', 'This registration option no longer grants access.', array( 'status' => 409 ) );
		}
		$local_date = sanitize_text_field( (string) ( $payload['attendance_local_date'] ?? '' ) );
		$today      = wp_date( 'Y-m-d', null, wp_timezone() );
		if ( $local_date !== $today ) {
			return new \WP_Error( 'oras_desk_date_changed', 'The site-local date changed. Review the check-in before trying again.', array( 'status' => 409 ) );
		}
		$range = $this->event_range( (int) $context['event_id'] );
		if ( $range instanceof \WP_Error || ! self::date_is_within_event( $local_date, $range['start'], $range['end'] ) ) {
			return $range instanceof \WP_Error ? $range : new \WP_Error( 'oras_desk_wrong_date', 'This registration is not valid for today.', array( 'status' => 409 ) );
		}
		if ( 'one_day' === (string) $registration['validity_type'] && $local_date !== (string) $registration['valid_local_date'] ) {
			return new \WP_Error( 'oras_desk_wrong_date', 'This one-day registration is not valid for today.', array( 'status' => 409 ) );
		}
		$payment_label = $this->registration_payment_label( $registration, $config, ! empty( $payload['explicit_unpaid'] ) );
		if ( $payment_label instanceof \WP_Error ) {
			return $payment_label;
		}
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
		$admission = array(
			'allowed'                  => true,
			'requires_explicit_unpaid' => false,
			'payment_label'            => '',
			'message'                  => '',
		);
		$payment = $this->registration_payment_label( $registration, $config, false );
		if ( $payment instanceof \WP_Error ) {
			if ( 'oras_desk_unpaid_confirmation_required' === $payment->get_error_code() ) {
				$admission['requires_explicit_unpaid'] = true;
				$admission['payment_label']            = 'Payment not confirmed';
				$admission['message']                  = $payment->get_error_message();
			} else {
				$admission['allowed'] = false;
				$admission['message'] = $payment->get_error_message();
			}
		} else {
			$admission['payment_label'] = $payment;
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
		if ( 'active' !== (string) $registration['status'] ) {
			return new \WP_Error( 'oras_desk_registration_inactive', 'This registration is not active. Request administrator review.', array( 'status' => 409 ) );
		}
		$local_date = sanitize_text_field( (string) ( $payload['attendance_local_date'] ?? '' ) );
		$today      = wp_date( 'Y-m-d', null, wp_timezone() );
		if ( $local_date !== $today ) {
			return new \WP_Error( 'oras_desk_date_changed', 'The site-local date changed. Review the check-in before trying again.', array( 'status' => 409 ) );
		}
		$range = $this->event_range( (int) $context['event_id'] );
		if ( $range instanceof \WP_Error || ! self::date_is_within_event( $local_date, $range['start'], $range['end'] ) ) {
			return $range instanceof \WP_Error ? $range : new \WP_Error( 'oras_desk_wrong_date', 'This registration is not valid for today.', array( 'status' => 409 ) );
		}
		if ( 'online' !== $registration['source_type'] || empty( $registration['source_order_id'] ) || empty( $registration['source_order_item_id'] ) ) {
			return new \WP_Error( 'oras_desk_source_review', 'This M1A registration does not have a supported online source.', array( 'status' => 409 ) );
		}
		$evidence = $this->source_adapter->load( (int) $registration['source_order_id'], (int) $registration['source_order_item_id'] );
		if ( $evidence instanceof \WP_Error ) {
			return new \WP_Error( 'oras_desk_source_review', 'Website registration source is unavailable. Retry or request review.', array( 'status' => 409 ) );
		}
		$source_unit = (int) ( $registration['source_unit_number'] ?? 0 );
		if (
			(int) ( $evidence['order_id'] ?? 0 ) !== (int) $registration['source_order_id']
			|| (int) ( $evidence['order_item_id'] ?? 0 ) !== (int) $registration['source_order_item_id']
			|| (int) ( $evidence['source_event_id'] ?? 0 ) !== (int) $registration['event_id']
		) {
			return new \WP_Error( 'oras_desk_source_changed', 'Website registration source identity changed. Request administrator review.', array( 'status' => 409 ) );
		}
		if ( $source_unit <= 0 || $source_unit > (int) ( $evidence['quantity'] ?? 0 ) ) {
			return new \WP_Error( 'oras_desk_source_unit_invalid', 'This registration unit is no longer present in the website order.', array( 'status' => 409 ) );
		}
		$resolution = Source_Resolver::resolve( $evidence, (int) $context['event_id'], $config );
		if ( 'supported' !== $resolution['resolution'] || 'individual' !== $resolution['classification'] || 'full_event' !== $resolution['validity_type'] ) {
			return new \WP_Error( 'oras_desk_source_review', 'Website registration classification requires review.', array( 'status' => 409 ) );
		}
		if ( ! hash_equals( (string) $registration['option_uuid'], (string) $resolution['option_uuid'] ) ) {
			return new \WP_Error( 'oras_desk_source_option_changed', 'Website registration option mapping changed. Request administrator review.', array( 'status' => 409 ) );
		}
		$explicit_unpaid = ! empty( $payload['explicit_unpaid'] );
		if ( 'explicit_unpaid_required' === $resolution['eligibility'] && ! $explicit_unpaid ) {
			return new \WP_Error( 'oras_desk_unpaid_confirmation_required', 'Payment is not confirmed. Choose the explicit unpaid admission action to continue.', array( 'status' => 409 ) );
		}
		if ( ! in_array( $resolution['eligibility'], array( 'eligible', 'explicit_unpaid_required' ), true ) ) {
			return new \WP_Error( 'oras_desk_not_eligible', $resolution['payment_label'], array( 'status' => 409 ) );
		}
		$first_name = sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) );
		if ( '' === $first_name || '' === $last_name ) {
			return new \WP_Error( 'oras_desk_attendee_name_required', 'Confirm the arriving person’s first and last name.', array( 'status' => 400 ) );
		}

		$result = Store::transaction(
			function () use ( $registration, $first_name, $last_name, $local_date, $context, $binding, $explicit_unpaid, $resolution ) {
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
					'payment_label'     => (string) $resolution['payment_label'],
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
		$option = Config::option( $config, sanitize_text_field( (string) ( $payload['option_uuid'] ?? '' ) ) );
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
		$option = Config::option( $config, sanitize_text_field( (string) ( $payload['option_uuid'] ?? '' ) ) );
		if ( null === $option || ( ! $administrator_override && empty( $option['available_for_new'] ) ) ) {
			return new \WP_Error( 'oras_desk_option_invalid', 'Choose an available registration option.', array( 'status' => 400 ) );
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
			'evidence'              => array( 'mailing_address' => $address ),
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
