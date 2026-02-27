<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Settings {

    public const OPTION_KEY = 'oras_tickets_settings_v1';

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
        $settings['quickbooks'] = array_merge( self::get_quickbooks_defaults(), $existing_quickbooks, $partial );

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
            'sandbox'                    => true,
            'client_id'                  => '',
            'client_secret'              => '',
            'realm_id'                   => '',
            'access_token'               => '',
            'refresh_token'              => '',
            'token_expires_at'           => '',
            'refresh_token_expires_at'   => '',
            'connected_at'               => '',
            'clearing_account_id'        => '',
            'tickets_default_account_id' => '',
            'observer_account_id'        => '',
            'merchandise_account_id'     => '',
            'unmapped_account_id'        => '',
            'discount_mode'              => 'proportional',
            'observer_category_slugs'    => 'observer-pass,observer-passes',
            'merch_category_slugs'       => 'merch,merchandise,shirt,shirts,apparel',
            'event_account_map'          => '',
            'account_cache'              => array(),
            'last_error'                 => '',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_all_settings(): array {
        $settings = get_option( self::OPTION_KEY, array() );
        return is_array( $settings ) ? $settings : array();
    }
}
