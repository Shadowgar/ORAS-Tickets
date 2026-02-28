<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Settings {

    public const OPTION_KEY = 'oras_tickets_settings_v1';
    private const ENCRYPTED_FIELDS = array(
        'client_secret',
        'access_token',
        'refresh_token',
        'realm_id',
    );
    private const ENC_PREFIX = 'orasqbo:v1:';

    /**
     * Return merged QuickBooks settings.
     *
     * @return array<string,mixed>
     */
    public static function get_quickbooks_settings(): array {
        $settings = self::get_all_settings();
        $qbo      = isset( $settings['quickbooks'] ) && is_array( $settings['quickbooks'] )
            ? $settings['quickbooks']
            : array();
        $qbo      = self::hydrate_from_storage( $qbo );

        return array_merge( self::get_quickbooks_defaults(), $qbo );
    }

    /**
     * Update only the QuickBooks branch in the plugin settings option.
     *
     * @param array<string,mixed> $partial
     */
    public static function update_quickbooks_settings( array $partial ): void {
        $settings             = self::get_all_settings();
        $existing_quickbooks  = isset( $settings['quickbooks'] ) && is_array( $settings['quickbooks'] )
            ? $settings['quickbooks']
            : array();
        $existing_quickbooks  = self::hydrate_from_storage( $existing_quickbooks );
        $merged               = array_merge( self::get_quickbooks_defaults(), $existing_quickbooks, $partial );
        $settings['quickbooks'] = self::prepare_for_storage( $merged );

        update_option( self::OPTION_KEY, $settings );
    }

    public static function is_enabled(): bool {
        $qbo = self::get_quickbooks_settings();
        return ! empty( $qbo['enabled'] );
    }

    public static function get_api_base_url(): string {
        $qbo = self::get_quickbooks_settings();
        return ! empty( $qbo['sandbox'] )
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';
    }

    public static function is_sandbox(): bool {
        $qbo = self::get_quickbooks_settings();
        return ! empty( $qbo['sandbox'] );
    }

    public static function has_explicit_encryption_key(): bool {
        if ( defined( 'ORAS_TICKETS_QBO_AES_KEY' ) ) {
            return trim( (string) ORAS_TICKETS_QBO_AES_KEY ) !== '';
        }

        return false;
    }

    public static function get_redirect_uri(): string {
        $default_uri = admin_url( 'admin-post.php?action=oras_tickets_qbo_oauth_callback' );
        $filtered    = apply_filters( 'oras_tickets_qbo_redirect_uri', $default_uri );

        return is_string( $filtered ) && $filtered !== '' ? $filtered : $default_uri;
    }

    /**
     * Parse newline account map lines in the format: event-slug=123
     *
     * @return array<string,string>
     */
    public static function parse_event_account_map( string $raw ): array {
        $map   = array();
        $lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
        if ( ! is_array( $lines ) ) {
            return $map;
        }

        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( $line === '' ) {
                continue;
            }

            $parts = explode( '=', $line, 2 );
            if ( count( $parts ) !== 2 ) {
                continue;
            }

            $slug       = sanitize_title( trim( (string) $parts[0] ) );
            $account_id = trim( sanitize_text_field( (string) $parts[1] ) );
            if ( $slug === '' || $account_id === '' ) {
                continue;
            }

            $map[ $slug ] = $account_id;
        }

        return $map;
    }

    /**
     * Resolve account ID for an event slug.
     *
     * Matching order:
     * 1) Exact slug match.
     * 2) Series prefix match where configured slug is followed by a hyphen.
     *    Example: map key "astroblast" matches "astroblast-26".
     *
     * @param array<string,string> $event_account_map
     */
    public static function resolve_event_account_id( string $event_slug, array $event_account_map ): string {
        $event_slug = sanitize_title( trim( $event_slug ) );
        if ( $event_slug === '' ) {
            return '';
        }

        if ( isset( $event_account_map[ $event_slug ] ) ) {
            return (string) $event_account_map[ $event_slug ];
        }

        foreach ( $event_account_map as $configured_slug => $account_id ) {
            $configured_slug = sanitize_title( (string) $configured_slug );
            $account_id      = trim( (string) $account_id );
            if ( $configured_slug === '' || $account_id === '' ) {
                continue;
            }

            $series_prefix = $configured_slug . '-';
            if ( strpos( $event_slug, $series_prefix ) === 0 ) {
                return $account_id;
            }
        }

        return '';
    }

    /**
     * Parse comma separated category slug list.
     *
     * @return string[]
     */
    public static function parse_slug_list( string $csv ): array {
        $items = array();
        $parts = explode( ',', $csv );
        foreach ( $parts as $part ) {
            $slug = sanitize_title( trim( (string) $part ) );
            if ( $slug !== '' ) {
                $items[] = $slug;
            }
        }

        return array_values( array_unique( $items ) );
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_quickbooks_defaults(): array {
        return array(
            'enabled'                    => false,
            'dry_run_mode'               => true,
            'require_manual_approval'    => true,
            'strict_mapping_mode'        => true,
            'allow_unmapped_fallback'    => false,
            'sandbox'                    => true,
            'client_id'                  => '',
            'client_secret'              => '',
            'realm_id'                   => '',
            'access_token'               => '',
            'refresh_token'              => '',
            'token_expires_at'           => '',
            'refresh_token_expires_at'   => '',
            'connected_at'               => '',
            'sync_cutoff_date'           => '',
            'clearing_account_id'        => '',
            'tickets_default_account_id' => '',
            'observer_account_id'        => '',
            'merchandise_account_id'     => '',
            'printful_account_id'        => '',
            'donations_account_id'       => '',
            'unmapped_account_id'        => '',
            'discount_mode'              => 'proportional',
            'observer_category_slugs'    => 'observer-pass,observer-passes',
            'merch_category_slugs'       => 'merch,merchandise,shirt,shirts,apparel',
            'printful_category_slugs'    => 'printful,pod',
            'donation_category_slugs'    => 'donation,donations,give,giving',
            'event_account_map'          => '',
            'account_cache'              => array(),
            'last_error'                 => '',
        );
    }

    /**
     * Encrypt sensitive fields before saving to persistent storage.
     *
     * @param array<string,mixed> $quickbooks
     * @return array<string,mixed>
     */
    public static function prepare_for_storage( array $quickbooks ): array {
        foreach ( self::ENCRYPTED_FIELDS as $field ) {
            if ( ! array_key_exists( $field, $quickbooks ) ) {
                continue;
            }

            $value = (string) $quickbooks[ $field ];
            if ( $value === '' ) {
                $quickbooks[ $field ] = '';
                continue;
            }

            $quickbooks[ $field ] = self::encrypt_value( $value );
        }

        return $quickbooks;
    }

    /**
     * Decrypt sensitive fields loaded from persistent storage.
     *
     * @param array<string,mixed> $quickbooks
     * @return array<string,mixed>
     */
    public static function hydrate_from_storage( array $quickbooks ): array {
        foreach ( self::ENCRYPTED_FIELDS as $field ) {
            if ( ! array_key_exists( $field, $quickbooks ) ) {
                continue;
            }

            $value = (string) $quickbooks[ $field ];
            if ( $value === '' ) {
                $quickbooks[ $field ] = '';
                continue;
            }

            $quickbooks[ $field ] = self::decrypt_value( $value );
        }

        return $quickbooks;
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_all_settings(): array {
        $settings = get_option( self::OPTION_KEY, array() );
        return is_array( $settings ) ? $settings : array();
    }

    private static function encrypt_value( string $value ): string {
        if ( strpos( $value, self::ENC_PREFIX ) === 0 ) {
            return $value;
        }

        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
            return $value;
        }

        $key = self::get_encryption_key();
        if ( $key === '' ) {
            return $value;
        }

        try {
            $iv = random_bytes( 16 );
        } catch ( \Exception $e ) {
            return $value;
        }

        $cipher_raw = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        if ( ! is_string( $cipher_raw ) || $cipher_raw === '' ) {
            return $value;
        }

        $hmac    = hash_hmac( 'sha256', $iv . $cipher_raw, $key, true );
        $payload = base64_encode( $iv . $hmac . $cipher_raw );

        return self::ENC_PREFIX . $payload;
    }

    private static function decrypt_value( string $value ): string {
        if ( strpos( $value, self::ENC_PREFIX ) !== 0 ) {
            return $value;
        }

        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }

        $encoded = substr( $value, strlen( self::ENC_PREFIX ) );
        if ( $encoded === '' ) {
            return '';
        }

        $binary = base64_decode( $encoded, true );
        if ( ! is_string( $binary ) || strlen( $binary ) <= 48 ) {
            return '';
        }

        $iv         = substr( $binary, 0, 16 );
        $hmac       = substr( $binary, 16, 32 );
        $cipher_raw = substr( $binary, 48 );

        $key       = self::get_encryption_key();
        $calc_hmac = hash_hmac( 'sha256', $iv . $cipher_raw, $key, true );
        if ( ! hash_equals( $hmac, $calc_hmac ) ) {
            return '';
        }

        $plain = openssl_decrypt( $cipher_raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        if ( ! is_string( $plain ) ) {
            return '';
        }

        return $plain;
    }

    private static function get_encryption_key(): string {
        if ( defined( 'ORAS_TICKETS_QBO_AES_KEY' ) ) {
            $configured = trim( (string) ORAS_TICKETS_QBO_AES_KEY );
            if ( $configured !== '' ) {
                return hash( 'sha256', $configured, true );
            }
        }

        $auth_key        = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
        $secure_auth_key = defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '';
        $material        = $auth_key . '|' . $secure_auth_key;
        if ( trim( $material ) === '' ) {
            return '';
        }

        return hash( 'sha256', $material, true );
    }
}
