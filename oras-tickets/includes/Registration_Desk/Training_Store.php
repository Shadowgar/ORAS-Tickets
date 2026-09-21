<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Durable state for one isolated Registration Desk training station. */
final class Training_Store extends Store {
	private const MAX_STATE_BYTES = 524288;

	public function __construct() {
		parent::__construct( 'training_sessions' );
	}

	/**
	 * @param array<string,mixed> $binding Station and event binding.
	 * @param array<string,mixed> $state Initial synthetic state.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function create( array $binding, array $state, int $ttl = 43200 ) {
		global $wpdb;
		$encoded = $this->encode_state( $state );
		if ( $encoded instanceof \WP_Error ) {
			return $encoded;
		}
		$now           = self::utc_now();
		$requested_uuid = strtolower( trim( (string) ( $binding['training_uuid'] ?? '' ) ) );
		$training_uuid  = 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $requested_uuid ) ? $requested_uuid : self::uuid();
		$created       = $wpdb->insert(
			$this->table,
			array(
				'training_uuid'        => $training_uuid,
				'station_uuid'         => sanitize_text_field( (string) ( $binding['station_uuid'] ?? '' ) ),
				'user_id'              => absint( $binding['user_id'] ?? 0 ),
				'wp_session_digest'    => sanitize_text_field( (string) ( $binding['wp_session'] ?? '' ) ),
				'event_id'             => absint( $binding['event_id'] ?? 0 ),
				'config_revision'      => absint( $binding['config_revision'] ?? 0 ),
				'simulated_local_date' => sanitize_text_field( (string) ( $binding['simulated_local_date'] ?? '' ) ),
				'state_json'           => $encoded,
				'record_version'       => 1,
				'expires_at_utc'       => gmdate( 'Y-m-d H:i:s', time() + max( 300, min( 86400, $ttl ) ) ),
				'created_at_utc'       => $now,
				'updated_at_utc'       => $now,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( false === $created ) {
			return new \WP_Error( 'oras_desk_training_create_failed', 'Training Mode could not be started. No live event data was changed.' );
		}

		return $this->find_for_station( (string) $binding['station_uuid'] )
			?? new \WP_Error( 'oras_desk_training_create_failed', 'Training Mode could not be loaded. No live event data was changed.' );
	}

	/** @return array<string,mixed>|null */
	public function find_for_station( string $station_uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned table name.
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE station_uuid = %s LIMIT 1", $station_uuid ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * @template T
	 * @param callable(array<string,mixed>,array<string,mixed>):(array{state:array<string,mixed>,result:T}|\WP_Error) $transition
	 * @return T|\WP_Error
	 */
	public function mutate( string $station_uuid, int $expected_revision, callable $transition ) {
		return self::transaction(
			function () use ( $station_uuid, $expected_revision, $transition ) {
				global $wpdb;
				$row = $wpdb->get_row(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned table name.
					$wpdb->prepare( "SELECT * FROM {$this->table} WHERE station_uuid = %s FOR UPDATE", $station_uuid ),
					ARRAY_A
				);
				if ( ! is_array( $row ) ) {
					return new \WP_Error( 'oras_desk_training_required', 'Training Mode is not active for this station.', array( 'status' => 409 ) );
				}
				$normalized = $this->normalize_row( $row );
				if ( $expected_revision !== (int) $normalized['record_version'] ) {
					return new \WP_Error( 'oras_desk_training_stale', 'Training data changed. Reload and try again.', array( 'status' => 409 ) );
				}
				$transitioned = $transition( $normalized['state'], $normalized );
				if ( $transitioned instanceof \WP_Error ) {
					return $transitioned;
				}
				if ( ! isset( $transitioned['state'] ) || ! is_array( $transitioned['state'] ) || ! array_key_exists( 'result', $transitioned ) ) {
					return new \WP_Error( 'oras_desk_training_transition_invalid', 'Training action could not be saved. No live event data was changed.' );
				}
				$encoded = $this->encode_state( $transitioned['state'] );
				if ( $encoded instanceof \WP_Error ) {
					return $encoded;
				}
				$updated = $wpdb->update(
					$this->table,
					array(
						'state_json'     => $encoded,
						'record_version' => $expected_revision + 1,
						'updated_at_utc' => self::utc_now(),
					),
					array(
						'id'             => (int) $normalized['id'],
						'record_version' => $expected_revision,
					),
					array( '%s', '%d', '%s' ),
					array( '%d', '%d' )
				);
				if ( 1 !== $updated ) {
					return new \WP_Error( 'oras_desk_training_save_failed', 'Training action could not be saved. No live event data was changed.' );
				}

				return $transitioned['result'];
			}
		);
	}

	/** @param array<string,mixed> $state @return array<string,mixed>|\WP_Error */
	public function reset( string $station_uuid, int $expected_revision, array $state ) {
		return $this->mutate(
			$station_uuid,
			$expected_revision,
			static fn(): array => array(
				'state'  => $state,
				'result' => array( 'reset' => true ),
			)
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function change_date( string $station_uuid, int $expected_revision, string $simulated_local_date ) {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $simulated_local_date ) ) {
			return new \WP_Error( 'oras_desk_training_date_invalid', 'The training date is invalid.', array( 'status' => 400 ) );
		}

		return self::transaction(
			function () use ( $station_uuid, $expected_revision, $simulated_local_date ) {
				global $wpdb;
				$row = $wpdb->get_row(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned table name.
					$wpdb->prepare( "SELECT * FROM {$this->table} WHERE station_uuid = %s FOR UPDATE", $station_uuid ),
					ARRAY_A
				);
				if ( ! is_array( $row ) ) {
					return new \WP_Error( 'oras_desk_training_required', 'Training Mode is not active for this station.', array( 'status' => 409 ) );
				}
				$normalized = $this->normalize_row( $row );
				if ( $expected_revision !== (int) $normalized['record_version'] ) {
					return new \WP_Error( 'oras_desk_training_stale', 'Training data changed. Reload and try again.', array( 'status' => 409 ) );
				}
				$updated = $wpdb->update(
					$this->table,
					array(
						'simulated_local_date' => $simulated_local_date,
						'record_version'       => $expected_revision + 1,
						'updated_at_utc'       => self::utc_now(),
					),
					array(
						'id'             => (int) $normalized['id'],
						'record_version' => $expected_revision,
					),
					array( '%s', '%d', '%s' ),
					array( '%d', '%d' )
				);
				if ( 1 !== $updated ) {
					return new \WP_Error( 'oras_desk_training_save_failed', 'Training date could not be saved. No live event data was changed.' );
				}

				return array(
					'simulated_local_date' => $simulated_local_date,
					'record_version'       => $expected_revision + 1,
				);
			}
		);
	}

	public function delete_for_station( string $station_uuid ): bool {
		global $wpdb;

		return false !== $wpdb->delete( $this->table, array( 'station_uuid' => $station_uuid ), array( '%s' ) );
	}

	public function cleanup_expired(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal plugin-owned table name.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE expires_at_utc < %s", self::utc_now() ) );

		return false === $deleted ? 0 : (int) $deleted;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function normalize_row( array $row ): array {
		$state = json_decode( (string) ( $row['state_json'] ?? '' ), true );
		$row['state'] = is_array( $state ) ? $state : array();
		foreach ( array( 'id', 'user_id', 'event_id', 'config_revision', 'record_version' ) as $key ) {
			$row[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}

		return $row;
	}

	/** @param array<string,mixed> $state @return string|\WP_Error */
	private function encode_state( array $state ) {
		$encoded = wp_json_encode( $state );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_STATE_BYTES ) {
			return new \WP_Error( 'oras_desk_training_state_invalid', 'Training data is too large to save. No live event data was changed.' );
		}

		return $encoded;
	}
}
