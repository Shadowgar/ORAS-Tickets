<?php

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run this with: wp eval-file scripts/qbo-reconciliation-tests.php\n" );
    return;
}

if ( ! class_exists( '\\ORAS\\Tickets\\Integrations\\QuickBooks\\Cli_Command' ) ) {
    throw new RuntimeException( 'QuickBooks CLI command class not loaded.' );
}

if ( ! function_exists( 'wc_create_order' ) ) {
    throw new RuntimeException( 'WooCommerce is required for reconciliation tests.' );
}

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function oras_qbo_reconcile_assert_same( string $label, $actual, $expected ): void {
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

function oras_qbo_reconcile_assert_close( string $label, float $actual, float $expected, float $delta = 0.0001 ): void {
    if ( abs( $actual - $expected ) > $delta ) {
        throw new RuntimeException(
            sprintf( '%s failed. Expected %.2f, got %.2f', $label, $expected, $actual )
        );
    }
}

$cli = new \ORAS\Tickets\Integrations\QuickBooks\Cli_Command(
    new \ORAS\Tickets\Integrations\QuickBooks\Sync_Orchestrator(),
    new \ORAS\Tickets\Integrations\QuickBooks\Api_Client(),
    new \ORAS\Tickets\Integrations\QuickBooks\Split_Calculator()
);

$method = new ReflectionMethod( \ORAS\Tickets\Integrations\QuickBooks\Cli_Command::class, 'build_reconciliation_report' );
$method->setAccessible( true );
$from = '2000-01-01';
$to   = '2099-12-31';

$before_report = $method->invoke( $cli, $from, $to );
if ( is_wp_error( $before_report ) ) {
    throw new RuntimeException( 'Baseline reconciliation report failed: ' . $before_report->get_error_message() );
}
if ( ! is_array( $before_report ) ) {
    throw new RuntimeException( 'Baseline reconciliation report returned invalid type.' );
}

$before_summary = isset( $before_report['summary'] ) && is_array( $before_report['summary'] ) ? $before_report['summary'] : array();
$before_mismatch_count = isset( $before_report['mismatches'] ) && is_array( $before_report['mismatches'] ) ? count( $before_report['mismatches'] ) : 0;

$fixture_product = new \WC_Product_Simple();
$fixture_product->set_name( 'QBO Reconciliation Fixture Product' );
$fixture_product->set_regular_price( '10.00' );
$fixture_product->set_price( '10.00' );
$fixture_product->save();
$product_id = (int) $fixture_product->get_id();
if ( $product_id <= 0 ) {
    throw new RuntimeException( 'Failed to create fixture product.' );
}
update_post_meta( $product_id, '_oras_qbo_bucket', 'merchandise' );

// Synced order: line total 10, JE exists.
$order_synced = wc_create_order();
$order_synced->add_product( wc_get_product( $product_id ), 1 );
$order_synced->calculate_totals();
$order_synced->update_status( 'completed', 'fixture', true );
$order_synced->update_meta_data( '_oras_qbo_sync_status', 'synced' );
$order_synced->update_meta_data( '_oras_qbo_je_id', 'JE-1001' );
$order_synced->update_meta_data(
    '_oras_qbo_split_snapshot',
    wp_json_encode(
        array(
            'split_total' => 10.00,
            'lines'       => array(
                array(
                    'bucket_key'   => 'merchandise',
                    'bucket_label' => 'Merchandise Income',
                    'account_id'   => '1150040003',
                    'amount'       => 10.00,
                ),
            ),
        )
    )
);
$order_synced->save();

// Unsynced order: line total 12, no JE.
$order_unsynced = wc_create_order();
$order_unsynced->add_product( wc_get_product( $product_id ), 1 );
$item_unsynced = $order_unsynced->get_items( 'line_item' );
foreach ( $item_unsynced as $item ) {
    if ( $item instanceof \WC_Order_Item_Product ) {
        $item->set_subtotal( 12.00 );
        $item->set_total( 12.00 );
        $item->save();
    }
}
$order_unsynced->calculate_totals();
$order_unsynced->update_status( 'completed', 'fixture', true );
$order_unsynced->update_meta_data( '_oras_qbo_sync_status', 'pending_qbo_review' );
$order_unsynced->save();

// Reversed order: line total 15, JE + reversal JE exists.
$order_reversed = wc_create_order();
$order_reversed->add_product( wc_get_product( $product_id ), 1 );
$item_reversed = $order_reversed->get_items( 'line_item' );
foreach ( $item_reversed as $item ) {
    if ( $item instanceof \WC_Order_Item_Product ) {
        $item->set_subtotal( 15.00 );
        $item->set_total( 15.00 );
        $item->save();
    }
}
$order_reversed->calculate_totals();
$order_reversed->update_status( 'completed', 'fixture', true );
$order_reversed->update_meta_data( '_oras_qbo_sync_status', 'reversed' );
$order_reversed->update_meta_data( '_oras_qbo_je_id', 'JE-1002' );
$order_reversed->update_meta_data( '_oras_qbo_reversal_je_id', 'JE-1002-R' );
$order_reversed->update_meta_data(
    '_oras_qbo_split_snapshot',
    wp_json_encode(
        array(
            'split_total' => 15.00,
            'lines'       => array(
                array(
                    'bucket_key'   => 'merchandise',
                    'bucket_label' => 'Merchandise Income',
                    'account_id'   => '1150040003',
                    'amount'       => 15.00,
                ),
            ),
        )
    )
);
$order_reversed->save();

$after_report = $method->invoke( $cli, $from, $to );
if ( is_wp_error( $after_report ) ) {
    throw new RuntimeException( 'Post-fixture reconciliation report failed: ' . $after_report->get_error_message() );
}
if ( ! is_array( $after_report ) ) {
    throw new RuntimeException( 'Post-fixture reconciliation report returned invalid type.' );
}

$after_summary = isset( $after_report['summary'] ) && is_array( $after_report['summary'] ) ? $after_report['summary'] : array();
$after_mismatch_count = isset( $after_report['mismatches'] ) && is_array( $after_report['mismatches'] ) ? count( $after_report['mismatches'] ) : 0;

oras_qbo_reconcile_assert_same(
    'completed order count delta',
    (int) ( $after_summary['order_count_completed'] ?? 0 ) - (int) ( $before_summary['order_count_completed'] ?? 0 ),
    3
);
oras_qbo_reconcile_assert_same(
    'synced order count delta',
    (int) ( $after_summary['order_count_synced'] ?? 0 ) - (int) ( $before_summary['order_count_synced'] ?? 0 ),
    2
);
oras_qbo_reconcile_assert_same(
    'reversed order count delta',
    (int) ( $after_summary['order_count_reversed'] ?? 0 ) - (int) ( $before_summary['order_count_reversed'] ?? 0 ),
    1
);
oras_qbo_reconcile_assert_same(
    'unsynced order count delta',
    (int) ( $after_summary['order_count_unsynced'] ?? 0 ) - (int) ( $before_summary['order_count_unsynced'] ?? 0 ),
    1
);

oras_qbo_reconcile_assert_close(
    'woo total line items delta',
    (float) ( $after_summary['woo_total_line_items'] ?? 0.0 ) - (float) ( $before_summary['woo_total_line_items'] ?? 0.0 ),
    37.00
);
oras_qbo_reconcile_assert_close(
    'qbo total synced split delta',
    (float) ( $after_summary['qbo_total_synced_split'] ?? 0.0 ) - (float) ( $before_summary['qbo_total_synced_split'] ?? 0.0 ),
    25.00
);
oras_qbo_reconcile_assert_close(
    'qbo total reversed split delta',
    (float) ( $after_summary['qbo_total_reversed_split'] ?? 0.0 ) - (float) ( $before_summary['qbo_total_reversed_split'] ?? 0.0 ),
    15.00
);
oras_qbo_reconcile_assert_close(
    'qbo net posted delta',
    (float) ( $after_summary['qbo_net_posted'] ?? 0.0 ) - (float) ( $before_summary['qbo_net_posted'] ?? 0.0 ),
    10.00
);
oras_qbo_reconcile_assert_close(
    'variance woo vs qbo delta',
    (float) ( $after_summary['variance_woo_vs_qbo_net'] ?? 0.0 ) - (float) ( $before_summary['variance_woo_vs_qbo_net'] ?? 0.0 ),
    27.00
);

oras_qbo_reconcile_assert_same( 'mismatch count delta', $after_mismatch_count - $before_mismatch_count, 2 );

echo "QBO reconciliation tests passed.\n";
