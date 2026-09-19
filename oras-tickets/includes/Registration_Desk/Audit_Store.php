<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is fixed by Schema and WordPress 6.0 lacks identifier placeholders.

final class Audit_Store extends Store {
	public function __construct() {
		parent::__construct( 'audit' );
	}

	/** @return array<string,mixed>|null */
	public function find_request( string $request_uuid ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE request_uuid = %s", $request_uuid ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	public function for_registration( string $registration_uuid, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT operation,operator_label,result_status,result_code,changes_json,created_at_utc FROM {$this->table} WHERE registration_uuid = %s ORDER BY created_at_utc DESC,id DESC LIMIT %d",
				$registration_uuid,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<string,mixed> $record @return array<string,mixed>|\WP_Error */
	public function append( array $record ) {
		global $wpdb;
		$record['created_at_utc'] = self::utc_now();
		if ( false === $wpdb->insert( $this->table, $record ) ) {
			$existing = $this->find_request( (string) ( $record['request_uuid'] ?? '' ) );
			if ( $existing ) {
				return new \WP_Error( 'oras_desk_request_exists', 'This request identifier already has a result.', array( 'status' => 409 ) );
			}

			return new \WP_Error( 'oras_desk_audit_persist_failed', 'Registration Desk could not persist the operation audit.', array( 'status' => 500 ) );
		}

		return $this->find_request( (string) $record['request_uuid'] ) ?? array();
	}
}
