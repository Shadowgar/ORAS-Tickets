<?php

namespace ORAS\Tickets\Commerce\Woo;

use ORAS\Tickets\Integrations\QuickBooks\Settings;

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
     * Add ORAS order line details to Stripe PaymentIntent description/metadata.
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
        $description          = sprintf( 'ORAS Order: %1$s - Order %2$s', $summary['description_summary'], (string) $order->get_order_number() );
        $request['description'] = $this->truncate_for_stripe( $description, self::STRIPE_DESCRIPTION_MAX_LENGTH );

        if ( ! isset( $request['metadata'] ) || ! is_array( $request['metadata'] ) ) {
            $request['metadata'] = array();
        }

        $request['metadata']['oras_item_names']               = $this->truncate_for_stripe( (string) $summary['item_summary'], self::STRIPE_METADATA_MAX_LENGTH );
        $request['metadata']['oras_item_buckets']             = $this->truncate_for_stripe( (string) $summary['bucket_summary'], self::STRIPE_METADATA_MAX_LENGTH );
        $request['metadata']['oras_contains_tickets']         = ! empty( $summary['has_tickets'] ) ? 'yes' : 'no';
        $request['metadata']['oras_contains_observer_passes'] = ! empty( $summary['has_observer_passes'] ) ? 'yes' : 'no';
        $request['metadata']['oras_contains_merch']           = ! empty( $summary['has_merch'] ) ? 'yes' : 'no';
        $request['metadata']['oras_contains_donations']       = ! empty( $summary['has_donations'] ) ? 'yes' : 'no';
        $request['metadata']['oras_contains_unmapped']        = ! empty( $summary['has_unmapped'] ) ? 'yes' : 'no';

        if ( ! empty( $summary['has_tickets'] ) ) {
            $request['metadata']['oras_ticket_names'] = $this->truncate_for_stripe( (string) $summary['ticket_summary'], self::STRIPE_METADATA_MAX_LENGTH );
        }

        if ( ! empty( $summary['has_merch'] ) ) {
            $request['metadata']['oras_merch_items'] = $this->truncate_for_stripe( (string) $summary['merch_summary'], self::STRIPE_METADATA_MAX_LENGTH );
        }

        return $request;
    }

    /**
     * Build an order summary using the same product classifications as QBO splits.
     */
    private function build_order_summary( \WC_Order $order ): array {
        $item_lines   = array();
        $ticket_lines = array();
        $merch_lines  = array();
        $bucket_lines = array();
        $buckets_seen = array();
        $classifier   = new Order_Item_Classifier();
        $qbo_settings = Settings::get_quickbooks_settings();

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item || ! method_exists( $item, 'get_meta' ) ) {
                continue;
            }

            if ( ! $item instanceof \WC_Order_Item_Product ) {
                continue;
            }

            $classification = $classifier->classify_product_item( $item, $qbo_settings );
            $metadata_bucket = isset( $classification['metadata_bucket'] ) ? (string) $classification['metadata_bucket'] : 'unmapped';
            $item_name_raw   = (string) $item->get_meta( '_oras_ticket_name', true );
            $item_name       = trim( $item_name_raw );
            if ( $item_name === '' ) {
                $item_name = trim( (string) $item->get_name() );
            }

            $item_name = sanitize_text_field( wp_strip_all_tags( $item_name ) );
            if ( $item_name === '' ) {
                continue;
            }

            $quantity = max( 1, (int) $item->get_quantity() );
            $total    = (float) $item->get_total();

            if ( ! isset( $item_lines[ $item_name ] ) ) {
                $item_lines[ $item_name ] = array(
                    'qty'   => 0,
                    'total' => 0.0,
                );
            }
            $item_lines[ $item_name ]['qty']   += $quantity;
            $item_lines[ $item_name ]['total'] += $total;

            $bucket_line_key = $item_name . '|' . $metadata_bucket;
            if ( ! isset( $bucket_lines[ $bucket_line_key ] ) ) {
                $bucket_lines[ $bucket_line_key ] = array(
                    'name'   => $item_name,
                    'bucket' => $metadata_bucket,
                    'qty'    => 0,
                );
            }
            $bucket_lines[ $bucket_line_key ]['qty'] += $quantity;
            $buckets_seen[ $metadata_bucket ]          = true;

            if ( $metadata_bucket === 'event_ticket' ) {
                if ( ! isset( $ticket_lines[ $item_name ] ) ) {
                    $ticket_lines[ $item_name ] = array(
                        'qty'   => 0,
                        'total' => 0.0,
                    );
                }
                $ticket_lines[ $item_name ]['qty']   += $quantity;
                $ticket_lines[ $item_name ]['total'] += $total;
            }

            if ( $metadata_bucket === 'merchandise' || $metadata_bucket === 'printful' ) {
                if ( ! isset( $merch_lines[ $item_name ] ) ) {
                    $merch_lines[ $item_name ] = array(
                        'qty'   => 0,
                        'total' => 0.0,
                    );
                }
                $merch_lines[ $item_name ]['qty']   += $quantity;
                $merch_lines[ $item_name ]['total'] += $total;
            }
        }

        $currency       = (string) $order->get_currency();
        $item_summary   = $this->render_line_summary( $item_lines, $currency );
        $ticket_summary = $this->render_line_summary( $ticket_lines, $currency );
        $merch_summary  = $this->render_line_summary( $merch_lines, $currency );
        $bucket_summary = $this->render_bucket_summary( $bucket_lines );

        $parts = array();
        if ( $item_summary !== '' ) {
            $parts[] = 'Items: ' . $item_summary;
        }

        return array(
            'has_tickets'         => isset( $buckets_seen['event_ticket'] ),
            'has_observer_passes' => isset( $buckets_seen['observer_pass'] ),
            'has_merch'           => isset( $buckets_seen['merchandise'] ) || isset( $buckets_seen['printful'] ),
            'has_donations'       => isset( $buckets_seen['donation'] ),
            'has_unmapped'        => isset( $buckets_seen['unmapped'] ),
            'item_summary'        => $item_summary,
            'ticket_summary'      => $ticket_summary,
            'merch_summary'       => $merch_summary,
            'bucket_summary'      => $bucket_summary,
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
     * Render each line as "Name=bucket xQTY" for compact audit metadata.
     *
     * @param array<string, array{name:string,bucket:string,qty:int}> $lines
     */
    private function render_bucket_summary( array $lines ): string {
        if ( empty( $lines ) ) {
            return '';
        }

        $parts = array();
        foreach ( $lines as $line_data ) {
            $item_name = isset( $line_data['name'] ) ? (string) $line_data['name'] : '';
            $bucket   = isset( $line_data['bucket'] ) ? (string) $line_data['bucket'] : 'unmapped';
            $quantity = isset( $line_data['qty'] ) ? max( 1, (int) $line_data['qty'] ) : 1;
            $parts[]  = sprintf( '%1$s=%2$s x%3$d', $item_name, $bucket, $quantity );
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
