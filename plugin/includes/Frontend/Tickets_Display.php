<?php
namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Tickets_Display {

    private static ?Tickets_Display $instance = null;
    private bool $rendered = false;

    public static function instance(): Tickets_Display {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function init(): void {
        // Hook after TEC templates are included; check template path and render on single event pages.
        // Primary preferred hook: fires after the event content/description in some TEC installs.
        add_action( 'tribe_events_single_event_after_the_content', [ $this, 'render_from_single_event_hook' ], 20 );

        // Fallback: render after template include if the primary hook is not available.
        add_action( 'tribe_template_after_include', [ $this, 'maybe_render' ], 20, 1 );
    }

    public function maybe_render( string $template ): void {
        if ( $this->rendered ) {
            return;
        }

        // Ensure the included template path is the v2 single event template.
        $tpl = (string) $template;
        $tpl_low = strtolower( $tpl );

        $is_single_event_tpl = false;
        // Match exact v2 path ending
        if ( preg_match( '~/views/v2/single-event\.php$~', $tpl_low ) ) {
            $is_single_event_tpl = true;
        }
        // Or match filename fallback
        if ( ! $is_single_event_tpl && basename( $tpl_low ) === 'single-event.php' ) {
            $is_single_event_tpl = true;
        }

        if ( ! $is_single_event_tpl ) {
            return;
        }

        if ( ! is_singular( Meta::EVENT_POST_TYPE ) ) {
            return;
        }

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) {
            return;
        }

        $this->rendered = true;
        $this->render_for_event( $event_id );
    }

    public function render_from_single_event_hook(): void {
        if ( $this->rendered ) {
            return;
        }

        if ( ! is_singular( Meta::EVENT_POST_TYPE ) ) {
            return;
        }

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) {
            return;
        }

        $this->rendered = true;
        $this->render_for_event( $event_id );
    }

    private function render_for_event( int $event_id ): void {
        if ( ! is_singular( \ORAS\Tickets\Domain\Meta::EVENT_POST_TYPE ) ) {
            return;
        }

        // Load tickets as domain objects
        $collection = Ticket_Collection::load_for_event( $event_id );
        if ( $collection->count() === 0 ) {
            echo '<p>Tickets not available</p>';
            return;
        }

        // Load Woo mapping
        $map = get_post_meta( $event_id, '_oras_tickets_woo_map_v1', true );
        if ( ! is_array( $map ) || empty( $map ) ) {
            echo '<p>Tickets not available</p>';
            return;
        }

        // Handle POST submission (add to cart)
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['oras_tickets_nonce'] ) ) {
            if ( wp_verify_nonce( wp_unslash( $_POST['oras_tickets_nonce'] ), 'oras_tickets_add_to_cart' ) && is_singular( \ORAS\Tickets\Domain\Meta::EVENT_POST_TYPE ) ) {
                if ( class_exists( 'WooCommerce' ) && function_exists( 'WC' ) && WC() && isset( WC()->cart ) ) {
                    $posted = isset( $_POST['oras_qty'] ) && is_array( $_POST['oras_qty'] ) ? wp_unslash( $_POST['oras_qty'] ) : [];
                    $added_any = false;
                    $tickets = $collection->all();
                    foreach ( $tickets as $idx => $ticket_obj ) {
                        $key = (string) $idx;
                        if ( ! isset( $posted[ $key ] ) ) {
                            continue;
                        }
                        $qty = absint( $posted[ $key ] );
                        if ( $qty <= 0 ) {
                            continue;
                        }

                        $product_id = isset( $map[ $key ] ) ? absint( $map[ $key ] ) : 0;
                        if ( $product_id <= 0 ) {
                            continue;
                        }

                        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
                        if ( ! $product ) {
                            continue;
                        }

                        // Determine availability cap
                        $manages = ( method_exists( $product, 'managing_stock' ) && $product->managing_stock() );
                        if ( $manages ) {
                            $max = method_exists( $product, 'get_stock_quantity' ) ? max( 0, (int) $product->get_stock_quantity() ) : 0;
                        } else {
                            $max = 10;
                        }

                        if ( $qty > $max ) {
                            $qty = $max;
                        }

                        if ( $qty <= 0 ) {
                            continue;
                        }

                        // Add to cart
                        $added = WC()->cart->add_to_cart( $product_id, $qty );
                        if ( $added ) {
                            $added_any = true;
                        }
                    }

                    if ( $added_any && function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( __( 'Added tickets to cart.', 'oras-tickets' ), 'success' );
                    }

                    // Redirect to avoid resubmission
                    $redirect = add_query_arg( 'oras_added', $added_any ? '1' : '0', get_permalink( $event_id ) );
                    wp_safe_redirect( $redirect );
                    exit;
                }
            }
        }

        // Render simple purchase form
        echo '<section class="oras-tickets-section">';
        echo '<div id="oras-tickets-display" class="oras-tickets-display">';
        echo '<h2>Tickets</h2>';

        echo '<form method="post" action="' . esc_url( get_permalink( $event_id ) ) . '">';
        wp_nonce_field( 'oras_tickets_add_to_cart', 'oras_tickets_nonce' );

        echo '<table class="oras-tickets-table">';
        echo '<thead><tr><th>Ticket</th><th>Price</th><th>Status</th><th>Qty</th></tr></thead>';
        echo '<tbody>';

        $now = (int) current_time( 'timestamp' );
        $tickets = $collection->all();
        foreach ( $tickets as $index => $ticket_obj ) {
            $ticket = method_exists( $ticket_obj, 'to_array' ) ? $ticket_obj->to_array() : ( is_array( $ticket_obj ) ? $ticket_obj : [] );
            $key = (string) $index;

            // mapped product
            $product_id = isset( $map[ $key ] ) ? absint( $map[ $key ] ) : 0;
            if ( $product_id <= 0 ) {
                continue;
            }
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
            if ( ! $product ) {
                continue;
            }

            $name = isset( $ticket['name'] ) ? esc_html( $ticket['name'] ) : $product->get_name();
            $price_raw = isset( $ticket['price'] ) ? $ticket['price'] : $product->get_price();
            $price_display = $price_raw !== '' && is_numeric( $price_raw ) ? '$' . number_format( (float) $price_raw, 2, '.', '' ) : esc_html( (string) $price_raw );
            $description = isset( $ticket['description'] ) ? esc_html( $ticket['description'] ) : '';

            // Sale window
            $sale_start = isset( $ticket['sale_start'] ) ? (string) $ticket['sale_start'] : '';
            $sale_end = isset( $ticket['sale_end'] ) ? (string) $ticket['sale_end'] : '';
            $start_ts = null;
            $end_ts = null;
            if ( $sale_start !== '' ) {
                $dt = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_start, wp_timezone() );
                if ( $dt instanceof \DateTimeInterface ) {
                    $start_ts = (int) $dt->getTimestamp();
                }
            }
            if ( $sale_end !== '' ) {
                $dt2 = \DateTime::createFromFormat( 'Y-m-d H:i', $sale_end, wp_timezone() );
                if ( $dt2 instanceof \DateTimeInterface ) {
                    $end_ts = (int) $dt2->getTimestamp();
                }
            }

            $status = 'On sale';
            $disabled = false;
            if ( $start_ts !== null && $now < $start_ts ) {
                $status = 'Not on sale yet';
                $disabled = true;
            } elseif ( $end_ts !== null && $now > $end_ts ) {
                $status = 'Sales ended';
                $disabled = true;
            }

            // Availability from product
            $manages = ( method_exists( $product, 'managing_stock' ) && $product->managing_stock() );
            if ( $manages ) {
                $max = method_exists( $product, 'get_stock_quantity' ) ? max( 0, (int) $product->get_stock_quantity() ) : 0;
            } else {
                $max = 10;
            }

            $hide_sold_out = isset( $ticket['hide_sold_out'] ) && ( $ticket['hide_sold_out'] === '1' || $ticket['hide_sold_out'] === 1 || $ticket['hide_sold_out'] === true );
            if ( $manages && $max <= 0 ) {
                if ( $hide_sold_out ) {
                    continue; // do not render this row
                }
                $status = 'Sold out';
                $disabled = true;
            }

            // Render row
            echo '<tr>';
            echo '<td><strong>' . $name . '</strong>' . ( $description !== '' ? '<div class="oras-ticket-desc">' . $description . '</div>' : '' ) . '</td>';
            echo '<td>' . esc_html( $price_display ) . '</td>';
            echo '<td>' . esc_html( $status ) . '</td>';

            $input_attrs = '';
            if ( $disabled ) {
                $input_attrs .= ' disabled';
            }
            $input_max = $max > 0 ? $max : 0;
            echo '<td>';
            echo '<input type="number" name="oras_qty[' . esc_attr( $key ) . ']" min="0" value="0" max="' . esc_attr( $input_max ) . '"' . $input_attrs . ' />';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p><button type="submit" name="oras_tickets_add_to_cart" class="button">Add selected tickets to cart</button></p>';
        echo '</form>';

        echo '</div>';
        echo '</section>';
    }
}
