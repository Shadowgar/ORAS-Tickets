<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is fixed by Schema.
final class Offline_Membership_Store extends Store {
	public function __construct() {
		parent::__construct( 'offline_memberships' );
	}

	/** @return array<string,mixed>|null */
	public function find_request( string $request_uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE request_uuid = %s", $request_uuid ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function find_activation( string $activation_uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE activation_uuid = %s", $activation_uuid ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function find_code( string $code ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE credit_code = %s", strtoupper( trim( $code ) ) ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @param array<string,mixed> $record @return array<string,mixed>|\WP_Error */
	public function create( array $record ) {
		global $wpdb;
		$now = self::utc_now();
		$record['created_at_utc'] = $now;
		$record['updated_at_utc'] = $now;
		if ( false === $wpdb->insert( $this->table, $record ) ) {
			$existing = $this->find_request( (string) ( $record['request_uuid'] ?? '' ) );

			return $existing ?? new \WP_Error( 'oras_desk_membership_save_failed', 'The membership record could not be saved.', array( 'status' => 500 ) );
		}

		return $this->find_request( (string) $record['request_uuid'] ) ?? array();
	}

	/** @param array<string,mixed> $changes @return array<string,mixed>|\WP_Error */
	public function update( string $activation_uuid, array $changes ) {
		global $wpdb;
		$changes['updated_at_utc'] = self::utc_now();
		$updated = $wpdb->update( $this->table, $changes, array( 'activation_uuid' => $activation_uuid ) );
		if ( false === $updated ) {
			return new \WP_Error( 'oras_desk_membership_update_failed', 'The membership record could not be updated.', array( 'status' => 500 ) );
		}

		return $this->find_activation( $activation_uuid ) ?? array();
	}

	/** @return array<int,array<string,mixed>> */
	public function search( string $query, int $event_id = 0, int $limit = 50 ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( strtolower( trim( $query ) ) ) . '%';
		$where = $event_id > 0 ? $wpdb->prepare( 'event_id = %d AND ', $event_id ) : '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE {$where}(LOWER(CONCAT(first_name,' ',last_name)) LIKE %s OR normalized_email LIKE %s) ORDER BY id DESC LIMIT %d", $like, $like, max( 1, min( 100, $limit ) ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,array<string,mixed>> */
	public function for_event( int $event_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE event_id = %d ORDER BY id DESC", $event_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}
