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

		return array(
			'registration' => $registration,
			'attendees'    => $this->attendees->for_registration( (int) $registration['id'] ),
			'coverage'     => array(
				'complete'    => 'individual' === $registration['classification'],
				'limitations' => 'individual' === $registration['classification'] ? array() : array( 'M1A does not admit family, one-day, cross-event, or unclassified registrations.' ),
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
		$resolution = Source_Resolver::resolve( $evidence, (int) $context['event_id'], $config );
		if ( 'supported' !== $resolution['resolution'] || 'individual' !== $resolution['classification'] || 'full_event' !== $resolution['validity_type'] ) {
			return new \WP_Error( 'oras_desk_source_review', 'Website registration classification requires review.', array( 'status' => 409 ) );
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
					$code = 'checked_in' === $attendance['state'] && 1 === (int) $attendance['record_version'] ? 'checked_in' : 'already_checked_in';
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
		return $this->attendance->recent( $event_id, $limit );
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
