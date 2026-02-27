<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OAuth_Client {

    private const AUTHORIZE_URL = 'https://appcenter.intuit.com/connect/oauth2';
    private const TOKEN_URL     = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    private QuickBooks_Logger $logger;

    public function __construct( ?QuickBooks_Logger $logger = null ) {
        $this->logger = $logger ?: new QuickBooks_Logger();
    }

    public function has_client_credentials(): bool {
        $settings = Settings::get_quickbooks_settings();
        return ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] );
    }

    public function get_authorize_url( string $state ): string {
        $settings = Settings::get_quickbooks_settings();
        $args     = array(
            'client_id'     => (string) $settings['client_id'],
            'response_type' => 'code',
            'scope'         => 'com.intuit.quickbooks.accounting',
            'redirect_uri'  => Settings::get_redirect_uri(),
            'state'         => $state,
        );

        return add_query_arg( $args, self::AUTHORIZE_URL );
    }

    /**
     * Exchange OAuth authorization code for access/refresh tokens.
     */
    public function exchange_code( string $code, string $realm_id ) {
        $settings = Settings::get_quickbooks_settings();
        if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
            return new \WP_Error( 'oras_qbo_missing_credentials', 'QuickBooks client ID/secret are not configured.' );
        }

        $response = $this->request_token(
            array(
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => Settings::get_redirect_uri(),
            ),
            (string) $settings['client_id'],
            (string) $settings['client_secret']
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->persist_token_response( $response, $realm_id );
    }

    /**
     * Refresh tokens using the stored refresh token.
     */
    public function refresh_access_token() {
        $settings = Settings::get_quickbooks_settings();
        if ( empty( $settings['refresh_token'] ) ) {
            return new \WP_Error( 'oras_qbo_missing_refresh_token', 'QuickBooks refresh token is missing.' );
        }

        if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
            return new \WP_Error( 'oras_qbo_missing_credentials', 'QuickBooks client ID/secret are not configured.' );
        }

        $response = $this->request_token(
            array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => (string) $settings['refresh_token'],
            ),
            (string) $settings['client_id'],
            (string) $settings['client_secret']
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->persist_token_response( $response, (string) $settings['realm_id'] );
    }

    /**
     * Return a valid access token, refreshing if needed.
     */
    public function get_valid_access_token() {
        $settings = Settings::get_quickbooks_settings();
        if ( empty( $settings['access_token'] ) ) {
            return new \WP_Error( 'oras_qbo_missing_access_token', 'QuickBooks access token is missing. Reconnect QuickBooks.' );
        }

        $expires_at = isset( $settings['token_expires_at'] ) ? (string) $settings['token_expires_at'] : '';
        if ( $expires_at !== '' ) {
            $expires_at_ts = strtotime( $expires_at );
            if ( $expires_at_ts !== false && $expires_at_ts <= ( time() + 60 ) ) {
                $refresh = $this->refresh_access_token();
                if ( is_wp_error( $refresh ) ) {
                    return $refresh;
                }
                $settings = Settings::get_quickbooks_settings();
            }
        }

        return (string) $settings['access_token'];
    }

    /**
     * @param array<string,string> $body
     */
    private function request_token( array $body, string $client_id, string $client_secret ) {
        $basic_auth = base64_encode( $client_id . ':' . $client_secret );
        $response   = wp_remote_post(
            self::TOKEN_URL,
            array(
                'timeout' => 25,
                'headers' => array(
                    'Authorization' => 'Basic ' . $basic_auth,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ),
                'body'    => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->logger->error(
                'QuickBooks token request failed',
                array(
                    'error' => $response->get_error_message(),
                )
            );
            return $response;
        }

        $status  = (int) wp_remote_retrieve_response_code( $response );
        $raw     = (string) wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );

        if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) ) {
            $this->logger->error(
                'QuickBooks token request returned non-success status',
                array(
                    'status' => $status,
                    'body'   => $raw,
                )
            );
            return new \WP_Error( 'oras_qbo_token_http_error', 'QuickBooks token request failed.' );
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $token_response
     */
    private function persist_token_response( array $token_response, string $realm_id ) {
        $access_token  = isset( $token_response['access_token'] ) ? (string) $token_response['access_token'] : '';
        $refresh_token = isset( $token_response['refresh_token'] ) ? (string) $token_response['refresh_token'] : '';
        $expires_in    = isset( $token_response['expires_in'] ) ? (int) $token_response['expires_in'] : 0;
        $refresh_in    = isset( $token_response['x_refresh_token_expires_in'] ) ? (int) $token_response['x_refresh_token_expires_in'] : 0;

        if ( $access_token === '' || $refresh_token === '' ) {
            return new \WP_Error( 'oras_qbo_invalid_token_response', 'QuickBooks token response is missing required fields.' );
        }

        $updates = array(
            'access_token'             => $access_token,
            'refresh_token'            => $refresh_token,
            'realm_id'                 => sanitize_text_field( $realm_id ),
            'token_expires_at'         => $expires_in > 0 ? gmdate( 'Y-m-d H:i:s', time() + $expires_in ) : '',
            'refresh_token_expires_at' => $refresh_in > 0 ? gmdate( 'Y-m-d H:i:s', time() + $refresh_in ) : '',
            'connected_at'             => gmdate( 'Y-m-d H:i:s' ),
            'last_error'               => '',
        );
        Settings::update_quickbooks_settings( $updates );

        $this->logger->info(
            'QuickBooks tokens updated successfully',
            array(
                'realm_id' => sanitize_text_field( $realm_id ),
            )
        );

        return true;
    }
}
