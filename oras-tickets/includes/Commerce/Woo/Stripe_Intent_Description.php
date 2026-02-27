<?php

namespace ORAS\Tickets\Commerce\Woo;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Stripe_Intent_Description {

    private const STRIPE_DESCRIPTION_MAX_LENGTH = 500;
    private const STRIPE_METADATA_MAX_LENGTH    = 500;

    public function register(): void {
        add_filter( 'wc_stripe_generate_create_intent_request', array( $this, 'inject_ticket_description' ), 20, 4 );
    }

    /**
     * Add ORAS ticket names to Stripe PaymentIntent description/metadata.
     *
     * @param array    $request
     * @param \WC_Order $order
     * @param mixed    $prepared_source
     * @param bool     $is_setup_intent
     *
     * @return array
     */
    public function inject_ticket_description( array $request, $order, $prepared_source = null, bool $is_setup_intent = false ): array {
        if ( $is_setup_intent ) {
            return $request;
        }

        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            return $request;
        }

        $summary = $this->build_order_summary( $order );
        if ( empty( $summary['has_tickets'] ) ) {
            return $request;
        }

        $description          = sprintf( 'ORAS Order: %1$s - Order %2$s', $summary['description_summary'], (string) $order->get_order_number() );
        $request['description'] = $this->truncate_for_stripe( $description, self::STRIPE_DESCRIPTION_MAX_LENGTH );

        if ( ! isset( $request['metadata'] ) || ! is_array( $request['metadata'] ) ) {
            $request['metadata'] = array();
        }

        $request['metadata']['oras_contains_tickets'] = 'yes';
        $request['metadata']['oras_contains_merch']   = ! empty( $summary['has_merch'] ) ? 'yes' : 'no';
        $request['metadata']['oras_ticket_names']     = $this->truncate_for_stripe( (string) $summary['ticket_summary'], self::STRIPE_METADATA_MAX_LENGTH );

        if ( ! empty( $summary['has_merch'] ) ) {
            $request['metadata']['oras_merch_items'] = $this->truncate_for_stripe( (string) $summary['merch_summary'], self::STRIPE_METADATA_MAX_LENGTH );
        }

        return $request;
    }

    /**
     * Build an order summary that separates ORAS tickets from non-ticket merchandise.
     */
    private function build_order_summary( \WC_Order $order ): array {
        $ticket_lines = array();
        $merch_lines  = array();

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item || ! method_exists( $item, 'get_meta' ) ) {
                continue;
            }

            $event_id = (string) $item->get_meta( '_oras_ticket_event_id', true );
            $name_raw = (string) $item->get_meta( '_oras_ticket_name', true );
            $is_ticket = ( $event_id !== '' || $name_raw !== '' );

            $ticket_name = trim( $name_raw );
            if ( $ticket_name === '' && method_exists( $item, 'get_name' ) ) {
                $ticket_name = trim( (string) $item->get_name() );
            }

            $ticket_name = sanitize_text_field( wp_strip_all_tags( $ticket_name ) );
            if ( $ticket_name === '' ) {
                continue;
            }

            $quantity = method_exists( $item, 'get_quantity' ) ? max( 1, (int) $item->get_quantity() ) : 1;
            $total    = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;

            $target = $is_ticket ? $ticket_lines : $merch_lines;

            if ( ! isset( $target[ $ticket_name ] ) ) {
                $target[ $ticket_name ] = array(
                    'qty'   => 0,
                    'total' => 0.0,
                );
            }

            $target[ $ticket_name ]['qty']   += $quantity;
            $target[ $ticket_name ]['total'] += $total;

            if ( $is_ticket ) {
                $ticket_lines = $target;
            } else {
                $merch_lines = $target;
            }
        }

        $currency       = (string) $order->get_currency();
        $ticket_summary = $this->render_line_summary( $ticket_lines, $currency );
        $merch_summary  = $this->render_line_summary( $merch_lines, $currency );

        $parts = array();
        if ( $ticket_summary !== '' ) {
            $parts[] = 'Tickets: ' . $ticket_summary;
        }
        if ( $merch_summary !== '' ) {
            $parts[] = 'Merch: ' . $merch_summary;
        }

        return array(
            'has_tickets'         => ! empty( $ticket_lines ),
            'has_merch'           => ! empty( $merch_lines ),
            'ticket_summary'      => $ticket_summary,
            'merch_summary'       => $merch_summary,
            'description_summary' => implode( '; ', $parts ),
        );
    }

    /**
     * Render each line as "Name xQTY (CUR TOTAL)" and join with commas.
     *
     * @param array<string, array{qty:int,total:float}> $lines
     */
    private function render_line_summary( array $lines, string $currency ): string {
        if ( empty( $lines ) ) {
            return '';
        }

        $parts = array();
        foreach ( $lines as $ticket_name => $line_data ) {
            $quantity = isset( $line_data['qty'] ) ? max( 1, (int) $line_data['qty'] ) : 1;
            $total    = isset( $line_data['total'] ) ? (float) $line_data['total'] : 0.0;
            $parts[]  = sprintf(
                '%1$s x%2$d (%3$s)',
                $ticket_name,
                $quantity,
                $this->format_amount_for_metadata( $total, $currency )
            );
        }

        return implode( ', ', $parts );
    }

    /**
     * Format amount as plain text for Stripe metadata/description.
     */
    private function format_amount_for_metadata( float $amount, string $currency ): string {
        $currency_code = strtoupper( $currency !== '' ? $currency : get_woocommerce_currency() );

        return sprintf( '%1$s %2$s', $currency_code, number_format( $amount, 2, '.', '' ) );
    }

    /**
     * Stripe enforces maximum lengths for text fields such as description/metadata.
     */
    private function truncate_for_stripe( string $value, int $max_length ): string {
        if ( strlen( $value ) <= $max_length ) {
            return $value;
        }

        if ( $max_length <= 3 ) {
            return substr( $value, 0, $max_length );
        }

        return substr( $value, 0, $max_length - 3 ) . '...';
    }
}
