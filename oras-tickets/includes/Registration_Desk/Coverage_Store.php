<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coverage_Store {
	private const OPTION_KEY = 'oras_registration_desk_coverage_v1';
	private const MAX_STATES = 20;

	/** @return array<string,mixed> */
	public function get( int $event_id, int $config_revision ): array {
		$states = $this->states();
		$key    = $this->key( $event_id, $config_revision );

		return isset( $states[ $key ] ) && is_array( $states[ $key ] )
			? array_merge( $this->initial( $event_id, $config_revision ), $states[ $key ] )
			: $this->initial( $event_id, $config_revision );
	}

	/** @return array<string,mixed> */
	public function begin( int $event_id, int $config_revision, array $snapshot ): array {
		$state = $this->get( $event_id, $config_revision );
		if ( 'recovery_snapshot' === (string) ( $state['unresolved_failure']['identity'] ?? '' ) ) {
			$state['unresolved_failure'] = null;
		}
		$state['status']              = 'in_progress';
		$state['snapshot_count']      = (int) ( $snapshot['count'] ?? 0 );
		$state['snapshot_highest_id'] = (int) ( $snapshot['highest_id'] ?? 0 );
		$state['next_page']           = 1;
		$state['continuation']        = '';
		$state['started_at_utc']      = Store::utc_now();
		$state['completed_at_utc']    = null;

		return $this->persist( $state );
	}

	/** @return array<string,mixed> */
	public function advance( int $event_id, int $config_revision, array $snapshot, int $next_page, string $continuation ): array {
		$state                        = $this->get( $event_id, $config_revision );
		$state['status']               = empty( $state['unresolved_failure'] ) ? 'in_progress' : 'failed';
		$state['snapshot_count']       = (int) ( $snapshot['count'] ?? 0 );
		$state['snapshot_highest_id']  = (int) ( $snapshot['highest_id'] ?? 0 );
		$state['next_page']            = max( 1, $next_page );
		$state['continuation']         = $continuation;

		return $this->persist( $state );
	}

	/** @return array<string,mixed> */
	public function fail( int $event_id, int $config_revision, string $identity, string $code, string $continuation = '' ): array {
		$state = $this->get( $event_id, $config_revision );
		$state['status']             = 'failed';
		$state['continuation']       = $continuation;
		$state['unresolved_failure'] = array(
			'identity'      => sanitize_text_field( $identity ),
			'code'          => sanitize_key( $code ),
			'failed_at_utc' => Store::utc_now(),
		);

		return $this->persist( $state );
	}

	/** @return array<string,mixed> */
	public function clear_failure( int $event_id, int $config_revision, string $identity ): array {
		$state = $this->get( $event_id, $config_revision );
		if ( hash_equals( (string) ( $state['unresolved_failure']['identity'] ?? '' ), $identity ) ) {
			$state['unresolved_failure'] = null;
			$state['status'] = $state['snapshot_count'] > 0 ? 'in_progress' : 'not_started';
		}

		return $this->persist( $state );
	}

	/** @return array<string,mixed> */
	public function complete( int $event_id, int $config_revision, array $snapshot ): array {
		$state                        = $this->get( $event_id, $config_revision );
		$state['snapshot_count']       = (int) ( $snapshot['count'] ?? 0 );
		$state['snapshot_highest_id']  = (int) ( $snapshot['highest_id'] ?? 0 );
		$state['next_page']            = null;
		$state['continuation']         = '';
		$state['status']               = empty( $state['unresolved_failure'] ) ? 'complete' : 'failed';
		$state['completed_at_utc']     = 'complete' === $state['status'] ? Store::utc_now() : null;

		return $this->persist( $state );
	}

	/** @return array<string,mixed> */
	public function refresh_listener_snapshot( int $event_id, int $config_revision, array $snapshot ): array {
		$state = $this->get( $event_id, $config_revision );
		if ( 'complete' === $state['status'] && empty( $state['unresolved_failure'] ) ) {
			$state['snapshot_count']      = (int) ( $snapshot['count'] ?? 0 );
			$state['snapshot_highest_id'] = (int) ( $snapshot['highest_id'] ?? 0 );
			$state['completed_at_utc']    = Store::utc_now();
		}

		return $this->persist( $state );
	}

	/** @return array<string,array<string,mixed>> */
	private function states(): array {
		$value = get_option( self::OPTION_KEY, array() );

		return is_array( $value ) ? $value : array();
	}

	/** @return array<string,mixed> */
	private function initial( int $event_id, int $config_revision ): array {
		return array(
			'event_id'            => $event_id,
			'config_revision'     => $config_revision,
			'status'              => 'not_started',
			'snapshot_count'      => 0,
			'snapshot_highest_id' => 0,
			'next_page'           => 1,
			'continuation'        => '',
			'unresolved_failure'  => null,
			'started_at_utc'      => null,
			'completed_at_utc'    => null,
			'updated_at_utc'      => null,
		);
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function persist( array $state ): array {
		$state['updated_at_utc'] = Store::utc_now();
		$states                   = $this->states();
		$states[ $this->key( (int) $state['event_id'], (int) $state['config_revision'] ) ] = $state;
		if ( count( $states ) > self::MAX_STATES ) {
			uasort( $states, static fn( array $a, array $b ): int => strcmp( (string) ( $a['updated_at_utc'] ?? '' ), (string) ( $b['updated_at_utc'] ?? '' ) ) );
			$states = array_slice( $states, -self::MAX_STATES, null, true );
		}
		update_option( self::OPTION_KEY, $states, false );

		return $state;
	}

	private function key( int $event_id, int $config_revision ): string {
		return $event_id . ':' . $config_revision;
	}
}
