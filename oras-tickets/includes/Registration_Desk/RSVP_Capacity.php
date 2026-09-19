<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared RSVP capacity decision used by public and accountless desk flows. */
final class RSVP_Capacity {
	public static function decision( int $capacity, int $public_count, int $desk_count, bool $waitlist_enabled ): string {
		if ( $capacity <= 0 || max( 0, $public_count ) + max( 0, $desk_count ) < $capacity ) {
			return 'admit';
		}

		return $waitlist_enabled ? 'waitlist' : 'refuse';
	}

	public static function desk_admitted_count( int $event_id ): int {
		if ( $event_id <= 0 || ! class_exists( Registration_Store::class ) ) {
			return 0;
		}
		global $wpdb;
		$tables = Schema::table_names();
		$count  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['registrations']} WHERE event_id = %d AND source_type = 'rsvp_walk_in' AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned table name.
				$event_id
			)
		);

		return max( 0, (int) $count );
	}

	public static function effective_count( int $event_id, ?int $public_count = null ): int {
		if ( null === $public_count ) {
			$public_count = class_exists( \ORAS\Tickets\Frontend\Event_RSVP::class ) ? \ORAS\Tickets\Frontend\Event_RSVP::yes_count( $event_id ) : 0;
		}

		return max( 0, $public_count ) + self::desk_admitted_count( $event_id );
	}

	/** @return array<string,mixed> */
	public static function state( int $event_id, ?int $now = null ): array {
		$meta = get_post_meta( $event_id, '_oras_rsvp_v1', true );
		$meta = is_array( $meta ) ? $meta : array();
		$now  = null === $now ? time() : $now;
		$window_state = 'open';
		$open = self::local_timestamp( (string) ( $meta['open_at'] ?? '' ) );
		$close = self::local_timestamp( (string) ( $meta['close_at'] ?? '' ) );
		if ( null !== $open && $now < $open ) {
			$window_state = 'upcoming';
		} elseif ( null !== $close && $now > $close ) {
			$window_state = 'closed';
		}
		$capacity    = absint( $meta['capacity'] ?? 0 );
		$public      = class_exists( \ORAS\Tickets\Frontend\Event_RSVP::class ) ? \ORAS\Tickets\Frontend\Event_RSVP::yes_count( $event_id ) : 0;
		$desk        = self::desk_admitted_count( $event_id );
		$waitlist    = ! empty( $meta['waitlist_enabled'] );
		$decision    = 'open' === $window_state ? self::decision( $capacity, $public, $desk, $waitlist ) : 'refuse';

		return array(
			'enabled'          => ! empty( $meta['enabled'] ),
			'window_state'     => $window_state,
			'capacity'         => $capacity,
			'public_count'     => $public,
			'desk_count'       => $desk,
			'effective_count'  => $public + $desk,
			'waitlist_enabled' => $waitlist,
			'decision'         => $decision,
		);
	}

	private static function local_timestamp( string $value ): ?int {
		if ( '' === trim( $value ) ) {
			return null;
		}
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$date     = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $value, $timezone );

		return $date instanceof \DateTimeImmutable ? $date->getTimestamp() : null;
	}
}
