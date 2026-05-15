<?php

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run this with: wp eval-file scripts/stripe-metadata-tests.php\n" );
    return;
}

if ( ! class_exists( '\ORAS\Tickets\Commerce\Woo\Stripe_Intent_Description' ) ) {
    throw new RuntimeException( 'Stripe_Intent_Description class is not loaded.' );
}

if ( ! class_exists( '\ORAS\Tickets\Commerce\Woo\Order_Item_Classifier' ) ) {
    throw new RuntimeException( 'Order_Item_Classifier class is not loaded.' );
}

if ( ! function_exists( 'wc_create_order' ) ) {
    throw new RuntimeException( 'WooCommerce is required for Stripe metadata tests.' );
}

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function oras_stripe_metadata_assert_same( string $label, $actual, $expected ): void {
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

function oras_stripe_metadata_create_product( string $name, string $price, string $bucket = '' ): int {
    $product = new \WC_Product_Simple();
    $product->set_name( $name );
    $product->set_regular_price( $price );
    $product->set_price( $price );
    $product->save();

    $product_id = (int) $product->get_id();
    if ( $product_id <= 0 ) {
        throw new RuntimeException( 'Failed to create Stripe metadata fixture product.' );
    }

    if ( $bucket !== '' ) {
        update_post_meta( $product_id, '_oras_qbo_bucket', $bucket );
    }

    return $product_id;
}

$observer_product_id = oras_stripe_metadata_create_product( 'Annual Observer Pass', '50.00', 'observer_pass' );
$merch_product_id    = oras_stripe_metadata_create_product( 'ORAS T-Shirt', '25.00', 'merchandise' );
$ticket_product_id   = oras_stripe_metadata_create_product( 'Spring Stargaze Standard Ticket', '20.00' );
$unmapped_product_id = oras_stripe_metadata_create_product( 'Mystery Product', '7.00' );

$event_id = wp_insert_post(
    array(
        'post_title'  => 'Spring Stargaze',
        'post_name'   => 'spring-stargaze',
        'post_type'   => 'tribe_events',
        'post_status' => 'publish',
    )
);

$mixed_order = wc_create_order();
$mixed_order->add_product( wc_get_product( $observer_product_id ), 1 );
$mixed_order->add_product( wc_get_product( $merch_product_id ), 1 );
$mixed_order->add_product( wc_get_product( $ticket_product_id ), 1 );
foreach ( $mixed_order->get_items( 'line_item' ) as $item ) {
    if ( $item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() === $ticket_product_id ) {
        $item->update_meta_data( '_oras_ticket_event_id', (string) $event_id );
        $item->update_meta_data( '_oras_ticket_name', 'Spring Stargaze Standard Ticket' );
        $item->save();
    }
}
$mixed_order->calculate_totals();

$service       = new \ORAS\Tickets\Commerce\Woo\Stripe_Intent_Description();
$mixed_request = $service->inject_ticket_description( array(), $mixed_order );
$mixed_meta    = isset( $mixed_request['metadata'] ) && is_array( $mixed_request['metadata'] ) ? $mixed_request['metadata'] : array();

oras_stripe_metadata_assert_same(
    'mixed order item names',
    (string) ( $mixed_meta['oras_item_names'] ?? '' ),
    'Annual Observer Pass x1 (USD 50.00), ORAS T-Shirt x1 (USD 25.00), Spring Stargaze Standard Ticket x1 (USD 20.00)'
);
oras_stripe_metadata_assert_same(
    'mixed order item buckets',
    (string) ( $mixed_meta['oras_item_buckets'] ?? '' ),
    'Annual Observer Pass=observer_pass x1, ORAS T-Shirt=merchandise x1, Spring Stargaze Standard Ticket=event_ticket x1'
);
oras_stripe_metadata_assert_same( 'mixed order observer flag', (string) ( $mixed_meta['oras_contains_observer_passes'] ?? '' ), 'yes' );
oras_stripe_metadata_assert_same( 'mixed order merch flag', (string) ( $mixed_meta['oras_contains_merch'] ?? '' ), 'yes' );
oras_stripe_metadata_assert_same( 'mixed order donation flag', (string) ( $mixed_meta['oras_contains_donations'] ?? '' ), 'no' );
oras_stripe_metadata_assert_same( 'mixed order unmapped flag', (string) ( $mixed_meta['oras_contains_unmapped'] ?? '' ), 'no' );
oras_stripe_metadata_assert_same( 'mixed order ticket flag', (string) ( $mixed_meta['oras_contains_tickets'] ?? '' ), 'yes' );
oras_stripe_metadata_assert_same(
    'mixed order ticket names',
    (string) ( $mixed_meta['oras_ticket_names'] ?? '' ),
    'Spring Stargaze Standard Ticket x1 (USD 20.00)'
);
oras_stripe_metadata_assert_same(
    'mixed order merch items',
    (string) ( $mixed_meta['oras_merch_items'] ?? '' ),
    'ORAS T-Shirt x1 (USD 25.00)'
);

$observer_only_order = wc_create_order();
$observer_only_order->add_product( wc_get_product( $observer_product_id ), 1 );
$observer_only_order->calculate_totals();
$observer_request = $service->inject_ticket_description( array(), $observer_only_order );
$observer_meta    = isset( $observer_request['metadata'] ) && is_array( $observer_request['metadata'] ) ? $observer_request['metadata'] : array();
oras_stripe_metadata_assert_same(
    'observer-only order item names',
    (string) ( $observer_meta['oras_item_names'] ?? '' ),
    'Annual Observer Pass x1 (USD 50.00)'
);
oras_stripe_metadata_assert_same(
    'observer-only order item buckets',
    (string) ( $observer_meta['oras_item_buckets'] ?? '' ),
    'Annual Observer Pass=observer_pass x1'
);
oras_stripe_metadata_assert_same( 'observer-only ticket flag', (string) ( $observer_meta['oras_contains_tickets'] ?? '' ), 'no' );

$unmapped_order = wc_create_order();
$unmapped_order->add_product( wc_get_product( $unmapped_product_id ), 1 );
$unmapped_order->calculate_totals();
$unmapped_request = $service->inject_ticket_description( array(), $unmapped_order );
$unmapped_meta    = isset( $unmapped_request['metadata'] ) && is_array( $unmapped_request['metadata'] ) ? $unmapped_request['metadata'] : array();
oras_stripe_metadata_assert_same(
    'unmapped order bucket',
    (string) ( $unmapped_meta['oras_item_buckets'] ?? '' ),
    'Mystery Product=unmapped x1'
);
oras_stripe_metadata_assert_same( 'unmapped order flag', (string) ( $unmapped_meta['oras_contains_unmapped'] ?? '' ), 'yes' );

$long_product_id = oras_stripe_metadata_create_product( str_repeat( 'Long Product ', 60 ), '9.00', 'merchandise' );
$long_order      = wc_create_order();
$long_order->add_product( wc_get_product( $long_product_id ), 1 );
$long_order->calculate_totals();
$long_request = $service->inject_ticket_description( array(), $long_order );
$long_meta    = isset( $long_request['metadata'] ) && is_array( $long_request['metadata'] ) ? $long_request['metadata'] : array();
if ( strlen( (string) ( $long_meta['oras_item_names'] ?? '' ) ) > 500 ) {
    throw new RuntimeException( 'oras_item_names exceeded Stripe metadata limit.' );
}
if ( strlen( (string) ( $long_meta['oras_item_buckets'] ?? '' ) ) > 500 ) {
    throw new RuntimeException( 'oras_item_buckets exceeded Stripe metadata limit.' );
}

echo "Stripe metadata tests passed.\n";
