<?php

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run this with: wp eval-file scripts/qbo-safety-controls-tests.php\n" );
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

if ( ! class_exists( '\\ORAS\\Tickets\\Integrations\\QuickBooks\\Settings' ) ) {
    throw new RuntimeException( 'QuickBooks Settings class not loaded.' );
}

if ( ! class_exists( '\\ORAS\\Tickets\\Integrations\\QuickBooks\\Sync_Orchestrator' ) ) {
    throw new RuntimeException( 'QuickBooks Sync_Orchestrator class not loaded.' );
}

if ( ! function_exists( 'wc_create_order' ) ) {
    throw new RuntimeException( 'WooCommerce is required for safety controls tests.' );
}

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function oras_qbo_safety_assert_same( string $label, $actual, $expected ): void {
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

function oras_qbo_safety_assert_true( string $label, bool $condition ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $label . ' failed.' );
    }
}

$original_settings = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();

try {
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
        array(
            'enabled'                 => true,
            'sandbox'                 => true,
            'dry_run_mode'            => true,
            'require_manual_approval' => true,
            'strict_mapping_mode'     => true,
            'allow_unmapped_fallback' => false,
            'sync_cutoff_date'        => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
            'clearing_account_id'     => '1150040001',
            'tickets_default_account_id' => '1150040000',
            'merchandise_account_id'  => '1150040003',
            'unmapped_account_id'     => '',
        )
    );

    $mapped_product = new \WC_Product_Simple();
    $mapped_product->set_name( 'QBO Safety Fixture (Mapped)' );
    $mapped_product->set_regular_price( '10.00' );
    $mapped_product->set_price( '10.00' );
    $mapped_product->save();
    $mapped_product_id = (int) $mapped_product->get_id();
    if ( $mapped_product_id <= 0 ) {
        throw new RuntimeException( 'Failed to create mapped fixture product.' );
    }
    update_post_meta( $mapped_product_id, '_oras_qbo_bucket', 'merchandise' );

    $orchestrator = new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator();

    // Scenario 1: Completed orders queue into manual review and require approval.
    $order = wc_create_order();
    $order->add_product( wc_get_product( $mapped_product_id ), 1 );
    $order->calculate_totals();
    $order->update_status( 'completed', 'fixture', true );
    $order_id = (int) $order->get_id();

    $orchestrator->enqueue_order_sync( $order_id );
    $order = wc_get_order( $order_id );
    oras_qbo_safety_assert_same(
        'enqueue sets pending review status',
        (string) $order->get_meta( '_oras_qbo_sync_status', true ),
        'pending_qbo_review'
    );

    $blocked = $orchestrator->sync_order( $order_id );
    oras_qbo_safety_assert_true( 'sync blocked without manual approval', is_wp_error( $blocked ) );
    if ( is_wp_error( $blocked ) ) {
        oras_qbo_safety_assert_same( 'manual approval error code', $blocked->get_error_code(), 'oras_qbo_manual_approval_required' );
    }

    $approved = $orchestrator->approve_order_sync( $order_id, false );
    oras_qbo_safety_assert_true( 'approval command succeeded', ! is_wp_error( $approved ) );

    $dry_run = $orchestrator->sync_order( $order_id );
    oras_qbo_safety_assert_true( 'dry run sync result is array', is_array( $dry_run ) );
    if ( is_array( $dry_run ) ) {
        oras_qbo_safety_assert_same( 'dry run status', (string) ( $dry_run['status'] ?? '' ), 'dry_run' );
    }

    $order = wc_get_order( $order_id );
    oras_qbo_safety_assert_same(
        'dry run does not mark synced meta',
        (string) $order->get_meta( '_oras_qbo_synced', true ),
        ''
    );

    // Scenario 2: Strict mapping blocks unmapped product when fallback is disabled.
    $unmapped_product = new \WC_Product_Simple();
    $unmapped_product->set_name( 'QBO Safety Fixture (Unmapped)' );
    $unmapped_product->set_regular_price( '12.00' );
    $unmapped_product->set_price( '12.00' );
    $unmapped_product->save();
    $unmapped_product_id = (int) $unmapped_product->get_id();

    $strict_order = wc_create_order();
    $strict_order->add_product( wc_get_product( $unmapped_product_id ), 1 );
    $strict_order->calculate_totals();
    $strict_order->update_status( 'completed', 'fixture', true );
    $strict_order_id = (int) $strict_order->get_id();

    $orchestrator->approve_order_sync( $strict_order_id, false );
    $strict_result = $orchestrator->sync_order( $strict_order_id );
    oras_qbo_safety_assert_true( 'strict mapping returns WP_Error', is_wp_error( $strict_result ) );
    if ( is_wp_error( $strict_result ) ) {
        oras_qbo_safety_assert_same( 'strict mapping error code', $strict_result->get_error_code(), 'oras_qbo_strict_mapping_failed' );
    }

    // Scenario 3: Reversal in dry-run mode validates payload without write.
    $order = wc_get_order( $order_id );
    $order->update_meta_data( '_oras_qbo_je_id', '12345' );
    $order->update_meta_data(
        '_oras_qbo_split_snapshot',
        wp_json_encode(
            array(
                'lines' => array(
                    array(
                        'bucket_key'   => 'merchandise',
                        'bucket_label' => 'Merchandise Income',
                        'account_id'   => '1150040003',
                        'amount'       => 10.00,
                    ),
                ),
                'split_total' => 10.00,
            )
        )
    );
    $order->save();

    $reversal = $orchestrator->reverse_order( $order_id, true );
    oras_qbo_safety_assert_true( 'reversal dry-run response is array', is_array( $reversal ) );
    if ( is_array( $reversal ) ) {
        oras_qbo_safety_assert_same( 'reversal dry-run status', (string) ( $reversal['status'] ?? '' ), 'reversal_dry_run' );
    }

    $audit_entries = get_post_meta( $order_id, '_oras_qbo_audit_entry', false );
    oras_qbo_safety_assert_true( 'audit entries captured', is_array( $audit_entries ) && count( $audit_entries ) >= 3 );

    echo "QBO safety controls tests passed.\n";
} finally {
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( $original_settings );
}
