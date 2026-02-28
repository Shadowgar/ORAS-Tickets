<?php

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run this with: wp eval-file scripts/qbo-split-calculator-tests.php\n" );
    return;
}

if ( ! class_exists( '\ORAS\Tickets\Integrations\QuickBooks\Split_Calculator' ) ) {
    fwrite( STDERR, "ORAS Split_Calculator class is not loaded.\n" );
    return;
}

if ( ! class_exists( '\ORAS\Tickets\Integrations\QuickBooks\Settings' ) ) {
    fwrite( STDERR, "ORAS QuickBooks Settings class is not loaded.\n" );
    return;
}

if ( ! class_exists( '\ORAS\Tickets\Integrations\QuickBooks\OAuth_Client' ) ) {
    fwrite( STDERR, "ORAS QuickBooks OAuth_Client class is not loaded.\n" );
    return;
}

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function oras_qbo_assert_equals( string $label, $actual, $expected ): void {
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

/**
 * @param float|int $actual
 * @param float|int $expected
 */
function oras_qbo_assert_close( string $label, $actual, $expected, float $delta = 0.0001 ): void {
    if ( abs( (float) $actual - (float) $expected ) > $delta ) {
        throw new RuntimeException(
            sprintf(
                '%s failed. Expected %.4f, got %.4f',
                $label,
                (float) $expected,
                (float) $actual
            )
        );
    }
}

$rows_ticket_only = array(
    array(
        'bucket_key'   => 'event:spring-stargaze',
        'bucket_label' => 'Spring Stargaze Registration Fees',
        'account_id'   => '4001',
        'total'        => 20.00,
        'subtotal'     => 20.00,
    ),
    array(
        'bucket_key'   => 'event:spring-stargaze',
        'bucket_label' => 'Spring Stargaze Registration Fees',
        'account_id'   => '4001',
        'total'        => 20.00,
        'subtotal'     => 20.00,
    ),
);
$result_ticket_only = \ORAS\Tickets\Integrations\QuickBooks\Split_Calculator::aggregate_classified_rows( $rows_ticket_only );
oras_qbo_assert_close( 'ticket-only split total', $result_ticket_only['split_total'], 40.00 );
oras_qbo_assert_equals( 'ticket-only line count', count( $result_ticket_only['lines'] ), 1 );

$rows_mixed = array(
    array(
        'bucket_key'   => 'event:astroblast',
        'bucket_label' => 'Astroblast Registration Fees',
        'account_id'   => '4100',
        'total'        => 55.00,
        'subtotal'     => 60.00,
    ),
    array(
        'bucket_key'   => 'merchandise',
        'bucket_label' => 'Merchandise Income',
        'account_id'   => '4200',
        'total'        => 35.00,
        'subtotal'     => 40.00,
    ),
);
$result_mixed = \ORAS\Tickets\Integrations\QuickBooks\Split_Calculator::aggregate_classified_rows( $rows_mixed );
oras_qbo_assert_close( 'mixed split total', $result_mixed['split_total'], 90.00 );
oras_qbo_assert_equals( 'mixed line count', count( $result_mixed['lines'] ), 2 );
oras_qbo_assert_close( 'mixed discount total', $result_mixed['discount_total'], 10.00 );

$rows_discount = array(
    array(
        'bucket_key'   => 'event:spring-stargaze',
        'bucket_label' => 'Spring Stargaze Registration Fees',
        'account_id'   => '4001',
        'total'        => 20.00,
        'subtotal'     => 25.00,
    ),
    array(
        'bucket_key'   => 'merchandise',
        'bucket_label' => 'Merchandise Income',
        'account_id'   => '4200',
        'total'        => 30.00,
        'subtotal'     => 35.00,
    ),
);
$result_discount = \ORAS\Tickets\Integrations\QuickBooks\Split_Calculator::aggregate_classified_rows( $rows_discount );
oras_qbo_assert_close( 'discount mode line total sum', $result_discount['line_total_sum'], 50.00 );
oras_qbo_assert_close( 'discount mode discount total', $result_discount['discount_total'], 10.00 );

$event_account_map = array(
    'astroblast'     => '1150040000',
    'spring-stargaze' => '1150040004',
);
oras_qbo_assert_equals(
    'event map exact slug',
    \ORAS\Tickets\Integrations\QuickBooks\Settings::resolve_event_account_id( 'spring-stargaze', $event_account_map ),
    '1150040004'
);
oras_qbo_assert_equals(
    'event map series slug fallback',
    \ORAS\Tickets\Integrations\QuickBooks\Settings::resolve_event_account_id( 'spring-stargaze-26', $event_account_map ),
    '1150040004'
);
oras_qbo_assert_equals(
    'event map does not match partial prefix without hyphen',
    \ORAS\Tickets\Integrations\QuickBooks\Settings::resolve_event_account_id( 'spring-stargazer', $event_account_map ),
    ''
);

$qbo_defaults = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_defaults();
oras_qbo_assert_equals(
    'donation slugs default',
    (string) $qbo_defaults['donation_category_slugs'],
    'donation,donations,give,giving'
);
oras_qbo_assert_equals(
    'printful slugs default',
    (string) $qbo_defaults['printful_category_slugs'],
    'printful,pod'
);

$encrypted_qbo = \ORAS\Tickets\Integrations\QuickBooks\Settings::prepare_for_storage(
    array(
        'realm_id'      => '1234567890',
        'refresh_token' => 'refresh-token-value',
    )
);
if ( strpos( (string) $encrypted_qbo['realm_id'], 'orasqbo:v1:' ) !== 0 ) {
    throw new RuntimeException( 'realm_id encryption prefix missing.' );
}
if ( strpos( (string) $encrypted_qbo['refresh_token'], 'orasqbo:v1:' ) !== 0 ) {
    throw new RuntimeException( 'refresh_token encryption prefix missing.' );
}

$hydrated_qbo = \ORAS\Tickets\Integrations\QuickBooks\Settings::hydrate_from_storage( $encrypted_qbo );
oras_qbo_assert_equals( 'realm_id decrypt', (string) $hydrated_qbo['realm_id'], '1234567890' );
oras_qbo_assert_equals( 'refresh token decrypt', (string) $hydrated_qbo['refresh_token'], 'refresh-token-value' );

$grant_error = \ORAS\Tickets\Integrations\QuickBooks\OAuth_Client::build_auth_error_from_response(
    array(
        'error'             => 'invalid_grant',
        'error_description' => 'authorization code expired',
    ),
    400,
    'authorization_code'
);
oras_qbo_assert_equals( 'grant error code', $grant_error->get_error_code(), 'oras_qbo_auth_error_grant' );
if ( strpos( $grant_error->get_error_message(), 'Auth Error Grant' ) === false ) {
    throw new RuntimeException( 'grant error message label missing.' );
}

$refresh_error = \ORAS\Tickets\Integrations\QuickBooks\OAuth_Client::build_auth_error_from_response(
    array(
        'error'             => 'invalid_grant',
        'error_description' => 'refresh token invalid',
    ),
    401,
    'refresh_token'
);
oras_qbo_assert_equals( 'refresh error code', $refresh_error->get_error_code(), 'oras_qbo_auth_error_refresh' );
if ( strpos( $refresh_error->get_error_message(), 'Auth Error Refresh' ) === false ) {
    throw new RuntimeException( 'refresh error message label missing.' );
}

$split_calculator = new \ORAS\Tickets\Integrations\QuickBooks\Split_Calculator();
$resolve_method   = new ReflectionMethod( \ORAS\Tickets\Integrations\QuickBooks\Split_Calculator::class, 'resolve_account_id' );
$resolve_method->setAccessible( true );
$donation_account = $resolve_method->invoke(
    $split_calculator,
    array(
        'type'       => 'donation',
        'bucket_key' => 'donation',
    ),
    array(),
    array(
        'donations_account_id' => '3010',
    )
);
oras_qbo_assert_equals( 'donation account routing', (string) $donation_account, '3010' );

$printful_account = $resolve_method->invoke(
    $split_calculator,
    array(
        'type'       => 'printful',
        'bucket_key' => 'printful',
    ),
    array(),
    array(
        'printful_account_id'    => '3042',
        'merchandise_account_id' => '3040',
    )
);
oras_qbo_assert_equals( 'printful account routing', (string) $printful_account, '3042' );

$printful_fallback_account = $resolve_method->invoke(
    $split_calculator,
    array(
        'type'       => 'printful',
        'bucket_key' => 'printful',
    ),
    array(),
    array(
        'printful_account_id'    => '',
        'merchandise_account_id' => '3040',
    )
);
oras_qbo_assert_equals( 'printful account fallback to merchandise', (string) $printful_fallback_account, '3040' );

echo "QBO split calculator tests passed.\n";
