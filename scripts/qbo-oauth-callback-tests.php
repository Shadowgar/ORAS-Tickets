<?php

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run this with: wp eval-file scripts/qbo-oauth-callback-tests.php\n" );
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

if ( ! class_exists( '\\ORAS\\Tickets\\Integrations\\QuickBooks\\Module' ) ) {
    throw new RuntimeException( 'QuickBooks Module class not loaded.' );
}

if ( ! class_exists( '\\ORAS\\Tickets\\Integrations\\QuickBooks\\Settings' ) ) {
    throw new RuntimeException( 'QuickBooks Settings class not loaded.' );
}

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function oras_qbo_oauth_assert_same( string $label, $actual, $expected ): void {
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

function oras_qbo_oauth_assert_true( string $label, bool $condition ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $label . ' failed.' );
    }
}

// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- CLI test exception is intentionally colocated with helper functions.
final class ORAS_QBO_Redirect_Intercept_Exception extends RuntimeException {}

/**
 * @return array{user_id:int,module:\ORAS\Tickets\Integrations\QuickBooks\Module}
 */
function oras_qbo_oauth_test_bootstrap(): array {
    $users = get_users(
        array(
            'number' => 1,
            'fields' => array( 'ID' ),
        )
    );

    if ( empty( $users ) || ! isset( $users[0]->ID ) ) {
        throw new RuntimeException( 'No WordPress user available for OAuth callback tests.' );
    }

    $user_id = (int) $users[0]->ID;
    wp_set_current_user( $user_id );

    return array(
        'user_id' => $user_id,
        'module'  => new \ORAS\Tickets\Integrations\QuickBooks\Module(),
    );
}

/**
 * @param array<string,string>      $query
 * @param callable(int):void|null   $setup
 */
function oras_qbo_oauth_run_case( string $label, array $query, string $expected_error, ?callable $setup = null ): void {
    $boot    = oras_qbo_oauth_test_bootstrap();
    $user_id = (int) $boot['user_id'];
    $module  = $boot['module'];

    if ( $setup !== null ) {
        $setup( $user_id );
    }

    $captured_url  = '';
    $captured_args = array();

    $redirect_action = static function ( string $url, array $args ) use ( &$captured_url, &$captured_args ): void {
        $captured_url  = $url;
        $captured_args = $args;
        throw new ORAS_QBO_Redirect_Intercept_Exception( 'redirect_intercepted' );
    };

    add_action( 'oras_tickets_qbo_redirecting', $redirect_action, 10, 2 );

    $_GET = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

    try {
        try {
            $module->handle_oauth_callback();
            throw new RuntimeException( $label . ' did not trigger redirect interception.' );
        } catch ( ORAS_QBO_Redirect_Intercept_Exception $e ) {
            // Expected path for callback guards.
        }
    } finally {
        remove_action( 'oras_tickets_qbo_redirecting', $redirect_action, 10 );
    }

    $settings   = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();
    $last_error = isset( $settings['last_error'] ) ? (string) $settings['last_error'] : '';

    oras_qbo_oauth_assert_same( $label . ' sets expected last_error', $last_error, $expected_error );
    oras_qbo_oauth_assert_true( $label . ' captured redirect url', $captured_url !== '' );
    oras_qbo_oauth_assert_true( $label . ' redirect query page is set', strpos( $captured_url, 'page=oras-tickets-quickbooks' ) !== false );

    $redirect_error = isset( $captured_args['oras_qbo_error'] ) ? rawurldecode( (string) $captured_args['oras_qbo_error'] ) : '';
    oras_qbo_oauth_assert_same( $label . ' redirect includes expected error', $redirect_error, $expected_error );
}

/**
 * Invoke an admin handler while intercepting both local and external redirects.
 *
 * @param array<string,string> $post
 * @param array<string,string> $query
 * @return array{result:mixed,redirect_url:string}
 */
function oras_qbo_oauth_invoke_handler( string $method, string $nonce_action, array $post = array(), array $query = array() ): array {
	$boot = oras_qbo_oauth_test_bootstrap();
	$module = $boot['module'];
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- This helper constructs nonce-authenticated synthetic admin requests.
	$previous_post = $_POST;
	$previous_get = $_GET;
	$previous_request = $_REQUEST;
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	$redirect_url = '';
	$stop = static function ( string $url ) use ( &$redirect_url ): void {
		$redirect_url = $url;
		throw new ORAS_QBO_Redirect_Intercept_Exception( 'redirect_intercepted' );
	};
	$external_stop = static function ( string $location ) use ( &$redirect_url ): string {
		$redirect_url = $location;
		throw new ORAS_QBO_Redirect_Intercept_Exception( 'redirect_intercepted' );
	};

	if ( $nonce_action !== '' ) {
		$post['_wpnonce'] = wp_create_nonce( $nonce_action );
	}
	$_POST = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$_GET = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$_REQUEST = array_merge( $query, $post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	add_action( 'oras_tickets_qbo_redirecting', $stop, 1, 2 );
	add_filter( 'wp_redirect', $external_stop, 1, 2 );
	try {
		try {
			$result = $module->{$method}();
		} catch ( ORAS_QBO_Redirect_Intercept_Exception $exception ) {
			if ( $exception->getMessage() !== 'redirect_intercepted' ) {
				throw $exception;
			}
		}
	} finally {
		remove_action( 'oras_tickets_qbo_redirecting', $stop, 1 );
		remove_filter( 'wp_redirect', $external_stop, 1 );
		$_POST = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_GET = $previous_get; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_REQUEST = $previous_request; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	return array(
		'result'       => $result ?? null,
		'redirect_url' => $redirect_url,
	);
}

$original_get      = $_GET;
$original_settings = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();

try {
	\ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
		array(
			'enabled'      => true,
			'dry_run_mode' => false,
			'sandbox'      => true,
		)
	);

    // Case 1: missing state.
    oras_qbo_oauth_run_case(
        'missing-state',
        array(
            'code'    => 'sample-code',
            'realmId' => '123456',
        ),
        'CSRF Error: missing OAuth state parameter.'
    );

    // Case 2: missing grant fields.
    oras_qbo_oauth_run_case(
        'missing-grant-fields',
        array(
            'state' => 'state-without-code-and-realm',
        ),
        'Auth Error Grant: QuickBooks OAuth callback is missing required grant fields.'
    );

    // Case 3: state validation failure (no transient).
    $validation_state = 'state-no-transient-' . wp_generate_password( 8, false, false );
    oras_qbo_oauth_run_case(
        'state-validation-failed',
        array(
            'state'   => $validation_state,
            'code'    => 'sample-code',
            'realmId' => '123456',
        ),
        'CSRF Error: QuickBooks OAuth state validation failed.'
    );

    // Case 4: state owner mismatch.
    $mismatch_state = 'state-owner-mismatch-' . wp_generate_password( 8, false, false );
    oras_qbo_oauth_run_case(
        'state-owner-mismatch',
        array(
            'state'   => $mismatch_state,
            'code'    => 'sample-code',
            'realmId' => '123456',
        ),
        'CSRF Error: QuickBooks OAuth state owner mismatch.',
        static function ( int $user_id ) use ( $mismatch_state ): void {
            // Store a different owner than current user to force mismatch.
            set_transient( 'oras_tickets_qbo_state_' . $mismatch_state, $user_id + 999, 15 * MINUTE_IN_SECONDS );
        }
    );

    oras_qbo_oauth_assert_same(
        'state-owner-mismatch transient deleted after callback',
        get_transient( 'oras_tickets_qbo_state_' . $mismatch_state ),
        false
    );

	// Dry-run applies to the complete OAuth/admin surface, including failures.
	$dry_settings = array(
		'enabled'       => false,
		'dry_run_mode'  => true,
		'sandbox'       => true,
		'client_id'     => 'dry-existing-client',
		'client_secret' => 'dry-existing-secret',
		'realm_id'      => 'dry-existing-realm',
		'access_token'  => 'dry-existing-access',
		'refresh_token' => 'dry-existing-refresh',
		'last_error'    => 'dry-existing-error',
	);
	ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( $dry_settings );
	$dry_state = 'dry-state-' . wp_generate_password( 8, false, false );
	set_transient( 'oras_tickets_qbo_state_' . $dry_state, get_current_user_id(), 15 * MINUTE_IN_SECONDS );
	$dry_option_before = get_option( ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY );
	$dry_transient_before = get_transient( 'oras_tickets_qbo_state_' . $dry_state );
	$dry_http_calls = 0;
	$dry_option_writes = 0;
	$dry_log_writes = 0;
	$dry_http_spy = static function () use ( &$dry_http_calls ): WP_Error {
		++$dry_http_calls;
		return new WP_Error( 'unexpected_http', 'Dry-run administration must not use HTTP.' );
	};
	$dry_option_spy = static function ( $value, string $option ) use ( &$dry_option_writes ) {
		if ( $option === ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY
			|| strpos( $option, '_transient_oras_tickets_qbo_state_' ) !== false ) {
			++$dry_option_writes;
		}
		return $value;
	};
	$dry_log_spy = static function ( string $message ) use ( &$dry_log_writes ): string {
		++$dry_log_writes;
		return $message;
	};
	add_filter( 'pre_http_request', $dry_http_spy, 1, 3 );
	add_filter( 'pre_update_option', $dry_option_spy, 1, 3 );
	add_filter( 'woocommerce_logger_log_message', $dry_log_spy, 1, 3 );
	try {
		$dry_start = oras_qbo_oauth_invoke_handler(
			'handle_oauth_start',
			'oras_tickets_qbo_oauth_start',
			array(
				'oras_qbo_client_id'     => 'must-not-be-persisted',
				'oras_qbo_client_secret' => 'must-not-be-persisted',
			)
		);
		$dry_callback = oras_qbo_oauth_invoke_handler(
			'handle_oauth_callback',
			'',
			array(),
			array(
				'state'   => $dry_state,
				'code'    => 'must-not-be-exchanged',
				'realmId' => 'must-not-be-persisted',
			)
		);
		$dry_callback_error = oras_qbo_oauth_invoke_handler( 'handle_oauth_callback', '', array(), array() );
		$dry_connection = oras_qbo_oauth_invoke_handler(
			'handle_test_connection',
			'oras_tickets_qbo_test_connection'
		);
		$dry_mapping = oras_qbo_oauth_invoke_handler(
			'handle_auto_map_event_accounts',
			'oras_tickets_qbo_auto_map_event_accounts'
		);
	} finally {
		remove_filter( 'pre_http_request', $dry_http_spy, 1 );
		remove_filter( 'pre_update_option', $dry_option_spy, 1 );
		remove_filter( 'woocommerce_logger_log_message', $dry_log_spy, 1 );
	}

	foreach (
		array(
			'OAuth start'          => $dry_start,
			'OAuth callback'       => $dry_callback,
			'OAuth callback error' => $dry_callback_error,
			'connection test'      => $dry_connection,
			'automatic mapping'    => $dry_mapping,
		) as $dry_label => $dry_result
	) {
		oras_qbo_oauth_assert_same( $dry_label . ' performs no redirect', (string) $dry_result['redirect_url'], '' );
		oras_qbo_oauth_assert_true( $dry_label . ' returns a local dry-run result', is_array( $dry_result['result'] ) );
		oras_qbo_oauth_assert_same( $dry_label . ' local result status', (string) ( $dry_result['result']['status'] ?? '' ), 'dry_run_skipped' );
		oras_qbo_oauth_assert_true( $dry_label . ' local result explains dry-run skip', strpos( (string) ( $dry_result['result']['message'] ?? '' ), 'dry-run' ) !== false );
	}
	oras_qbo_oauth_assert_same( 'dry-run admin HTTP count', $dry_http_calls, 0 );
	oras_qbo_oauth_assert_same( 'dry-run admin option/transient write count', $dry_option_writes, 0 );
	oras_qbo_oauth_assert_same( 'dry-run admin WooCommerce log count', $dry_log_writes, 0 );
	oras_qbo_oauth_assert_same( 'dry-run admin settings remain byte-for-byte unchanged', get_option( ORAS\Tickets\Integrations\QuickBooks\Settings::OPTION_KEY ), $dry_option_before );
	oras_qbo_oauth_assert_same( 'dry-run OAuth callback preserves state transient', get_transient( 'oras_tickets_qbo_state_' . $dry_state ), $dry_transient_before );

	// Dry-run toggled by the mocked first request blocks every subsequent
	// connection-test write and account request, including the error branch.
	ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
		array_merge(
			$dry_settings,
			array(
				'dry_run_mode'     => false,
				'enabled'          => true,
				'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			)
		)
	);
	$race_http_calls = 0;
	$race_http = static function () use ( &$race_http_calls ): WP_Error {
		++$race_http_calls;
		ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( array( 'dry_run_mode' => true ) );
		return new WP_Error( 'http_request_failed', 'Injected connection failure while dry-run became enabled.' );
	};
	add_filter( 'pre_http_request', $race_http, 1, 3 );
	try {
		$race_result = oras_qbo_oauth_invoke_handler(
			'handle_test_connection',
			'oras_tickets_qbo_test_connection'
		);
	} finally {
		remove_filter( 'pre_http_request', $race_http, 1 );
	}
	$race_settings = ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();
	oras_qbo_oauth_assert_same( 'connection race performs only the already-started request', $race_http_calls, 1 );
	oras_qbo_oauth_assert_same( 'connection race preserves last_error', (string) $race_settings['last_error'], 'dry-existing-error' );
	oras_qbo_oauth_assert_same( 'connection race performs no redirect', (string) $race_result['redirect_url'], '' );
	oras_qbo_oauth_assert_same( 'connection race returns local dry-run result', (string) ( $race_result['result']['status'] ?? '' ), 'dry_run_skipped' );

    echo "QBO OAuth callback tests passed.\n";
} finally {
    $_GET = is_array( $original_get ) ? $original_get : array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( $original_settings );
}
