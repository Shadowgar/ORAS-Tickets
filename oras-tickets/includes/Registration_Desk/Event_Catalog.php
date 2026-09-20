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
			$row      = self::eligible_row( $event_id, $year );
			if ( null !== $row && self::is_available_on( $row, $today ) ) {
				$rows[] = $row;
			}
		}

		return self::sort_rows( $rows, $today );
	}

	/** @return array<string,mixed>|null */
	public static function find( int $event_id ): ?array {
		$today = wp_date( 'Y-m-d', null, wp_timezone() );
		$row   = self::eligible_row( $event_id, (int) substr( $today, 0, 4 ) );

		return null !== $row && self::is_available_on( $row, $today ) ? $row : null;
	}

	/** Return a qualifying event even after it ends, for reporting and stale-station diagnosis. */
	public static function find_any( int $event_id ): ?array {
		return self::eligible_row( $event_id, null );
	}

	public static function display_title( string $title ): string {
		return sanitize_text_field( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/** @return array<string,mixed>|null */
	private static function eligible_row( int $event_id, ?int $year ): ?array {
		$post = get_post( $event_id );
		if ( ! $post instanceof \WP_Post || 'tribe_events' !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		$start = substr( (string) get_post_meta( $event_id, '_EventStartDate', true ), 0, 10 );
		$end   = substr( (string) get_post_meta( $event_id, '_EventEndDate', true ), 0, 10 );
		if ( '' === $end ) {
			$end = $start;
		}
		if ( null !== $year && ! self::overlaps_year( $start, $end, $year ) ) {
			return null;
		}
		$tickets = ! Ticket_Collection::load_for_event( $event_id )->is_empty();
		$rsvp     = get_post_meta( $event_id, '_oras_rsvp_v1', true );
		$rsvp     = is_array( $rsvp ) && ! empty( $rsvp['enabled'] );
		if ( ! $tickets && ! $rsvp ) {
			return null;
		}

		return array(
			'event_id'      => $event_id,
			'title'         => self::display_title( get_the_title( $event_id ) ),
			'start_date'    => $start,
			'end_date'      => $end,
			'friendly_date' => self::friendly_date( $start, $end ),
			'has_tickets'   => $tickets,
			'has_rsvp'      => $rsvp,
		);
	}

	public static function overlaps_year( string $start_date, string $end_date, int $year ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			return false;
		}

		return $end_date >= sprintf( '%04d-01-01', $year ) && $start_date <= sprintf( '%04d-12-31', $year );
	}

	/** @param array<string,mixed> $row */
	public static function is_available_on( array $row, string $local_date ): bool {
		$end_date = (string) ( $row['end_date'] ?? '' );

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $local_date )
			&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date )
			&& $end_date >= $local_date;
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
