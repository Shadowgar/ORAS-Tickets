<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Attendee_Store extends Store {
	public function __construct() {
		parent::__construct( 'attendees' );
	}

	/** @return array<string,mixed>|null */
	public function find_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE attendee_uuid = %s", $uuid ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}
}
