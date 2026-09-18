<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is fixed by Schema and WordPress 6.0 lacks identifier placeholders.

final class Attendance_Store extends Store {
	public function __construct() {
		parent::__construct( 'attendance' );
	}

	/** @return array<string,mixed>|null */
	public function find_daily( int $event_id, int $attendee_id, string $local_date ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE event_id = %d AND attendee_id = %d AND attendance_local_date = %s",
				$event_id,
				$attendee_id,
				$local_date
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function find_by_id( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	private function find_current_by_id( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d FOR UPDATE", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function check_in( int $event_id, int $attendee_id, string $local_date, int $actor_user_id, string $station_uuid, string $operator_label ) {
		global $wpdb;
		$now = self::utc_now();
		// Atomically converge simultaneous first check-ins on the unique daily
		// row. The duplicate branch leaves original attribution untouched.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$sql = $wpdb->prepare(
			"INSERT INTO {$this->table} (event_id,attendee_id,attendance_local_date,state,record_version,checked_in_at_utc,checked_in_by,checked_in_station_uuid,checked_in_operator_label,created_at_utc,updated_at_utc) VALUES (%d,%d,%s,%s,%d,%s,%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
			$event_id,
			$attendee_id,
			$local_date,
			'checked_in',
			1,
			$now,
			$actor_user_id,
			$station_uuid,
			$operator_label,
			$now,
			$now
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query was prepared immediately above.
		$affected = $wpdb->query( $sql );
		if ( false === $affected ) {
			return new \WP_Error( 'oras_desk_attendance_create_failed', 'Attendance could not be recorded.', array( 'status' => 500 ) );
		}
		$attendance = $this->find_current_by_id( (int) $wpdb->insert_id );
		if ( ! $attendance ) {
			return new \WP_Error( 'oras_desk_attendance_create_failed', 'Attendance could not be recorded.', array( 'status' => 500 ) );
		}
		$attendance['_was_created'] = 1 === (int) $affected;

		return $attendance;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function reverse( int $attendance_id, int $expected_version, int $actor_user_id, string $reason ) {
		global $wpdb;
		$now = self::utc_now();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$sql = $wpdb->prepare(
			"UPDATE {$this->table} SET state = 'reversed', record_version = record_version + 1, reversed_at_utc = %s, reversed_by = %d, reversal_reason = %s, updated_at_utc = %s WHERE id = %d AND record_version = %d AND state = 'checked_in'",
			$now,
			$actor_user_id,
			$reason,
			$now,
			$attendance_id,
			$expected_version
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query was prepared immediately above.
		if ( 1 !== (int) $wpdb->query( $sql ) ) {
			return new \WP_Error( 'oras_desk_attendance_stale', 'Attendance changed before it could be reversed.', array( 'status' => 409 ) );
		}

		return $this->find_by_id( $attendance_id ) ?? array();
	}

	/** @return array<int,array<string,mixed>> */
	public function recent( int $event_id, int $limit = 25 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE event_id = %d ORDER BY updated_at_utc DESC,id DESC LIMIT %d", $event_id, max( 1, min( 100, $limit ) ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed> */
	public function dashboard( int $event_id, string $local_date ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$checked_in = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE event_id = %d AND attendance_local_date = %s AND state = 'checked_in'", $event_id, $local_date ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$reversed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE event_id = %d AND attendance_local_date = %s AND state = 'reversed'", $event_id, $local_date ) );
		$registration_table = Schema::table_names()['registrations'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table names are fixed by Schema.
		$active_registrations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$registration_table} WHERE event_id = %d AND status = 'active'", $event_id ) );

		return array(
			'local_date'           => $local_date,
			'checked_in_today'     => $checked_in,
			'reversed_today'       => $reversed,
			'active_registrations' => $active_registrations,
			'definitions'          => array(
				'checked_in_today'     => 'Active attendee attendance records for the site-local date.',
				'reversed_today'       => 'Attendance records reversed by an administrator for the site-local date.',
				'active_registrations' => 'Active desk registrations for this event; this is not a remaining-capacity count.',
			),
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function recent_detailed( int $event_id, int $limit = 25 ): array {
		global $wpdb;
		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table names are fixed by Schema.
			$wpdb->prepare(
				"SELECT a.*,t.attendee_uuid,t.slot_key,t.display_name,t.identity_state,r.registration_uuid,r.source_type,r.source_contact_name FROM {$tables['attendance']} a INNER JOIN {$tables['attendees']} t ON t.id = a.attendee_id INNER JOIN {$tables['registrations']} r ON r.id = t.registration_id WHERE a.event_id = %d AND a.state = 'checked_in' ORDER BY a.checked_in_at_utc DESC,a.id DESC LIMIT %d",
				$event_id,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<int,int> $attendee_ids @return array<int,array<string,mixed>> */
	public function for_attendees_on_date( int $event_id, array $attendee_ids, string $local_date ): array {
		global $wpdb;
		$attendee_ids = array_values( array_unique( array_filter( array_map( 'absint', $attendee_ids ) ) ) );
		if ( empty( $attendee_ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $attendee_ids ), '%d' ) );
		$arguments    = array_merge( array( $event_id, $local_date ), $attendee_ids );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Fixed table name; runtime-generated placeholders exactly match the attendee ID arguments.
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE event_id = %d AND attendance_local_date = %s AND attendee_id IN ({$placeholders})", ...$arguments ),
			ARRAY_A
		);
		$indexed = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$indexed[ (int) $row['attendee_id'] ] = $row;
		}

		return $indexed;
	}
}
