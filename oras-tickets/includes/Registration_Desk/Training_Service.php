<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Domain\Event_Offering_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Synthetic-only Registration Desk behavior for one isolated training row. */
final class Training_Service {
	private const MAX_STATE_BYTES = 524288;

	/**
	 * Read the selected event's canonical ticket configuration without reading registrations.
	 *
	 * @param array<string,mixed> $config
	 * @return array<int,array<string,mixed>>
	 */
	public static function canonical_offerings( int $event_id, array $config ): array {
		return Event_Offering_Resolver::desk_offerings( $event_id, $config );
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
			'schema_version' => 1,
			'seed_version'   => 1,
			'registrations'  => $registrations,
			'attendance'     => array(),
			'requests'       => array(),
			'memberships'    => array(),
			'history'        => array(),
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

	/** @param array<string,mixed> $state */
	public static function state_within_limit( array $state ): bool {
		$encoded = json_encode( $state );

		return is_string( $encoded ) && strlen( $encoded ) <= self::MAX_STATE_BYTES;
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
			'registration_uuid'  => $registration_uuid,
			'source_type'        => 'training_seed',
			'contact_name'       => $identity['name'],
			'email'              => $identity['email'],
			'phone'              => sprintf( '555-01%02d', $index % 100 ),
			'option_uuid'        => $option_uuid,
			'option_label'       => $label,
			'option_description' => (string) ( $offering['description'] ?? '' ),
			'option_price'       => (string) ( $offering['price'] ?? '' ),
			'classification'     => $classification,
			'max_attendees'      => max( 1, (int) ( $offering['max_attendees'] ?? 1 ) ),
			'validity_type'      => 'one_day' === (string) ( $offering['validity_type'] ?? '' ) ? 'one_day' : 'full_event',
			'valid_local_date'   => (string) ( $offering['valid_local_date'] ?? '' ),
			'offering_fingerprint' => (string) ( $offering['offering_fingerprint'] ?? '' ),
			'included_events'    => self::included_events_snapshot( $offering['included_events'] ?? array() ),
			'attendees'          => $attendees,
			'manager_detail'     => array(
				'synthetic'     => true,
				'origin'        => 'Deterministic Training Mode seed',
				'training_only' => true,
			),
		);
	}

	/** @return array{name:string,email:string} */
	private static function seed_identity( string $classification, bool $student, int $index ): array {
		if ( $student ) {
			return array( 'name' => 'DEMO — Jamie Morgan', 'email' => 'demo.student@example.invalid' );
		}
		if ( 'family' === $classification ) {
			return array( 'name' => 'DEMO — Taylor Family', 'email' => 'demo.family@example.invalid' );
		}
		if ( 1 === $index ) {
			return array( 'name' => 'DEMO — Alex Carter', 'email' => 'demo.alex@example.invalid' );
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
		if ( ! in_array( $status, array( 'everyone', 'checked_in', 'not_checked_in', 'walk_in' ), true ) ) {
			$status = 'everyone';
		}

		return array(
			'q'           => self::lower( trim( strip_tags( (string) ( $filters['q'] ?? '' ) ) ) ),
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
		if ( 'walk_in' === $status ) {
			return 'training_walk_in' === (string) ( $registration['source_type'] ?? '' );
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
}
