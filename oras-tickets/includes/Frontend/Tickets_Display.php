<?php

namespace ORAS\Tickets\Frontend;

use ORAS\Tickets\Admin\Pages\Settings_Page;
use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Pricing\Price_Resolver;
use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Domain\Ticket_Collection;
use ORAS\Tickets\Event_Questions;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Tickets_Display { // NOSONAR legacy WP class naming

    private const CART_HOLD_SECONDS_DEFAULT = 900;






    private static ?Tickets_Display $instance = null;

    public static function instance(): Tickets_Display {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function init(): void {
        // Rendering will be injected via the_content filter only.

        // Inject into the main post content for TEC single event pages.
        add_filter( 'the_content', array( $this, 'the_content_filter' ), 20 );

        // Frontend styles for the tickets table.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );

        // Handle POST submissions early in the request lifecycle.
        add_action( 'template_redirect', array( $this, 'handle_post' ), 10 );

        // Revalidate cart items on cart/checkout views.
        add_action( 'woocommerce_check_cart_items', array( $this, 'revalidate_cart_items' ), 10 );
        add_action( 'woocommerce_before_checkout_process', array( $this, 'revalidate_cart_items' ), 10 );
        add_action( 'woocommerce_checkout_process', array( $this, 'revalidate_cart_items' ), 10 );

        // Validate ORAS ticket reservation window and quantity on direct Woo add-to-cart calls.
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validateAddToCart' ), 10, 5 );

        // Stamp hold start in cart item data; reuse existing hold timestamp so merge keys stay stable.
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_oras_hold_timestamp' ), 10, 4 );
    }

    /**
     * @param array<string,mixed> $cart_item_data
     * @return array<string,mixed>
     */
    public function add_oras_hold_timestamp( array $cart_item_data, int $product_id, int $variation_id, int $quantity ): array {
        unset( $variation_id, $quantity );

        if ( ! $this->is_oras_ticket_product( $product_id ) ) {
            return $cart_item_data;
        }

        if ( isset( $cart_item_data['_oras_hold_started_at'] ) && (int) $cart_item_data['_oras_hold_started_at'] > 0 ) {
            return $cart_item_data;
        }

        $existing_started_at = $this->get_existing_hold_started_at( $product_id );
        $cart_item_data['_oras_hold_started_at'] = $existing_started_at > 0 ? $existing_started_at : (int) time();

        return $cart_item_data;
    }

    /**
     * Validate ORAS ticket items when added to cart via Woo endpoints.
     *
     * @param bool  $passed Original validation state.
     * @param int   $product_id Product ID.
     * @param int   $quantity Requested quantity.
     * @param mixed ...$args Unused Woo callback args.
     */
    public function validateAddToCart( bool $passed, int $product_id, int $quantity, ...$args ): bool {
        unset( $args );

        if ( ! $passed || $product_id <= 0 ) {
            return $passed;
        }

        $context = $this->buildAddToCartContext( $product_id );
        if ( null === $context ) {
            return true;
        }

        $error_message = isset( $context['error_message'] ) ? (string) $context['error_message'] : '';
        if ( '' === $error_message ) {
            $error_message = $this->getAddToCartValidationError( $context, $quantity );
        }

        if ( '' === $error_message ) {
            return true;
        }

        if ( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( $error_message, 'error' );
        }

        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildAddToCartContext( int $product_id ): ?array {
        $event_id_raw = get_post_meta( $product_id, '_oras_ticket_event_id', true );
        $index_raw    = get_post_meta( $product_id, '_oras_ticket_index', true );

        if ( $event_id_raw === '' && $index_raw === '' ) {
            return null;
        }

        $event_id = (int) $event_id_raw;
        $index    = (int) $index_raw;
        if ( $event_id <= 0 || $index < 0 ) {
            return array(
                'error_message' => __( 'This ticket is no longer available.', 'oras-tickets' ),
            );
        }

        $ticket = $this->get_ticket_definition( $event_id, $index );
        if ( ! $ticket ) {
            return array(
                'error_message' => __( 'This ticket is no longer available.', 'oras-tickets' ),
            );
        }

        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

        return array(
            'product_id' => $product_id,
            'ticket'     => $ticket,
            'product'    => $product,
            'name'       => $this->get_ticket_name( $ticket, $product ),
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    private function getAddToCartValidationError( array $context, int $quantity ): string {
        $ticket  = isset( $context['ticket'] ) && is_array( $context['ticket'] ) ? $context['ticket'] : array();
        $product = $context['product'] ?? null;
        $name    = isset( $context['name'] ) ? (string) $context['name'] : __( 'Ticket', 'oras-tickets' );

        if ( ! $this->is_ticket_on_sale_now( $ticket, (int) time() ) ) {
            $sale_start = isset( $ticket['sale_start'] ) ? (string) $ticket['sale_start'] : '';
            $start_ts   = $sale_start !== '' ? strtotime( $sale_start . ' UTC' ) : false;
            $is_future  = $start_ts && $start_ts > (int) time();
            return $is_future
                ? sprintf( __( 'Ticket %s is not on sale yet.', 'oras-tickets' ), $name )
                : sprintf( __( 'Ticket %s sales have ended.', 'oras-tickets' ), $name );
        }

        if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() ) {
            return sprintf( __( 'Ticket %s is currently unavailable for purchase.', 'oras-tickets' ), $name );
        }

        $product_id      = isset( $context['product_id'] ) ? (int) $context['product_id'] : 0;
        $already_in_cart = $this->get_cart_quantity_for_product( $product_id );
        $requested_total = max( 0, $already_in_cart + max( 0, $quantity ) );

        if ( $product->is_sold_individually() && $requested_total > 1 ) {
            return sprintf( __( 'Ticket %s can only be purchased once per order.', 'oras-tickets' ), $name );
        }

        if ( $product->managing_stock() ) {
            $available = max( 0, (int) $product->get_stock_quantity() );
            if ( $requested_total > $available && ! $product->backorders_allowed() ) {
                return sprintf( __( 'Ticket %1$s has only %2$d remaining.', 'oras-tickets' ), $name, $available );
            }
            return '';
        }

        return $requested_total > 10
            ? sprintf( __( 'Ticket %s has a maximum quantity of 10 per order.', 'oras-tickets' ), $name )
            : '';
    }

    /**
     * Enqueue frontend assets for the tickets display.
     */
    public function enqueue_assets(): void {
        wp_enqueue_style(
            'oras-tickets-frontend',
            ORAS_TICKETS_URL . 'assets/css/tickets-frontend.css',
            array(),
            ORAS_TICKETS_VERSION
        );
    }

    /**
     * Revalidate ORAS ticket items already in the cart.
     */
    public function revalidate_cart_items(): void {
        static $ran = false;
        if ( $ran ) {
            return;
        }
        $ran = true;

        if ( ! function_exists( 'WC' ) || ! WC() ) {
            return;
        }

        $now = (int) time();

        $changed = false;

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
            if ( $product_id <= 0 ) {
                continue;
            }

            if ( $this->is_cart_item_hold_expired( $cart_item, $now ) ) {
                WC()->cart->remove_cart_item( $cart_item_key );
                $changed = true;
                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( __( 'A ticket hold in your cart expired and was removed.', 'oras-tickets' ), 'error' );
                }
                continue;
            }

            $event_id_raw = get_post_meta( $product_id, '_oras_ticket_event_id', true );
            $index_raw    = get_post_meta( $product_id, '_oras_ticket_index', true );
            if ( $event_id_raw === '' || $index_raw === '' ) {
                if ( $event_id_raw !== '' || $index_raw !== '' ) {
                    WC()->cart->remove_cart_item( $cart_item_key );
                    $changed = true;
                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( __( 'A ticket in your cart is no longer available and was removed.', 'oras-tickets' ), 'error' );
                    }
}
                continue;
            }

            $event_id = (int) $event_id_raw;
            $index    = (int) $index_raw;
            if ( $event_id <= 0 || $index < 0 ) {
                WC()->cart->remove_cart_item( $cart_item_key );
                $changed = true;
                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( __( 'A ticket in your cart is no longer available and was removed.', 'oras-tickets' ), 'error' );
                }
                continue;
            }

            $ticket = $this->get_ticket_definition( $event_id, $index );
            if ( ! $ticket ) {
                WC()->cart->remove_cart_item( $cart_item_key );
                $changed = true;
                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( __( 'A ticket in your cart is no longer available and was removed.', 'oras-tickets' ), 'error' );
                }
                continue;
            }

            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
            if ( ! $product || ! $product->is_purchasable() ) {
                WC()->cart->remove_cart_item( $cart_item_key );
                $changed = true;
                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( __( 'A ticket in your cart is no longer available and was removed.', 'oras-tickets' ), 'error' );
                }
                continue;
            }

            $name = $this->get_ticket_name( $ticket, $product );

            $manages = $product->managing_stock();

            // phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment
            if ( ! $product->is_in_stock() && ! $product->backorders_allowed() ) {
                if ( ! $manages ) {
WC()->cart->remove_cart_item( $cart_item_key );
                    $changed = true;
                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( sprintf( __( 'Ticket %s is sold out and was removed from your cart.', 'oras-tickets' ), $name ), 'error' );
                    }
                    continue;
                }
            }

            $sale_start = isset( $ticket['sale_start'] ) ? (string) $ticket['sale_start'] : '';
            $sale_end   = isset( $ticket['sale_end'] ) ? (string) $ticket['sale_end'] : '';

            if ( $sale_start !== '' ) {
                $start_ts = strtotime( $sale_start . ' UTC' );
                if ( $start_ts && $start_ts > $now ) {
                    WC()->cart->remove_cart_item( $cart_item_key );
                    $changed = true;
                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( sprintf( __( 'Ticket %s is not on sale yet and was removed from your cart.', 'oras-tickets' ), $name ), 'error' );
                    }
                    continue;
                }
            }

            if ( $sale_end !== '' ) {
                $end_ts = strtotime( $sale_end . ' UTC' );
                if ( $end_ts && $end_ts < $now ) {
                    WC()->cart->remove_cart_item( $cart_item_key );
                    $changed = true;
                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( sprintf( __( 'Ticket %s sales have ended and was removed from your cart.', 'oras-tickets' ), $name ), 'error' );
                    }
                    continue;
                }
            }

            $current_qty = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;

            if ( $manages ) {
                $available = (int) $product->get_stock_quantity();
                // Woo can temporarily reserve stock during checkout; do not remove on 0 here.
                if ( $available > 0 && $current_qty > $available ) {
                    WC()->cart->set_quantity( $cart_item_key, $available, true );
                    $changed = true;
                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( sprintf( __( 'Quantity for %1$s was reduced to %2$d due to limited availability.', 'oras-tickets' ), $name, $available ), 'notice' );
                    }
                }
            } elseif ( $current_qty > 10 ) {
                WC()->cart->set_quantity( $cart_item_key, 10, true );
                $changed = true;
                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( sprintf( __( 'Quantity for %s was reduced to 10.', 'oras-tickets' ), $name ), 'notice' );
                }
            }
        }

        if ( $changed ) {
            WC()->cart->calculate_totals();
        }
    }

    /**
     * Fetch the ticket definition for the given event and index.
     */
    private function get_ticket_definition( int $event_id, int $index ): ?array {
        if ( $event_id <= 0 || $index < 0 ) {
            return null;
        }

        $collection = Ticket_Collection::load_for_event( $event_id );
        $tickets    = $collection->all();

        if ( ! array_key_exists( $index, $tickets ) ) {
            return null;
        }

        $ticket_obj = $tickets[ $index ];
        $ticket     = $ticket_obj->to_array();

        return ! empty( $ticket ) ? $ticket : null;
    }

    /**
     * Resolve the display name for a ticket.
     *
     * @param array $ticket
     * @param mixed $product
     */
    private function get_ticket_name( array $ticket, $product ): string {
        if ( isset( $ticket['name'] ) && $ticket['name'] !== '' ) {
            return (string) $ticket['name'];
        }

        if ( $product && method_exists( $product, 'get_name' ) ) {
            return (string) $product->get_name();
        }

        return __( 'Ticket', 'oras-tickets' );
    }

    /**
     * Return true when the ticket is currently on sale based on its sale window.
     */
    private function is_ticket_on_sale_now( array $ticket, int $now ): bool {
        $sale_start = isset( $ticket['sale_start'] ) ? (string) $ticket['sale_start'] : '';
        $sale_end   = isset( $ticket['sale_end'] ) ? (string) $ticket['sale_end'] : '';

        if ( $sale_start !== '' ) {
            $start_ts = strtotime( $sale_start . ' UTC' );
            if ( $start_ts && $start_ts > $now ) {
                return false;
            }
        }

        if ( $sale_end !== '' ) {
            $end_ts = strtotime( $sale_end . ' UTC' );
            if ( $end_ts && $end_ts < $now ) {
                return false;
            }
        }

        return true;
    }

    private function get_ticket_sale_state( array $ticket, int $now ): string {
        $sale_start = isset( $ticket['sale_start'] ) ? (string) $ticket['sale_start'] : '';
        $sale_end   = isset( $ticket['sale_end'] ) ? (string) $ticket['sale_end'] : '';

        if ( $sale_start !== '' ) {
            $start_ts = strtotime( $sale_start . ' UTC' );
            if ( $start_ts && $start_ts > $now ) {
                return 'upcoming';
            }
        }

        if ( $sale_end !== '' ) {
            $end_ts = strtotime( $sale_end . ' UTC' );
            if ( $end_ts && $end_ts < $now ) {
                return 'ended';
            }
        }

        return 'on_sale';
    }

    private function format_ticket_datetime( string $value ): string {
        if ( '' === $value ) {
            return '';
        }

        $datetime = \DateTime::createFromFormat( 'Y-m-d H:i', $value, wp_timezone() );
        if ( ! $datetime ) {
            return $value;
        }

        return wp_date(
            trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
            $datetime->getTimestamp(),
            wp_timezone()
        );
    }

    private function get_mapped_product_id( array $map, int $index ): int {
        $string_key = (string) $index;
        if ( isset( $map[ $string_key ] ) ) {
            return absint( $map[ $string_key ] );
        }

        if ( isset( $map[ $index ] ) ) {
            return absint( $map[ $index ] );
        }

        return 0;
    }

    /**
     * @param array<int|string,mixed> $posted
     * @param array<int,mixed>        $tickets
     * @return array<int,array<string,mixed>>
     */
    private function get_ticket_questions_for_selection( int $event_id, array $posted, array $tickets ): array {
        $definitions = Event_Questions::load_definitions( $event_id );
        if ( empty( $definitions ) ) {
            return array();
        }

        $modes = array();
        foreach ( $posted as $raw_index => $raw_qty ) {
            $index = absint( $raw_index );
            $qty = absint( $raw_qty );
            if ( $qty <= 0 || ! array_key_exists( $index, $tickets ) ) {
                continue;
            }

            $ticket_obj = $tickets[ $index ];
            $ticket = method_exists( $ticket_obj, 'to_array' ) ? $ticket_obj->to_array() : ( is_array( $ticket_obj ) ? $ticket_obj : array() );
            $modes[] = Ticket::normalizeAttendanceMode(
                isset( $ticket['attendance_mode'] ) ? (string) $ticket['attendance_mode'] : Ticket::ATTENDANCE_MODE_ONSITE,
                Ticket::ATTENDANCE_MODE_ONSITE
            );
        }

        $modes = array_values( array_unique( $modes ) );
        $attendance_scope = 1 === count( $modes ) ? (string) $modes[0] : Event_Questions::ATTENDANCE_ALL;

        return Event_Questions::filter_questions( $definitions, Event_Questions::APPLIES_TICKETS, $attendance_scope );
    }

    /**
     * @param array<int|string,mixed> $posted
     * @return array<int,int>
     */
    private function normalize_posted_quantities( array $posted ): array {
        $quantities = array();
        foreach ( $posted as $raw_index => $raw_qty ) {
            $index = absint( $raw_index );
            $qty = absint( $raw_qty );
            if ( $qty > 0 ) {
                $quantities[ $index ] = $qty;
            }
        }

        return $quantities;
    }

    /**
     * @param array<int,array<string,mixed>> $questions
     * @param array<int,int>                 $quantities
     * @param array<string,mixed>            $answers
     */
    private function render_ticket_question_step( int $event_id, array $questions, array $quantities, array $answers = array() ): string {
        if ( empty( $questions ) || empty( $quantities ) ) {
            return '';
        }

        ob_start();
        echo '<section class="oras-tickets-section">';
        echo '<div class="oras-tickets-display oras-event-questions-panel">';
        echo '<h2>' . esc_html__( 'Event Questions', 'oras-tickets' ) . '</h2>';
        echo '<p>' . esc_html__( 'Please answer these event questions before continuing to the cart.', 'oras-tickets' ) . '</p>';
        if ( function_exists( 'wc_print_notices' ) ) {
            wc_print_notices();
        }
        echo '<form method="post" action="' . esc_url( get_permalink( $event_id ) ) . '">';
        echo wp_nonce_field( 'oras_tickets_add_to_cart', 'oras_tickets_nonce', true, false );
        echo '<input type="hidden" name="_oras_tickets" value="1" />';
        echo '<input type="hidden" name="oras_ticket_questions_confirmed" value="1" />';
        foreach ( $quantities as $index => $qty ) {
            echo '<input type="hidden" name="oras_qty[' . esc_attr( (string) $index ) . ']" value="' . esc_attr( (string) $qty ) . '" />';
        }
        Event_Questions::render_fields( $questions, $answers );
        echo '<p><button type="submit" name="oras_tickets_add_to_cart" class="button">' . esc_html__( 'Continue to Cart', 'oras-tickets' ) . '</button> ';
        echo '<a class="button" href="' . esc_url( get_permalink( $event_id ) ) . '">' . esc_html__( 'Back to Tickets', 'oras-tickets' ) . '</a></p>';
        echo '</form>';
        echo '</div>';
        echo '</section>';

        return (string) ob_get_clean();
    }

    private function get_cart_quantity_for_product( int $product_id ): int {
        if ( $product_id <= 0 || ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return 0;
        }

        $quantity = 0;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $cart_product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
            if ( $cart_product_id !== $product_id ) {
                continue;
            }

            $quantity += isset( $cart_item['quantity'] ) ? max( 0, (int) $cart_item['quantity'] ) : 0;
        }

        return $quantity;
    }

    private function is_oras_ticket_product( int $product_id ): bool {
        if ( $product_id <= 0 ) {
            return false;
        }

        $event_id_raw = get_post_meta( $product_id, '_oras_ticket_event_id', true );
        $index_raw    = get_post_meta( $product_id, '_oras_ticket_index', true );

        return $event_id_raw !== '' && $index_raw !== '';
    }

    private function get_existing_hold_started_at( int $product_id ): int {
        if ( $product_id <= 0 || ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return 0;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $cart_product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
            if ( $cart_product_id !== $product_id ) {
                continue;
            }

            $started_at = isset( $cart_item['_oras_hold_started_at'] ) ? (int) $cart_item['_oras_hold_started_at'] : 0;
            if ( $started_at > 0 ) {
                return $started_at;
            }
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $cart_item
     */
    private function is_cart_item_hold_expired( array $cart_item, int $now ): bool {
        $product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
        if ( ! $this->is_oras_ticket_product( $product_id ) ) {
            return false;
        }

        $started_at = isset( $cart_item['_oras_hold_started_at'] ) ? (int) $cart_item['_oras_hold_started_at'] : 0;
        if ( $started_at <= 0 ) {
            return false;
        }

        $hold_seconds = $this->get_cart_hold_seconds( $cart_item );
        if ( $hold_seconds <= 0 ) {
            return false;
        }

        return ( $now - $started_at ) >= $hold_seconds;
    }

    /**
     * @param array<string,mixed> $cart_item
     */
    private function get_cart_hold_seconds( array $cart_item ): int {
        $hold_seconds = self::CART_HOLD_SECONDS_DEFAULT;

        $settings = Settings_Page::get_settings();
        $minutes  = isset( $settings['tickets']['cart_hold_minutes'] ) ? absint( $settings['tickets']['cart_hold_minutes'] ) : 0;
        if ( $minutes > 0 ) {
            $hold_seconds = $minutes * 60;
        }

        $hold_seconds = (int) apply_filters( 'oras_tickets_cart_hold_seconds', $hold_seconds, $cart_item );

        return max( 0, $hold_seconds );
    }

    /**
     * Filter the main post content and append purchase form for single TEC events.
     */
    public function the_content_filter( string $content ): string {
        if ( ! is_singular( Meta::EVENT_POST_TYPE ) ) {
            return $content;
        }

        if ( ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $event_id = get_the_ID();
        if ( ! $event_id || $event_id <= 0 ) {
            return $content;
        }

        $form = $this->render_form_html( $event_id );
        return $content . $form;
    }

    /**
     * Return the HTML for the purchase form for the given event.
     */
    private function render_form_html( int $event_id ): string {
        $collection = Ticket_Collection::load_for_event( $event_id );
        if ( $collection->count() === 0 ) {
            return '';
        }

        $map = get_post_meta( $event_id, '_oras_tickets_woo_map_v1', true );
        if ( ! is_array( $map ) ) {
            $map = array();
        }

        // All sale window comparisons are done in UTC.
        $now                   = (int) time();
        $tickets               = $collection->all();
        $tickets_on_sale       = array();
        $has_ended_ticket      = false;
        $next_sale_start_ts    = 0;
        $next_sale_start_label = '';
        foreach ( $tickets as $index => $ticket_obj ) {
            $ticket = method_exists( $ticket_obj, 'to_array' ) ? $ticket_obj->to_array() : ( is_array( $ticket_obj ) ? $ticket_obj : array() );

            $sale_state = $this->get_ticket_sale_state( $ticket, $now );
            if ( 'upcoming' === $sale_state ) {
                $sale_start = isset( $ticket['sale_start'] ) ? (string) $ticket['sale_start'] : '';
                $start_ts   = $sale_start !== '' ? strtotime( $sale_start . ' UTC' ) : false;
                if ( $start_ts && ( 0 === $next_sale_start_ts || $start_ts < $next_sale_start_ts ) ) {
                    $next_sale_start_ts    = (int) $start_ts;
                    $next_sale_start_label = $this->format_ticket_datetime( $sale_start );
                }
                continue;
            }

            if ( 'ended' === $sale_state ) {
                $has_ended_ticket = true;
                continue;
            }

            $tickets_on_sale[ $index ] = $ticket_obj;
        }

        ob_start();

        echo '<section class="oras-tickets-section">';
        echo '<div id="oras-tickets-display" class="oras-tickets-display">';
        echo '<h2>Tickets</h2>';

        if ( function_exists( 'wc_print_notices' ) ) {
            wc_print_notices();
        }

        if ( empty( $tickets_on_sale ) ) {
            if ( $next_sale_start_label !== '' ) {
                /* translators: %s: ticket sale start date/time */
                echo '<p>' . esc_html( sprintf( __( 'Tickets will go on sale on %s.', 'oras-tickets' ), $next_sale_start_label ) ) . '</p>';
            } elseif ( $has_ended_ticket ) {
                echo '<p>' . esc_html__( 'Ticket sales have ended.', 'oras-tickets' ) . '</p>';
            } else {
                echo '<p>' . esc_html__( 'Tickets are currently unavailable.', 'oras-tickets' ) . '</p>';
            }
            echo '</div>';
            echo '</section>';
            return (string) ob_get_clean();
        }

        $posted_quantities_raw = isset( $_POST['_oras_tickets'], $_POST['oras_qty'] ) && is_array( $_POST['oras_qty'] )
            ? wp_unslash( $_POST['oras_qty'] )
            : array();
        $posted_quantities = $this->normalize_posted_quantities( is_array( $posted_quantities_raw ) ? $posted_quantities_raw : array() );
        $ticket_questions = $this->get_ticket_questions_for_selection( $event_id, $posted_quantities, $tickets );
        $question_answers = isset( $_POST['oras_event_question_answers'] ) && is_array( $_POST['oras_event_question_answers'] )
            ? wp_unslash( $_POST['oras_event_question_answers'] )
            : array();
        $is_question_step_request = ! empty( $posted_quantities )
            && ! empty( $ticket_questions )
            && empty( $_POST['oras_ticket_questions_confirmed'] );
        $is_failed_question_submission = ! empty( $posted_quantities )
            && ! empty( $ticket_questions )
            && ! empty( $_POST['oras_ticket_questions_confirmed'] )
            && function_exists( 'wc_notice_count' )
            && wc_notice_count( 'error' ) > 0;

        if ( $is_question_step_request || $is_failed_question_submission ) {
            ob_end_clean();
            return $this->render_ticket_question_step( $event_id, $ticket_questions, $posted_quantities, is_array( $question_answers ) ? $question_answers : array() );
        }

        echo '<form method="post" action="' . esc_url( get_permalink( $event_id ) ) . '">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo wp_nonce_field( 'oras_tickets_add_to_cart', 'oras_tickets_nonce', true, false );
        // marker to make remote HTML checks easier
        echo '<input type="hidden" name="_oras_tickets" value="1" />';

        echo '<table class="oras-tickets-table">';
        echo '<thead><tr><th>Ticket</th><th>Price</th><th>Status</th><th>Qty</th></tr></thead>';
        echo '<tbody>';

        foreach ( $tickets_on_sale as $index => $ticket_obj ) {
            $ticket = method_exists( $ticket_obj, 'to_array' ) ? $ticket_obj->to_array() : ( is_array( $ticket_obj ) ? $ticket_obj : array() );
            $key    = (string) $index;

            $product_id = $this->get_mapped_product_id( $map, $index );
if ( $product_id <= 0 ) {
                continue;
            }
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
            if ( ! $product ) {
                continue;
            }

            $name          = isset( $ticket['name'] ) ? esc_html( $ticket['name'] ) : $product->get_name();
            $resolved      = Price_Resolver::resolve_ticket_price( $ticket );
            $price_raw     = $resolved['price'];
            $price_display = $price_raw !== '' && is_numeric( $price_raw ) ? '$' . number_format( (float) $price_raw, 2, '.', '' ) : esc_html( (string) $price_raw );
            $description   = isset( $ticket['description'] ) ? esc_html( $ticket['description'] ) : '';
            $attendance_mode = Ticket::normalizeAttendanceMode(
                isset( $ticket['attendance_mode'] ) ? (string) $ticket['attendance_mode'] : '',
                Ticket::ATTENDANCE_MODE_VIRTUAL
            );
            $attendance_label = Ticket::ATTENDANCE_MODE_VIRTUAL === $attendance_mode
                ? __( 'Virtual Access', 'oras-tickets' )
                : __( 'On-site Access', 'oras-tickets' );

            $sale_start = isset( $ticket['sale_start'] ) ? (string) $ticket['sale_start'] : '';
            $sale_end   = isset( $ticket['sale_end'] ) ? (string) $ticket['sale_end'] : '';

            // Ticket definition stores sale window in UTC strings.
            $start_ts = $sale_start !== '' ? strtotime( $sale_start . ' UTC' ) : false;
            $end_ts   = $sale_end !== '' ? strtotime( $sale_end . ' UTC' ) : false;

            $status          = 'On sale';
            $status_class    = 'oras-status--on-sale';
            $disabled        = false;
            $disabled_reason = '';
            if ( $start_ts && $now < $start_ts ) {
                $status          = 'Not on sale yet';
                $status_class    = 'oras-status--not-yet';
                $disabled        = true;
                $disabled_reason = 'Not on sale yet';
            } elseif ( $end_ts && $now > $end_ts ) {
                $status          = 'Sales ended';
                $status_class    = 'oras-status--ended';
                $disabled        = true;
                $disabled_reason = 'Sales ended';
            }

            $manages = ( method_exists( $product, 'managing_stock' ) && $product->managing_stock() );
            if ( $manages ) {
                $max = method_exists( $product, 'get_stock_quantity' ) ? max( 0, (int) $product->get_stock_quantity() ) : 0;
            } else {
                $max = 10;
            }

            if ( $manages && $max <= 0 ) {
                $status          = 'Sold out';
                $status_class    = 'oras-status--sold-out';
                $disabled        = true;
                $disabled_reason = 'Sold out';
            }

            $stock_note = '';
            if ( $status_class === 'oras-status--on-sale' ) {
                if ( $manages ) {
                    $stock_qty = (int) $max;
                    if ( $stock_qty > 0 ) {
                        $stock_note = '• ' . $stock_qty . ' left';
                    }
                } else {
                    $stock_note = '• Unlimited';
                }
            }

            echo '<tr>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<td><strong>' . $name . '</strong>';
            if ( $description !== '' ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '<div class="oras-ticket-desc">' . $description . '</div>';
            }
            echo '<div class="oras-ticket-mode">' . esc_html( $attendance_label ) . '</div>';
            if ( ! empty( $resolved['phase_label'] ) && is_string( $resolved['phase_label'] ) ) {
                $phase_label = (string) $resolved['phase_label'];
                if ( strtolower( $phase_label ) !== 'standard' ) {
                    echo '<div class="oras-ticket-phase">' . esc_html( $phase_label ) . '</div>';
                }
            }
            if ( isset( $resolved['phase_end_ts'] ) && is_int( $resolved['phase_end_ts'] ) && $resolved['phase_end_ts'] > $now ) {
                $remaining     = max( 0, $resolved['phase_end_ts'] - $now );
                $total_minutes = (int) floor( $remaining / 60 );
                $days          = (int) floor( $total_minutes / 1440 );
                $hours         = (int) floor( ( $total_minutes % 1440 ) / 60 );
                $minutes       = (int) ( $total_minutes % 60 );
                $parts         = array();
                if ( $days > 0 ) {
                    $parts[] = $days . 'd';
                }
                if ( $hours > 0 ) {
                    $parts[] = $hours . 'h';
                }
                if ( $minutes > 0 ) {
                    $parts[] = $minutes . 'm';
                }
                if ( ! empty( $parts ) ) {
                    echo '<div class="oras-ticket-phase-countdown">' . esc_html( 'Price increases in: ' . implode( ' ', $parts ) ) . '</div>';
                }
            }
            if ( $disabled && $disabled_reason !== '' ) {
                echo '<div class="oras-ticket-status">' . esc_html( $disabled_reason ) . '</div>';
            }
            echo '</td>';
            echo '<td>' . esc_html( $price_display ) . '</td>';
            echo '<td><span class="oras-ticket-status-badge ' . esc_attr( $status_class ) . '">' . esc_html( $status );
            if ( $stock_note !== '' ) {
                echo ' <span class="oras-ticket-stock-note">' . esc_html( $stock_note ) . '</span>';
            }
            echo '</span></td>';

            $input_attrs = '';
            if ( $disabled ) {
                $input_attrs .= ' disabled';
            }
            $input_max = ( ! $disabled && $max > 0 ) ? $max : 0;
            echo '<td>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<input type="number" name="oras_qty[' . esc_attr( (string) $key ) . ']" min="0" value="0" max="' . esc_attr( (string) $input_max ) . '"' . $input_attrs . ' />';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        // Submit and view cart
        $cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '#';
        $has_ticket_questions = ! empty( Event_Questions::filter_questions( Event_Questions::load_definitions( $event_id ), Event_Questions::APPLIES_TICKETS, Event_Questions::ATTENDANCE_ALL ) );
        $button_label = $has_ticket_questions ? __( 'Continue to Event Questions', 'oras-tickets' ) : __( 'Add selected tickets to cart', 'oras-tickets' );
        echo '<p><button type="submit" name="oras_tickets_add_to_cart" class="button">' . esc_html( $button_label ) . '</button> ';
        echo '<a class="button" href="' . esc_url( $cart_url ) . '">' . esc_html__( 'View cart', 'oras-tickets' ) . '</a></p>';
        echo '</form>';

        echo '</div>';
        echo '</section>';

        return (string) ob_get_clean();
    }

    /**
     * Handle POST submission on template_redirect.
     */
    public function handle_post(): void {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            return;
        }

        if ( ! is_singular( Meta::EVENT_POST_TYPE ) ) {
            return;
        }

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) {
            return;
        }

        if ( ! isset( $_POST['_oras_tickets'] ) || (string) wp_unslash( $_POST['_oras_tickets'] ) !== '1' ) {
            return;
        }

        if ( ! isset( $_POST['oras_tickets_nonce'] ) ) {
            return;
        }

        $nonce = (string) wp_unslash( $_POST['oras_tickets_nonce'] );
        if ( ! wp_verify_nonce( $nonce, 'oras_tickets_add_to_cart' ) ) {
            return;
        }

        // Avoid fatals if Woo bootstrap isn't available.
        if ( ! function_exists( 'WC' ) || ! WC() ) {
            return;
        }

        $collection = Ticket_Collection::load_for_event( $event_id );
        $tickets    = $collection->all();

        $map = get_post_meta( $event_id, '_oras_tickets_woo_map_v1', true );
        if ( ! is_array( $map ) ) {
            $map = array();
        }

        $posted = isset( $_POST['oras_qty'] ) && is_array( $_POST['oras_qty'] ) ? wp_unslash( $_POST['oras_qty'] ) : array();
        $posted_quantities = $this->normalize_posted_quantities( is_array( $posted ) ? $posted : array() );
        $ticket_questions = $this->get_ticket_questions_for_selection( $event_id, $posted_quantities, $tickets );
        $question_snapshots = array();

        if ( ! empty( $ticket_questions ) && empty( $_POST['oras_ticket_questions_confirmed'] ) ) {
            return;
        }

        if ( ! empty( $ticket_questions ) ) {
            $raw_answers = isset( $_POST['oras_event_question_answers'] ) && is_array( $_POST['oras_event_question_answers'] )
                ? wp_unslash( $_POST['oras_event_question_answers'] )
                : array();
            $validation = Event_Questions::validate_answers( $ticket_questions, is_array( $raw_answers ) ? $raw_answers : array() );
            if ( $validation instanceof \WP_Error ) {
                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( $validation->get_error_message(), 'error' );
                }
                return;
            }

            $question_snapshots = Event_Questions::build_answer_snapshots( $ticket_questions, is_array( $raw_answers ) ? $raw_answers : array() );
        }

        $added_any = false;
        $had_error = false;
        $now       = (int) time();

        foreach ( $posted as $raw_index => $raw_qty ) {
            $index = absint( $raw_index );
            $qty   = absint( $raw_qty );

            if ( $qty === 0 ) {
                continue;
            }

            if ( ! array_key_exists( $index, $tickets ) ) {
                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( __( 'Invalid ticket selection.', 'oras-tickets' ), 'error' );
                }
                $had_error = true;
                continue;
            }

            $ticket_obj = $tickets[ $index ];
            $ticket     = $ticket_obj->to_array();
            $name       = isset( $ticket['name'] ) && $ticket['name'] !== '' ? (string) $ticket['name'] : __( 'Ticket', 'oras-tickets' );

            $product_id = $this->get_mapped_product_id( $map, $index );
            if ( $product_id <= 0 ) {
                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( sprintf( __( 'Ticket %s is not available.', 'oras-tickets' ), $name ), 'error' );
                }
                $had_error = true;
                continue;
            }

            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
            if ( ! $product ) {
                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( sprintf( __( 'Ticket %s is not available.', 'oras-tickets' ), $name ), 'error' );
                }
                $had_error = true;
                continue;
            }

            if ( ! $product->is_purchasable() ) {
                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( sprintf( __( 'Ticket %s is currently unavailable for purchase.', 'oras-tickets' ), $name ), 'error' );
                }
                $had_error = true;
                continue;
            }

            if ( $product->is_sold_individually() && $qty > 1 ) {
                $qty = 1;
                if ( function_exists( 'wc_add_notice' ) ) {
                    wc_add_notice( sprintf( __( 'Ticket %s can only be purchased once per order.', 'oras-tickets' ), $name ), 'notice' );
                }
            }

            if ( $product->is_sold_individually() && function_exists( 'WC' ) && WC()->cart ) {
                $cart_id     = WC()->cart->generate_cart_id( $product_id );
                $existing_key = WC()->cart->find_product_in_cart( $cart_id );
                if ( $existing_key ) {
                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( sprintf( __( 'Ticket %s is already in your cart and can only be purchased once per order.', 'oras-tickets' ), $name ), 'error' );
                    }
                    $had_error = true;
                    continue;
                }
            }

            $sale_start = isset( $ticket['sale_start'] ) ? (string) $ticket['sale_start'] : '';
            $sale_end   = isset( $ticket['sale_end'] ) ? (string) $ticket['sale_end'] : '';

            if ( $sale_start !== '' ) {
                $start_ts = strtotime( $sale_start . ' UTC' );
                if ( $start_ts && $start_ts > $now ) {
                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( sprintf( __( 'Ticket %s is not on sale yet.', 'oras-tickets' ), $name ), 'error' );
                    }
                    $had_error = true;
                    continue;
                }
            }

            if ( $sale_end !== '' ) {
                $end_ts = strtotime( $sale_end . ' UTC' );
                if ( $end_ts && $end_ts < $now ) {
                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( sprintf( __( 'Ticket %s sales have ended.', 'oras-tickets' ), $name ), 'error' );
                    }
                    $had_error = true;
                    continue;
                }
            }

            if ( $product->managing_stock() ) {
                $available = max( 0, (int) $product->get_stock_quantity() );
                if ( $available <= 0 ) {
                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( sprintf( __( 'Ticket %s is sold out.', 'oras-tickets' ), $name ), 'error' );
                    }
                    $had_error = true;
                    continue;
                }

                $qty_to_add = min( $qty, $available );
                if ( $qty_to_add < $qty ) {
                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( sprintf( __( 'Ticket %s quantity was capped to remaining stock.', 'oras-tickets' ), $name ), 'error' );
                    }
                    $had_error = true;
                }
            } else {
                $qty_to_add = min( $qty, 10 );
                if ( $qty_to_add < $qty ) {
                    if ( function_exists( 'wc_add_notice' ) ) {
                        wc_add_notice( sprintf( __( 'Ticket %s quantity was capped.', 'oras-tickets' ), $name ), 'error' );
                    }
                    $had_error = true;
                }
            }

            if ( $qty_to_add <= 0 ) {
                continue;
            }

            $error_count_before = function_exists( 'wc_notice_count' ) ? (int) wc_notice_count( 'error' ) : 0;

            // Some Woo validation extensions depend on standard request keys from
            // native add-to-cart forms. Mirror those keys while adding ticket
            // products so validation callbacks can evaluate correctly.
            $request_has_add_to_cart = array_key_exists( 'add-to-cart', $_REQUEST );
            $request_has_product_id  = array_key_exists( 'product_id', $_REQUEST );
            $request_has_quantity    = array_key_exists( 'quantity', $_REQUEST );
            $post_has_add_to_cart    = array_key_exists( 'add-to-cart', $_POST );
            $post_has_product_id     = array_key_exists( 'product_id', $_POST );
            $post_has_quantity       = array_key_exists( 'quantity', $_POST );

            $request_prev_add_to_cart = $request_has_add_to_cart ? $_REQUEST['add-to-cart'] : null;
            $request_prev_product_id  = $request_has_product_id ? $_REQUEST['product_id'] : null;
            $request_prev_quantity    = $request_has_quantity ? $_REQUEST['quantity'] : null;
            $post_prev_add_to_cart    = $post_has_add_to_cart ? $_POST['add-to-cart'] : null;
            $post_prev_product_id     = $post_has_product_id ? $_POST['product_id'] : null;
            $post_prev_quantity       = $post_has_quantity ? $_POST['quantity'] : null;

            $_REQUEST['add-to-cart'] = (string) $product_id;
            $_REQUEST['product_id']  = (string) $product_id;
            $_REQUEST['quantity']    = (string) $qty_to_add;
            $_POST['add-to-cart']    = (string) $product_id;
            $_POST['product_id']     = (string) $product_id;
            $_POST['quantity']       = (string) $qty_to_add;

            $cart_item_data = array();
            if ( ! empty( $question_snapshots ) ) {
                $cart_item_data[ Event_Questions::CART_ITEM_KEY ] = $question_snapshots;
            }

            $added = WC()->cart->add_to_cart( $product_id, $qty_to_add, 0, array(), $cart_item_data );

            if ( $request_has_add_to_cart ) {
                $_REQUEST['add-to-cart'] = $request_prev_add_to_cart;
            } else {
                unset( $_REQUEST['add-to-cart'] );
            }

            if ( $request_has_product_id ) {
                $_REQUEST['product_id'] = $request_prev_product_id;
            } else {
                unset( $_REQUEST['product_id'] );
            }

            if ( $request_has_quantity ) {
                $_REQUEST['quantity'] = $request_prev_quantity;
            } else {
                unset( $_REQUEST['quantity'] );
            }

            if ( $post_has_add_to_cart ) {
                $_POST['add-to-cart'] = $post_prev_add_to_cart;
            } else {
                unset( $_POST['add-to-cart'] );
            }

            if ( $post_has_product_id ) {
                $_POST['product_id'] = $post_prev_product_id;
            } else {
                unset( $_POST['product_id'] );
            }

            if ( $post_has_quantity ) {
                $_POST['quantity'] = $post_prev_quantity;
            } else {
                unset( $_POST['quantity'] );
            }

            if ( ! $added ) {
                $error_count_after = function_exists( 'wc_notice_count' ) ? (int) wc_notice_count( 'error' ) : $error_count_before;
                if ( function_exists( 'wc_add_notice' ) && $error_count_after <= $error_count_before ) {
                    $details = array();
                    $details[] = sprintf( __( 'Product ID: %d', 'oras-tickets' ), (int) $product_id );
                    if ( method_exists( $product, 'is_sold_individually' ) && $product->is_sold_individually() ) {
                        $details[] = __( 'sold individually', 'oras-tickets' );
                    }
                    if ( method_exists( $product, 'managing_stock' ) && $product->managing_stock() ) {
                        $details[] = sprintf( __( 'stock: %d', 'oras-tickets' ), (int) $product->get_stock_quantity() );
                    }

                    wc_add_notice(
                        sprintf(
                            __( 'Could not add %1$s to cart. Check product purchasability/stock or plugin validation rules (%2$s).', 'oras-tickets' ),
                            $name,
                            implode( ', ', $details )
                        ),
                        'error'
                    );
                }
                $had_error = true;
                continue;
            }

            $added_any = true;
        }

        if ( $added_any && function_exists( 'wc_add_notice' ) ) {
            $cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
            $message  = sprintf(
                /* translators: %s is the cart URL. */
                __( 'Tickets added to cart. <a class="button" href="%s">View cart</a>', 'oras-tickets' ),
                esc_url( $cart_url )
            );
            $allowed = array(
                'a' => array(
                    'class' => true,
                    'href'  => true,
                ),
            );
            wc_add_notice( wp_kses( $message, $allowed ), 'success' );
        }

        if ( ! $added_any && ! $had_error && function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( __( 'No valid tickets were added.', 'oras-tickets' ), 'error' );
        }
        // phpcs:enable WordPress.WP.I18n.MissingTranslatorsComment

        if ( $added_any && ! empty( $ticket_questions ) && function_exists( 'wc_get_cart_url' ) ) {
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        wp_safe_redirect( get_permalink( $event_id ) );
        exit;
    }
}
