<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is fixed by Schema and WordPress 6.0 lacks identifier placeholders.

final class Attendee_Store extends Store {
	public function __construct() {
		parent::__construct( 'attendees' );
	}

	/** @return array<string,mixed>|null */
	public function find_by_uuid( string $uuid ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE attendee_uuid = %s", $uuid ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function find_slot( int $registration_id, string $slot_key ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE registration_id = %d AND slot_key = %s", $registration_id, $slot_key ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	private function find_current_by_id( int $id ): ?array {
		global $wpdb;
		// A locking read sees the latest committed duplicate after an insert race,
		// rather than the transaction's earlier repeatable-read snapshot.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d FOR UPDATE", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	public function for_registration( int $registration_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE registration_id = %d ORDER BY id ASC", $registration_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function confirm_individual( int $registration_id, string $first_name, string $last_name ) {
		global $wpdb;
		$display = trim( $first_name . ' ' . $last_name );
		$uuid    = self::uuid();
		$now     = self::utc_now();
		// The no-op duplicate branch is an atomic rendezvous for simultaneous
		// first confirmations. LAST_INSERT_ID exposes the winning row to this
		// connection without mutating its identity or version.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$sql = $wpdb->prepare(
			"INSERT INTO {$this->table} (attendee_uuid,registration_id,slot_key,first_name,last_name,display_name,identity_state,status,record_version,confirmed_at_utc,created_at_utc,updated_at_utc) VALUES (%s,%d,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
			$uuid,
			$registration_id,
			'individual-1',
			$first_name,
			$last_name,
			$display,
			'confirmed',
			'active',
			1,
			$now,
			$now,
			$now
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query was prepared immediately above.
		if ( false === $wpdb->query( $sql ) ) {
			return new \WP_Error( 'oras_desk_attendee_create_failed', 'Arriving attendee could not be confirmed.', array( 'status' => 409 ) );
		}
		$attendee = $this->find_current_by_id( (int) $wpdb->insert_id );
		if ( ! $attendee ) {
			return new \WP_Error( 'oras_desk_attendee_create_failed', 'Arriving attendee could not be confirmed.', array( 'status' => 409 ) );
		}
		if ( 'confirmed' === $attendee['identity_state'] && $display !== $attendee['display_name'] ) {
			return new \WP_Error( 'oras_desk_attendee_conflict', 'This attendee was already confirmed with a different name.', array( 'status' => 409 ) );
		}

		return $attendee;
	}
}
