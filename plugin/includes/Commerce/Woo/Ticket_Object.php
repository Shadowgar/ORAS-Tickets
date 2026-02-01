<?php
namespace ORAS\Tickets\Commerce\Woo;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Minimal Ticket_Object wrapper for integration with Event Tickets.
 * Provides the core public fields that Event Tickets expects so the provider
 * can return a ticket-like object without depending on ET+ internals.
 */
class Ticket_Object extends \Tribe__Tickets__Ticket_Object {

    // Ensure these public properties exist for ET consumption.
    public $ID = 0;
    public $name = '';
    public $description = '';
    public $price = '';
    public $capacity = 0;
    public $start_date = '';
    public $end_date = '';
    public $provider_class = '';

    public function __construct( array $args = [] ) {
        // Do not rely on parent's constructor signature; set properties defensively.
        foreach ( $args as $k => $v ) {
            $this->{$k} = $v;
        }
        // Keep this wrapper lightweight; do not call parent constructor.
    }
}
