<?php

namespace ORAS\Tickets\Registration_Desk;

use ORAS\Tickets\Support\DbLock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** PIN-gated manager access that is limited to one active desk station. */
final class Manager_Access {
	public const PIN_HASH_OPTION = 'oras_registration_desk_manager_pin_hash';
	private const VERSION = 1;
	private const MAX_FAILURES = 5;
	private const FAILURE_WINDOW = 300;

	/** @return true|\WP_Error */
	public static function set_pin( string $pin ) {
		if ( 1 !== preg_match( '/^\d{4}$/', $pin ) ) {
			return new \WP_Error( 'oras_desk_pin_invalid', 'The manager PIN must be exactly four digits.', array( 'status' => 400 ) );
		}

		return update_option( self::PIN_HASH_OPTION, wp_hash_password( $pin ), false )
			? true
			: new \WP_Error( 'oras_desk_pin_save_failed', 'The manager PIN could not be saved.', array( 'status' => 500 ) );
	}

	/** @param array<string,mixed> $station @return string|\WP_Error */
	public static function unlock( string $pin, array $station, string $rate_identity = '' ) {
		$key = self::rate_key( $station, $rate_identity );
		return DbLock::withLock(
			'desk-manager-pin:' . $key,
			static function () use ( $pin, $station, $key ) {
				$failures = (int) get_transient( $key );
				if ( $failures >= self::MAX_FAILURES ) {
					return new \WP_Error( 'oras_desk_pin_rate_limited', 'Too many incorrect attempts. Please wait a few minutes.', array( 'status' => 429 ) );
				}
				$hash = (string) get_option( self::PIN_HASH_OPTION, '' );
				if ( '' === $hash || ! wp_check_password( $pin, $hash ) ) {
					set_transient( $key, $failures + 1, self::FAILURE_WINDOW );
					return new \WP_Error( 'oras_desk_pin_incorrect', 'That PIN was not correct.', array( 'status' => 403 ) );
				}
				delete_transient( $key );
				$payload = array(
					'v'            => self::VERSION,
					'manager_uuid' => wp_generate_uuid4(),
					'user_id'      => (int) ( $station['user_id'] ?? 0 ),
					'station_uuid' => (string) ( $station['station_uuid'] ?? '' ),
					'event_id'     => (int) ( $station['event_id'] ?? 0 ),
					'wp_session'   => (string) ( $station['wp_session'] ?? '' ),
					'issued_at'    => time(),
				);
				$encoded = self::base64url_encode( (string) wp_json_encode( $payload ) );

				return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
			}
		);
	}

	/** @param array<string,mixed> $station @return array<string,mixed>|\WP_Error */
	public static function validate( string $token, array $station ) {
		$parts = explode( '.', trim( $token ) );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) {
			return self::error();
		}
		$decoded = self::base64url_decode( $parts[0] );
		$payload = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $payload ) || self::VERSION !== (int) ( $payload['v'] ?? 0 ) ) {
			return self::error();
		}
		foreach ( array( 'user_id', 'station_uuid', 'event_id', 'wp_session' ) as $field ) {
			if ( ! hash_equals( (string) ( $station[ $field ] ?? '' ), (string) ( $payload[ $field ] ?? '' ) ) ) {
				return self::error();
			}
		}

		return $payload;
	}

	/** @param array<string,mixed> $station */
	private static function rate_key( array $station, string $identity ): string {
		$user_id = (int) ( $station['user_id'] ?? 0 );
		return 'oras_desk_pin_' . substr( hash( 'sha256', $user_id > 0 ? 'user:' . $user_id : 'fallback:' . $identity ), 0, 32 );
	}

	private static function base64url_encode( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes signed JSON, not executable code.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $value ): string|false {
		$padding = strlen( $value ) % 4;
		if ( 0 !== $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes signed JSON, not executable code.
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	private static function error(): \WP_Error {
		return new \WP_Error( 'oras_desk_manager_invalid', 'Manager Mode is no longer unlocked.', array( 'status' => 403 ) );
	}
}
