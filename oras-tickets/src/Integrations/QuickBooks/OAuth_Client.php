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
        $client_id = trim( (string) ( $settings['client_id'] ?? '' ) );
        $redirect_uri = Settings::get_redirect_uri();
        $args     = array(
            'client_id'     => $client_id,
            'response_type' => 'code',
            'scope'         => 'com.intuit.quickbooks.accounting',
            'redirect_uri'  => $redirect_uri,
            'state'         => trim( $state ),
        );

        return self::AUTHORIZE_URL . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
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
            return new \WP_Error( 'oras_qbo_auth_error_refresh', 'Auth Error Refresh: QuickBooks refresh token is missing. Reconnect QuickBooks.' );
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
            $this->persist_refresh_failure_state( $response );
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
            return new \WP_Error( 'oras_qbo_auth_error_access', 'Auth Error Access: QuickBooks access token is missing. Reconnect QuickBooks.' );
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
            $error_code = is_array( $decoded ) && isset( $decoded['error'] ) ? (string) $decoded['error'] : '';
            $this->logger->error(
                'QuickBooks token request returned non-success status',
                array(
                    'status'     => $status,
                    'error_code' => $error_code,
                )
            );
            $grant_type = isset( $body['grant_type'] ) ? (string) $body['grant_type'] : '';

            return self::build_auth_error_from_response( is_array( $decoded ) ? $decoded : array(), $status, $grant_type );
        }

        return $decoded;
    }

    /**
     * Build standardized auth errors for token endpoint failures.
     *
     * @param array<string,mixed> $response_body
     */
    public static function build_auth_error_from_response( array $response_body, int $status, string $grant_type ): \WP_Error {
        $error_code = isset( $response_body['error'] ) ? (string) $response_body['error'] : '';
        $detail     = isset( $response_body['error_description'] ) ? sanitize_text_field( (string) $response_body['error_description'] ) : '';
        $grant_type = trim( $grant_type );

        if ( $grant_type === 'refresh_token' && ( $error_code === 'invalid_grant' || $status === 401 ) ) {
            $message = 'Auth Error Refresh: QuickBooks refresh token is expired or invalid. Reconnect QuickBooks.';
            if ( $detail !== '' ) {
                $message .= ' ' . $detail;
            }

            return new \WP_Error( 'oras_qbo_auth_error_refresh', $message );
        }

        if ( $error_code === 'invalid_grant' ) {
            $message = 'Auth Error Grant: QuickBooks returned invalid_grant during OAuth exchange.';
            if ( $detail !== '' ) {
                $message .= ' ' . $detail;
            }

            return new \WP_Error( 'oras_qbo_auth_error_grant', $message );
        }

        $message = 'QuickBooks token request failed.';
        if ( $detail !== '' ) {
            $message .= ' ' . $detail;
        }

        return new \WP_Error( 'oras_qbo_token_http_error', $message );
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
            'QuickBooks tokens updated successfully'
        );

        return true;
    }

    private function persist_refresh_failure_state( \WP_Error $error ): void {
        $code = $error->get_error_code();
        if ( ! in_array( $code, array( 'oras_qbo_auth_error_refresh', 'oras_qbo_auth_error_grant' ), true ) ) {
            Settings::update_quickbooks_settings(
                array(
                    'last_error' => $error->get_error_message(),
                )
            );
            return;
        }

        Settings::update_quickbooks_settings(
            array(
                'access_token'             => '',
                'refresh_token'            => '',
                'token_expires_at'         => '',
                'refresh_token_expires_at' => '',
                'connected_at'             => '',
                'last_error'               => $error->get_error_message(),
            )
        );
    }
}
