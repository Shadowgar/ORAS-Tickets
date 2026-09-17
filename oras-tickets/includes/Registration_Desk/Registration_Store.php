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
			$updated = $wpdb->update( $this->table, $data, array( 'id' => (int) $existing['id'], 'record_version' => (int) $existing['record_version'] ) );
			if ( false === $updated ) {
				return new \WP_Error( 'oras_desk_projection_failed', 'Website registration projection could not be refreshed.' );
			}

			return $this->find_by_uuid( (string) $existing['registration_uuid'] ) ?? array();
		}

		$uuid = self::uuid();
		$insert = array_merge(
			$data,
			array(
				'registration_uuid'      => $uuid,
				'event_id'               => $event_id,
				'option_uuid'             => '' !== (string) $resolution['option_uuid'] ? (string) $resolution['option_uuid'] : '00000000-0000-4000-8000-000000000000',
				'source_type'             => 'online',
				'source_key'              => $source_key,
				'source_order_id'         => (int) $evidence['order_id'],
				'source_order_item_id'    => (int) $evidence['order_item_id'],
				'source_unit_number'      => $unit,
				'classification'          => (string) $resolution['classification'],
				'status'                  => $status,
				'coverage_type'           => (string) $resolution['classification'],
				'validity_type'           => (string) $resolution['validity_type'],
				'valid_local_date'        => null,
				'payment_assertion'       => null,
				'config_revision'         => $config_revision,
				'record_version'          => 1,
				'created_at_utc'          => $now,
			)
		);
		if ( false === $wpdb->insert( $this->table, $insert ) ) {
			return new \WP_Error( 'oras_desk_projection_failed', 'Website registration projection could not be created.' );
		}

		return $this->find_by_uuid( $uuid ) ?? array();
	}

	private static function normalize_search( string $value ): string {
		$value = strtolower( sanitize_text_field( $value ) );

		return trim( preg_replace( '/\s+/', ' ', $value ) ?? '' );
	}
}
