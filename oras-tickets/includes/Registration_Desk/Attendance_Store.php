<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Attendance_Store extends Store {
	public function __construct() {
		parent::__construct( 'attendance' );
	}

	/** @return array<string,mixed>|null */
	public function find_daily( int $event_id, int $attendee_id, string $local_date ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
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
}
