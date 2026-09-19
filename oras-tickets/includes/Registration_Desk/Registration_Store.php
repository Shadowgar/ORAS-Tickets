<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is fixed by Schema and WordPress 6.0 lacks identifier placeholders.

final class Registration_Store extends Store {
	public function __construct() {
		parent::__construct( 'registrations' );
	}

	/** @return array<string,mixed>|null */
	public function find_by_uuid( string $uuid ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE registration_uuid = %s", $uuid ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function find_by_source_key( int $event_id, string $source_key ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE event_id = %d AND source_key = %s", $event_id, $source_key ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @param array<string,mixed> $record @return array<string,mixed>|\WP_Error */
	public function create_manual( array $record ) {
		global $wpdb;
		$uuid       = self::uuid();
		$now        = self::utc_now();
		$first_name = sanitize_text_field( (string) ( $record['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $record['last_name'] ?? '' ) );
		$name       = trim( $first_name . ' ' . $last_name );
		$email      = strtolower( sanitize_email( (string) ( $record['email'] ?? '' ) ) );
		$phone      = sanitize_text_field( (string) ( $record['phone'] ?? '' ) );
		$evidence   = wp_json_encode( is_array( $record['evidence'] ?? null ) ? $record['evidence'] : array() );
		$insert     = array(
			'registration_uuid'     => $uuid,
			'event_id'              => absint( $record['event_id'] ?? 0 ),
			'option_uuid'           => sanitize_text_field( (string) ( $record['option_uuid'] ?? '' ) ),
			'source_type'           => sanitize_key( (string) ( $record['source_type'] ?? 'walk_in' ) ),
			'source_key'            => null,
			'source_order_id'       => null,
			'source_order_item_id'  => null,
			'source_unit_number'    => null,
			'classification'        => sanitize_key( (string) ( $record['classification'] ?? 'unclassified' ) ),
			'status'                => 'active',
			'source_status'         => '',
			'source_contact_name'   => $name,
			'source_email'          => $email,
			'source_phone'          => $phone,
			'search_name'           => self::normalize_search( $name ),
			'search_email'          => $email,
			'search_phone'          => self::normalize_phone( $phone ),
			'coverage_type'         => sanitize_key( (string) ( $record['classification'] ?? 'unclassified' ) ),
			'validity_type'         => sanitize_key( (string) ( $record['validity_type'] ?? 'unclassified' ) ),
			'valid_local_date'      => '' !== (string) ( $record['valid_local_date'] ?? '' ) ? (string) $record['valid_local_date'] : null,
			'payment_assertion'     => sanitize_key( (string) ( $record['payment_assertion'] ?? '' ) ),
			'source_evidence'       => is_string( $evidence ) ? $evidence : '{}',
			'source_checked_at_utc' => null,
			'config_revision'       => absint( $record['config_revision'] ?? 0 ),
			'record_version'        => 1,
			'created_at_utc'        => $now,
			'updated_at_utc'        => $now,
		);
		if ( false === $wpdb->insert( $this->table, $insert ) ) {
			return new \WP_Error( 'oras_desk_registration_create_failed', 'Registration could not be saved.', array( 'status' => 500 ) );
		}

		return $this->find_by_uuid( $uuid ) ?? array();
	}

	/** @param array<string,mixed> $contact @return array<string,mixed>|\WP_Error */
	public function ensure_rsvp_website( int $event_id, int $user_id, array $contact, int $config_revision ) {
		global $wpdb;
		$source_key = 'rsvp-user:' . $user_id;
		$existing   = $this->find_by_source_key( $event_id, $source_key );
		if ( $existing ) {
			return $existing;
		}
		$first_name = sanitize_text_field( (string) ( $contact['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $contact['last_name'] ?? '' ) );
		$name       = trim( $first_name . ' ' . $last_name );
		$email      = strtolower( sanitize_email( (string) ( $contact['email'] ?? '' ) ) );
		$phone      = sanitize_text_field( (string) ( $contact['phone'] ?? '' ) );
		$evidence   = wp_json_encode(
			array(
				'rsvp_user_id' => $user_id,
				'item_label'   => 'Event RSVP',
				'contact'      => $contact,
			)
		);
		$now = self::utc_now();
		$inserted = $wpdb->insert(
			$this->table,
			array(
				'registration_uuid'     => self::uuid(),
				'event_id'              => $event_id,
				'option_uuid'           => \ORAS\Tickets\Domain\Event_Offering_Resolver::option_uuid( $event_id, 'rsvp' ),
				'source_type'           => 'rsvp_website',
				'source_key'            => $source_key,
				'source_order_id'       => null,
				'source_order_item_id'  => null,
				'source_unit_number'    => 1,
				'classification'        => 'individual',
				'status'                => 'active',
				'source_status'         => 'yes',
				'source_contact_name'   => $name,
				'source_email'          => $email,
				'source_phone'          => $phone,
				'search_name'           => self::normalize_search( $name ),
				'search_email'          => $email,
				'search_phone'          => self::normalize_phone( $phone ),
				'coverage_type'         => 'individual',
				'validity_type'         => 'full_event',
				'valid_local_date'      => null,
				'payment_assertion'     => 'rsvp',
				'source_evidence'       => is_string( $evidence ) ? $evidence : '{}',
				'source_checked_at_utc' => $now,
				'config_revision'       => $config_revision,
				'record_version'        => 1,
				'created_at_utc'        => $now,
				'updated_at_utc'        => $now,
			)
		);
		if ( false === $inserted ) {
			$existing = $this->find_by_source_key( $event_id, $source_key );
			return $existing ?? new \WP_Error( 'oras_desk_rsvp_projection_failed', 'The website RSVP could not be prepared for check-in.', array( 'status' => 409 ) );
		}

		return $this->find_by_source_key( $event_id, $source_key ) ?? array();
	}

	/** @return array<int,array<string,mixed>> */
	public function duplicate_candidates( int $event_id, string $email, string $phone, string $exclude_uuid = '' ): array {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) );
		$phone = self::normalize_phone( $phone );
		if ( '' === $email && '' === $phone ) {
			return array();
		}
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE event_id = %d AND registration_uuid <> %s AND ((%s <> '' AND search_email = %s) OR (%s <> '' AND search_phone = %s)) ORDER BY updated_at_utc DESC,id DESC LIMIT 10",
				$event_id,
				$exclude_uuid,
				$email,
				$email,
				$phone,
				$phone
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<string,mixed> $changes @return array<string,mixed>|\WP_Error */
	public function correct_manual( string $uuid, int $expected_version, array $changes ) {
		global $wpdb;
		$current = $this->find_by_uuid( $uuid );
		if ( ! $current || 'online' === (string) $current['source_type'] ) {
			return new \WP_Error( 'oras_desk_correction_forbidden', 'Only desk-created registration details can be corrected here.', array( 'status' => 409 ) );
		}
		$first_name = sanitize_text_field( (string) ( $changes['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $changes['last_name'] ?? '' ) );
		$name       = trim( $first_name . ' ' . $last_name );
		$email      = strtolower( sanitize_email( (string) ( $changes['email'] ?? '' ) ) );
		$phone      = sanitize_text_field( (string) ( $changes['phone'] ?? '' ) );
		$evidence   = wp_json_encode( is_array( $changes['evidence'] ?? null ) ? $changes['evidence'] : array() );
		$updated    = $wpdb->update(
			$this->table,
			array(
				'option_uuid'         => sanitize_text_field( (string) ( $changes['option_uuid'] ?? $current['option_uuid'] ) ),
				'classification'      => sanitize_key( (string) ( $changes['classification'] ?? $current['classification'] ) ),
				'coverage_type'       => sanitize_key( (string) ( $changes['classification'] ?? $current['classification'] ) ),
				'validity_type'       => sanitize_key( (string) ( $changes['validity_type'] ?? $current['validity_type'] ) ),
				'valid_local_date'    => '' !== (string) ( $changes['valid_local_date'] ?? '' ) ? (string) $changes['valid_local_date'] : null,
				'payment_assertion'   => sanitize_key( (string) ( $changes['payment_assertion'] ?? $current['payment_assertion'] ) ),
				'source_contact_name' => $name,
				'source_email'        => $email,
				'source_phone'        => $phone,
				'search_name'         => self::normalize_search( $name ),
				'search_email'        => $email,
				'search_phone'        => self::normalize_phone( $phone ),
				'source_evidence'     => is_string( $evidence ) ? $evidence : '{}',
				'record_version'      => $expected_version + 1,
				'updated_at_utc'      => self::utc_now(),
			),
			array(
				'id'             => (int) $current['id'],
				'record_version' => $expected_version,
			)
		);
		if ( 1 !== (int) $updated ) {
			return new \WP_Error( 'oras_desk_registration_stale', 'Registration changed before the correction could be saved.', array( 'status' => 409 ) );
		}

		return $this->find_by_uuid( $uuid ) ?? array();
	}

	/** @param array<string,mixed> $evidence @param array<string,mixed> $resolution @return array<string,mixed>|\WP_Error */
	public function upsert_source_projection( int $event_id, string $source_key, int $unit, array $evidence, array $resolution, int $config_revision ) {
		global $wpdb;
		$existing = $this->find_by_source_key( $event_id, $source_key );
		$now      = self::utc_now();
		$status   = in_array( $resolution['eligibility'], array( 'eligible', 'explicit_unpaid_required' ), true ) && 'supported' === $resolution['resolution'] ? 'active' : ( 'revoked' === $resolution['eligibility'] ? 'revoked' : 'needs_review' );
		$evidence_json = wp_json_encode( $evidence );
		$data = array(
			'source_status'         => sanitize_key( (string) ( $evidence['order_status'] ?? '' ) ),
			'source_contact_name'   => sanitize_text_field( (string) ( $evidence['contact_name'] ?? '' ) ),
			'source_email'          => sanitize_email( (string) ( $evidence['email'] ?? '' ) ),
			'source_phone'          => sanitize_text_field( (string) ( $evidence['phone'] ?? '' ) ),
			'search_name'           => self::normalize_search( (string) ( $evidence['contact_name'] ?? '' ) ),
			'search_email'          => strtolower( sanitize_email( (string) ( $evidence['email'] ?? '' ) ) ),
			'search_phone'          => preg_replace( '/\D+/', '', (string) ( $evidence['phone'] ?? '' ) ),
			'source_evidence'       => is_string( $evidence_json ) ? $evidence_json : '{}',
			'source_checked_at_utc' => $now,
			'updated_at_utc'        => $now,
		);
		if ( $existing ) {
			if ( (string) $existing['option_uuid'] !== (string) $resolution['option_uuid'] && '' !== (string) $resolution['option_uuid'] ) {
				$data['status'] = 'needs_review';
			} else {
				$data['status'] = $status;
			}
			$data['record_version'] = (int) $existing['record_version'] + 1;
			$updated = $wpdb->update(
				$this->table,
				$data,
				array(
					'id'             => (int) $existing['id'],
					'record_version' => (int) $existing['record_version'],
				)
			);
			if ( false === $updated ) {
				return new \WP_Error( 'oras_desk_projection_failed', 'Website registration projection could not be refreshed.' );
			}

			return $this->find_by_uuid( (string) $existing['registration_uuid'] ) ?? array();
		}

		$uuid = self::uuid();
		$insert = array_merge(
			$data,
			array(
				'registration_uuid'    => $uuid,
				'event_id'             => $event_id,
				'option_uuid'          => '' !== (string) $resolution['option_uuid'] ? (string) $resolution['option_uuid'] : '00000000-0000-4000-8000-000000000000',
				'source_type'          => 'online',
				'source_key'           => $source_key,
				'source_order_id'      => (int) $evidence['order_id'],
				'source_order_item_id' => (int) $evidence['order_item_id'],
				'source_unit_number'   => $unit,
				'classification'       => (string) $resolution['classification'],
				'status'               => $status,
				'coverage_type'        => (string) $resolution['classification'],
				'validity_type'        => (string) $resolution['validity_type'],
				'valid_local_date'     => '' !== (string) ( $resolution['valid_local_date'] ?? '' ) ? (string) $resolution['valid_local_date'] : null,
				'payment_assertion'    => null,
				'config_revision'      => $config_revision,
				'record_version'       => 1,
				'created_at_utc'       => $now,
			)
		);
		if ( false === $wpdb->insert( $this->table, $insert ) ) {
			return new \WP_Error( 'oras_desk_projection_failed', 'Website registration projection could not be created.' );
		}

		return $this->find_by_uuid( $uuid ) ?? array();
	}

	/** @return int|\WP_Error */
	public function revoke_source_units_above( int $event_id, int $order_id, int $order_item_id, int $maximum_unit ) {
		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET status = 'revoked', record_version = record_version + 1, updated_at_utc = %s WHERE event_id = %d AND source_order_id = %d AND source_order_item_id = %d AND source_unit_number > %d AND status <> 'revoked'",
				self::utc_now(),
				$event_id,
				$order_id,
				$order_item_id,
				max( 0, $maximum_unit )
			)
		);
		if ( false === $updated ) {
			return new \WP_Error( 'oras_desk_projection_failed', 'Excess website registration units could not be revoked.' );
		}

		return (int) $updated;
	}

	private static function normalize_search( string $value ): string {
		$value = strtolower( sanitize_text_field( $value ) );

		return trim( preg_replace( '/\s+/', ' ', $value ) ?? '' );
	}

	private static function normalize_phone( string $value ): string {
		return preg_replace( '/\D+/', '', $value ) ?? '';
	}

	/** @return array<int,array<string,mixed>> */
	public function search( int $event_id, string $query, int $limit = 25 ): array {
		global $wpdb;
		$query = self::normalize_search( $query );
		if ( '' === $query ) {
			return array();
		}
		$like        = '%' . $wpdb->esc_like( $query ) . '%';
		$phone_query = self::normalize_phone( $query );
		$phone_like  = '' !== $phone_query ? '%' . $wpdb->esc_like( $phone_query ) . '%' : '__no_phone_match__';
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name is fixed by Schema.
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE event_id = %d AND (registration_uuid = %s OR search_name LIKE %s OR search_email LIKE %s OR search_phone LIKE %s OR source_key = %s) ORDER BY updated_at_utc DESC,id DESC LIMIT %d",
				$event_id,
				$query,
				$like,
				$like,
				$phone_like,
				$query,
				max( 1, min( 50, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
