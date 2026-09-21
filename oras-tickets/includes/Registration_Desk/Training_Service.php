<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Domain\Event_Offering_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Synthetic-only Registration Desk behavior for one isolated training row. */
final class Training_Service {
	private const MAX_STATE_BYTES = 8388608;

	/**
	 * Read the selected event's canonical ticket configuration without reading registrations.
	 *
	 * @param array<string,mixed> $config
	 * @return array<int,array<string,mixed>>
	 */
	public static function canonical_offerings( int $event_id, array $config ): array {
		return Event_Offering_Resolver::desk_offerings( $event_id, $config );
	}

	/** @return array<int,array<string,mixed>> */
	public static function canonical_membership_offerings(): array {
		return Config::get_membership_offerings();
	}

	/**
	 * Build a deterministic synthetic dataset for a training session.
	 *
	 * @param array<int,array<string,mixed>> $offerings Canonical desk offerings.
	 * @return array<string,mixed>
	 */
	public static function seed_state( string $training_uuid, array $offerings ): array {
		$registrations = array();
		$seed_index    = 0;
		foreach ( $offerings as $offering ) {
			if ( ! is_array( $offering ) || '' === trim( (string) ( $offering['option_uuid'] ?? '' ) ) ) {
				continue;
			}
			++$seed_index;
			$registration = self::seed_registration( $training_uuid, $offering, $seed_index );
			$registrations[ $registration['registration_uuid'] ] = $registration;
		}

		return array(
			'schema_version'   => 1,
			'seed_version'     => 1,
			'registrations'    => $registrations,
			'attendance'       => array(),
			'requests'         => array(),
			'memberships'      => array(),
			'member_directory' => array(
				array(
					'member_uuid' => self::deterministic_uuid( $training_uuid, 'member|default' ),
					'name'        => 'DEMO — Morgan Lee',
					'email'       => 'demo.member@example.invalid',
					'phone'       => '555-0188',
					'level_name'  => 'DEMO — Active Member',
					'status'      => 'active',
					'synthetic'   => true,
				),
			),
			'history'          => array(),
		);
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $filters
	 * @return array{items:array<int,array<string,mixed>>,total:int,filters:array<string,mixed>}
	 */
	public static function roster( array $state, array $filters, string $simulated_local_date ): array {
		$normalized = self::normalize_filters( $filters );
		$items      = array();
		foreach ( self::registrations( $state ) as $registration ) {
			$checked_in = self::registration_checked_in( $state, $registration, $simulated_local_date );
			if ( ! self::matches_status( $registration, $checked_in, $normalized['status'] ) ) {
				continue;
			}
			if ( '' !== $normalized['option_uuid'] && $normalized['option_uuid'] !== (string) ( $registration['option_uuid'] ?? '' ) ) {
				continue;
			}
			if ( '' !== $normalized['q'] && ! self::matches_search( $registration, $normalized['q'] ) ) {
				continue;
			}
			$item                     = $registration;
			$item['checked_in_today'] = $checked_in;
			unset( $item['manager_detail'] );
			$items[] = $item;
		}
		usort(
			$items,
			static fn( array $left, array $right ): int => strcasecmp( (string) ( $left['contact_name'] ?? '' ), (string) ( $right['contact_name'] ?? '' ) )
		);
		$total = count( $items );
		$items = array_slice( $items, $normalized['offset'], $normalized['limit'] );

		return array(
			'items'   => $items,
			'total'   => $total,
			'filters' => $normalized,
		);
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>|null
	 */
	public static function detail( array $state, string $registration_uuid, bool $manager = false ): ?array {
		$registration = self::registrations( $state )[ $registration_uuid ] ?? null;
		if ( ! is_array( $registration ) ) {
			return null;
		}
		if ( ! $manager ) {
			unset( $registration['manager_detail'] );
		}

		return $registration;
	}

	/**
	 * Return the copied record in the same shape as the live registration detail endpoint.
	 *
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>|null
	 */
	public static function live_detail( array $state, string $registration_uuid, string $simulated_local_date ): ?array {
		$entry = self::registrations( $state )[ $registration_uuid ] ?? null;
		if ( ! is_array( $entry ) ) {
			return null;
		}
		$detail = is_array( $entry['live_detail'] ?? null ) ? $entry['live_detail'] : self::legacy_live_detail( $entry );
		$detail['local_date'] = $simulated_local_date;
		$attendance = is_array( $state['attendance'][ $simulated_local_date ] ?? null ) ? $state['attendance'][ $simulated_local_date ] : array();
		$available  = 0;
		$checked    = 0;
		$base_allowed = ! empty( $detail['admission']['check_in_allowed'] );
		$wrong_date = 'one_day' === (string) ( $entry['validity_type'] ?? '' ) && $simulated_local_date !== (string) ( $entry['valid_local_date'] ?? '' );
		$maximum    = max( 1, (int) ( $detail['admission']['maximum_attendees'] ?? count( $detail['attendees'] ) ) );
		foreach ( $detail['attendees'] as &$attendee ) {
			$attendee_uuid = (string) ( $attendee['attendee_uuid'] ?? '' );
			if ( isset( $attendance[ $attendee_uuid ] ) ) {
				$attendee['current_attendance'] = array_merge( $attendance[ $attendee_uuid ], array( 'state' => 'checked_in' ) );
				$attendee['admission'] = array(
					'state'             => 'already_checked_in',
					'selection_allowed' => false,
					'status_label'      => 'CHECKED IN TODAY',
				);
				++$checked;
			} else {
				unset( $attendee['current_attendance'] );
				$selection_allowed = $base_allowed && ! $wrong_date;
				$attendee['admission'] = array(
					'state'             => $selection_allowed ? 'eligible' : (string) ( $detail['admission']['state'] ?? 'manager_review_required' ),
					'selection_allowed' => $selection_allowed,
					'status_label'      => $selection_allowed ? 'READY TO CHECK IN' : (string) ( $detail['admission']['status_label'] ?? 'MANAGER HELP NEEDED' ),
				);
				$available += $selection_allowed ? 1 : 0;
			}
		}
		unset( $attendee );
		if ( $wrong_date ) {
			$detail['admission'] = array_merge(
				$detail['admission'],
				array(
					'state'             => 'wrong_date',
					'status_label'      => 'NOT VALID TODAY',
					'message'           => 'This registration is valid on another event date.',
					'selection_allowed' => false,
					'check_in_allowed'  => false,
					'allowed'           => false,
				)
			);
		} elseif ( $checked > 0 && 0 === $available && count( $detail['attendees'] ) >= $maximum ) {
			$detail['admission'] = array_merge(
				$detail['admission'],
				array(
					'state'             => 'already_checked_in',
					'status_label'      => 'CHECKED IN TODAY',
					'message'           => 'Everyone on this registration is already checked in today.',
					'selection_allowed' => false,
					'check_in_allowed'  => false,
					'allowed'           => false,
				)
			);
		}

		return $detail;
	}

	/**
	 * Apply one training-only attendance transition.
	 *
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $context
	 * @return array{state:array<string,mixed>,result:array<string,mixed>}|\WP_Error
	 */
	public static function check_in_state( array $state, array $payload, array $context ) {
		$valid_context = self::validate_operation_context( $context );
		if ( $valid_context instanceof \WP_Error ) {
			return $valid_context;
		}
		$request = self::request_state( $state, 'check_in', $payload );
		if ( $request instanceof \WP_Error ) {
			return $request;
		}
		if ( isset( $request['replay'] ) ) {
			return array(
				'state'  => $state,
				'result' => $request['replay'],
			);
		}

		$registration_uuid = strtolower( trim( (string) ( $payload['registration_uuid'] ?? '' ) ) );
		$registration      = self::registrations( $state )[ $registration_uuid ] ?? null;
		if ( ! is_array( $registration ) ) {
			return new \WP_Error( 'oras_desk_training_registration_missing', 'That training registration is no longer available.', array( 'status' => 404 ) );
		}
		$valid_offering = ! empty( $registration['snapshot'] ) ? $registration : self::validate_registration_offering( $registration, $context );
		if ( $valid_offering instanceof \WP_Error ) {
			return $valid_offering;
		}
		$date_valid = self::validate_offering_date( $valid_offering, (string) $valid_context['simulated_local_date'] );
		if ( $date_valid instanceof \WP_Error ) {
			return $date_valid;
		}
		if ( ! empty( $registration['snapshot'] ) && empty( $registration['live_detail']['admission']['check_in_allowed'] ) ) {
			return new \WP_Error( 'oras_desk_not_eligible', 'This registration cannot be checked in right now.', array( 'status' => 409 ) );
		}

		$available = array();
		foreach ( is_array( $registration['attendees'] ?? null ) ? $registration['attendees'] : array() as $attendee ) {
			if ( is_array( $attendee ) && '' !== (string) ( $attendee['attendee_uuid'] ?? '' ) ) {
				$available[ (string) $attendee['attendee_uuid'] ] = $attendee;
			}
		}
		$selected = array_values( array_unique( array_map( 'strval', is_array( $payload['attendee_uuids'] ?? null ) ? $payload['attendee_uuids'] : array() ) ) );
		$arrivals = is_array( $payload['arrivals'] ?? null ) ? $payload['arrivals'] : array();
		if ( ! empty( $arrivals ) ) {
			$by_slot = array();
			foreach ( $available as $attendee_uuid => $attendee ) {
				$slot_key = (string) ( $attendee['slot_key'] ?? '' );
				if ( '' !== $slot_key ) {
					$by_slot[ $slot_key ] = $attendee_uuid;
				}
			}
			$maximum = max( 1, (int) ( $registration['live_detail']['admission']['maximum_attendees'] ?? $registration['max_attendees'] ?? count( $available ) ) );
			foreach ( $arrivals as $arrival_index => $arrival ) {
				if ( ! is_array( $arrival ) ) {
					continue;
				}
				$slot_key = self::clean_text( (string) ( $arrival['slot_key'] ?? '' ) );
				if ( isset( $by_slot[ $slot_key ] ) ) {
					$selected[] = $by_slot[ $slot_key ];
					continue;
				}
				if ( count( $available ) >= $maximum ) {
					return new \WP_Error( 'oras_desk_training_attendees_invalid', 'Enter a valid number of attendees for this registration type.', array( 'status' => 400 ) );
				}
				$first_name = self::clean_text( (string) ( $arrival['first_name'] ?? '' ) );
				$last_name  = self::clean_text( (string) ( $arrival['last_name'] ?? '' ) );
				$name       = trim( $first_name . ' ' . $last_name );
				$attendee_uuid = self::deterministic_uuid( (string) $valid_context['training_uuid'], 'arrival|' . (string) $request['request_uuid'] . '|' . $slot_key . '|' . $arrival_index );
				$attendee = array(
					'attendee_uuid' => $attendee_uuid,
					'slot_key'      => '' !== $slot_key ? $slot_key : 'arrival-' . ( $arrival_index + 1 ),
					'display_name'  => $name,
					'name'          => $name,
					'first_name'    => $first_name,
					'last_name'     => $last_name,
					'status'        => 'active',
					'synthetic'     => true,
				);
				$available[ $attendee_uuid ] = $attendee;
				$registration['attendees'][] = $attendee;
				$registration['live_detail']['attendees'][] = $attendee;
				$selected[] = $attendee_uuid;
			}
			$state['registrations'][ $registration_uuid ] = $registration;
			$selected = array_values( array_unique( $selected ) );
		}
		if ( empty( $selected ) ) {
			return new \WP_Error( 'oras_desk_training_attendee_required', 'Choose at least one training attendee to check in.', array( 'status' => 400 ) );
		}
		foreach ( $selected as $attendee_uuid ) {
			if ( ! isset( $available[ $attendee_uuid ] ) ) {
				return new \WP_Error( 'oras_desk_training_attendee_invalid', 'A selected training attendee does not belong to this registration.', array( 'status' => 400 ) );
			}
		}

		$date       = (string) $valid_context['simulated_local_date'];
		$occurred   = self::occurred_at( $context );
		$attendance = is_array( $state['attendance'][ $date ] ?? null ) ? $state['attendance'][ $date ] : array();
		foreach ( $selected as $attendee_uuid ) {
			if ( ! isset( $attendance[ $attendee_uuid ] ) ) {
				$attendance[ $attendee_uuid ] = array(
					'attendee_uuid'        => $attendee_uuid,
					'registration_uuid'    => $registration_uuid,
					'checked_in_at_utc'    => $occurred,
					'simulated_local_date' => $date,
					'synthetic'            => true,
				);
			}
		}
		$state['attendance'][ $date ] = $attendance;
		$result = array(
			'registration_uuid'  => $registration_uuid,
			'attendee_uuids'     => $selected,
			'local_date'         => $date,
			'checked_in'         => true,
			'current_attendance' => array_values( array_intersect_key( $attendance, array_flip( $selected ) ) ),
		);
		$state = self::record_request( $state, (string) $request['request_uuid'], 'check_in', (string) $request['payload_hash'], $result, $occurred );
		$state['history'][] = array(
			'action'            => 'check_in',
			'request_uuid'      => (string) $request['request_uuid'],
			'registration_uuid' => $registration_uuid,
			'local_date'        => $date,
			'occurred_at_utc'   => $occurred,
		);

		return self::bounded_transition( $state, $result );
	}

	/**
	 * Apply one nonfinancial training walk-in transition.
	 *
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $context
	 * @return array{state:array<string,mixed>,result:array<string,mixed>}|\WP_Error
	 */
	public static function walk_in_state( array $state, array $payload, array $context ) {
		$valid_context = self::validate_operation_context( $context );
		if ( $valid_context instanceof \WP_Error ) {
			return $valid_context;
		}
		$request = self::request_state( $state, 'walk_in', $payload );
		if ( $request instanceof \WP_Error ) {
			return $request;
		}
		if ( isset( $request['replay'] ) ) {
			return array(
				'state'  => $state,
				'result' => $request['replay'],
			);
		}

		$option_uuid = strtolower( trim( (string) ( $payload['option_uuid'] ?? '' ) ) );
		$offering    = self::find_context_offering( $context, $option_uuid );
		if ( null === $offering ) {
			return new \WP_Error( 'oras_desk_training_offering_missing', 'That training registration type is no longer available.', array( 'status' => 409 ) );
		}
		$fingerprint = (string) ( $payload['offering_fingerprint'] ?? '' );
		if ( '' === $fingerprint || ! hash_equals( (string) ( $offering['offering_fingerprint'] ?? '' ), $fingerprint ) ) {
			return new \WP_Error( 'oras_desk_training_offering_changed', 'The event configuration changed. Restart Training Mode before continuing.', array( 'status' => 409 ) );
		}
		$date_valid = self::validate_offering_date( $offering, (string) $valid_context['simulated_local_date'] );
		if ( $date_valid instanceof \WP_Error ) {
			return $date_valid;
		}
		$payment = strtolower( trim( (string) ( $payload['payment_assertion'] ?? '' ) ) );
		if ( ! in_array( $payment, array( 'paid_card', 'paid_cash', 'paid_check', 'unpaid' ), true ) ) {
			return new \WP_Error( 'oras_desk_training_payment_invalid', 'Choose the training payment statement used in this practice scenario.', array( 'status' => 400 ) );
		}

		$submitted_attendees = is_array( $payload['attendees'] ?? null ) ? $payload['attendees'] : array();
		$maximum             = 'family' === (string) ( $offering['classification'] ?? '' ) ? max( 1, (int) ( $offering['max_attendees'] ?? 1 ) ) : 1;
		if ( empty( $submitted_attendees ) || count( $submitted_attendees ) > $maximum ) {
			return new \WP_Error( 'oras_desk_training_attendees_invalid', 'Enter a valid number of training attendees for this registration type.', array( 'status' => 400 ) );
		}
		$contact_name = self::clean_text( (string) ( $payload['contact_name'] ?? '' ) );
		if ( '' === $contact_name ) {
			return new \WP_Error( 'oras_desk_training_contact_required', 'Enter a name for the training walk-in.', array( 'status' => 400 ) );
		}
		$registration_uuid = self::deterministic_uuid( (string) ( $context['training_uuid'] ?? '' ), 'walk-in|' . (string) $request['request_uuid'] );
		$attendees         = array();
		foreach ( $submitted_attendees as $index => $submitted ) {
			$name = is_array( $submitted ) ? self::clean_text( (string) ( $submitted['name'] ?? '' ) ) : self::clean_text( (string) $submitted );
			if ( '' === $name ) {
				$name = $contact_name;
			}
			$attendees[] = array(
				'attendee_uuid' => self::deterministic_uuid( (string) ( $context['training_uuid'] ?? '' ), 'walk-in-attendee|' . (string) $request['request_uuid'] . '|' . $index ),
				'name'          => $name,
				'synthetic'     => true,
			);
		}
		$registration = array(
			'registration_uuid'    => $registration_uuid,
			'source_type'          => 'walk_in',
			'contact_name'         => $contact_name,
			'email'                => trim( (string) ( $payload['email'] ?? '' ) ),
			'phone'                => trim( (string) ( $payload['phone'] ?? '' ) ),
			'option_uuid'          => $option_uuid,
			'option_label'         => (string) ( $offering['label'] ?? $offering['name'] ?? 'Registration' ),
			'option_description'   => (string) ( $offering['description'] ?? '' ),
			'option_price'         => (string) ( $offering['price'] ?? '' ),
			'classification'       => 'family' === (string) ( $offering['classification'] ?? '' ) ? 'family' : 'individual',
			'max_attendees'        => $maximum,
			'validity_type'        => 'one_day' === (string) ( $offering['validity_type'] ?? '' ) ? 'one_day' : 'full_event',
			'valid_local_date'     => (string) ( $offering['valid_local_date'] ?? '' ),
			'offering_fingerprint' => (string) ( $offering['offering_fingerprint'] ?? '' ),
			'included_events'      => self::included_events_snapshot( $offering['included_events'] ?? array() ),
			'payment_assertion'    => $payment,
			'attendees'            => $attendees,
			'manager_detail'       => array(
				'synthetic'         => true,
				'origin'            => 'Training Mode walk-in',
				'training_only'     => true,
				'payment_simulated' => true,
			),
		);
		$state['registrations'][ $registration_uuid ] = $registration;
		$date       = (string) $valid_context['simulated_local_date'];
		$occurred   = self::occurred_at( $context );
		$attendance = is_array( $state['attendance'][ $date ] ?? null ) ? $state['attendance'][ $date ] : array();
		$attendee_uuids = array();
		foreach ( $attendees as $attendee ) {
			$attendee_uuid = (string) $attendee['attendee_uuid'];
			$attendee_uuids[] = $attendee_uuid;
			$attendance[ $attendee_uuid ] = array(
				'attendee_uuid'        => $attendee_uuid,
				'registration_uuid'    => $registration_uuid,
				'checked_in_at_utc'    => $occurred,
				'simulated_local_date' => $date,
				'synthetic'            => true,
			);
		}
		$state['attendance'][ $date ] = $attendance;
		$result = array(
			'registration_uuid' => $registration_uuid,
			'attendee_uuids'    => $attendee_uuids,
			'local_date'        => $date,
			'payment_assertion' => $payment,
			'checked_in'        => true,
		);
		$state = self::record_request( $state, (string) $request['request_uuid'], 'walk_in', (string) $request['payload_hash'], $result, $occurred );
		$state['history'][] = array(
			'action'            => 'walk_in',
			'request_uuid'      => (string) $request['request_uuid'],
			'registration_uuid' => $registration_uuid,
			'local_date'        => $date,
			'occurred_at_utc'   => $occurred,
		);

		return self::bounded_transition( $state, $result );
	}

	/** @param array<string,mixed> $state @return array<int,array<string,mixed>> */
	public static function member_lookup( array $state, string $query ): array {
		$query   = self::lower( self::clean_text( $query ) );
		$matches = array();
		if ( '' === $query ) {
			return $matches;
		}
		foreach ( is_array( $state['member_directory'] ?? null ) ? $state['member_directory'] : array() as $member ) {
			if ( ! is_array( $member ) ) {
				continue;
			}
			$haystack = self::lower( implode( ' ', array( (string) ( $member['name'] ?? '' ), (string) ( $member['email'] ?? '' ), (string) ( $member['phone'] ?? '' ) ) ) );
			if ( false !== strpos( $haystack, $query ) ) {
				$matches[] = $member;
			}
		}

		return $matches;
	}

	/**
	 * Record a synthetic membership practice result without issuing credit or activation.
	 *
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $context
	 * @return array{state:array<string,mixed>,result:array<string,mixed>}|\WP_Error
	 */
	public static function record_membership_state( array $state, array $payload, array $context ) {
		$valid_context = self::validate_operation_context( $context );
		if ( $valid_context instanceof \WP_Error ) {
			return $valid_context;
		}
		$request = self::request_state( $state, 'membership', $payload );
		if ( $request instanceof \WP_Error ) {
			return $request;
		}
		if ( isset( $request['replay'] ) ) {
			return array(
				'state'  => $state,
				'result' => $request['replay'],
			);
		}

		$level_id = max( 0, (int) ( $payload['level_id'] ?? 0 ) );
		$offering = null;
		foreach ( is_array( $context['canonical_membership_offerings'] ?? null ) ? $context['canonical_membership_offerings'] : array() as $candidate ) {
			if ( is_array( $candidate ) && $level_id === (int) ( $candidate['level_id'] ?? 0 ) ) {
				$offering = $candidate;
				break;
			}
		}
		if ( null === $offering ) {
			return new \WP_Error( 'oras_desk_training_membership_unavailable', 'That training membership option is no longer available.', array( 'status' => 409 ) );
		}
		$payment_method = strtolower( trim( (string) ( $payload['payment_method'] ?? '' ) ) );
		if ( ! in_array( $payment_method, array( 'cash', 'check' ), true ) ) {
			return new \WP_Error( 'oras_desk_training_membership_payment_invalid', 'Choose Cash or Check for the training membership scenario.', array( 'status' => 400 ) );
		}
		$contact_name = self::clean_text( (string) ( $payload['contact_name'] ?? '' ) );
		$email        = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );
		if ( '' === $contact_name || '' === $email ) {
			return new \WP_Error( 'oras_desk_training_membership_contact_required', 'Enter a name and email for the training membership scenario.', array( 'status' => 400 ) );
		}
		$membership_uuid = self::deterministic_uuid( (string) ( $context['training_uuid'] ?? '' ), 'membership|' . (string) $request['request_uuid'] );
		$occurred        = self::occurred_at( $context );
		$membership      = array(
			'membership_uuid' => $membership_uuid,
			'level_id'        => $level_id,
			'level_name'      => (string) ( $offering['display_name'] ?? 'Membership' ),
			'reference_price' => (string) ( $offering['price'] ?? '' ),
			'period_label'    => (string) ( $offering['period_label'] ?? '' ),
			'contact_name'    => $contact_name,
			'email'           => $email,
			'payment_method'  => $payment_method,
			'status'          => 'simulated',
			'synthetic'       => true,
			'occurred_at_utc' => $occurred,
		);
		$state['memberships'][ $membership_uuid ] = $membership;
		$result = array(
			'membership_uuid' => $membership_uuid,
			'level_name'      => $membership['level_name'],
			'reference_price' => $membership['reference_price'],
			'payment_method'  => $payment_method,
			'simulated'       => true,
			'activation_sent' => false,
		);
		$state = self::record_request( $state, (string) $request['request_uuid'], 'membership', (string) $request['payload_hash'], $result, $occurred );
		$state['history'][] = array(
			'action'          => 'membership',
			'request_uuid'    => (string) $request['request_uuid'],
			'membership_uuid' => $membership_uuid,
			'local_date'      => (string) $valid_context['simulated_local_date'],
			'occurred_at_utc' => $occurred,
		);

		return self::bounded_transition( $state, $result );
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	public static function stats( array $state, string $simulated_local_date ): array {
		$registrations = self::registrations( $state );
		$registration_by_attendee = array();
		$pass_types      = array();
		$classifications = array();
		$validity        = array();
		$walk_ins        = 0;
		$source_registrations = array(
			'website'          => 0,
			'included'         => 0,
			'walk_in'          => 0,
			'complimentary'    => 0,
			'rsvp'             => 0,
			'manager_verified' => 0,
		);
		$family_registrations = 0;
		$payment         = array(
			'paid_card'  => 0,
			'paid_cash'  => 0,
			'paid_check' => 0,
			'unpaid'     => 0,
		);
		$people_registered = 0;
		foreach ( $registrations as $registration_uuid => $registration ) {
			self::increment_count( $pass_types, (string) ( $registration['option_label'] ?? 'Other' ) );
			self::increment_count( $classifications, (string) ( $registration['classification'] ?? 'individual' ) );
			self::increment_count( $validity, (string) ( $registration['validity_type'] ?? 'full_event' ) );
			if ( in_array( (string) ( $registration['source_type'] ?? '' ), array( 'training_walk_in', 'walk_in' ), true ) ) {
				++$walk_ins;
				$assertion = (string) ( $registration['payment_assertion'] ?? '' );
				if ( isset( $payment[ $assertion ] ) ) {
					++$payment[ $assertion ];
				}
			}
			$source_bucket = self::source_bucket( (string) ( $registration['source_type'] ?? '' ) );
			++$source_registrations[ $source_bucket ];
			if ( 'family' === (string) ( $registration['classification'] ?? '' ) ) {
				++$family_registrations;
			}
			foreach ( is_array( $registration['attendees'] ?? null ) ? $registration['attendees'] : array() as $attendee ) {
				if ( is_array( $attendee ) && '' !== (string) ( $attendee['attendee_uuid'] ?? '' ) ) {
					$registration_by_attendee[ (string) $attendee['attendee_uuid'] ] = $registration_uuid;
					++$people_registered;
				}
			}
		}

		$attendance_by_day = array();
		$unique_attendees  = array();
		$attended_registrations = array();
		$today_people      = array();
		$today_walk_ins    = array();
		$today_walk_in_registrations = array();
		$today_by_source   = array_fill_keys( array_keys( $source_registrations ), array() );
		$family_attendees_attended = array();
		$today_pass_types  = array();
		$attendance_instances = 0;
		foreach ( is_array( $state['attendance'] ?? null ) ? $state['attendance'] : array() as $date => $instances ) {
			if ( ! is_array( $instances ) ) {
				continue;
			}
			foreach ( $instances as $attendee_uuid => $instance ) {
				if ( ! isset( $registration_by_attendee[ (string) $attendee_uuid ] ) ) {
					continue;
				}
				++$attendance_instances;
				self::increment_count( $attendance_by_day, (string) $date );
				$unique_attendees[ (string) $attendee_uuid ] = true;
				$registration_uuid = $registration_by_attendee[ (string) $attendee_uuid ];
				$attended_registrations[ $registration_uuid ] = true;
				$registration = $registrations[ $registration_uuid ];
				if ( 'family' === (string) ( $registration['classification'] ?? '' ) ) {
					$family_attendees_attended[ (string) $attendee_uuid ] = true;
				}
				if ( (string) $date === $simulated_local_date ) {
					$today_people[ (string) $attendee_uuid ] = true;
					$source_bucket = self::source_bucket( (string) ( $registration['source_type'] ?? '' ) );
					$today_by_source[ $source_bucket ][ (string) $attendee_uuid ] = true;
					if ( in_array( (string) ( $registration['source_type'] ?? '' ), array( 'training_walk_in', 'walk_in' ), true ) ) {
						$today_walk_ins[ (string) $attendee_uuid ] = true;
						$today_walk_in_registrations[ $registration_uuid ] = true;
					}
					self::increment_count( $today_pass_types, (string) ( $registration['option_label'] ?? 'Other' ) );
				}
			}
		}
		ksort( $attendance_by_day );

		$membership_summary = array(
			'total'  => 0,
			'cash'   => 0,
			'check'  => 0,
			'levels' => array(),
		);
		foreach ( is_array( $state['memberships'] ?? null ) ? $state['memberships'] : array() as $membership ) {
			if ( ! is_array( $membership ) ) {
				continue;
			}
			++$membership_summary['total'];
			$method = (string) ( $membership['payment_method'] ?? '' );
			if ( isset( $membership_summary[ $method ] ) ) {
				++$membership_summary[ $method ];
			}
			self::increment_count( $membership_summary['levels'], (string) ( $membership['level_name'] ?? 'Other' ) );
		}

		return array(
			'training'    => true,
			'today'       => array(
				'actual_people'             => count( $today_people ),
				'website_people'            => count( $today_by_source['website'] ),
				'included_event_people'     => count( $today_by_source['included'] ),
				'walk_in_people'            => count( $today_walk_ins ),
				'complimentary_people'      => count( $today_by_source['complimentary'] ),
				'rsvp_people'               => count( $today_by_source['rsvp'] ),
				'manager_verified_people'   => count( $today_by_source['manager_verified'] ),
				'new_walk_in_registrations' => count( $today_walk_in_registrations ),
				'pass_types'                => $today_pass_types,
			),
			'event_total' => array(
				'active_registrations'           => count( $registrations ),
				'people_registered'              => $people_registered,
				'unique_attendees'               => count( $unique_attendees ),
				'attendance_instances'           => $attendance_instances,
				'attendance_by_day'              => $attendance_by_day,
				'direct_website_registrations'   => $source_registrations['website'],
				'included_event_registrations'   => $source_registrations['included'],
				'walk_in_registrations'          => $walk_ins,
				'complimentary_registrations'    => $source_registrations['complimentary'],
				'rsvp_registrations'             => $source_registrations['rsvp'],
				'manager_verified_registrations' => $source_registrations['manager_verified'],
				'family_registrations'           => $family_registrations,
				'family_attendees_attended'      => count( $family_attendees_attended ),
				'no_show_registrations'          => count( $registrations ) - count( $attended_registrations ),
				'pass_types'                     => $pass_types,
				'classifications'                => $classifications,
				'validity'                       => $validity,
				'payment_assertions'             => $payment,
			),
			'memberships' => $membership_summary,
		);
	}

	/** @param array<string,mixed> $state */
	public static function state_within_limit( array $state ): bool {
		$encoded = self::json_encode( $state );

		return is_string( $encoded ) && strlen( $encoded ) <= self::MAX_STATE_BYTES;
	}

	/** @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	private static function validate_operation_context( array $context ) {
		if ( (int) ( $context['config_revision'] ?? -1 ) !== (int) ( $context['current_config_revision'] ?? -2 ) ) {
			return new \WP_Error( 'oras_desk_training_config_changed', 'Event configuration changed. End and restart Training Mode before continuing.', array( 'status' => 409 ) );
		}
		$date  = (string) ( $context['simulated_local_date'] ?? '' );
		$start = (string) ( $context['event_start_date'] ?? '' );
		$end   = (string) ( $context['event_end_date'] ?? '' );
		if ( ! Training_Context::is_event_date( $date, $start, $end ) ) {
			return new \WP_Error( 'oras_desk_training_date_invalid', 'The training date must be within the selected event.', array( 'status' => 400 ) );
		}

		return $context;
	}

	/** @param array<string,mixed> $registration @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
	private static function validate_registration_offering( array $registration, array $context ) {
		$offering = self::find_context_offering( $context, (string) ( $registration['option_uuid'] ?? '' ) );
		if ( null === $offering ) {
			return new \WP_Error( 'oras_desk_training_offering_missing', 'That training registration type is no longer available.', array( 'status' => 409 ) );
		}
		if ( ! hash_equals( (string) ( $registration['offering_fingerprint'] ?? '' ), (string) ( $offering['offering_fingerprint'] ?? '' ) ) ) {
			return new \WP_Error( 'oras_desk_training_offering_changed', 'The event configuration changed. Restart Training Mode before continuing.', array( 'status' => 409 ) );
		}

		return $offering;
	}

	/** @param array<string,mixed> $context @return array<string,mixed>|null */
	private static function find_context_offering( array $context, string $option_uuid ): ?array {
		foreach ( is_array( $context['canonical_offerings'] ?? null ) ? $context['canonical_offerings'] : array() as $offering ) {
			if ( is_array( $offering ) && strtolower( trim( (string) ( $offering['option_uuid'] ?? '' ) ) ) === strtolower( trim( $option_uuid ) ) ) {
				return $offering;
			}
		}

		return null;
	}

	/** @param array<string,mixed> $offering @return true|\WP_Error */
	private static function validate_offering_date( array $offering, string $date ) {
		if ( 'one_day' === (string) ( $offering['validity_type'] ?? '' ) && $date !== (string) ( $offering['valid_local_date'] ?? '' ) ) {
			return new \WP_Error( 'oras_desk_training_date_invalid', 'That one-day registration is not valid on the selected training date.', array( 'status' => 409 ) );
		}

		return true;
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $payload @return array<string,mixed>|\WP_Error */
	private static function request_state( array $state, string $operation, array $payload ) {
		$request_uuid = strtolower( trim( (string) ( $payload['request_uuid'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request_uuid ) ) {
			return new \WP_Error( 'oras_desk_training_request_invalid', 'The training request identity is invalid.', array( 'status' => 400 ) );
		}
		$payload_hash = self::payload_hash( $payload );
		$existing     = is_array( $state['requests'][ $request_uuid ] ?? null ) ? $state['requests'][ $request_uuid ] : null;
		if ( null !== $existing ) {
			if ( $operation !== (string) ( $existing['operation'] ?? '' ) || ! hash_equals( (string) ( $existing['payload_hash'] ?? '' ), $payload_hash ) ) {
				return new \WP_Error( 'oras_desk_training_request_conflict', 'That training request was already used for a different action.', array( 'status' => 409 ) );
			}

			return array(
				'request_uuid' => $request_uuid,
				'payload_hash' => $payload_hash,
				'replay'       => is_array( $existing['result'] ?? null ) ? $existing['result'] : array(),
			);
		}

		return array(
			'request_uuid' => $request_uuid,
			'payload_hash' => $payload_hash,
		);
	}

	/** @param array<string,mixed> $payload */
	private static function payload_hash( array $payload ): string {
		$canonical = self::canonicalize( $payload );

		return hash( 'sha256', (string) self::json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::canonicalize( $child );
		}

		return $value;
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $result @return array<string,mixed> */
	private static function record_request( array $state, string $request_uuid, string $operation, string $payload_hash, array $result, string $occurred ): array {
		$state['requests'][ $request_uuid ] = array(
			'operation'       => $operation,
			'payload_hash'    => $payload_hash,
			'result'          => $result,
			'occurred_at_utc' => $occurred,
		);

		return $state;
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $result @return array{state:array<string,mixed>,result:array<string,mixed>}|\WP_Error */
	private static function bounded_transition( array $state, array $result ) {
		if ( ! self::state_within_limit( $state ) ) {
			return new \WP_Error( 'oras_desk_training_state_invalid', 'Training data is too large to save. No live event data was changed.', array( 'status' => 409 ) );
		}

		return array(
			'state'  => $state,
			'result' => $result,
		);
	}

	/** @param array<string,mixed> $context */
	private static function occurred_at( array $context ): string {
		$value = trim( (string) ( $context['occurred_at_utc'] ?? '' ) );

		return '' !== $value ? $value : gmdate( 'Y-m-d H:i:s' );
	}

	private static function demo_name( string $name ): string {
		$name = trim( $name );

		return str_starts_with( $name, 'DEMO — ' ) ? $name : 'DEMO — ' . $name;
	}

	/** @param array<string,int> $counts */
	private static function increment_count( array &$counts, string $key ): void {
		$key = '' !== trim( $key ) ? $key : 'Other';
		$counts[ $key ] = (int) ( $counts[ $key ] ?? 0 ) + 1;
	}

	/**
	 * @param array<string,mixed> $offering
	 * @return array<string,mixed>
	 */
	private static function seed_registration( string $training_uuid, array $offering, int $index ): array {
		$option_uuid    = strtolower( trim( (string) $offering['option_uuid'] ) );
		$classification = 'family' === (string) ( $offering['classification'] ?? '' ) ? 'family' : 'individual';
		$label          = trim( (string) ( $offering['label'] ?? $offering['name'] ?? 'Registration' ) );
		$is_student     = false !== stripos( $label . ' ' . (string) ( $offering['ticket_key'] ?? '' ), 'student' );
		$identity       = self::seed_identity( $classification, $is_student, $index );
		$registration_uuid = self::deterministic_uuid( $training_uuid, 'registration|' . $option_uuid );
		$attendee_count = 'family' === $classification ? min( 3, max( 1, (int) ( $offering['max_attendees'] ?? 1 ) ) ) : 1;
		$attendees      = array();
		$family_names   = array( 'DEMO — Taylor Morgan', 'DEMO — Jordan Morgan', 'DEMO — Casey Morgan', 'DEMO — Riley Morgan' );
		for ( $attendee_index = 0; $attendee_index < $attendee_count; ++$attendee_index ) {
			$name = 'family' === $classification ? $family_names[ $attendee_index ] : $identity['name'];
			$attendees[] = array(
				'attendee_uuid' => self::deterministic_uuid( $training_uuid, 'attendee|' . $option_uuid . '|' . $attendee_index ),
				'name'          => $name,
				'synthetic'     => true,
			);
		}

		return array(
			'registration_uuid'    => $registration_uuid,
			'source_type'          => 'training_seed',
			'contact_name'         => $identity['name'],
			'email'                => $identity['email'],
			'phone'                => sprintf( '555-01%02d', $index % 100 ),
			'option_uuid'          => $option_uuid,
			'option_label'         => $label,
			'option_description'   => (string) ( $offering['description'] ?? '' ),
			'option_price'         => (string) ( $offering['price'] ?? '' ),
			'classification'       => $classification,
			'max_attendees'        => max( 1, (int) ( $offering['max_attendees'] ?? 1 ) ),
			'validity_type'        => 'one_day' === (string) ( $offering['validity_type'] ?? '' ) ? 'one_day' : 'full_event',
			'valid_local_date'     => (string) ( $offering['valid_local_date'] ?? '' ),
			'offering_fingerprint' => (string) ( $offering['offering_fingerprint'] ?? '' ),
			'included_events'      => self::included_events_snapshot( $offering['included_events'] ?? array() ),
			'attendees'            => $attendees,
			'manager_detail'       => array(
				'synthetic'     => true,
				'origin'        => 'Deterministic Training Mode seed',
				'training_only' => true,
			),
		);
	}

	/** @return array{name:string,email:string} */
	private static function seed_identity( string $classification, bool $student, int $index ): array {
		if ( $student ) {
			return array(
				'name'  => 'DEMO — Jamie Morgan',
				'email' => 'demo.student@example.invalid',
			);
		}
		if ( 'family' === $classification ) {
			return array(
				'name'  => 'DEMO — Taylor Family',
				'email' => 'demo.family@example.invalid',
			);
		}
		if ( 1 === $index ) {
			return array(
				'name'  => 'DEMO — Alex Carter',
				'email' => 'demo.alex@example.invalid',
			);
		}

		return array(
			'name'  => sprintf( 'DEMO — Sample Guest %d', $index ),
			'email' => sprintf( 'demo.guest%d@example.invalid', $index ),
		);
	}

	/** @param mixed $events @return array<int,array<string,mixed>> */
	private static function included_events_snapshot( mixed $events ): array {
		$snapshot = array();
		foreach ( is_array( $events ) ? $events : array() as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$snapshot[] = array(
				'event_id' => max( 0, (int) ( $event['event_id'] ?? $event['id'] ?? 0 ) ),
				'label'    => trim( (string) ( $event['label'] ?? $event['name'] ?? '' ) ),
			);
		}

		return $snapshot;
	}

	/** @param array<string,mixed> $filters @return array{q:string,status:string,option_uuid:string,offset:int,limit:int} */
	private static function normalize_filters( array $filters ): array {
		$status = strtolower( trim( (string) ( $filters['status'] ?? 'everyone' ) ) );
		if ( ! in_array( $status, array( 'everyone', 'checked_in', 'not_checked_in', 'walk_in', 'walk_ins' ), true ) ) {
			$status = 'everyone';
		}

		return array(
			'q'           => self::lower( self::clean_text( (string) ( $filters['q'] ?? '' ) ) ),
			'status'      => $status,
			'option_uuid' => strtolower( trim( (string) ( $filters['option_uuid'] ?? '' ) ) ),
			'offset'      => max( 0, min( 5000, (int) ( $filters['offset'] ?? 0 ) ) ),
			'limit'       => max( 1, min( 50, (int) ( $filters['limit'] ?? 50 ) ) ),
		);
	}

	/** @param array<string,mixed> $registration */
	private static function matches_status( array $registration, bool $checked_in, string $status ): bool {
		if ( 'checked_in' === $status ) {
			return $checked_in;
		}
		if ( 'not_checked_in' === $status ) {
			return ! $checked_in;
		}
		if ( in_array( $status, array( 'walk_in', 'walk_ins' ), true ) ) {
			return in_array( (string) ( $registration['source_type'] ?? '' ), array( 'training_walk_in', 'walk_in' ), true );
		}

		return true;
	}

	/** @param array<string,mixed> $registration */
	private static function matches_search( array $registration, string $query ): bool {
		$values = array(
			(string) ( $registration['contact_name'] ?? '' ),
			(string) ( $registration['email'] ?? '' ),
			(string) ( $registration['phone'] ?? '' ),
		);
		foreach ( is_array( $registration['attendees'] ?? null ) ? $registration['attendees'] : array() as $attendee ) {
			if ( is_array( $attendee ) ) {
				$values[] = (string) ( $attendee['name'] ?? '' );
			}
		}

		return false !== strpos( self::lower( implode( ' ', $values ) ), $query );
	}

	private static function source_bucket( string $source_type ): string {
		return match ( $source_type ) {
			'online'                                  => 'website',
			'online_included'                         => 'included',
			'training_walk_in', 'walk_in'             => 'walk_in',
			'complimentary', 'speaker'                 => 'complimentary',
			'rsvp_walk_in', 'rsvp_website', 'rsvp_waitlist' => 'rsvp',
			'manager_verified_manual'                 => 'manager_verified',
			default                                   => 'website',
		};
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $registration */
	private static function registration_checked_in( array $state, array $registration, string $date ): bool {
		$attendance = is_array( $state['attendance'][ $date ] ?? null ) ? $state['attendance'][ $date ] : array();
		foreach ( is_array( $registration['attendees'] ?? null ) ? $registration['attendees'] : array() as $attendee ) {
			if ( is_array( $attendee ) && isset( $attendance[ (string) ( $attendee['attendee_uuid'] ?? '' ) ] ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,mixed> $state @return array<string,array<string,mixed>> */
	private static function registrations( array $state ): array {
		$registrations = array();
		foreach ( is_array( $state['registrations'] ?? null ) ? $state['registrations'] : array() as $key => $registration ) {
			if ( ! is_array( $registration ) ) {
				continue;
			}
			$uuid = (string) ( $registration['registration_uuid'] ?? $key );
			if ( '' !== $uuid ) {
				$registrations[ $uuid ] = $registration;
			}
		}

		return $registrations;
	}

	/** @param array<string,mixed> $entry @return array<string,mixed> */
	private static function legacy_live_detail( array $entry ): array {
		$attendees = array();
		foreach ( is_array( $entry['attendees'] ?? null ) ? $entry['attendees'] : array() as $index => $attendee ) {
			if ( ! is_array( $attendee ) ) {
				continue;
			}
			$name        = (string) ( $attendee['display_name'] ?? $attendee['name'] ?? '' );
			$attendees[] = array_merge(
				$attendee,
				array(
					'attendee_uuid' => (string) ( $attendee['attendee_uuid'] ?? '' ),
					'slot_key'      => (string) ( $attendee['slot_key'] ?? ( (string) ( $entry['classification'] ?? '' ) . '-' . ( $index + 1 ) ) ),
					'display_name'  => $name,
					'first_name'    => (string) ( $attendee['first_name'] ?? '' ),
					'last_name'     => (string) ( $attendee['last_name'] ?? '' ),
				)
			);
		}

		return array(
			'registration' => array(
				'registration_uuid' => (string) ( $entry['registration_uuid'] ?? '' ),
				'option_uuid'       => (string) ( $entry['option_uuid'] ?? '' ),
				'source_type'       => (string) ( $entry['source_type'] ?? 'walk_in' ),
				'classification'    => (string) ( $entry['classification'] ?? 'individual' ),
				'status'            => 'active',
				'website_status'    => '',
				'contact_name'      => (string) ( $entry['contact_name'] ?? '' ),
				'contact_email'     => self::mask_email( (string) ( $entry['email'] ?? '' ) ),
				'contact_phone'     => self::mask_phone( (string) ( $entry['phone'] ?? '' ) ),
				'validity_type'     => (string) ( $entry['validity_type'] ?? 'full_event' ),
				'valid_local_date'  => (string) ( $entry['valid_local_date'] ?? '' ),
				'payment_assertion' => (string) ( $entry['payment_assertion'] ?? '' ),
				'registration_type' => (string) ( $entry['option_label'] ?? 'Event registration' ),
				'record_version'    => 1,
			),
			'attendees'    => $attendees,
			'local_date'   => '',
			'admission'    => array(
				'state'             => 'eligible',
				'status_label'      => 'REGISTRATION VALID',
				'message'           => '',
				'selection_allowed' => true,
				'check_in_allowed'  => true,
				'allowed'           => true,
				'maximum_attendees' => max( 1, (int) ( $entry['max_attendees'] ?? count( $attendees ) ) ),
			),
			'coverage'     => array(
				'complete'    => true,
				'limitations' => array(),
			),
		);
	}

	private static function deterministic_uuid( string $training_uuid, string $identity ): string {
		$hex      = substr( hash( 'sha256', 'oras-training|' . strtolower( trim( $training_uuid ) ) . '|' . $identity ), 0, 32 );
		$hex[12]  = '5';
		$variants = array( '8', '9', 'a', 'b' );
		$hex[16]  = $variants[ hexdec( $hex[16] ) % 4 ];

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	private static function lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	private static function mask_email( string $email ): string {
		$parts = explode( '@', $email, 2 );

		return 2 === count( $parts ) ? substr( $parts[0], 0, 1 ) . '***@' . $parts[1] : '';
	}

	private static function mask_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';

		return strlen( $digits ) >= 4 ? '***-***-' . substr( $digits, -4 ) : '';
	}

	private static function clean_text( string $value ): string {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return trim( wp_strip_all_tags( $value ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standalone host-check fallback when WordPress is unavailable.
		return trim( strip_tags( $value ) );
	}

	private static function json_encode( mixed $value, int $flags = 0 ): string|false {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $value, $flags );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone host-check fallback when WordPress is unavailable.
		return json_encode( $value, $flags );
	}
}
