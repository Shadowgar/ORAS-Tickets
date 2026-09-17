<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Store {
	protected string $table;

	public function __construct( string $table_key ) {
		$tables      = Schema::table_names();
		$this->table = $tables[ $table_key ];
	}

	public function table_name(): string {
		return $this->table;
	}

	public static function uuid(): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::fallback_uuid();
	}

	public static function utc_now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** @template T @param callable():T $callback @return T|\WP_Error */
	public static function transaction( callable $callback ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'oras_desk_transaction_unavailable', 'Registration Desk could not start a database transaction.' );
		}

		try {
			$result = $callback();
			if ( $result instanceof \WP_Error ) {
				$wpdb->query( 'ROLLBACK' );
				return $result;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'oras_desk_commit_failed', 'Registration Desk could not commit the operation.' );
			}

			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'oras_desk_transaction_failed', $error->getMessage() );
		}
	}

	private static function fallback_uuid(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}
}
