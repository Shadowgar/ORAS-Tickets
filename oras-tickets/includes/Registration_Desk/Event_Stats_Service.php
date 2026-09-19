<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical registration and attendance definitions shared by kiosk and reports. */
final class Event_Stats_Service {
	/** @return array<string,mixed> */
	public function for_event( int $event_id, ?string $today = null ): array {
		global $wpdb;
		$tables = Schema::table_names();
		$config = Config::get_event_config( $event_id );
		$labels = array();
		foreach ( $config['options'] as $option ) {
			$labels[ (string) $option['option_uuid'] ] = (string) $option['label'];
		}
		$registrations = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['registrations']} WHERE event_id = %d AND status = 'active' ORDER BY id", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		foreach ( is_array( $registrations ) ? $registrations : array() as &$registration ) {
			$evidence = json_decode( (string) ( $registration['source_evidence'] ?? '' ), true );
			$snapshot = is_array( $evidence['offering'] ?? null ) ? sanitize_text_field( (string) ( $evidence['offering']['label'] ?? '' ) ) : '';
			if ( '' === $snapshot && 'online' === (string) $registration['source_type'] && is_array( $evidence ) ) {
				$snapshot = sanitize_text_field( (string) ( $evidence['item_label'] ?? '' ) );
			}
			if ( '' === $snapshot && in_array( (string) $registration['source_type'], array( 'rsvp_walk_in', 'rsvp_waitlist' ), true ) ) {
				$snapshot = 'RSVP';
			}
			$registration['option_label'] = '' !== $snapshot ? $snapshot : ( $labels[ (string) $registration['option_uuid'] ] ?? __( 'Other', 'oras-tickets' ) );
			$registration['created_local_date'] = get_date_from_gmt( (string) $registration['created_at_utc'], 'Y-m-d' );
		}
		unset( $registration );
		$attendees = $wpdb->get_results( $wpdb->prepare( "SELECT a.id,a.registration_id FROM {$tables['attendees']} a INNER JOIN {$tables['registrations']} r ON r.id=a.registration_id WHERE r.event_id=%d AND r.status='active' AND a.status='active' ORDER BY a.id", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table names.
		$attendance = $wpdb->get_results( $wpdb->prepare( "SELECT x.attendee_id,x.attendance_local_date FROM {$tables['attendance']} x WHERE x.event_id=%d AND x.state='checked_in' ORDER BY x.attendance_local_date,x.id", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		$memberships = ( new Offline_Membership_Store() )->for_event( $event_id );
		$local_today = $today ?? wp_date( 'Y-m-d', null, wp_timezone() );

		return self::summarize_rows(
			is_array( $registrations ) ? $registrations : array(),
			is_array( $attendees ) ? $attendees : array(),
			is_array( $attendance ) ? $attendance : array(),
			$memberships,
			$local_today,
			Store::utc_now()
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $registrations
	 * @param array<int,array<string,mixed>> $attendees
	 * @param array<int,array<string,mixed>> $attendance
	 * @param array<int,array<string,mixed>> $memberships
	 * @return array<string,mixed>
	 */
	public static function summarize_rows( array $registrations, array $attendees, array $attendance, array $memberships, string $today, string $now_utc ): array {
		$registrations_by_id = array();
		$source_counts = array(
			'website'          => 0,
			'walk_in'          => 0,
			'complimentary'    => 0,
			'rsvp'             => 0,
			'manager_verified' => 0,
		);
		$classification = array();
		$validity = array();
		$pass_types = array();
		$payment = array(
			'card'   => 0,
			'cash'   => 0,
			'check'  => 0,
			'unpaid' => 0,
		);
		$new_walk_ins = 0;
		foreach ( $registrations as $registration ) {
			$id = (int) $registration['id'];
			$registrations_by_id[ $id ] = $registration;
			$source = self::source_key( (string) ( $registration['source_type'] ?? '' ) );
			++$source_counts[ $source ];
			self::increment( $classification, (string) ( $registration['classification'] ?? 'unclassified' ) );
			self::increment( $validity, (string) ( $registration['validity_type'] ?? 'unclassified' ) );
			self::increment( $pass_types, (string) ( $registration['option_label'] ?? 'Other' ) );
			$assertion = (string) ( $registration['payment_assertion'] ?? '' );
			if ( 'walk_in' === (string) ( $registration['source_type'] ?? '' ) && isset( $payment[ $assertion ] ) ) {
				++$payment[ $assertion ];
			}
			if ( in_array( (string) ( $registration['source_type'] ?? '' ), array( 'walk_in', 'rsvp_walk_in' ), true ) && $today === (string) ( $registration['created_local_date'] ?? '' ) ) {
				++$new_walk_ins;
			}
		}

		$attendees_by_id = array();
		foreach ( $attendees as $attendee ) {
			$attendees_by_id[ (int) $attendee['id'] ] = (int) $attendee['registration_id'];
		}
		$today_people = array();
		$today_source = array(
			'website'          => array(),
			'walk_in'          => array(),
			'complimentary'    => array(),
			'rsvp'             => array(),
			'manager_verified' => array(),
		);
		$attended_people = array();
		$attended_registrations = array();
		$attendance_by_day = array();
		$family_people = array();
		foreach ( $attendance as $instance ) {
			$attendee_id = (int) $instance['attendee_id'];
			$registration_id = $attendees_by_id[ $attendee_id ] ?? 0;
			if ( ! isset( $registrations_by_id[ $registration_id ] ) ) {
				continue;
			}
			$date = (string) $instance['attendance_local_date'];
			$attended_people[ $attendee_id ] = true;
			$attended_registrations[ $registration_id ] = true;
			self::increment( $attendance_by_day, $date );
			$registration = $registrations_by_id[ $registration_id ];
			if ( 'family' === (string) ( $registration['classification'] ?? '' ) ) {
				$family_people[ $attendee_id ] = true;
			}
			if ( $date === $today ) {
				$today_people[ $attendee_id ] = true;
				$today_source[ self::source_key( (string) ( $registration['source_type'] ?? '' ) ) ][ $attendee_id ] = true;
			}
		}
		ksort( $attendance_by_day );

		$today_pass_types = array();
		foreach ( $today_people as $attendee_id => $_present ) {
			$registration_id = $attendees_by_id[ $attendee_id ] ?? 0;
			self::increment( $today_pass_types, (string) ( $registrations_by_id[ $registration_id ]['option_label'] ?? 'Other' ) );
		}

		$membership_summary = array(
			'total'     => count( $memberships ),
			'cash'      => 0,
			'check'     => 0,
			'pending'   => 0,
			'redeemed'  => 0,
			'expired'   => 0,
			'cancelled' => 0,
			'levels'    => array(),
		);
		foreach ( $memberships as $membership ) {
			$method = (string) ( $membership['payment_method'] ?? '' );
			if ( isset( $membership_summary[ $method ] ) ) {
				++$membership_summary[ $method ];
			}
			$status = (string) ( $membership['status'] ?? 'pending' );
			if ( 'pending' === $status && (string) ( $membership['expires_at_utc'] ?? '' ) < $now_utc ) {
				$status = 'expired';
			}
			if ( isset( $membership_summary[ $status ] ) ) {
				++$membership_summary[ $status ];
			}
			self::increment( $membership_summary['levels'], (string) ( $membership['level_name'] ?? 'Other' ) );
		}

		return array(
			'today'       => array(
				'actual_people'             => count( $today_people ),
				'website_people'            => count( $today_source['website'] ),
				'walk_in_people'            => count( $today_source['walk_in'] ),
				'complimentary_people'      => count( $today_source['complimentary'] ),
				'rsvp_people'               => count( $today_source['rsvp'] ),
				'manager_verified_people'   => count( $today_source['manager_verified'] ),
				'new_walk_in_registrations' => $new_walk_ins,
				'pass_types'                => $today_pass_types,
			),
			'event_total' => array(
				'active_registrations'           => count( $registrations ),
				'website_registrations'          => $source_counts['website'],
				'walk_in_registrations'          => $source_counts['walk_in'],
				'complimentary_registrations'    => $source_counts['complimentary'],
				'rsvp_registrations'             => $source_counts['rsvp'],
				'manager_verified_registrations' => $source_counts['manager_verified'],
				'people_registered'              => count( $attendees ),
				'unique_attendees'               => count( $attended_people ),
				'attendance_instances'           => count( $attendance ),
				'attendance_by_day'              => $attendance_by_day,
				'pass_types'                     => $pass_types,
				'classifications'                => $classification,
				'validity'                       => $validity,
				'family_registrations'           => (int) ( $classification['family'] ?? 0 ),
				'family_attendees_attended'      => count( $family_people ),
				'no_show_registrations'          => count( $registrations ) - count( $attended_registrations ),
				'payment_assertions'             => $payment,
			),
			'memberships' => $membership_summary,
		);
	}

	private static function source_key( string $source ): string {
		if ( 'online' === $source ) {
			return 'website';
		}
		if ( 'speaker' === $source ) {
			return 'complimentary';
		}
		if ( str_starts_with( $source, 'rsvp_' ) ) {
			return 'rsvp';
		}
		if ( 'manager_verified_manual' === $source ) {
			return 'manager_verified';
		}

		return in_array( $source, array( 'walk_in', 'complimentary' ), true ) ? $source : 'website';
	}

	/** @param array<string,int> $counts */
	private static function increment( array &$counts, string $key ): void {
		$counts[ $key ] = (int) ( $counts[ $key ] ?? 0 ) + 1;
	}
}
