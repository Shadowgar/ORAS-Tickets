<?php

namespace ORAS\Tickets\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical direct included-event identities and immutable order-item snapshots. */
final class Included_Event_Access {
	public const ORDER_ITEM_META_KEY = '_oras_ticket_event_access_v1';
	public const SCHEMA = 1;

	/** @return array<int,int> */
	public static function normalize_ids( mixed $value, int $primary_event_id = 0 ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array();
		foreach ( $value as $candidate ) {
			$id = absint( $candidate );
			if ( $id <= 0 || $id === $primary_event_id || isset( $ids[ $id ] ) ) {
				continue;
			}
			$ids[ $id ] = $id;
		}

		return array_values( $ids );
	}

	/** @return array{event_id:int,title:string,date:string} */
	public static function describe_event( int $event_id ): array {
		$event_id = absint( $event_id );
		$title    = $event_id > 0 && function_exists( 'get_the_title' ) ? sanitize_text_field( (string) get_the_title( $event_id ) ) : '';
		$date     = '';
		if ( $event_id > 0 && function_exists( 'tribe_get_start_date' ) ) {
			$format = function_exists( 'get_option' ) ? (string) get_option( 'date_format', 'F j, Y' ) : 'F j, Y';
			$start  = sanitize_text_field( (string) tribe_get_start_date( $event_id, false, $format ) );
			$end    = function_exists( 'tribe_get_end_date' ) ? sanitize_text_field( (string) tribe_get_end_date( $event_id, false, $format ) ) : '';
			$date   = '' !== $end && $end !== $start ? $start . ' – ' . $end : $start;
		}

		return array(
			'event_id' => $event_id,
			'title'    => $title,
			'date'     => $date,
		);
	}

	/** @param array<int,int> $event_ids @return array<int,array{event_id:int,title:string,date:string}> */
	public static function describe_events( array $event_ids, int $primary_event_id = 0 ): array {
		return array_map( array( self::class, 'describe_event' ), self::normalize_ids( $event_ids, $primary_event_id ) );
	}

	/** @param array<string,mixed> $ticket @return array<string,mixed> */
	public static function snapshot( int $primary_event_id, array $ticket ): array {
		return array(
			'schema'          => self::SCHEMA,
			'primary_event'   => self::describe_event( $primary_event_id ),
			'ticket'          => array(
				'ticket_key'      => sanitize_text_field( (string) ( $ticket['ticket_key'] ?? '' ) ),
				'name'            => sanitize_text_field( (string) ( $ticket['name'] ?? '' ) ),
				'attendance_mode' => Ticket::normalizeAttendanceMode( (string) ( $ticket['attendance_mode'] ?? '' ), Ticket::ATTENDANCE_MODE_VIRTUAL ),
			),
			'included_events' => self::describe_events( self::normalize_ids( $ticket['included_event_ids'] ?? array(), $primary_event_id ) ),
		);
	}

	/** @return array<string,mixed> */
	public static function normalize_snapshot( mixed $value ): array {
		if ( ! is_array( $value ) || self::SCHEMA !== (int) ( $value['schema'] ?? 0 ) ) {
			return array();
		}
		$primary = self::normalize_event_snapshot( $value['primary_event'] ?? array() );
		$ticket  = is_array( $value['ticket'] ?? null ) ? $value['ticket'] : array();
		$events  = array();
		$seen    = array();
		foreach ( is_array( $value['included_events'] ?? null ) ? $value['included_events'] : array() as $event ) {
			$normalized = self::normalize_event_snapshot( $event );
			$id         = (int) $normalized['event_id'];
			if ( $id <= 0 || $id === (int) $primary['event_id'] || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$events[]    = $normalized;
		}

		return array(
			'schema'          => self::SCHEMA,
			'primary_event'   => $primary,
			'ticket'          => array(
				'ticket_key'      => sanitize_text_field( (string) ( $ticket['ticket_key'] ?? '' ) ),
				'name'            => sanitize_text_field( (string) ( $ticket['name'] ?? '' ) ),
				'attendance_mode' => Ticket::normalizeAttendanceMode( (string) ( $ticket['attendance_mode'] ?? '' ), Ticket::ATTENDANCE_MODE_VIRTUAL ),
			),
			'included_events' => $events,
		);
	}

	/** @param array<string,mixed> $snapshot */
	public static function includes( array $snapshot, int $target_event_id ): bool {
		foreach ( self::normalize_snapshot( $snapshot )['included_events'] ?? array() as $event ) {
			if ( $target_event_id > 0 && $target_event_id === (int) $event['event_id'] ) {
				return true;
			}
		}

		return false;
	}

	/** @return array{event_id:int,title:string,date:string} */
	private static function normalize_event_snapshot( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'event_id' => absint( $value['event_id'] ?? 0 ),
			'title'    => sanitize_text_field( (string) ( $value['title'] ?? '' ) ),
			'date'     => sanitize_text_field( (string) ( $value['date'] ?? '' ) ),
		);
	}
}
