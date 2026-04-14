<?php

namespace ORAS\Tickets\Commerce\Woo;

use ORAS\Tickets\Domain\Pricing\Price_Resolver;
use ORAS\Tickets\Domain\Ticket_Collection;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cart_Pricing { // NOSONAR legacy WP class naming



    public static function register(): void {
        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_cart_pricing' ), 20, 1 );
    }

    /**
     * Apply time-based pricing to cart items.
     *
     * @param \WC_Cart $cart
     */
    public static function apply_cart_pricing( $cart ): void {
        if ( ! $cart || ! method_exists( $cart, 'get_cart' ) ) {
            return;
        }

        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( ! isset( $cart_item['data'] ) ) {
                continue;
            }

            $product = $cart_item['data'];
            if ( ! $product instanceof \WC_Product ) {
                continue;
            }

            $event_id = (int) $product->get_meta( '_oras_ticket_event_id', true );
            $index    = (int) $product->get_meta( '_oras_ticket_index', true );
            if ( $event_id <= 0 || $index < 0 ) {
                continue;
            }

            $collection = Ticket_Collection::load_for_event( $event_id );
            $tickets    = $collection->all();
            if ( ! array_key_exists( $index, $tickets ) ) {
                continue;
            }

            $ticket_obj  = $tickets[ $index ];
            $ticket_data = $ticket_obj->to_array();
            if ( empty( $ticket_data ) ) {
                continue;
            }

            $resolved = Price_Resolver::resolve_ticket_price( $ticket_data );
            if ( empty( $resolved['price'] ) || ! is_numeric( $resolved['price'] ) ) {
                continue;
            }

            $new_price = (float) $resolved['price'];
            $current   = (float) $product->get_price();

            if ( abs( $new_price - $current ) > 0.0001 ) {
                $product->set_price( number_format( $new_price, 2, '.', '' ) );
            }
        }
    }
}
