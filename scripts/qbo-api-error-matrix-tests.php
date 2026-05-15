<?php

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run this with: wp eval-file scripts/qbo-api-error-matrix-tests.php\n" );
    return;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    add_filter(
        'pre_wp_mail',
        static function ( $short_circuit, $atts ) {
            return true;
        },
        10,
        2
    );
}

if ( ! class_exists( '\\ORAS\\Tickets\\Integrations\\QuickBooks\\Api_Client' ) ) {
    throw new RuntimeException( 'QuickBooks Api_Client class is not loaded.' );
}

if ( ! class_exists( '\\ORAS\\Tickets\\Integrations\\QuickBooks\\OAuth_Client' ) ) {
    throw new RuntimeException( 'QuickBooks OAuth_Client class is not loaded.' );
}

if ( ! class_exists( '\\ORAS\\Tickets\\Integrations\\QuickBooks\\Settings' ) ) {
    throw new RuntimeException( 'QuickBooks Settings class is not loaded.' );
}

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function oras_qbo_api_assert_same( string $label, $actual, $expected ): void {
    if ( $actual !== $expected ) {
        throw new RuntimeException(
            sprintf(
                '%s failed. Expected %s, got %s',
                $label,
                var_export( $expected, true ),
                var_export( $actual, true )
            )
        );
    }
}

function oras_qbo_api_assert_true( string $label, bool $condition ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $label . ' failed.' );
    }
}

/**
 * @param array<string,mixed>|string $body
 * @param array<string,string>       $headers
 * @return array<string,mixed>
 */
function oras_qbo_api_mock_response( int $status, $body, array $headers = array() ): array {
    $rendered_body = is_string( $body ) ? $body : wp_json_encode( $body );

    return array(
        'headers'  => $headers,
        'body'     => (string) $rendered_body,
        'response' => array(
            'code'    => $status,
            'message' => 'mock',
        ),
        'cookies'  => array(),
        'filename' => null,
    );
}

/**
 * @param string[] $includes
 */
function oras_qbo_api_assert_url_contains( string $label, string $url, array $includes ): void {
    foreach ( $includes as $needle ) {
        if ( strpos( $url, $needle ) === false ) {
            throw new RuntimeException( sprintf( '%s failed. URL missing "%s": %s', $label, $needle, $url ) );
        }
    }
}

/**
 * @return array<string,mixed>
 */
function oras_qbo_api_base_settings(): array {
    return array(
        'enabled'                  => true,
        'sandbox'                  => true,
        'client_id'                => 'client-test',
        'client_secret'            => 'secret-test',
        'realm_id'                 => '9341456493205916',
        'access_token'             => 'access-test-token',
        'refresh_token'            => 'refresh-test-token',
        'token_expires_at'         => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
        'refresh_token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( 24 * HOUR_IN_SECONDS ) ),
        'connected_at'             => gmdate( 'Y-m-d H:i:s' ),
        'last_error'               => '',
    );
}

/**
 * @param array<int,array{contains:string,response:mixed}> $queue
 * @return callable
 */
function oras_qbo_api_register_http_queue( array &$queue, array &$calls ) {
    $callback = static function ( $preempt, $args, $url ) use ( &$queue, &$calls ) {
        $calls[] = array(
            'url'    => (string) $url,
            'method' => is_array( $args ) && isset( $args['method'] ) ? (string) $args['method'] : 'GET',
        );

        if ( empty( $queue ) ) {
            throw new RuntimeException( 'Unexpected HTTP request with empty queue: ' . (string) $url );
        }

        $next = array_shift( $queue );
        if ( ! is_array( $next ) ) {
            throw new RuntimeException( 'Invalid queued response fixture.' );
        }

        $contains = isset( $next['contains'] ) ? (string) $next['contains'] : '';
        if ( $contains !== '' && strpos( (string) $url, $contains ) === false ) {
            throw new RuntimeException(
                sprintf(
                    'Queued response expected URL containing "%s" but got "%s"',
                    $contains,
                    (string) $url
                )
            );
        }

        return $next['response'];
    };

    add_filter( 'pre_http_request', $callback, 10, 3 );

    return $callback;
}

$original_settings = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();
$api_client        = new \ORAS\Tickets\Integrations\QuickBooks\Api_Client();

try {
    // Scenario 1: Validation error (HTTP 400) returns deterministic WP_Error + intuit_tid.
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_api_base_settings() );
    $queue = array(
        array(
            'contains' => '/companyinfo/',
            'response' => oras_qbo_api_mock_response(
                400,
                array(
                    'Fault' => array(
                        'Error' => array(
                            array(
                                'Message' => 'ValidationFault',
                                'Detail'  => 'Request is invalid.',
                            ),
                        ),
                    ),
                ),
                array( 'intuit_tid' => 'tid-validation-400' )
            ),
        ),
    );
    $calls    = array();
    $callback = oras_qbo_api_register_http_queue( $queue, $calls );
    $result   = $api_client->test_connection();
    remove_filter( 'pre_http_request', $callback, 10 );

    oras_qbo_api_assert_true( 'validation error returns WP_Error', is_wp_error( $result ) );
    if ( is_wp_error( $result ) ) {
        oras_qbo_api_assert_same( 'validation error code', $result->get_error_code(), 'oras_qbo_api_http_400' );
        oras_qbo_api_assert_true( 'validation error message contains validation fault', strpos( $result->get_error_message(), 'ValidationFault' ) !== false );
        $data = $result->get_error_data();
        oras_qbo_api_assert_true( 'validation error data is array', is_array( $data ) );
        if ( is_array( $data ) ) {
            oras_qbo_api_assert_same( 'validation status', (int) ( $data['status'] ?? 0 ), 400 );
            oras_qbo_api_assert_same( 'validation retriable false', (bool) ( $data['retriable'] ?? true ), false );
            oras_qbo_api_assert_same( 'validation intuit_tid', (string) ( $data['intuit_tid'] ?? '' ), 'tid-validation-400' );
        }
    }

    oras_qbo_api_assert_same( 'validation queue drained', count( $queue ), 0 );
    oras_qbo_api_assert_same( 'validation call count', count( $calls ), 1 );
    oras_qbo_api_assert_url_contains( 'validation endpoint', (string) $calls[0]['url'], array( '/v3/company/', 'companyinfo/', 'minorversion=75' ) );

    // Scenario 2: Syntax-like API fault uses same 400 code path and captures intuit_tid.
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_api_base_settings() );
    $queue = array(
        array(
            'contains' => '/query',
            'response' => oras_qbo_api_mock_response(
                400,
                array(
                    'Fault' => array(
                        'Error' => array(
                            array(
                                'Message' => 'Request has invalid or unsupported property.',
                                'Detail'  => 'QueryParserError: invalid syntax near "FROMM".',
                            ),
                        ),
                    ),
                ),
                array( 'intuit_tid' => 'tid-syntax-400' )
            ),
        ),
    );
    $calls    = array();
    $callback = oras_qbo_api_register_http_queue( $queue, $calls );
    $result   = $api_client->fetch_accounts();
    remove_filter( 'pre_http_request', $callback, 10 );

    oras_qbo_api_assert_true( 'syntax error returns WP_Error', is_wp_error( $result ) );
    if ( is_wp_error( $result ) ) {
        oras_qbo_api_assert_same( 'syntax error code', $result->get_error_code(), 'oras_qbo_api_http_400' );
        oras_qbo_api_assert_true( 'syntax error detail retained', strpos( $result->get_error_message(), 'QueryParserError' ) !== false );
        $data = $result->get_error_data();
        oras_qbo_api_assert_true( 'syntax error data array', is_array( $data ) );
        if ( is_array( $data ) ) {
            oras_qbo_api_assert_same( 'syntax retriable false', (bool) ( $data['retriable'] ?? true ), false );
            oras_qbo_api_assert_same( 'syntax intuit_tid', (string) ( $data['intuit_tid'] ?? '' ), 'tid-syntax-400' );
        }
    }
    oras_qbo_api_assert_url_contains( 'syntax endpoint', (string) $calls[0]['url'], array( '/query', 'minorversion=75' ) );

    // Scenario 2b: JournalEntry writes use the shared supported minor version.
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_api_base_settings() );
    $queue = array(
        array(
            'contains' => '/journalentry',
            'response' => oras_qbo_api_mock_response(
                200,
                array(
                    'JournalEntry' => array(
                        'Id' => 'JE-1',
                    ),
                )
            ),
        ),
    );
    $calls    = array();
    $callback = oras_qbo_api_register_http_queue( $queue, $calls );
    $result   = $api_client->create_journal_entry(array('Line' => array()));
    remove_filter( 'pre_http_request', $callback, 10 );

    oras_qbo_api_assert_true( 'journal entry create returns array', is_array( $result ) );
    oras_qbo_api_assert_url_contains( 'journal entry endpoint', (string) $calls[0]['url'], array( '/journalentry', 'minorversion=75' ) );

    // Scenario 3: 429 is marked retriable.
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_api_base_settings() );
    $queue = array(
        array(
            'contains' => '/query',
            'response' => oras_qbo_api_mock_response(
                429,
                array(
                    'Fault' => array(
                        'Error' => array(
                            array(
                                'Message' => 'Rate limit exceeded',
                                'Detail'  => 'Too many requests.',
                            ),
                        ),
                    ),
                ),
                array( 'intuit_tid' => 'tid-429' )
            ),
        ),
    );
    $calls    = array();
    $callback = oras_qbo_api_register_http_queue( $queue, $calls );
    $result   = $api_client->fetch_accounts();
    remove_filter( 'pre_http_request', $callback, 10 );

    oras_qbo_api_assert_true( '429 returns WP_Error', is_wp_error( $result ) );
    if ( is_wp_error( $result ) ) {
        oras_qbo_api_assert_same( '429 code', $result->get_error_code(), 'oras_qbo_api_http_429' );
        $data = $result->get_error_data();
        oras_qbo_api_assert_true( '429 data array', is_array( $data ) );
        if ( is_array( $data ) ) {
            oras_qbo_api_assert_same( '429 retriable true', (bool) ( $data['retriable'] ?? false ), true );
            oras_qbo_api_assert_same( '429 intuit_tid', (string) ( $data['intuit_tid'] ?? '' ), 'tid-429' );
        }
    }

    // Scenario 4: 503 is marked retriable.
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_api_base_settings() );
    $queue = array(
        array(
            'contains' => '/query',
            'response' => oras_qbo_api_mock_response(
                503,
                array(
                    'Fault' => array(
                        'Error' => array(
                            array(
                                'Message' => 'System temporarily unavailable',
                                'Detail'  => 'Please retry later.',
                            ),
                        ),
                    ),
                ),
                array( 'intuit_tid' => 'tid-503' )
            ),
        ),
    );
    $calls    = array();
    $callback = oras_qbo_api_register_http_queue( $queue, $calls );
    $result   = $api_client->fetch_accounts();
    remove_filter( 'pre_http_request', $callback, 10 );

    oras_qbo_api_assert_true( '503 returns WP_Error', is_wp_error( $result ) );
    if ( is_wp_error( $result ) ) {
        oras_qbo_api_assert_same( '503 code', $result->get_error_code(), 'oras_qbo_api_http_503' );
        $data = $result->get_error_data();
        oras_qbo_api_assert_true( '503 data array', is_array( $data ) );
        if ( is_array( $data ) ) {
            oras_qbo_api_assert_same( '503 retriable true', (bool) ( $data['retriable'] ?? false ), true );
            oras_qbo_api_assert_same( '503 intuit_tid', (string) ( $data['intuit_tid'] ?? '' ), 'tid-503' );
        }
    }

    // Scenario 5: Invalid JSON response is handled as non-retriable parse failure with intuit_tid.
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_api_base_settings() );
    $queue = array(
        array(
            'contains' => '/companyinfo/',
            'response' => oras_qbo_api_mock_response( 200, '{invalid_json', array( 'intuit_tid' => 'tid-json' ) ),
        ),
    );
    $calls    = array();
    $callback = oras_qbo_api_register_http_queue( $queue, $calls );
    $result   = $api_client->test_connection();
    remove_filter( 'pre_http_request', $callback, 10 );

    oras_qbo_api_assert_true( 'invalid json returns WP_Error', is_wp_error( $result ) );
    if ( is_wp_error( $result ) ) {
        oras_qbo_api_assert_same( 'invalid json code', $result->get_error_code(), 'oras_qbo_invalid_json' );
        $data = $result->get_error_data();
        oras_qbo_api_assert_true( 'invalid json data array', is_array( $data ) );
        if ( is_array( $data ) ) {
            oras_qbo_api_assert_same( 'invalid json retriable false', (bool) ( $data['retriable'] ?? true ), false );
            oras_qbo_api_assert_same( 'invalid json intuit_tid', (string) ( $data['intuit_tid'] ?? '' ), 'tid-json' );
        }
    }

    // Scenario 6: Network transport errors are marked retriable with endpoint metadata.
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_api_base_settings() );
    $queue = array(
        array(
            'contains' => '/companyinfo/',
            'response' => new WP_Error( 'http_request_failed', 'cURL error 28: operation timed out' ),
        ),
    );
    $calls    = array();
    $callback = oras_qbo_api_register_http_queue( $queue, $calls );
    $result   = $api_client->test_connection();
    remove_filter( 'pre_http_request', $callback, 10 );

    oras_qbo_api_assert_true( 'network failure returns WP_Error', is_wp_error( $result ) );
    if ( is_wp_error( $result ) ) {
        oras_qbo_api_assert_same( 'network error code', $result->get_error_code(), 'http_request_failed' );
        $data = $result->get_error_data();
        oras_qbo_api_assert_true( 'network data array', is_array( $data ) );
        if ( is_array( $data ) ) {
            oras_qbo_api_assert_same( 'network retriable true', (bool) ( $data['retriable'] ?? false ), true );
            oras_qbo_api_assert_true( 'network endpoint metadata present', isset( $data['endpoint'] ) );
        }
    }

    // Scenario 7: 401 triggers refresh and second attempt succeeds.
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_api_base_settings() );
    $queue = array(
        array(
            'contains' => '/companyinfo/',
            'response' => oras_qbo_api_mock_response(
                401,
                array(
                    'Fault' => array(
                        'Error' => array(
                            array(
                                'Message' => 'AuthenticationFailed',
                            ),
                        ),
                    ),
                ),
                array( 'intuit_tid' => 'tid-401-initial' )
            ),
        ),
        array(
            'contains' => 'oauth2/v1/tokens/bearer',
            'response' => oras_qbo_api_mock_response(
                200,
                array(
                    'access_token'               => 'access-token-refreshed',
                    'refresh_token'              => 'refresh-token-refreshed',
                    'expires_in'                 => 3600,
                    'x_refresh_token_expires_in' => 86400,
                )
            ),
        ),
        array(
            'contains' => '/companyinfo/',
            'response' => oras_qbo_api_mock_response(
                200,
                array(
                    'CompanyInfo' => array(
                        'Id' => '1',
                    ),
                ),
                array( 'intuit_tid' => 'tid-401-retry-success' )
            ),
        ),
    );
    $calls    = array();
    $callback = oras_qbo_api_register_http_queue( $queue, $calls );
    $result   = $api_client->test_connection();
    remove_filter( 'pre_http_request', $callback, 10 );

    oras_qbo_api_assert_true( '401 refresh success returns array', is_array( $result ) );
    if ( is_array( $result ) ) {
        oras_qbo_api_assert_true( '401 refresh success contains company info', isset( $result['CompanyInfo']['Id'] ) );
        $meta = isset( $result['__oras_meta'] ) && is_array( $result['__oras_meta'] ) ? $result['__oras_meta'] : array();
        oras_qbo_api_assert_same( '401 retry success intuit_tid', (string) ( $meta['intuit_tid'] ?? '' ), 'tid-401-retry-success' );
    }

    oras_qbo_api_assert_same( '401 refresh success call count', count( $calls ), 3 );
    oras_qbo_api_assert_url_contains( '401 refresh token endpoint called', (string) $calls[1]['url'], array( 'oauth2/v1/tokens/bearer' ) );

    $settings_after_refresh = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();
    oras_qbo_api_assert_same( 'refreshed access token persisted', (string) ( $settings_after_refresh['access_token'] ?? '' ), 'access-token-refreshed' );

    // Scenario 8: 401 + refresh invalid_grant returns Auth Error Access with non-retriable metadata.
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( oras_qbo_api_base_settings() );
    $queue = array(
        array(
            'contains' => '/companyinfo/',
            'response' => oras_qbo_api_mock_response(
                401,
                array(
                    'Fault' => array(
                        'Error' => array(
                            array(
                                'Message' => 'AuthenticationFailed',
                            ),
                        ),
                    ),
                ),
                array( 'intuit_tid' => 'tid-401-refresh-fail' )
            ),
        ),
        array(
            'contains' => 'oauth2/v1/tokens/bearer',
            'response' => oras_qbo_api_mock_response(
                400,
                array(
                    'error'             => 'invalid_grant',
                    'error_description' => 'refresh token expired',
                )
            ),
        ),
    );
    $calls    = array();
    $callback = oras_qbo_api_register_http_queue( $queue, $calls );
    $result   = $api_client->test_connection();
    remove_filter( 'pre_http_request', $callback, 10 );

    oras_qbo_api_assert_true( '401 refresh invalid_grant returns WP_Error', is_wp_error( $result ) );
    if ( is_wp_error( $result ) ) {
        oras_qbo_api_assert_same( '401 refresh invalid_grant code', $result->get_error_code(), 'oras_qbo_auth_error_access' );
        oras_qbo_api_assert_true( '401 refresh invalid_grant message label', strpos( $result->get_error_message(), 'Auth Error Access' ) !== false );
        $data = $result->get_error_data();
        oras_qbo_api_assert_true( '401 refresh invalid_grant data array', is_array( $data ) );
        if ( is_array( $data ) ) {
            oras_qbo_api_assert_same( '401 refresh invalid_grant retriable false', (bool) ( $data['retriable'] ?? true ), false );
            oras_qbo_api_assert_same( '401 refresh invalid_grant intuit_tid', (string) ( $data['intuit_tid'] ?? '' ), 'tid-401-refresh-fail' );
        }
    }

    $settings_after_refresh_fail = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();
    oras_qbo_api_assert_same( 'refresh failure clears access token', (string) ( $settings_after_refresh_fail['access_token'] ?? '' ), '' );
    oras_qbo_api_assert_same( 'refresh failure clears refresh token', (string) ( $settings_after_refresh_fail['refresh_token'] ?? '' ), '' );

    // Scenario 9: OAuth grant/refresh error label mapping remains deterministic.
    $grant_error = \ORAS\Tickets\Integrations\QuickBooks\OAuth_Client::build_auth_error_from_response(
        array(
            'error'             => 'invalid_grant',
            'error_description' => 'authorization code expired',
        ),
        400,
        'authorization_code'
    );
    oras_qbo_api_assert_same( 'grant mapping code', $grant_error->get_error_code(), 'oras_qbo_auth_error_grant' );
    oras_qbo_api_assert_true( 'grant mapping label', strpos( $grant_error->get_error_message(), 'Auth Error Grant' ) !== false );

    $refresh_error = \ORAS\Tickets\Integrations\QuickBooks\OAuth_Client::build_auth_error_from_response(
        array(
            'error'             => 'invalid_grant',
            'error_description' => 'refresh token revoked',
        ),
        401,
        'refresh_token'
    );
    oras_qbo_api_assert_same( 'refresh mapping code', $refresh_error->get_error_code(), 'oras_qbo_auth_error_refresh' );
    oras_qbo_api_assert_true( 'refresh mapping label', strpos( $refresh_error->get_error_message(), 'Auth Error Refresh' ) !== false );

    echo "QBO API error matrix tests passed.\n";
} finally {
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( $original_settings );
}
