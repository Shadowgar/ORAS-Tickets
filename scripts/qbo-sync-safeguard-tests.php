<?php

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run this with: wp eval-file scripts/qbo-sync-safeguard-tests.php\n" );
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

if ( ! class_exists( '\ORAS\Tickets\Integrations\QuickBooks\Settings' ) ) {
    throw new RuntimeException( 'QuickBooks Settings class not loaded.' );
}

if ( ! class_exists( '\ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator' ) ) {
    throw new RuntimeException( 'QuickBooks Sync_Orchestrator class not loaded.' );
}

if ( ! function_exists( 'wc_create_order' ) ) {
    throw new RuntimeException( 'WooCommerce is required for safeguard tests.' );
}

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function oras_qbo_guard_assert_same( string $label, $actual, $expected ): void {
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
 * @param \WC_Order $order
 */
function oras_qbo_guard_reset_sync_meta( $order ): void {
    $order_id = (int) $order->get_id();
    if ( $order_id <= 0 ) {
        return;
    }

    delete_post_meta( $order_id, '_oras_qbo_sync_status' );
    delete_post_meta( $order_id, '_oras_qbo_je_id' );
    delete_post_meta( $order_id, '_oras_qbo_je_hash' );
    delete_post_meta( $order_id, '_oras_qbo_synced' );
    delete_post_meta( $order_id, '_oras_qbo_synced_at' );

    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions(
            \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
            array( $order_id ),
            'oras-tickets'
        );
    } else {
        wp_clear_scheduled_hook(
            \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator::ACTION_HOOK,
            array( $order_id )
        );
    }
}

$original_settings = \ORAS\Tickets\Integrations\QuickBooks\Settings::get_quickbooks_settings();

try {
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
        array(
            'enabled'          => true,
            'sync_cutoff_date' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
        )
    );

    $product = new \WC_Product_Simple();
    $product->set_name( 'QBO Safeguard Fixture' );
    $product->set_regular_price( '10.00' );
    $product->set_price( '10.00' );
    $product->save();
    $product_id = (int) $product->get_id();
    if ( $product_id <= 0 ) {
        throw new RuntimeException( 'Failed to create fixture product.' );
    }

    $orchestrator = new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator();

    // 1) Processing orders must NOT queue for sync.
    $processing_order = wc_create_order();
    $processing_order->add_product( wc_get_product( $product_id ), 1 );
    $processing_order->calculate_totals();
    $processing_order->update_status( 'processing', 'fixture', true );
    oras_qbo_guard_reset_sync_meta( $processing_order );
    $orchestrator->enqueue_order_sync( (int) $processing_order->get_id() );
    $processing_order = wc_get_order( (int) $processing_order->get_id() );
    oras_qbo_guard_assert_same(
        'processing order does not queue',
        (string) $processing_order->get_meta( '_oras_qbo_sync_status', true ),
        ''
    );

    // 2) Completed orders before cutoff must NOT queue.
    $old_order = wc_create_order();
    $old_order->add_product( wc_get_product( $product_id ), 1 );
    $old_order->calculate_totals();
    $old_order->update_status( 'completed', 'fixture', true );
    oras_qbo_guard_reset_sync_meta( $old_order );
    $orchestrator->enqueue_order_sync( (int) $old_order->get_id() );
    $old_order = wc_get_order( (int) $old_order->get_id() );
    oras_qbo_guard_assert_same(
        'order before cutoff does not queue',
        (string) $old_order->get_meta( '_oras_qbo_sync_status', true ),
        ''
    );

    // 3) Completed orders with _oras_qbo_synced must NOT queue.
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings(
        array(
            'sync_cutoff_date' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
        )
    );
    $synced_order = wc_create_order();
    $synced_order->add_product( wc_get_product( $product_id ), 1 );
    $synced_order->calculate_totals();
    $synced_order->update_status( 'completed', 'fixture', true );
    oras_qbo_guard_reset_sync_meta( $synced_order );
    $synced_order->update_meta_data( '_oras_qbo_synced', '1' );
    $synced_order->save();
    $synced_order = wc_get_order( (int) $synced_order->get_id() );
    oras_qbo_guard_assert_same(
        'fixture synced meta is set',
        (string) $synced_order->get_meta( '_oras_qbo_synced', true ),
        '1'
    );
    $orchestrator->enqueue_order_sync( (int) $synced_order->get_id() );
    $synced_order = wc_get_order( (int) $synced_order->get_id() );
    oras_qbo_guard_assert_same(
        'already synced order does not queue',
        (string) $synced_order->get_meta( '_oras_qbo_sync_status', true ),
        ''
    );

    echo "QBO safeguard tests passed.\n";
} finally {
    // Restore baseline settings.
    \ORAS\Tickets\Integrations\QuickBooks\Settings::update_quickbooks_settings( $original_settings );
}
