<?php
// Helper script for WP-CLI: force run Product_Sync for a given event id.
if ( php_sapi_name() !== 'cli' ) {
    return;
}

// Use admin user for capability checks
wp_set_current_user(1);

// Default to event id 57 for CLI test if not provided.
$event_id = isset( $argv[1] ) ? (int) $argv[1] : 57;
if ( $event_id <= 0 ) {
    echo "Missing event id\n";
    return;
}

// For testing: write a clean ticket envelope into postmeta using real PHP structures
$meta = array(
    'schema' => 1,
    'tickets' => array(
        0 => array(
            'ticket_key' => 'tk0',
            'name' => 'Integration Ticket',
            'price' => '12.50',
            'capacity' => 5,
            'sale_start' => '',
            'sale_end' => '',
            'description' => 'Integration test ticket',
            'hide_sold_out' => false,
        ),
    ),
);
update_post_meta( $event_id, '_oras_tickets_v1', $meta );

$ps = new \ORAS\Tickets\Commerce\Woo\Product_Sync();
$ps->on_save_event( $event_id, get_post( $event_id ), true );
echo "OK\n";
