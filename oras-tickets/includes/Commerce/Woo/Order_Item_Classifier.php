<?php

namespace ORAS\Tickets\Commerce\Woo;

use ORAS\Tickets\Integrations\QuickBooks\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Order_Item_Classifier {

    /**
     * Classify a Woo product line using the same signals consumed by QuickBooks splits.
     *
     * @param \WC_Order_Item_Product $item
     * @param array<string,mixed>     $qbo_settings
     * @return array<string,string>
     */
    public function classify_product_item( $item, array $qbo_settings ): array {
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
                'type'            => 'ticket_event',
                'metadata_bucket' => 'event_ticket',
                'event_slug'      => $slug,
                'bucket_key'      => 'event:' . $slug,
                'bucket_label'    => $label_base . ' Registration Fees',
            );
        }

        $product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
        $bucket_meta = $product_id > 0 ? sanitize_key( (string) get_post_meta( $product_id, '_oras_qbo_bucket', true ) ) : '';
        $bucket      = $this->classify_by_bucket_meta( $bucket_meta );
        if ( ! empty( $bucket ) ) {
            return $bucket;
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

        $slug_buckets = array(
            'observer_pass' => Settings::parse_slug_list( (string) ( $qbo_settings['observer_category_slugs'] ?? '' ) ),
            'donation'      => Settings::parse_slug_list( (string) ( $qbo_settings['donation_category_slugs'] ?? '' ) ),
            'printful'      => Settings::parse_slug_list( (string) ( $qbo_settings['printful_category_slugs'] ?? '' ) ),
            'merchandise'   => Settings::parse_slug_list( (string) ( $qbo_settings['merch_category_slugs'] ?? '' ) ),
        );

        foreach ( $slug_buckets as $bucket_type => $slugs ) {
            if ( ! empty( array_intersect( $product_slugs, $slugs ) ) ) {
                return $this->build_standard_bucket( $bucket_type );
            }
        }

        return $this->build_standard_bucket( 'unmapped' );
    }

    /**
     * @return array<string,string>
     */
    private function classify_by_bucket_meta( string $bucket_meta ): array {
        if ( $bucket_meta === 'observer_pass' || $bucket_meta === 'observer' ) {
            return $this->build_standard_bucket( 'observer_pass' );
        }

        if ( $bucket_meta === 'donation' || $bucket_meta === 'donations' ) {
            return $this->build_standard_bucket( 'donation' );
        }

        if ( $bucket_meta === 'printful' || $bucket_meta === 'pod' ) {
            return $this->build_standard_bucket( 'printful' );
        }

        if ( $bucket_meta === 'merchandise' || $bucket_meta === 'merch' ) {
            return $this->build_standard_bucket( 'merchandise' );
        }

        return array();
    }

    /**
     * @return array<string,string>
     */
    private function build_standard_bucket( string $bucket ): array {
        if ( $bucket === 'observer_pass' ) {
            return array(
                'type'            => 'observer_pass',
                'metadata_bucket' => 'observer_pass',
                'bucket_key'      => 'observer_pass',
                'bucket_label'    => 'Observer Pass Income',
            );
        }

        if ( $bucket === 'donation' ) {
            return array(
                'type'            => 'donation',
                'metadata_bucket' => 'donation',
                'bucket_key'      => 'donation',
                'bucket_label'    => 'Donations Income',
            );
        }

        if ( $bucket === 'printful' ) {
            return array(
                'type'            => 'printful',
                'metadata_bucket' => 'printful',
                'bucket_key'      => 'printful',
                'bucket_label'    => 'Printful Merchandise Income',
            );
        }

        if ( $bucket === 'merchandise' ) {
            return array(
                'type'            => 'merchandise',
                'metadata_bucket' => 'merchandise',
                'bucket_key'      => 'merchandise',
                'bucket_label'    => 'Merchandise Income',
            );
        }

        return array(
            'type'            => 'unmapped',
            'metadata_bucket' => 'unmapped',
            'bucket_key'      => 'unmapped',
            'bucket_label'    => 'Unmapped Woo Revenue',
        );
    }
}
