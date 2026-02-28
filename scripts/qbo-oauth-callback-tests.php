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

$original_get      = $_GET;
$original_settings = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();

try {
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

    echo "QBO OAuth callback tests passed.\n";
} finally {
    $_GET = is_array( $original_get ) ? $original_get : array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( $original_settings );
}
