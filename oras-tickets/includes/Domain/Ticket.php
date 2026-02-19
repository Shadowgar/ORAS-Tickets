<?php

/**
 * Ticket value object.
 *
 * @package ORAS\Tickets
 */

namespace ORAS\Tickets\Domain;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ticket value object.
 */
final class Ticket {

    /**
     * Stable internal ID for ticket row; never changes once created.
     *
     * @var string
     */
    public string $ticket_key; // NOSONAR legacy payload naming

    /**
     * Display name shown to customers/admin.
     *
     * @var string
     */
    public string $name;

    /**
     * Decimal string, e.g. "40.00".
     *
     * @var string
     */
    public string $price;

    /**
     * Optional time-based pricing phases.
     *
     * @var array<int,array<string,mixed>>
     */
    public array $price_phases; // NOSONAR legacy payload naming

    /**
     * Stock capacity.
     *
     * @var int
     */
    public int $capacity;

    /**
     * ISO8601 datetime string in site timezone, or empty string for none.
     *
     * @var string
     */
    public string $sale_start; // NOSONAR legacy payload naming

    /**
     * ISO8601 datetime string in site timezone, or empty string for none.
     *
     * @var string
     */
    public string $sale_end; // NOSONAR legacy payload naming

    /**
     * Optional admin/customer description.
     *
     * @var string
     */
    public string $description;

    /**
     * Optional explicit SKU.
     *
     * @var string
     */
    public string $sku;

    /**
     * If true, do not show ticket row when sold out.
     *
     * @var bool
     */
    public bool $hide_sold_out; // NOSONAR legacy payload naming

    /**
     * WooCommerce product ID created/managed by ORAS-Tickets.
     * 0 means not yet created.
     *
     * @var int
     */
    public int $product_id; // NOSONAR legacy payload naming

    /**
     * Build ticket object from raw data.
     *
     * @param array<string,mixed> $data Raw ticket payload.
     */
    public function __construct( array $data ) {
        $this->ticket_key = (string) ( $data['ticket_key'] ?? '' );
        $this->name       = (string) ( $data['name'] ?? '' );
        $this->price      = (string) ( $data['price'] ?? '0.00' );
        $price_phases     = $data['price_phases'] ?? array();
        if ( ! is_array( $price_phases ) ) {
            $price_phases = array();
        } else {
            foreach ( $price_phases as $phase ) {
                if ( ! is_array( $phase ) ) {
                    $price_phases = array();
                    break;
                }
            }
        }
        $this->price_phases  = $price_phases;
        $this->capacity      = (int) ( $data['capacity'] ?? 0 );
        $this->sale_start    = (string) ( $data['sale_start'] ?? '' );
        $this->sale_end      = (string) ( $data['sale_end'] ?? '' );
        $this->description   = (string) ( $data['description'] ?? '' );
        $this->sku           = (string) ( $data['sku'] ?? '' );
        $this->hide_sold_out = (bool) ( $data['hide_sold_out'] ?? false );
        $this->product_id    = (int) ( $data['product_id'] ?? 0 );
    }

    /**
     * Convert ticket to array payload.
     *
     * @return array<string,mixed>
     */
    public function to_array(): array {
        return array(
            'ticket_key'    => $this->ticket_key,
            'name'          => $this->name,
            'price'         => $this->price,
            'price_phases'  => $this->price_phases,
            'capacity'      => $this->capacity,
            'sale_start'    => $this->sale_start,
            'sale_end'      => $this->sale_end,
            'description'   => $this->description,
            'sku'           => $this->sku,
            'hide_sold_out' => $this->hide_sold_out,
            'product_id'    => $this->product_id,
        );
    }
}
