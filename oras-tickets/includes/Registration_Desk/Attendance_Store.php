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

	/** @return array<string,mixed>|\WP_Error */
	public function check_in( int $event_id, int $attendee_id, string $local_date, int $actor_user_id, string $station_uuid, string $operator_label ) {
		global $wpdb;
		$now = self::utc_now();
		$ok  = $wpdb->insert(
			$this->table,
			array(
				'event_id'                  => $event_id,
				'attendee_id'               => $attendee_id,
				'attendance_local_date'     => $local_date,
				'state'                     => 'checked_in',
				'record_version'            => 1,
				'checked_in_at_utc'         => $now,
				'checked_in_by'             => $actor_user_id,
				'checked_in_station_uuid'   => $station_uuid,
				'checked_in_operator_label' => $operator_label,
				'created_at_utc'            => $now,
				'updated_at_utc'            => $now,
			)
		);
		if ( false === $ok ) {
			$existing = $this->find_daily( $event_id, $attendee_id, $local_date );
			if ( $existing ) {
				return $existing;
			}

			return new \WP_Error( 'oras_desk_attendance_create_failed', 'Attendance could not be recorded.', array( 'status' => 500 ) );
		}

		return $this->find_by_id( (int) $wpdb->insert_id ) ?? array();
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
}
