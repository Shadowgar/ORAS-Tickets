<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Split_Calculator {

    private QuickBooks_Logger $logger;

    public function __construct( ?QuickBooks_Logger $logger = null ) {
        $this->logger = $logger ?: new QuickBooks_Logger();
    }

    /**
     * Build per-category/event split lines from a Woo order.
     *
     * @param \WC_Order $order
     * @param array<string,mixed> $qbo_settings
     * @return array<string,mixed>|\WP_Error
     */
    public function calculate( $order, array $qbo_settings ) {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            return new \WP_Error( 'oras_qbo_invalid_order', 'Invalid WooCommerce order for split calculation.' );
        }

        $event_account_map = Settings::parse_event_account_map( (string) ( $qbo_settings['event_account_map'] ?? '' ) );
        $observer_slugs    = Settings::parse_slug_list( (string) ( $qbo_settings['observer_category_slugs'] ?? '' ) );
        $merch_slugs       = Settings::parse_slug_list( (string) ( $qbo_settings['merch_category_slugs'] ?? '' ) );
        $printful_slugs    = Settings::parse_slug_list( (string) ( $qbo_settings['printful_category_slugs'] ?? '' ) );
        $donation_slugs    = Settings::parse_slug_list( (string) ( $qbo_settings['donation_category_slugs'] ?? '' ) );
        $allow_unmapped_fallback = ! empty( $qbo_settings['allow_unmapped_fallback'] );

        $classified_rows = array();
        $warnings        = array();
        $unmapped_lines  = 0;
        $missing_account_lines = 0;

        $items = $order->get_items( 'line_item' );
        foreach ( $items as $item ) {
            if ( ! $item || ! method_exists( $item, 'get_total' ) ) {
                continue;
            }

            if ( ! $item instanceof \WC_Order_Item_Product ) {
                continue;
            }

            $line_total    = round( (float) $item->get_total(), 2 );
            $line_subtotal = round( (float) $item->get_subtotal(), 2 );

            if ( abs( $line_total ) < 0.0001 ) {
                continue;
            }

            $classification = $this->classify_item( $item, $observer_slugs, $merch_slugs, $printful_slugs, $donation_slugs );
            $bucket_key     = (string) $classification['bucket_key'];
            $bucket_label   = (string) $classification['bucket_label'];
            $account_id     = $this->resolve_account_id( $classification, $event_account_map, $qbo_settings );

            if ( (string) ( $classification['type'] ?? '' ) === 'unmapped' ) {
                $unmapped_lines++;
                if ( ! $allow_unmapped_fallback ) {
                    $warnings[] = sprintf(
                        'Unmapped product classification for item "%s" and unmapped fallback is disabled.',
                        (string) $item->get_name()
                    );
                }
            }

            if ( $account_id === '' ) {
                $missing_account_lines++;
                $warnings[] = sprintf(
                    'Missing account mapping for bucket "%1$s" on order item "%2$s".',
                    $bucket_key,
                    (string) $item->get_name()
                );
                continue;
            }

            $classified_rows[] = array(
                'bucket_key'   => $bucket_key,
                'bucket_label' => $bucket_label,
                'account_id'   => $account_id,
                'total'        => $line_total,
                'subtotal'     => $line_subtotal,
            );
        }

        $fee_items = $order->get_items( 'fee' );
        foreach ( $fee_items as $fee_item ) {
            if ( ! $fee_item || ! method_exists( $fee_item, 'get_total' ) ) {
                continue;
            }

            if ( ! $fee_item instanceof \WC_Order_Item_Fee ) {
                continue;
            }

            $line_total    = round( (float) $fee_item->get_total(), 2 );
            $line_subtotal = $line_total;

            if ( abs( $line_total ) < 0.0001 ) {
                continue;
            }

            $classification = $this->classify_fee_item( $fee_item, $observer_slugs, $merch_slugs, $printful_slugs, $donation_slugs );
            $bucket_key     = (string) $classification['bucket_key'];
            $bucket_label   = (string) $classification['bucket_label'];
            $account_id     = $this->resolve_account_id( $classification, $event_account_map, $qbo_settings );

            if ( (string) ( $classification['type'] ?? '' ) === 'unmapped' ) {
                $unmapped_lines++;
                if ( ! $allow_unmapped_fallback ) {
                    $warnings[] = sprintf(
                        'Unmapped fee classification for item "%s" and unmapped fallback is disabled.',
                        (string) $fee_item->get_name()
                    );
                }
            }

            if ( $account_id === '' ) {
                $missing_account_lines++;
                $warnings[] = sprintf(
                    'Missing account mapping for bucket "%1$s" on order fee "%2$s".',
                    $bucket_key,
                    (string) $fee_item->get_name()
                );
                continue;
            }

            $classified_rows[] = array(
                'bucket_key'   => $bucket_key,
                'bucket_label' => $bucket_label,
                'account_id'   => $account_id,
                'total'        => $line_total,
                'subtotal'     => $line_subtotal,
            );
        }

        $aggregated = self::aggregate_classified_rows( $classified_rows );
        $normalized_lines = $aggregated['lines'];
        $split_total      = $aggregated['split_total'];
        if ( abs( $split_total ) < 0.0001 ) {
            if ( ! empty( $qbo_settings['strict_mapping_mode'] ) && ( $unmapped_lines > 0 || $missing_account_lines > 0 || ! empty( $warnings ) ) ) {
                return new \WP_Error( 'oras_qbo_strict_mapping_failed', 'Strict mapping mode blocked sync: order contains unmapped or unresolved account lines.' );
            }
            return new \WP_Error( 'oras_qbo_empty_split', 'No mappable line-item or fee totals were found for this order.' );
        }

        if ( ! empty( $warnings ) ) {
            $this->logger->warning(
                'QuickBooks split calculation produced warnings',
                array(
                    'order_id' => $order->get_id(),
                    'warnings' => $warnings,
                )
            );
        }

        return array(
            'order_id'        => (int) $order->get_id(),
            'order_number'    => (string) $order->get_order_number(),
            'currency'        => (string) $order->get_currency(),
            'lines'           => $normalized_lines,
            'split_total'     => $split_total,
            'line_total_sum'  => (float) $aggregated['line_total_sum'],
            'discount_total'  => (float) $aggregated['discount_total'],
            'warnings'        => $warnings,
            'unmapped_lines'  => $unmapped_lines,
            'missing_account_lines' => $missing_account_lines,
            'discount_mode'   => 'proportional',
        );
    }

    /**
     * Aggregate classified rows into unique JournalEntry credit lines.
     *
     * @param array<int,array<string,mixed>> $classified_rows
     * @return array{lines:array<int,array<string,mixed>>,split_total:float,line_total_sum:float,discount_total:float}
     */
    public static function aggregate_classified_rows( array $classified_rows ): array {
        $buckets           = array();
        $line_total_sum    = 0.0;
        $line_subtotal_sum = 0.0;

        foreach ( $classified_rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $bucket_key   = isset( $row['bucket_key'] ) ? (string) $row['bucket_key'] : '';
            $bucket_label = isset( $row['bucket_label'] ) ? (string) $row['bucket_label'] : '';
            $account_id   = isset( $row['account_id'] ) ? (string) $row['account_id'] : '';
            $line_total   = round( (float) ( $row['total'] ?? 0 ), 2 );
            $line_subtotal = round( (float) ( $row['subtotal'] ?? $line_total ), 2 );

            if ( $bucket_key === '' || $account_id === '' || abs( $line_total ) < 0.0001 ) {
                continue;
            }

            $line_total_sum += $line_total;
            $line_subtotal_sum += $line_subtotal;

            if ( ! isset( $buckets[ $bucket_key ] ) ) {
                $buckets[ $bucket_key ] = array(
                    'bucket_key'   => $bucket_key,
                    'bucket_label' => $bucket_label !== '' ? $bucket_label : $bucket_key,
                    'account_id'   => $account_id,
                    'amount'       => 0.0,
                );
            }

            $buckets[ $bucket_key ]['amount'] += $line_total;
        }

        $normalized_lines = array();
        $split_total      = 0.0;
        foreach ( $buckets as $bucket ) {
            $amount = round( (float) $bucket['amount'], 2 );
            if ( abs( $amount ) < 0.0001 ) {
                continue;
            }

            $bucket['amount'] = $amount;
            $normalized_lines[] = $bucket;
            $split_total += $amount;
        }

        usort(
            $normalized_lines,
            static function ( array $a, array $b ): int {
                return strcmp( (string) $a['bucket_key'], (string) $b['bucket_key'] );
            }
        );

        return array(
            'lines'          => $normalized_lines,
            'split_total'    => round( $split_total, 2 ),
            'line_total_sum' => round( $line_total_sum, 2 ),
            'discount_total' => round( max( 0, $line_subtotal_sum - $line_total_sum ), 2 ),
        );
    }

    /**
     * @param \WC_Order_Item_Product $item
     * @param string[] $observer_slugs
     * @param string[] $merch_slugs
     * @param string[] $printful_slugs
     * @param string[] $donation_slugs
     * @return array<string,string>
     */
    private function classify_item( $item, array $observer_slugs, array $merch_slugs, array $printful_slugs, array $donation_slugs ): array {
        $event_id    = (int) $item->get_meta( '_oras_ticket_event_id', true );
        $ticket_name = trim( (string) $item->get_meta( '_oras_ticket_name', true ) );

        if ( $event_id > 0 || $ticket_name !== '' ) {
            $slug = '';
            if ( $event_id > 0 ) {
                $event_post = get_post( $event_id );
                if ( $event_post && isset( $event_post->post_name ) ) {
                    $slug = sanitize_title( (string) $event_post->post_name );
                }
            }
            if ( $slug === '' ) {
                $slug = $event_id > 0 ? 'event-' . $event_id : 'event-ticket';
            }

            $event_title = $event_id > 0 ? get_the_title( $event_id ) : '';
            $label_base  = $event_title !== '' ? $event_title : ucwords( str_replace( '-', ' ', $slug ) );

            return array(
                'type'       => 'ticket_event',
                'event_slug' => $slug,
                'bucket_key' => 'event:' . $slug,
                'bucket_label' => $label_base . ' Registration Fees',
            );
        }

        $product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
        $bucket_meta = $product_id > 0 ? sanitize_key( (string) get_post_meta( $product_id, '_oras_qbo_bucket', true ) ) : '';
        if ( $bucket_meta === 'observer_pass' || $bucket_meta === 'observer' ) {
            return array(
                'type'         => 'observer_pass',
                'bucket_key'   => 'observer_pass',
                'bucket_label' => 'Observer Pass Income',
            );
        }

        if ( $bucket_meta === 'donation' || $bucket_meta === 'donations' ) {
            return array(
                'type'         => 'donation',
                'bucket_key'   => 'donation',
                'bucket_label' => 'Donations Income',
            );
        }

        if ( $bucket_meta === 'printful' || $bucket_meta === 'pod' ) {
            return array(
                'type'         => 'printful',
                'bucket_key'   => 'printful',
                'bucket_label' => 'Printful Merchandise Income',
            );
        }

        if ( $bucket_meta === 'merchandise' || $bucket_meta === 'merch' ) {
            return array(
                'type'         => 'merchandise',
                'bucket_key'   => 'merchandise',
                'bucket_label' => 'Merchandise Income',
            );
        }

        $product_slugs = array();
        if ( $product_id > 0 ) {
            $terms = get_the_terms( $product_id, 'product_cat' );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $term ) {
                    if ( is_object( $term ) ) {
                        $product_slugs[] = sanitize_title( (string) $term->slug );
                    }
                }
            }
        }

        if ( ! empty( array_intersect( $product_slugs, $observer_slugs ) ) ) {
            return array(
                'type'         => 'observer_pass',
                'bucket_key'   => 'observer_pass',
                'bucket_label' => 'Observer Pass Income',
            );
        }

        if ( ! empty( array_intersect( $product_slugs, $donation_slugs ) ) ) {
            return array(
                'type'         => 'donation',
                'bucket_key'   => 'donation',
                'bucket_label' => 'Donations Income',
            );
        }

        if ( ! empty( array_intersect( $product_slugs, $printful_slugs ) ) ) {
            return array(
                'type'         => 'printful',
                'bucket_key'   => 'printful',
                'bucket_label' => 'Printful Merchandise Income',
            );
        }

        if ( ! empty( array_intersect( $product_slugs, $merch_slugs ) ) ) {
            return array(
                'type'         => 'merchandise',
                'bucket_key'   => 'merchandise',
                'bucket_label' => 'Merchandise Income',
            );
        }

        return array(
            'type'         => 'unmapped',
            'bucket_key'   => 'unmapped',
            'bucket_label' => 'Unmapped Woo Revenue',
        );
    }

    /**
     * @param \WC_Order_Item_Fee $item
     * @param string[] $observer_slugs
     * @param string[] $merch_slugs
     * @param string[] $printful_slugs
     * @param string[] $donation_slugs
     * @return array<string,string>
     */
    private function classify_fee_item( $item, array $observer_slugs, array $merch_slugs, array $printful_slugs, array $donation_slugs ): array {
        $bucket_meta = sanitize_key( (string) $item->get_meta( '_oras_qbo_bucket', true ) );
        if ( $bucket_meta === 'observer_pass' || $bucket_meta === 'observer' ) {
            return array(
                'type'         => 'observer_pass',
                'bucket_key'   => 'observer_pass',
                'bucket_label' => 'Observer Pass Income',
            );
        }

        if ( $bucket_meta === 'donation' || $bucket_meta === 'donations' ) {
            return array(
                'type'         => 'donation',
                'bucket_key'   => 'donation',
                'bucket_label' => 'Donations Income',
            );
        }

        if ( $bucket_meta === 'printful' || $bucket_meta === 'pod' ) {
            return array(
                'type'         => 'printful',
                'bucket_key'   => 'printful',
                'bucket_label' => 'Printful Merchandise Income',
            );
        }

        if ( $bucket_meta === 'merchandise' || $bucket_meta === 'merch' ) {
            return array(
                'type'         => 'merchandise',
                'bucket_key'   => 'merchandise',
                'bucket_label' => 'Merchandise Income',
            );
        }

        $fee_name = sanitize_title( (string) $item->get_name() );
        if ( $this->text_matches_slug_list( $fee_name, $observer_slugs ) || strpos( $fee_name, 'observer' ) !== false ) {
            return array(
                'type'         => 'observer_pass',
                'bucket_key'   => 'observer_pass',
                'bucket_label' => 'Observer Pass Income',
            );
        }

        if ( $this->text_matches_slug_list( $fee_name, $donation_slugs ) || strpos( $fee_name, 'donation' ) !== false || strpos( $fee_name, 'contribution' ) !== false ) {
            return array(
                'type'         => 'donation',
                'bucket_key'   => 'donation',
                'bucket_label' => 'Donations Income',
            );
        }

        if ( $this->text_matches_slug_list( $fee_name, $printful_slugs ) || strpos( $fee_name, 'printful' ) !== false ) {
            return array(
                'type'         => 'printful',
                'bucket_key'   => 'printful',
                'bucket_label' => 'Printful Merchandise Income',
            );
        }

        if ( $this->text_matches_slug_list( $fee_name, $merch_slugs ) || strpos( $fee_name, 'merch' ) !== false ) {
            return array(
                'type'         => 'merchandise',
                'bucket_key'   => 'merchandise',
                'bucket_label' => 'Merchandise Income',
            );
        }

        return array(
            'type'         => 'unmapped',
            'bucket_key'   => 'unmapped',
            'bucket_label' => 'Unmapped Woo Fee Revenue',
        );
    }

    /**
     * @param string[] $slugs
     */
    private function text_matches_slug_list( string $text, array $slugs ): bool {
        foreach ( $slugs as $slug ) {
            $candidate = sanitize_title( (string) $slug );
            if ( $candidate !== '' && strpos( $text, $candidate ) !== false ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,string> $classification
     * @param array<string,string> $event_account_map
     * @param array<string,mixed> $qbo_settings
     */
    private function resolve_account_id( array $classification, array $event_account_map, array $qbo_settings ): string {
        $type = isset( $classification['type'] ) ? (string) $classification['type'] : '';
        if ( $type === 'ticket_event' ) {
            $event_slug = isset( $classification['event_slug'] ) ? (string) $classification['event_slug'] : '';
            if ( $event_slug !== '' ) {
                $event_account_id = Settings::resolve_event_account_id( $event_slug, $event_account_map );
                if ( $event_account_id !== '' ) {
                    return $event_account_id;
                }
            }

            return (string) ( $qbo_settings['tickets_default_account_id'] ?? '' );
        }

        if ( $type === 'observer_pass' ) {
            return (string) ( $qbo_settings['observer_account_id'] ?? '' );
        }

        if ( $type === 'merchandise' ) {
            return (string) ( $qbo_settings['merchandise_account_id'] ?? '' );
        }

        if ( $type === 'donation' ) {
            return (string) ( $qbo_settings['donations_account_id'] ?? '' );
        }

        if ( $type === 'printful' ) {
            $printful = (string) ( $qbo_settings['printful_account_id'] ?? '' );
            if ( $printful !== '' ) {
                return $printful;
            }

            return (string) ( $qbo_settings['merchandise_account_id'] ?? '' );
        }

        $unmapped = (string) ( $qbo_settings['unmapped_account_id'] ?? '' );
        $allow_unmapped_fallback = ! empty( $qbo_settings['allow_unmapped_fallback'] );
        if ( $allow_unmapped_fallback ) {
            if ( $unmapped !== '' ) {
                return $unmapped;
            }

            return (string) ( $qbo_settings['tickets_default_account_id'] ?? '' );
        }

        return '';
    }
}
