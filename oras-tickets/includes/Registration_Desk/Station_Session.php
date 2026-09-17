<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Station_Session {
	private const VERSION = 1;

	public static function issue( int $user_id, int $event_id, int $config_revision, string $operator_label, int $ttl = 43200 ): string {
		$now     = time();
		$payload = array(
			'v'               => self::VERSION,
			'station_uuid'    => wp_generate_uuid4(),
			'user_id'         => $user_id,
			'event_id'        => $event_id,
			'config_revision' => $config_revision,
			'operator_label'  => substr( sanitize_text_field( $operator_label ), 0, 100 ),
			'issued_at'       => $now,
			'expires_at'      => $now + max( 300, min( 86400, $ttl ) ),
			'wp_session'      => self::wordpress_session_digest(),
		);
		$encoded = self::base64url_encode( (string) wp_json_encode( $payload ) );

		return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function validate( string $token, int $user_id, int $event_id, int $config_revision ) {
		$parts = explode( '.', trim( $token ) );
		if ( 2 !== count( $parts ) ) {
			return self::error( 'oras_desk_station_invalid', 'Station session is invalid.' );
		}
		$expected = hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return self::error( 'oras_desk_station_invalid', 'Station session is invalid.' );
		}
		$decoded = self::base64url_decode( $parts[0] );
		$payload = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $payload ) || self::VERSION !== (int) ( $payload['v'] ?? 0 ) ) {
			return self::error( 'oras_desk_station_invalid', 'Station session is invalid.' );
		}
		if ( (int) ( $payload['expires_at'] ?? 0 ) < time() ) {
			return self::error( 'oras_desk_station_expired', 'Station session expired.' );
		}
		if ( $user_id !== (int) ( $payload['user_id'] ?? 0 ) || ! hash_equals( self::wordpress_session_digest(), (string) ( $payload['wp_session'] ?? '' ) ) ) {
			return self::error( 'oras_desk_station_session_changed', 'WordPress session changed. Set up this station again.' );
		}
		if ( $event_id !== (int) ( $payload['event_id'] ?? 0 ) ) {
			return self::error( 'oras_desk_station_event_changed', 'The active event changed. Set up this station again.' );
		}
		if ( $config_revision !== (int) ( $payload['config_revision'] ?? -1 ) ) {
			return self::error( 'oras_desk_station_config_changed', 'Registration Desk settings changed. Set up this station again.' );
		}

		return $payload;
	}

	private static function wordpress_session_digest(): string {
		return hash_hmac( 'sha256', (string) wp_get_session_token(), wp_salt( 'auth' ) );
	}

	private static function base64url_encode( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes a signed JSON token, not executable code.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $value ): string|false {
		$padding = strlen( $value ) % 4;
		if ( 0 !== $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a signed JSON token, not executable code.
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 401 ) );
	}
}
