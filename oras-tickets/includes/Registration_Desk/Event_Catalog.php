<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Domain\Ticket_Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Selectable events for a volunteer station. */
final class Event_Catalog {
	/** @return array<int,array<string,mixed>> */
	public static function current_year(): array {
		$today = wp_date( 'Y-m-d', null, wp_timezone() );
		$year  = (int) substr( $today, 0, 4 );
		$posts = get_posts(
			array(
				'post_type'      => 'tribe_events',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'meta_value',
				'meta_key'       => '_EventStartDate',
				'order'          => 'ASC',
			)
		);
		$rows = array();
		foreach ( $posts as $post ) {
			$event_id = absint( $post instanceof \WP_Post ? $post->ID : $post );
			if ( $event_id <= 0 ) {
				continue;
			}
			$start = substr( (string) get_post_meta( $event_id, '_EventStartDate', true ), 0, 10 );
			$end   = substr( (string) get_post_meta( $event_id, '_EventEndDate', true ), 0, 10 );
			if ( '' === $end ) {
				$end = $start;
			}
			if ( ! self::overlaps_year( $start, $end, $year ) ) {
				continue;
			}
			$tickets = ! Ticket_Collection::load_for_event( $event_id )->is_empty();
			$rsvp     = get_post_meta( $event_id, '_oras_rsvp_v1', true );
			$rsvp     = is_array( $rsvp ) && ! empty( $rsvp['enabled'] );
			if ( ! $tickets && ! $rsvp ) {
				continue;
			}
			$rows[] = array(
				'event_id'      => $event_id,
				'title'         => get_the_title( $event_id ),
				'start_date'    => $start,
				'end_date'      => $end,
				'friendly_date' => self::friendly_date( $start, $end ),
				'has_tickets'   => $tickets,
				'has_rsvp'      => $rsvp,
			);
		}

		return self::sort_rows( $rows, $today );
	}

	/** @return array<string,mixed>|null */
	public static function find( int $event_id ): ?array {
		foreach ( self::current_year() as $event ) {
			if ( $event_id === (int) $event['event_id'] ) {
				return $event;
			}
		}

		return null;
	}

	public static function overlaps_year( string $start_date, string $end_date, int $year ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			return false;
		}

		return $end_date >= sprintf( '%04d-01-01', $year ) && $start_date <= sprintf( '%04d-12-31', $year );
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	public static function sort_rows( array $rows, string $today ): array {
		usort(
			$rows,
			static function ( array $left, array $right ) use ( $today ): int {
				$left_group  = self::group( $left, $today );
				$right_group = self::group( $right, $today );
				if ( $left_group !== $right_group ) {
					return $left_group <=> $right_group;
				}
				$date_order = 2 === $left_group
					? strcmp( (string) $right['start_date'], (string) $left['start_date'] )
					: strcmp( (string) $left['start_date'], (string) $right['start_date'] );

				return 0 !== $date_order ? $date_order : strcasecmp( (string) $left['title'], (string) $right['title'] );
			}
		);

		return array_values( $rows );
	}

	/** @param array<string,mixed> $row */
	private static function group( array $row, string $today ): int {
		if ( (string) $row['start_date'] <= $today && (string) $row['end_date'] >= $today ) {
			return 0;
		}

		return (string) $row['start_date'] > $today ? 1 : 2;
	}

	private static function friendly_date( string $start, string $end ): string {
		$timezone = wp_timezone();
		$first    = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start, $timezone );
		$last     = \DateTimeImmutable::createFromFormat( '!Y-m-d', $end, $timezone );
		if ( ! $first || ! $last ) {
			return '';
		}
		if ( $start === $end ) {
			return wp_date( 'l, F j, Y', $first->getTimestamp(), $timezone );
		}

		return wp_date( 'M j', $first->getTimestamp(), $timezone ) . ' – ' . wp_date( 'M j, Y', $last->getTimestamp(), $timezone );
	}
}
