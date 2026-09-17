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
		$existing = $this->find_slot( $registration_id, 'individual-1' );
		$display  = trim( $first_name . ' ' . $last_name );
		if ( $existing ) {
			if ( 'confirmed' === $existing['identity_state'] && $display !== $existing['display_name'] ) {
				return new \WP_Error( 'oras_desk_attendee_conflict', 'This attendee was already confirmed with a different name.', array( 'status' => 409 ) );
			}

			return $existing;
		}
		$uuid = self::uuid();
		$now  = self::utc_now();
		$ok   = $wpdb->insert(
			$this->table,
			array(
				'attendee_uuid'    => $uuid,
				'registration_id'  => $registration_id,
				'slot_key'         => 'individual-1',
				'first_name'       => $first_name,
				'last_name'        => $last_name,
				'display_name'     => $display,
				'identity_state'   => 'confirmed',
				'status'           => 'active',
				'record_version'   => 1,
				'confirmed_at_utc' => $now,
				'created_at_utc'   => $now,
				'updated_at_utc'   => $now,
			)
		);
		if ( false === $ok ) {
			$existing = $this->find_slot( $registration_id, 'individual-1' );
			if ( $existing && $display === $existing['display_name'] ) {
				return $existing;
			}

			return new \WP_Error( 'oras_desk_attendee_create_failed', 'Arriving attendee could not be confirmed.', array( 'status' => 409 ) );
		}

		return $this->find_by_uuid( $uuid ) ?? array();
	}
}
