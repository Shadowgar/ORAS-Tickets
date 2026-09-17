<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Recovery_Cursor {
	/** @param array<string,mixed> $snapshot */
	public static function issue( int $event_id, int $config_revision, int $page, int $limit, array $snapshot ): string {
		$payload = array(
			'event_id'         => $event_id,
			'config_revision'  => $config_revision,
			'page'             => max( 1, $page ),
			'limit'            => max( 1, min( 100, $limit ) ),
			'snapshot_count'   => (int) ( $snapshot['count'] ?? 0 ),
			'snapshot_highest' => (int) ( $snapshot['highest_id'] ?? 0 ),
		);
		$json = wp_json_encode( $payload );
		$body = self::encode( is_string( $json ) ? $json : '{}' );

		return $body . '.' . self::encode( hash_hmac( 'sha256', $body, wp_salt( 'auth' ), true ) );
	}

	/** @return array<string,int>|\WP_Error */
	public static function validate( string $cursor, int $event_id, int $config_revision, int $limit, array $snapshot ) {
		$parts = explode( '.', $cursor, 2 );
		if ( 2 !== count( $parts ) ) {
			return self::invalid();
		}
		$expected = self::encode( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ), true ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return self::invalid();
		}
		$decoded = self::decode( $parts[0] );
		$data    = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $data ) || (int) ( $data['event_id'] ?? 0 ) !== $event_id || (int) ( $data['config_revision'] ?? -1 ) !== $config_revision || (int) ( $data['limit'] ?? 0 ) !== $limit || (int) ( $data['page'] ?? 0 ) < 1 ) {
			return self::invalid();
		}
		if ( (int) ( $data['snapshot_count'] ?? -1 ) !== (int) ( $snapshot['count'] ?? 0 ) || (int) ( $data['snapshot_highest'] ?? -1 ) !== (int) ( $snapshot['highest_id'] ?? 0 ) ) {
			return new \WP_Error( 'oras_desk_recovery_snapshot_changed', 'Website order coverage changed. Restart recovery from the beginning.', array( 'status' => 409 ) );
		}

		return array_map( 'intval', $data );
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error( 'oras_desk_recovery_cursor_invalid', 'The recovery continuation is invalid.', array( 'status' => 400 ) );
	}

	private static function encode( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport encoding for signed cursor data.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/** @return string|false */
	private static function decode( string $value ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the verified signed cursor transport.
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}
}
