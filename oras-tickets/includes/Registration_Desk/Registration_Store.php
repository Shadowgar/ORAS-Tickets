<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Registration_Store extends Store {
	public function __construct() {
		parent::__construct( 'registrations' );
	}

	/** @return array<string,mixed>|null */
	public function find_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE registration_uuid = %s", $uuid ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function find_by_source_key( int $event_id, string $source_key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE event_id = %d AND source_key = %s", $event_id, $source_key ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}
}
