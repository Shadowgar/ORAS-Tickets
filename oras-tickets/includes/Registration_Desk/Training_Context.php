<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves the server-authorized mode and clock for one desk station. */
final class Training_Context {
	private Training_Store $store;

	public function __construct( ?Training_Store $store = null ) {
		$this->store = $store ?? new Training_Store();
	}

	/** @param array<string,mixed> $station @return array<string,mixed>|\WP_Error */
	public function resolve( array $station ) {
		$row = $this->store->find_for_station( (string) ( $station['station_uuid'] ?? '' ) );
		if ( null === $row ) {
			return new \WP_Error( 'oras_desk_training_required', 'Training Mode is not active for this station.', array( 'status' => 409 ) );
		}
		$event_id = (int) ( $station['event_id'] ?? 0 );

		return self::validate_binding(
			$station,
			$row,
			Config::get_event_config( $event_id ),
			Event_Catalog::find_any( $event_id ) ?? array()
		);
	}

	/** @param array<string,mixed> $station @return true|\WP_Error */
	public function assert_station_live( array $station ) {
		return self::assert_live( $this->store->find_for_station( (string) ( $station['station_uuid'] ?? '' ) ) );
	}

	/** @param array<string,mixed>|null $training_row @return true|\WP_Error */
	public static function assert_live( ?array $training_row ) {
		return null === $training_row
			? true
			: new \WP_Error(
				'oras_desk_training_live_route_forbidden',
				'This station is in Training Mode. Live event operations are unavailable until Training Mode ends.',
				array( 'status' => 409 )
			);
	}

	/**
	 * @param array<string,mixed> $station Signed station payload.
	 * @param array<string,mixed> $row Training row.
	 * @param array<string,mixed> $config Current event configuration.
	 * @param array<string,mixed> $event Current event catalog row.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function validate_binding( array $station, array $row, array $config, array $event, ?int $now = null ) {
		$scope = self::validate_scope( $station, $row, $now );
		if ( $scope instanceof \WP_Error ) {
			return $scope;
		}

		$config_revision = (int) ( $config['revision'] ?? -1 );
		if (
			$config_revision !== (int) ( $station['config_revision'] ?? -2 )
			|| $config_revision !== (int) ( $row['config_revision'] ?? -3 )
		) {
			return new \WP_Error(
				'oras_desk_training_config_changed',
				'Event configuration changed. End and restart Training Mode before continuing.',
				array( 'status' => 409 )
			);
		}

		if ( (int) ( $event['event_id'] ?? 0 ) !== (int) ( $row['event_id'] ?? 0 ) ) {
			return new \WP_Error( 'oras_desk_training_event_changed', 'The training event is no longer available. End and restart Training Mode.', array( 'status' => 409 ) );
		}

		$date  = (string) ( $row['simulated_local_date'] ?? '' );
		$start = (string) ( $event['start_date'] ?? '' );
		$end   = (string) ( $event['end_date'] ?? '' );
		if ( ! self::is_event_date( $date, $start, $end ) ) {
			return new \WP_Error( 'oras_desk_training_date_invalid', 'The training date must be within the selected event.', array( 'status' => 400 ) );
		}

		return $row;
	}

	/**
	 * Validate immutable station ownership while allowing a manager to end a
	 * session whose external event configuration changed.
	 *
	 * @param array<string,mixed> $station Signed station payload.
	 * @param array<string,mixed> $row Training row.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function validate_scope( array $station, array $row, ?int $now = null ) {
		if (
			'training' !== (string) ( $station['mode'] ?? '' )
			|| ! hash_equals( (string) ( $station['station_uuid'] ?? '' ), (string) ( $row['station_uuid'] ?? '' ) )
			|| (int) ( $station['user_id'] ?? 0 ) !== (int) ( $row['user_id'] ?? 0 )
			|| ! hash_equals( (string) ( $station['wp_session'] ?? '' ), (string) ( $row['wp_session_digest'] ?? '' ) )
			|| (int) ( $station['event_id'] ?? 0 ) !== (int) ( $row['event_id'] ?? 0 )
			|| ! hash_equals( (string) ( $station['simulated_local_date'] ?? '' ), (string) ( $row['simulated_local_date'] ?? '' ) )
		) {
			return new \WP_Error( 'oras_desk_training_scope_invalid', 'Training Mode does not belong to this station.', array( 'status' => 403 ) );
		}

		$now     = $now ?? time();
		$expires = strtotime( (string) ( $row['expires_at_utc'] ?? '' ) . ' UTC' );
		if ( false === $expires || $expires < $now ) {
			return new \WP_Error( 'oras_desk_training_expired', 'Training Mode expired. Ask a manager to start a new training session.', array( 'status' => 401 ) );
		}

		return $row;
	}

	public static function is_event_date( string $date, string $start, string $end ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date )
			&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start )
			&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end )
			&& $date >= $start
			&& $date <= $end;
	}
}
