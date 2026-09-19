<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Domain\Event_Offering_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only, event-scoped roster projection for the Registration Desk kiosk. */
final class Event_Roster_Service {
	/** @param array<string,mixed> $raw @return array<string,mixed> */
	public static function normalize_filters( array $raw ): array {
		$status = sanitize_key( (string) ( $raw['status'] ?? 'everyone' ) );
		if ( ! in_array( $status, array( 'everyone', 'not_checked_in', 'checked_in', 'walk_ins', 'admitted', 'waitlist' ), true ) ) {
			$status = 'everyone';
		}

		return array(
			'q'           => sanitize_text_field( (string) ( $raw['q'] ?? '' ) ),
			'status'      => $status,
			'option_uuid' => sanitize_text_field( (string) ( $raw['option_uuid'] ?? '' ) ),
			'offset'      => min( 5000, absint( $raw['offset'] ?? 0 ) ),
			'limit'       => min( 50, max( 10, absint( $raw['limit'] ?? 25 ) ) ),
		);
	}

	/** @param array<string,mixed> $registration */
	public static function historical_label( array $registration ): string {
		$evidence = json_decode( (string) ( $registration['source_evidence'] ?? '' ), true );
		$evidence = is_array( $evidence ) ? $evidence : array();
		$offering = is_array( $evidence['offering'] ?? null ) ? $evidence['offering'] : array();
		$label    = sanitize_text_field( (string) ( $offering['label'] ?? $evidence['item_label'] ?? '' ) );
		if ( '' !== $label ) {
			return $label;
		}

		return match ( (string) ( $registration['source_type'] ?? '' ) ) {
			'rsvp_walk_in', 'rsvp_website' => __( 'Event RSVP', 'oras-tickets' ),
			'rsvp_waitlist'                => __( 'RSVP Waitlist', 'oras-tickets' ),
			'complimentary'                => __( 'Complimentary', 'oras-tickets' ),
			'speaker'                      => __( 'Speaker', 'oras-tickets' ),
			default                        => __( 'Event registration', 'oras-tickets' ),
		};
	}

	/** @param array<string,mixed> $raw_filters @return array<string,mixed> */
	public function get( int $event_id, array $raw_filters ): array {
		$filters   = self::normalize_filters( $raw_filters );
		$rsvp_only = ! Event_Offering_Resolver::has_canonical_tickets( $event_id ) && ! empty( RSVP_Capacity::state( $event_id )['enabled'] );
		$fetch     = $rsvp_only ? min( 5001, (int) $filters['offset'] + (int) $filters['limit'] + 1 ) : (int) $filters['limit'] + 1;
		$offset    = $rsvp_only ? 0 : (int) $filters['offset'];
		$desk      = $this->desk_rows( $event_id, $filters, $fetch, $offset );
		$public    = $rsvp_only ? $this->public_rsvp_rows( $event_id, $filters, $fetch ) : array();
		$rows      = array_merge( $desk, $public );
		usort( $rows, array( self::class, 'compare_rows' ) );
		if ( $rsvp_only ) {
			$rows = array_slice( $rows, (int) $filters['offset'], (int) $filters['limit'] + 1 );
		}
		$has_more = count( $rows ) > (int) $filters['limit'];
		$rows     = array_slice( $rows, 0, (int) $filters['limit'] );

		return array(
			'items'       => $rows,
			'has_more'    => $has_more,
			'next_offset' => (int) $filters['offset'] + count( $rows ),
			'filters'     => $filters,
			'mode'        => $rsvp_only ? 'rsvp' : 'tickets',
		);
	}

	/** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
	private function desk_rows( int $event_id, array $filters, int $limit, int $offset ): array {
		global $wpdb;
		$tables = Schema::table_names();
		$where  = array( 'r.event_id = %d', "r.status = 'active'" );
		$args   = array( $event_id );
		$query  = strtolower( (string) $filters['q'] );
		if ( '' !== $query ) {
			$like       = '%' . $wpdb->esc_like( $query ) . '%';
			$phone      = preg_replace( '/\D+/', '', $query ) ?? '';
			$phone_like = '' !== $phone ? '%' . $wpdb->esc_like( $phone ) . '%' : '__no_phone_match__';
			$where[]    = "(r.search_name LIKE %s OR r.search_email LIKE %s OR r.search_phone LIKE %s OR EXISTS (SELECT 1 FROM {$tables['attendees']} sqt WHERE sqt.registration_id = r.id AND LOWER(sqt.display_name) LIKE %s))";
			array_push( $args, $like, $like, $phone_like, $like );
		}
		if ( '' !== (string) $filters['option_uuid'] ) {
			$where[] = 'r.option_uuid = %s';
			$args[]  = (string) $filters['option_uuid'];
		}
		$today        = wp_date( 'Y-m-d', null, wp_timezone() );
		$attendance   = "EXISTS (SELECT 1 FROM {$tables['attendees']} sat INNER JOIN {$tables['attendance']} saa ON saa.attendee_id = sat.id WHERE sat.registration_id = r.id AND saa.event_id = r.event_id AND saa.attendance_local_date = %s AND saa.state = 'checked_in')";
		$status_filter = (string) $filters['status'];
		if ( 'checked_in' === $status_filter ) {
			$where[] = $attendance;
			$args[]  = $today;
		} elseif ( 'not_checked_in' === $status_filter ) {
			$where[] = 'NOT ' . $attendance;
			$args[]  = $today;
		} elseif ( 'walk_ins' === $status_filter ) {
			$where[] = "r.source_type IN ('walk_in','rsvp_walk_in')";
		} elseif ( 'admitted' === $status_filter ) {
			$where[] = "r.source_type IN ('rsvp_walk_in','rsvp_website')";
		} elseif ( 'waitlist' === $status_filter ) {
			$where[] = "r.source_type = 'rsvp_waitlist'";
		}

		$where_sql = implode( ' AND ', $where );
		$args       = array_merge( array( $today ), $args, array( max( 1, min( 5001, $limit ) ), max( 0, $offset ) ) );
		$sql       = "SELECT r.*, (SELECT GROUP_CONCAT(NULLIF(rt.display_name,'') ORDER BY rt.last_name,rt.first_name SEPARATOR '||') FROM {$tables['attendees']} rt WHERE rt.registration_id = r.id AND rt.status = 'active') AS attendee_names, CASE WHEN EXISTS (SELECT 1 FROM {$tables['attendees']} ct INNER JOIN {$tables['attendance']} ca ON ca.attendee_id = ct.id WHERE ct.registration_id = r.id AND ca.event_id = r.event_id AND ca.attendance_local_date = %s AND ca.state = 'checked_in') THEN 1 ELSE 0 END AS checked_in_today FROM {$tables['registrations']} r WHERE {$where_sql} ORDER BY SUBSTRING_INDEX(r.search_name,' ',-1) ASC, SUBSTRING_INDEX(r.search_name,' ',1) ASC,r.id ASC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and prepared placeholders.

		return array_map( array( self::class, 'volunteer_row' ), is_array( $rows ) ? $rows : array() );
	}

	/** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
	private function public_rsvp_rows( int $event_id, array $filters, int $limit ): array {
		global $wpdb;
		$status_filter = (string) $filters['status'];
		if ( in_array( $status_filter, array( 'checked_in', 'walk_ins' ), true ) || '' !== (string) $filters['option_uuid'] ) {
			return array();
		}
		$statuses = 'waitlist' === $status_filter ? array( 'waitlist' ) : ( 'admitted' === $status_filter ? array( 'yes' ) : array( 'yes', 'waitlist' ) );
		$meta_key = '_oras_rsvp_event_' . $event_id;
		$contact_key = $meta_key . '_contact';
		$tables   = Schema::table_names();
		$marks    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args     = array_merge( array( $meta_key ), $statuses, array( $contact_key, $event_id ) );
		$where    = '';
		$query    = strtolower( (string) $filters['q'] );
		if ( '' !== $query ) {
			$like   = '%' . $wpdb->esc_like( $query ) . '%';
			$where  = ' AND (LOWER(u.display_name) LIKE %s OR LOWER(u.user_email) LIKE %s OR LOWER(contact.meta_value) LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		$args[] = max( 1, min( 5001, $limit ) );
		$sql    = "SELECT u.ID,u.display_name,u.user_email,status.meta_value AS rsvp_status,contact.meta_value AS rsvp_contact FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} status ON status.user_id = u.ID AND status.meta_key = %s AND status.meta_value IN ({$marks}) LEFT JOIN {$wpdb->usermeta} contact ON contact.user_id = u.ID AND contact.meta_key = %s WHERE NOT EXISTS (SELECT 1 FROM {$tables['registrations']} rr WHERE rr.event_id = %d AND rr.source_key = CONCAT('rsvp-user:',u.ID)){$where} ORDER BY u.display_name ASC,u.ID ASC LIMIT %d";
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from fixed clauses and prepared placeholders.

		return array_map(
			static function ( array $row ): array {
				$contact = maybe_unserialize( $row['rsvp_contact'] ?? array() );
				$contact = is_array( $contact ) ? $contact : array();
				$name    = trim( sanitize_text_field( (string) ( $contact['first_name'] ?? '' ) ) . ' ' . sanitize_text_field( (string) ( $contact['last_name'] ?? '' ) ) );
				$status  = 'waitlist' === (string) $row['rsvp_status'] ? 'waitlist' : 'admitted';

				return array(
					'row_id'              => 'rsvp-user-' . absint( $row['ID'] ),
					'detail_kind'         => 'public_rsvp',
					'rsvp_user_id'        => absint( $row['ID'] ),
					'registration_uuid'   => '',
					'name'                => '' !== $name ? $name : sanitize_text_field( (string) $row['display_name'] ),
					'phone'               => self::mask_phone( (string) ( $contact['phone'] ?? '' ) ),
					'registration_type'   => 'waitlist' === $status ? __( 'RSVP Waitlist', 'oras-tickets' ) : __( 'Event RSVP', 'oras-tickets' ),
					'source_type'         => 'rsvp_website',
					'rsvp_status'         => $status,
					'checked_in_today'    => false,
					'attendees'           => array(),
					'classification'      => 'individual',
					'validity_type'       => 'full_event',
					'valid_local_date'    => '',
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function volunteer_row( array $row ): array {
		$names = array_values( array_filter( explode( '||', (string) ( $row['attendee_names'] ?? '' ) ) ) );

		return array(
			'row_id'            => (string) $row['registration_uuid'],
			'detail_kind'       => 'registration',
			'rsvp_user_id'      => 0,
			'registration_uuid' => (string) $row['registration_uuid'],
			'name'              => (string) $row['source_contact_name'],
			'phone'             => self::mask_phone( (string) $row['source_phone'] ),
			'registration_type' => self::historical_label( $row ),
			'source_type'       => (string) $row['source_type'],
			'rsvp_status'       => 'rsvp_waitlist' === (string) $row['source_type'] ? 'waitlist' : ( str_starts_with( (string) $row['source_type'], 'rsvp_' ) ? 'admitted' : '' ),
			'checked_in_today'  => 1 === (int) $row['checked_in_today'],
			'attendees'         => $names,
			'classification'    => (string) $row['classification'],
			'validity_type'     => (string) $row['validity_type'],
			'valid_local_date'  => (string) $row['valid_local_date'],
		);
	}

	private static function mask_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';

		return strlen( $digits ) >= 4 ? 'Phone ending ' . substr( $digits, -4 ) : '';
	}

	/** @param array<string,mixed> $left @param array<string,mixed> $right */
	private static function compare_rows( array $left, array $right ): int {
		$parts = static function ( array $row ): array {
			$name  = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
			$words = preg_split( '/\s+/', $name ) ?: array( '' );

			return array( (string) end( $words ), (string) reset( $words ), $name );
		};

		return $parts( $left ) <=> $parts( $right );
	}
}
