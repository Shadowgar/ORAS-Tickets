<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Audit_Store extends Store {
	public function __construct() {
		parent::__construct( 'audit' );
	}

	/** @return array<string,mixed>|null */
	public function find_request( string $request_uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE request_uuid = %s", $request_uuid ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}
}
