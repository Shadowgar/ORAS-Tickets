<?php

namespace ORAS\Tickets\Integrations\Zoom;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public const OPTION_KEY = 'oras_tickets_settings_v1';

	private const ENCRYPTED_FIELDS = array( 'client_secret' );
	private const ENC_PREFIX       = 'oraszoom:v1:';

	/**
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$all  = get_option( self::OPTION_KEY, array() );
		$all  = is_array( $all ) ? $all : array();
		$zoom = isset( $all['zoom'] ) && is_array( $all['zoom'] ) ? $all['zoom'] : array();

		return array_merge( self::defaults(), self::hydrate_from_storage( $zoom ) );
	}

	/**
	 * @param array<string,mixed> $partial
	 */
	public static function update( array $partial ): void {
		$all      = get_option( self::OPTION_KEY, array() );
		$all      = is_array( $all ) ? $all : array();
		$existing = isset( $all['zoom'] ) && is_array( $all['zoom'] )
			? self::hydrate_from_storage( $all['zoom'] )
			: array();
		$merged   = array_merge( self::defaults(), $existing, $partial );

		$all['zoom'] = self::prepare_for_storage( $merged );
		update_option( self::OPTION_KEY, $all );
	}

	public static function is_enabled(): bool {
		$settings = self::get();
		return ! empty( $settings['enabled'] );
	}

	public static function has_credentials(): bool {
		$settings = self::get();

		return '' !== trim( (string) $settings['account_id'] )
			&& '' !== trim( (string) $settings['client_id'] )
			&& '' !== trim( (string) $settings['client_secret'] );
	}

	public static function has_explicit_encryption_key(): bool {
		return defined( 'ORAS_TICKETS_ZOOM_AES_KEY' )
			&& '' !== trim( (string) ORAS_TICKETS_ZOOM_AES_KEY );
	}

	public static function protect_private_value( string $value ): string {
		if ( '' === $value || 0 === strpos( $value, self::ENC_PREFIX ) ) {
			return $value;
		}

		return self::encrypt_value( $value );
	}

	public static function reveal_private_value( string $value ): string {
		if ( 0 !== strpos( $value, self::ENC_PREFIX ) ) {
			return $value;
		}

		return self::decrypt_value( $value );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'                      => false,
			'account_id'                   => '',
			'client_id'                    => '',
			'client_secret'                => '',
			'default_managed_registration' => false,
			'last_connection_test_at'      => '',
			'last_error'                   => '',
		);
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public static function prepare_for_storage( array $settings ): array {
		foreach ( self::ENCRYPTED_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $settings ) ) {
				continue;
			}

			$value = (string) $settings[ $field ];
			if ( '' === $value || 0 === strpos( $value, self::ENC_PREFIX ) ) {
				$settings[ $field ] = $value;
				continue;
			}

			$settings[ $field ] = self::encrypt_value( $value );
		}

		return $settings;
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public static function hydrate_from_storage( array $settings ): array {
		foreach ( self::ENCRYPTED_FIELDS as $field ) {
			if ( ! isset( $settings[ $field ] ) ) {
				continue;
			}

			$value = (string) $settings[ $field ];
			$settings[ $field ] = 0 === strpos( $value, self::ENC_PREFIX )
				? self::decrypt_value( $value )
				: $value;
		}

		return $settings;
	}

	private static function encryption_key(): string {
		$material = self::has_explicit_encryption_key()
			? (string) constant( 'ORAS_TICKETS_ZOOM_AES_KEY' )
			: wp_salt( 'auth' );

		return hash( 'sha256', $material, true );
	}

	private static function encrypt_value( string $value ): string {
		$iv = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt(
			$value,
			'aes-256-gcm',
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $ciphertext ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary authenticated-encryption envelope.
		return self::ENC_PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	private static function decrypt_value( string $value ): string {
		$encoded = substr( $value, strlen( self::ENC_PREFIX ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary authenticated-encryption envelope.
		$payload = base64_decode( $encoded, true );
		if ( false === $payload || strlen( $payload ) < 29 ) {
			return '';
		}

		$iv         = substr( $payload, 0, 12 );
		$tag        = substr( $payload, 12, 16 );
		$ciphertext = substr( $payload, 28 );
		$plaintext  = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			self::encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return false === $plaintext ? '' : $plaintext;
	}
}
