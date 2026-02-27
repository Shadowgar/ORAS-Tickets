<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Api_Client {

    private OAuth_Client $oauth_client;
    private QuickBooks_Logger $logger;

    public function __construct( ?OAuth_Client $oauth_client = null, ?QuickBooks_Logger $logger = null ) {
        $this->logger       = $logger ?: new QuickBooks_Logger();
        $this->oauth_client = $oauth_client ?: new OAuth_Client( $this->logger );
    }

    public function test_connection() {
        $settings = Settings::get_quickbooks_settings();
        $realm_id = isset( $settings['realm_id'] ) ? (string) $settings['realm_id'] : '';
        if ( $realm_id === '' ) {
            return new \WP_Error( 'oras_qbo_missing_realm', 'QuickBooks realm ID is missing. Complete OAuth connection first.' );
        }

        return $this->request(
            'GET',
            'companyinfo/' . rawurlencode( $realm_id ),
            array(
                'minorversion' => 65,
            )
        );
    }

    public function fetch_accounts() {
        $query = 'SELECT Id, Name, FullyQualifiedName, AccountType, Active FROM Account WHERE Active = true ORDER BY Name';
        return $this->request(
            'GET',
            'query',
            array(
                'query'        => $query,
                'minorversion' => 65,
            )
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function create_journal_entry( array $payload ) {
        return $this->request(
            'POST',
            'journalentry',
            array(
                'minorversion' => 65,
            ),
            $payload
        );
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     */
    public function request( string $method, string $endpoint, array $query = array(), ?array $body = null ) {
        $token = $this->oauth_client->get_valid_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        return $this->request_with_token( $method, $endpoint, $query, $body, (string) $token, true );
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     */
    private function request_with_token( string $method, string $endpoint, array $query, ?array $body, string $token, bool $allow_refresh_retry ) {
        $settings = Settings::get_quickbooks_settings();
        $realm_id = isset( $settings['realm_id'] ) ? (string) $settings['realm_id'] : '';

        if ( $realm_id === '' ) {
            return new \WP_Error( 'oras_qbo_missing_realm', 'QuickBooks realm ID is missing.' );
        }

        $base_url = Settings::get_api_base_url();
        $endpoint = ltrim( $endpoint, '/' );
        $url      = $base_url . '/v3/company/' . rawurlencode( $realm_id ) . '/' . $endpoint;
        if ( ! empty( $query ) ) {
            $url = add_query_arg( $query, $url );
        }

        $args = array(
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ),
        );

        if ( $body !== null ) {
            $args['body'] = wp_json_encode( $body );
        }

        $this->logger->info(
            'QuickBooks API request',
            array(
                'method'   => $args['method'],
                'endpoint' => $endpoint,
                'query'    => $query,
            )
        );

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->logger->error(
                'QuickBooks API request failed',
                array(
                    'endpoint' => $endpoint,
                    'error'    => $response->get_error_message(),
                )
            );
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = (string) wp_remote_retrieve_body( $response );
        $data   = json_decode( $raw, true );

        if ( $status === 401 && $allow_refresh_retry ) {
            $refresh = $this->oauth_client->refresh_access_token();
            if ( ! is_wp_error( $refresh ) ) {
                $settings = Settings::get_quickbooks_settings();
                $token    = isset( $settings['access_token'] ) ? (string) $settings['access_token'] : '';
                if ( $token !== '' ) {
                    return $this->request_with_token( $method, $endpoint, $query, $body, $token, false );
                }
            }
        }

        if ( $status < 200 || $status >= 300 ) {
            $message = 'QuickBooks request failed with HTTP ' . $status . '.';
            if ( is_array( $data ) && isset( $data['Fault']['Error'][0]['Message'] ) ) {
                $message = (string) $data['Fault']['Error'][0]['Message'];
                if ( isset( $data['Fault']['Error'][0]['Detail'] ) ) {
                    $message .= ' ' . (string) $data['Fault']['Error'][0]['Detail'];
                }
            }

            $this->logger->error(
                'QuickBooks API returned error status',
                array(
                    'status'   => $status,
                    'endpoint' => $endpoint,
                    'body'     => $raw,
                )
            );

            return new \WP_Error( 'oras_qbo_api_error', $message );
        }

        if ( ! is_array( $data ) ) {
            return new \WP_Error( 'oras_qbo_invalid_json', 'QuickBooks returned invalid JSON.' );
        }

        return $data;
    }
}
